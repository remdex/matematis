<?php
declare(strict_types=1);

/**
 * Matematis — a JSON driven quiz player for 7 year olds.
 *
 * This file is the single entry point (the web server only executes
 * index.php here), so it serves both the page and the tiny JSON API:
 *
 *   GET  /                     the app (quiz chooser)
 *   GET  /t/<id>               deep link: start a quiz right away
 *   GET  /kurti                the JSON builder screen
 *   GET  /api/list             quiz summaries
 *   GET  /api/quiz/<id>        one quiz as JSON
 *   GET  /api/random           a random existing quiz
 *   GET  /api/generate         a freshly generated quiz
 *   POST /api/validate         validate author JSON (used by the builder)
 *   GET  /api/tts?status=1     is the ElevenLabs voice configured?
 *   GET  /api/tts?list=1       which sentences are already saved as MP3
 *   GET  /api/tts?text=…       the MP3 url of one task instruction
 *   POST /api/tts              { "items": [ { text, token } ] } → the MP3 urls
 *
 * The last two spend money, so every sentence has to carry a signed token:
 * the quiz payloads below ship a `tts` map of `sentence => token`, and a token
 * only ever authorises its own sentence (see tts_quiz_with_tokens()).
 */

define('APP_DIR', __DIR__);
define('QUIZ_APP', true);

require APP_DIR . '/lib/Quizzes.php';
require APP_DIR . '/lib/Generator.php';
require APP_DIR . '/lib/Tts.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Cache-busting URL for a static asset (mtime based, always root absolute). */
function asset(string $rel): string
{
    $file = APP_DIR . '/' . ltrim($rel, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return '/' . ltrim($rel, '/') . '?v=' . $version;
}

/**
 * Cache-busting import map for the ES modules `assets/js/app.js` imports.
 *
 * The `?v=<mtime>` on the entry <script> does NOT bust a nested
 * `import './x.js'`: the browser caches every module by its own resolved URL,
 * so an edited helper would keep running from cache. The import map maps each
 * module's absolute path to its own mtime-versioned URL, so every changed file
 * gets a fresh URL and nothing stale is ever served.
 *
 * @return array<string,string> e.g. ['/assets/js/figures.js' => '/assets/js/figures.js?v=1728…']
 */
function js_import_map(): array
{
    $map = [];
    foreach (glob(APP_DIR . '/assets/js/*.js') as $file) {
        $rel = 'assets/js/' . basename($file);
        $map['/' . $rel] = asset($rel);
    }
    foreach (glob(APP_DIR . '/assets/js/vendor/*.js') as $file) {
        $rel = 'assets/js/vendor/' . basename($file);
        $map['/' . $rel] = asset($rel);
    }
    return $map;
}

/** JSON that is safe to inline inside a <script> tag. */
function json_for_script(mixed $data): string
{
    return (string) json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}

/* ------------------------------------------------------------------- API -- */

function api_dispatch(string $rest, string $method): void
{
    $parts = $rest === '' ? [] : explode('/', $rest);
    $action = $parts[0] ?? 'list';

    switch ($action) {
        case 'list':
            json_out(['quizzes' => quiz_list()]);
            return;

        case 'quiz':
            $id = $parts[1] ?? (string) ($_GET['id'] ?? '');
            $quiz = quiz_load($id);
            if ($quiz === null || $quiz['questions'] === []) {
                json_out(['error' => 'Testas nerastas'], 404);
                return;
            }
            json_out(tts_quiz_with_tokens($quiz));
            return;

        case 'random':
            $id = quiz_random_id(isset($_GET['exclude']) ? (string) $_GET['exclude'] : null);
            if ($id === null) {
                json_out(['error' => 'Nėra testų'], 404);
                return;
            }
            json_out(tts_quiz_with_tokens(quiz_load($id)));
            return;

        case 'generate':
            $max = isset($_GET['max']) ? (int) $_GET['max'] : 10;
            $count = isset($_GET['count']) ? (int) $_GET['count'] : 1;
            $tasks = isset($_GET['tasks']) ? (int) $_GET['tasks'] : 0;
            $focus = isset($_GET['focus']) ? (string) $_GET['focus'] : 'mixed';
            // ?types=compare,count,... builds a quiz out of exactly those exercises.
            $types = isset($_GET['types']) ? gen_clean_types((string) $_GET['types']) : [];
            if ($types === []) {
                $focus = in_array($focus, GEN_FOCUSES, true) ? $focus : 'mixed';
                $types = gen_focus_types($focus);
                if ($focus === 'mixed') {
                    $focus = '';
                }
            } else {
                $focus = '';
            }
            // Two INDEPENDENT read-aloud switches, both off by default:
            //  · ?say=1        - every question that is NOT a story problem
            //                    (the global checkbox next to „Užduotys: visos“)
            //  · ?sayStory=1   - the story/storyChoice/storyMore/storyRead problems
            //                    (the checkbox inside the Pasakojimai group)
            // Absent (previews, old links) = leave each type's own default alone.
            $sayOthers = isset($_GET['say']) ? in_array((string) $_GET['say'], ['1', 'true', 'yes', 'on'], true) : null;
            $sayStory = isset($_GET['sayStory']) ? in_array((string) $_GET['sayStory'], ['1', 'true', 'yes', 'on'], true) : null;
            $quizzes = quiz_generate_batch($types, $max, $count, $tasks, $focus);
            if ($sayOthers !== null || $sayStory !== null) {
                gen_voice_apply($quizzes, $sayOthers, $sayStory);
            }
            json_out(['quizzes' => array_map('tts_quiz_with_tokens', $quizzes)]);
            return;

        case 'tts':
            // Speak the instructions (ElevenLabs), cached as files in cache/tts.
            tts_api($method, $parts[1] ?? '');
            return;

        case 'validate':
            if ($method !== 'POST') {
                json_out(['ok' => false, 'errors' => ['Naudokite POST'], 'warnings' => []], 405);
                return;
            }
            $body = (string) file_get_contents('php://input', false, null, 0, 262144);
            $raw = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                json_out(['ok' => false, 'errors' => ['Netaisyklingas JSON: ' . json_last_error_msg()], 'warnings' => []], 200);
                return;
            }
            json_out(quiz_validate($raw));
            return;

        default:
            json_out(['error' => 'Nežinoma API užklausa'], 404);
    }
}

/* -------------------------------------------------------------- routing -- */

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH);
$path = '/' . trim(is_string($path) ? $path : '/', '/');

if (preg_match('#^/api(?:/(.*))?$#', $path, $matches) || isset($_GET['api'])) {
    $rest = trim((string) ($matches[1] ?? $_GET['api'] ?? ''), '/');
    api_dispatch($rest, $method);
    exit;
}

$startQuiz = null;
$startScreen = 'home';
if (preg_match('#^/t/([A-Za-z0-9_-]{1,64})$#', $path, $matches)) {
    $startQuiz = $matches[1];
} elseif ($path === '/kurti') {
    $startScreen = 'builder';
}

$quizzes = quiz_list();
$groups = quiz_list_grouped();
$genGroups = gen_type_groups();
$genComplexity = gen_type_complexity_map();
$genPresets = [];
foreach (GEN_FOCUSES as $genFocus) {
    $genPresets[$genFocus] = gen_focus_types($genFocus);
}
$gradeWords = [1 => 'Pirmai', 2 => 'Antrai', 3 => 'Trečiai', 4 => 'Ketvirtai', 5 => 'Penktai', 6 => 'Šeštai'];
$emptyTemplate = "{\n  \"title\": \"Naujas testas\",\n  \"grade\": 1,\n  \"questions\": []\n}";

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="lt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Matematis — matematikos testai pirmai klasei</title>
<meta name="description" content="Skaičių palyginimas, skaičiavimas ir braižymas. Paprasti matematikos testai pirmai klasei.">
<meta name="theme-color" content="#0ea5e9">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='88'>🧮</text></svg>">
<!-- Nunito Sans, straight from Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,200..1000;1,6..12,200..1000&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<script>
  window.__QUIZZES__ = <?= json_for_script($quizzes) ?>;
  window.__START_QUIZ__ = <?= json_for_script($startQuiz) ?>;
  window.__START_SCREEN__ = <?= json_for_script($startScreen) ?>;
  window.__GEN_TYPE_GROUPS__ = <?= json_for_script($genGroups) ?>;
  window.__GEN_TYPE_COMPLEXITY__ = <?= json_for_script($genComplexity) ?>;
  window.__GEN_PRESETS__ = <?= json_for_script($genPresets) ?>;
</script>
<script type="importmap"><?= json_for_script(['imports' => js_import_map()]) ?></script>
<script type="module" src="<?= e(asset('assets/js/app.js')) ?>"></script>
</head>
<body>
<noscript><p class="p-6 text-2xl font-bold">Čia reikia įjungti JavaScript 🙂</p></noscript>

<div x-data="app()" x-init="boot()" class="wrap flex min-h-screen flex-col gap-4 px-3 py-4 sm:px-6">

  <!-- ------------------------------------------------------------ header -->
  <header class="flex items-center gap-3">
    <button class="icon-btn" x-show="screen !== 'home'" x-cloak @click="goHome()" title="Į pradžią">🏠</button>
    <p class="brand" x-show="screen === 'home'">🧮 Matematis</p>
    <p class="brand min-w-0 truncate" x-show="screen !== 'home'" x-cloak x-text="quiz ? quiz.emoji + ' ' + quiz.title : 'Matematis'"></p>
    <span class="grade-chip" x-show="screen === 'play' && quiz" x-cloak x-text="quiz ? (batchLabel || (quiz.grade || 1) + ' klasė') : ''"></span>
    <div class="ml-auto flex shrink-0 items-center gap-2">
      <button class="icon-btn" @click="showHelp = !showHelp" title="Kaip žaisti">❓</button>
    </div>
  </header>

  <!-- -------------------------------------------------------- help panel -->
  <div class="card-surface" x-show="showHelp" x-cloak @click.away="showHelp = false">
    <p class="text-xl font-extrabold">Kaip žaisti 🎈</p>
    <ul class="mt-2 list-disc pl-6 text-lg font-bold text-slate-600">
      <li>Pasirink testą, spausk 🎲 <em>Atsitiktinis testas iš esamų</em> arba ✨ susigeneruok naują.</li>
      <li>Atsakyk <strong>visus</strong> klausimus – jie visi matomi viename puslapyje.</li>
      <li>Suskaičiuok figūras ir įrašyk skaičių.</li>
      <li x-show="speechOn" x-cloak>Neperskaitei? Spausk <strong>🔊</strong> prie užduoties – ji bus perskaityta garsiai.</li>
      <li>Kur reikia – <strong>apibrauk</strong> kortelę su pele ar pirštu.</li>
      <li>Pabaigęs spausk <em>Tikrinti</em> – tada parodoma, kas teisingai.</li>
    </ul>
  </div>

  <main class="flex flex-1 flex-col gap-5">

    <!-- ==================================================== HOME SCREEN -->
    <section x-show="screen === 'home'" x-cloak class="flex flex-col gap-4">
      <div class="card-surface flex flex-col gap-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
          <p class="text-2xl font-extrabold text-slate-700">Sveikas! Skaičiuok ir brauk. 🎈</p>
          <div class="flex flex-wrap gap-2 sm:ml-auto">
            <button class="btn btn--primary" @click="randomQuiz()" :disabled="loading">🎲 Atsitiktinis testas iš esamų</button>
            <!-- <button class="btn btn--ghost" @click="openBuilder()">✏️ Kurti savo</button> -->
          </div>
        </div>

        <div class="rounded-3xl bg-slate-50 p-4">
          <p class="text-xl font-extrabold text-slate-700">✨ Sugeneruok naują testą</p>
          <p class="text-base font-bold text-slate-500">Testai sudaromi pagal tas pačias taisykles kaip ir pavyzdiniai.</p>

          <div class="mt-3 flex flex-wrap items-center gap-2">
            <span class="text-base font-bold text-slate-500">Skaičiai:</span>
            <button class="chip" :class="{ 'chip--on': genMax === 5 }" @click="genMax = 5">iki 5</button>
            <button class="chip" :class="{ 'chip--on': genMax === 10 }" @click="genMax = 10">iki 10</button>
            <button class="chip" :class="{ 'chip--on': genMax === 20 }" @click="genMax = 20">iki 20</button>
          </div>

          <div class="mt-3 flex flex-wrap items-center gap-3">
            <label class="block min-w-56 flex-1">
              <span class="flex items-center justify-between gap-3 text-base font-bold text-slate-500">
                <span>Kiek testų iš karto <span class="font-normal text-slate-400">(nuo 1 iki 20)</span></span>
                <output class="chip chip--on shrink-0" x-text="genCount"></output>
              </span>
              <input class="slider mt-2 w-full" type="range" min="1" max="20" step="1" x-model.number="genCount"
                     aria-label="Kiek testų sugeneruoti iš karto" aria-valuemin="1" aria-valuemax="20">
            </label>
          </div>

          <div class="mt-3 flex flex-wrap items-center gap-3">
            <label class="block min-w-56 flex-1">
              <span class="flex items-center justify-between gap-3 text-base font-bold text-slate-500">
                <span>Kiek užduočių teste <span class="font-normal text-slate-400">(nuo 1 iki 50)</span></span>
                <output class="chip chip--on shrink-0" x-text="genTasks"></output>
              </span>
              <input class="slider mt-2 w-full" type="range" min="1" max="50" step="1" x-model.number="genTasks"
                     aria-label="Kiek užduočių viename teste" aria-valuemin="1" aria-valuemax="50">
            </label>
          </div>

          <div class="mt-3 flex flex-wrap items-center gap-2">
            <button class="btn btn--amber" @click="newGenerated()" :disabled="loading">
              ✨ Generuoti testą
            </button>
            <button class="btn btn--ghost" @click="toggleGenPicker()"
                    :aria-expanded="genPickerOpen ? 'true' : 'false'" aria-controls="gen-types">
              <span x-text="genPickerOpen ? '⬆️ Paslėpti užduotis' : genPickLabel()"></span>
            </button>
            <label class="type-voice"
                   title="Įgarsinti visus kitus (ne pasakojimų) uždavinius. Pasakojimų uždaviniai įgarsinami atskirai – jų grupėje yra savas mygtukas.">
              <input type="checkbox" class="type-voice__box" x-model="genSay">
              <span>🔊 Įgarsinti</span>
            </label>
          </div>

          <div class="mt-4" id="gen-types" x-show="genPickerOpen" x-cloak>
            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
              <div class="min-w-0">
                <span class="text-base font-bold text-slate-500">
                  Iš kokių užduočių sudaryti testą?
                  <span class="font-normal text-slate-400">(nepažymėjus nieko – iš visų)</span>
                </span>
                <p class="text-sm font-bold text-slate-400">
                  Skaičius prie užduoties – jos sudėtingumas nuo 1 iki 10 (10 – sunkiausia).
                  Sugeneruotame teste užduotys sudėliojamos nuo paprasčiausių iki sunkiausių.
                </p>

                <template x-for="(types, group) in genGroups" :key="group">
                  <div class="mt-3">
                    <button type="button" class="gen-group" :class="{ 'is-on': isGenGroup(group) }"
                            @click="toggleGenGroup(group)" :aria-pressed="isGenGroup(group)">
                      <span x-text="group"></span>
                    </button>
                    <div class="mt-2 flex flex-wrap gap-2">
                      <template x-for="(label, type) in types" :key="type">
                        <button type="button" class="type-chip" :class="{ 'is-on': isGenType(type) }"
                                @click="toggleGenType(type); showTypePreview(type, label)"
                                @mouseenter="showTypePreview(type, label)"
                                @focus="showTypePreview(type, label)"
                                :aria-pressed="isGenType(type)"
                                :title="'Sudėtingumas ' + genScore(type) + ' iš 10 (1 – lengviausia, 10 – sunkiausia). Sugeneruotame teste užduotys sudėliojamos nuo paprasčiausių iki sunkiausių.'">
                          <span x-text="label"></span><span class="type-chip__score" x-text="genScore(type)"></span>
                        </button>
                      </template>
                      <label class="type-voice" x-show="group === 'Pasakojimai'" x-cloak
                             title="Įgarsinti pasakojimų uždavinius (atskirai nuo kitų uždavinių). Pagal nutylėjimą pasakojimai yra tyliai – juos reikia perskaityti.">
                        <input type="checkbox" class="type-voice__box" x-model="genSayStory">
                        <span>🔊 Įgarsinti</span>
                      </label>
                    </div>
                  </div>
                </template>

                <div class="mt-3 flex flex-wrap gap-2">
                  <button class="btn btn--ghost" @click="selectAllGenTypes()">☑️ Visos užduotys</button>
                  <button class="btn btn--ghost" @click="clearGenTypes()">✖️ Nė viena</button>
                </div>
              </div>

              <!-- ---------------------- one example of the hovered exercise -->
              <!-- Drawn by the real renderer, right next to the picker (no popup). -->
              <aside class="min-w-0 lg:sticky lg:top-4 lg:self-start">
                <div class="type-preview">
                  <div class="type-preview__head">
                    <span class="type-preview__icon" aria-hidden="true">👀</span>
                    <span class="type-preview__label" x-text="preview.label || 'Pavyzdys'"></span>
                    <span class="type-preview__score" x-show="preview.type" x-cloak
                          :title="'Sudėtingumas ' + genScore(preview.type) + ' iš 10'"
                          x-text="'Sudėtingumas ' + genScore(preview.type) + '/10'"></span>
                  </div>
                  <div class="type-preview__body" x-ref="previewBody"></div>
                  <p class="type-preview__placeholder" x-show="!preview.type && !preview.loading" x-cloak>
                    Užvesk (arba bakstelėk) ant užduoties – čia pamatysi, kaip ji atrodo.
                  </p>
                  <p class="type-preview__note" x-show="preview.loading" x-cloak>⏳ Kuriamas pavyzdys…</p>
                  <p class="type-preview__note type-preview__note--bad" x-show="preview.error" x-cloak x-text="preview.error"></p>
                </div>
              </aside>
            </div>
          </div>
        </div>
      </div>

      <p class="feedback feedback--bad" x-show="error" x-cloak x-text="error"></p>

      <h2 class="section-title mt-2">Pasirink esamą testą 👇</h2>
      <?php foreach ($groups as $group): ?>
      <h3 class="grade-title">
        <span class="grade-title__badge"><?= (int) $group['grade'] ?> klasė</span>
        <?= e(($gradeWords[(int) $group['grade']] ?? ((int) $group['grade'] . '-ai')) . ' klasei skirti testai') ?>
      </h3>
      <div class="grid gap-3 sm:grid-cols-2">
        <?php foreach ($group['quizzes'] as $quiz): ?>
        <button class="quiz-card" @click="open('<?= e($quiz['id']) ?>')">
          <span class="quiz-card__emoji"><?= e($quiz['emoji']) ?></span>
          <span class="min-w-0 flex-1">
            <span class="quiz-card__title block"><?= e($quiz['title']) ?></span>
            <?php if ($quiz['subtitle'] !== ''): ?>
            <span class="quiz-card__meta block"><?= e($quiz['subtitle']) ?></span>
            <?php endif; ?>
            <span class="quiz-card__meta block"><?= (int) $quiz['count'] ?> klausimai · <span x-text="starRow(starsFor('<?= e($quiz['id']) ?>'))"></span></span>
          </span>
          <span class="text-3xl" aria-hidden="true">▶️</span>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

      <template x-if="myQuizzes.length">
        <div>
          <h2 class="section-title">Mano testai 🧩</h2>
          <div class="grid gap-3 sm:grid-cols-2">
            <template x-for="quiz in myQuizzes" :key="quiz.id">
              <div class="quiz-card">
                <span class="quiz-card__emoji" x-text="quiz.emoji"></span>
                <button class="min-w-0 flex-1 text-left" @click="open(quiz.id)">
                  <span class="quiz-card__title block" x-text="quiz.title"></span>
                  <span class="quiz-card__meta block" x-text="(quiz.grade || 1) + ' klasė · ' + quiz.questions.length + ' klausimai · ' + starRow(starsFor(quiz.id))"></span>
                </button>
                <button class="icon-btn" @click="deleteCustom(quiz.id)" title="Ištrinti">🗑️</button>
              </div>
            </template>
          </div>
        </div>
      </template>


    </section>

    <!-- ==================================================== PLAY SCREEN -->
    <section x-show="screen === 'play'" x-cloak class="flex flex-col gap-4">
      <!-- only on paper: worksheet heading with a line for the name -->
      <div class="print-only print-head">
        <p class="print-head__title" x-text="quiz ? quiz.emoji + ' ' + quiz.title : ''"></p>
        <p class="print-head__line"><span>Vardas: ______________________</span><span>Data: ______________</span></p>
      </div>

      <!-- how much is already answered -->
      <div class="print-hide sticky top-0 z-20 -mx-3 bg-white/85 px-3 py-2 shadow-sm backdrop-blur sm:-mx-6 sm:px-6">
        <div class="flex items-center gap-3">
          <div class="progress-track">
            <div class="progress-fill" :style="'width:' + progressPercent() + '%'"></div>
          </div>
          <span class="shrink-0 text-lg font-bold text-slate-500" x-text="answered + ' / ' + total"></span>
        </div>
        <p class="mt-1 text-center text-base font-bold text-slate-400" x-show="!checked && answered === 0" x-cloak>
          Atsakyk bent vieną klausimą 🙂
        </p>
      </div>

      <!-- result after pressing Tikrinti -->
      <div class="print-hide card-surface text-center" x-show="checked" x-cloak>
        <p class="text-5xl" x-text="allRight ? '🏆' : (stars >= 2 ? '🎉' : '💪')"></p>
        <p class="mt-1 text-3xl font-extrabold" x-text="'Teisingai: ' + correct + ' / ' + total"></p>
        <p class="stars mt-1" x-text="starRow(stars)"></p>
        <p class="text-lg font-bold text-emerald-600" x-show="allRight" x-cloak>Puiku! Visi atsakymai teisingi 🎈</p>
        <p class="text-lg font-bold text-slate-500" x-show="!allRight" x-cloak>Neteisingi atsakymai pažymėti raudonai – apžiūrėk juos.</p>
      </div>

      <!-- every question of the quiz, in one page -->
      <div x-ref="qhost" id="quiz-host" class="flex flex-col gap-5 pb-64 sm:pb-44"></div>

      <!-- three ways to check the whole quiz -->
      <div id="check-bar" class="sticky bottom-0 z-20 -mx-3 bg-white/90 px-3 py-2 shadow-[0_-10px_24px_-16px_rgba(15,23,42,.5)] backdrop-blur sm:-mx-6 sm:px-6">
        <div class="flex flex-col items-center gap-2 sm:flex-row sm:justify-center sm:gap-3">
          <p class="feedback" x-show="!checked && scoreNote" x-cloak
             :class="scoreNoteKind === 'bad' ? 'feedback--bad' : 'feedback--ok'" x-text="scoreNote"></p>

          <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:gap-3" x-show="!checked">
            <button class="btn btn--success w-full sm:w-auto" @click="checkAll('count')" :disabled="answered === 0"
                    title="Parodys, kiek teisingai – nieko nežymės ir neparodys atsakymo">
              📊 Kiek teisingai?
            </button>
            <button class="btn btn--amber w-full sm:w-auto" @click="checkAll('mark')" :disabled="answered === 0"
                    title="Klaidingus atsakymus pažymės raudonai, bet neparodys teisingo atsakymo">
              🖍️ Pažymėti klaidas
            </button>
            <button class="btn btn--primary btn--lg col-span-2 w-full sm:w-auto" @click="checkAll('full')" :disabled="answered === 0"
                    title="Pažymės klaidas ir parodys teisingą atsakymą">
              ✓ Tikrinti viską
            </button>
          </div>

          <button class="btn btn--primary btn--lg w-full sm:w-auto" x-show="checked" x-cloak @click="retry()">🔁 Bandyti iš naujo</button>
          <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:gap-3">
            <button class="btn btn--amber" @click="anotherQuiz()" :disabled="loading" x-text="nextLabel"></button>
            <button class="btn btn--ghost" @click="printQuiz()" :disabled="loading"
                    title="Spausdinti juodai baltai – tvarkingas darbalapis su tuščiais langeliais">🖨️ Spausdinti</button>
            <button class="btn btn--ghost" @click="goHome()">🏠 Į pradžią</button>
          </div>
        </div>
      </div>
    </section>

    <!-- ================================================= BUILDER SCREEN -->
    <section x-show="screen === 'builder'" x-cloak class="flex flex-col gap-4">
      <div class="card-surface">
        <p class="text-2xl font-extrabold">✏️ Kurti savo testą</p>
        <p class="text-lg font-bold text-slate-500">Įklijuok JSON ir spausk „Patikrinti“ arba „Žaisti“.</p>
        <textarea x-model="json" spellcheck="false" rows="16"
                  class="mt-3 w-full rounded-2xl border-4 border-slate-200 bg-white p-3 font-mono text-sm leading-relaxed text-slate-700"
                  aria-label="Testo JSON"></textarea>
        <div class="mt-3 flex flex-wrap gap-2">
          <button class="btn btn--primary" @click="validateJson()" :disabled="loading">🔍 Patikrinti</button>
          <button class="btn btn--success" @click="playJson()">▶️ Žaisti</button>
          <button class="btn btn--ghost" @click="json = <?= e(json_for_script($emptyTemplate)) ?>">🧹 Išvalyti</button>
          <button class="btn btn--ghost" @click="goHome()">← Atgal</button>
        </div>

        <div x-show="validation" x-cloak class="mt-4 flex flex-col gap-2">
          <p class="feedback" x-text="validation && validation.ok ? 'Viskas gerai! ✅' : 'Reikia pataisyti ⚠️'"
             :class="{ 'feedback--ok': validation && validation.ok, 'feedback--bad': !(validation && validation.ok) }"></p>
          <template x-if="validation && validation.errors && validation.errors.length">
            <ul class="list-disc pl-6 text-base font-bold text-rose-600">
              <template x-for="err in validation.errors" :key="err"><li x-text="err"></li></template>
            </ul>
          </template>
          <template x-if="validation && validation.warnings && validation.warnings.length">
            <ul class="list-disc pl-6 text-base font-bold text-amber-600">
              <template x-for="warn in validation.warnings" :key="warn"><li x-text="warn"></li></template>
            </ul>
          </template>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="card-surface">
          <p class="text-xl font-extrabold">Klausimų tipai</p>
          <ul class="mt-2 text-base font-bold text-slate-600">
            <template x-for="t in typeList" :key="t.name">
              <li class="mb-1"><code class="rounded bg-slate-100 px-1" x-text="t.name"></code> — <span x-text="t.text"></span></li>
            </template>
          </ul>
          <p class="mt-4 text-xl font-extrabold">Figūrų pavadinimai</p>
          <p class="mt-1 flex flex-wrap gap-1">
            <template x-for="s in shapes" :key="s">
              <span class="rounded-lg bg-slate-100 px-2 py-0.5 text-sm font-bold text-slate-600" x-text="s"></span>
            </template>
          </p>
          <p class="mt-4 text-xl font-extrabold">Emoji kaip figūros</p>
          <p class="mt-1 flex flex-wrap gap-1">
            <template x-for="e in emojis" :key="e">
              <span class="rounded-lg bg-slate-100 px-2 py-0.5 text-lg" x-text="e"></span>
            </template>
          </p>
        </div>
        <div class="card-surface">
          <p class="text-xl font-extrabold">Įkelti pavyzdį</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <?php foreach ($quizzes as $quiz): ?>
            <button class="btn btn--ghost" @click="loadExampleIntoEditor('<?= e($quiz['id']) ?>')"><?= e($quiz['emoji'] . ' ' . $quiz['title']) ?></button>
            <?php endforeach; ?>
          </div>
          <p class="mt-4 text-xl font-extrabold">Spalvos</p>
          <p class="mt-1 flex flex-wrap gap-1">
            <template x-for="c in colors" :key="c">
              <span class="rounded-lg bg-slate-100 px-2 py-0.5 text-sm font-bold text-slate-600" x-text="c"></span>
            </template>
          </p>
        </div>
      </div>
    </section>
  </main>

  <footer class="pb-2 text-center text-base font-bold text-slate-400">
    <p>Matematis · 1 klasei</p>
  </footer>

  <div x-show="loading" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-white/70">
    <p class="text-5xl animate-pulse">⏳</p>
  </div>
</div>
</body>
</html>