<?php
declare(strict_types=1);

/**
 * Tts.php — ElevenLabs voice for the task instructions, with a disk cache.
 *
 * The child taps 🔊 next to "Apibrauk kortelę, kurioje figūrų yra daugiau." and
 * hears the sentence. Every sentence is generated **once**: the MP3 is written
 * to cache/tts/<sha1>.mp3 and from then on every request (any browser, any
 * quiz, any restart) is answered from that file, which nginx serves as a plain
 * static file - no PHP, no ElevenLabs call, no cost.
 *
 *   GET  /api/tts?status=1        is the voice configured? (no secrets)
 *   GET  /api/tts?list=1          what is already saved on disk
 *   GET  /api/tts?text=..&token=. url of one sentence (generating it if new)
 *   POST /api/tts  {"items":[{"text":…,"token":…}]}   the same for many
 *
 * Money is only spent by the last two, so they only accept **signed phrases**.
 * When the server hands out a quiz (data/quizzes/<id>.json or a generated one)
 * it adds a `tts` map of `sentence => token` to the payload (see
 * tts_quiz_with_tokens()); a token is an HMAC of that one sentence, so it can
 * never be swapped for other text. Anything the app does not contain has no
 * token and is refused with `bad-phrase`. Origin/Referer has to point at this
 * host as well.
 *
 * Configuration, per key, last hit wins:
 *   1. defaults     settings.ini.default.php      (template, holds no keys)
 *   2. legacy file  /etc/qu_lt/math-tts.json      (JSON, keeps old installs)
 *   3. settings     settings.ini.php next to index.php — a PHP file that returns
 *                   the array; this is the one to edit. It is only safe because
 *                   the vhost denies it (`location ~* \.ini\.php$ { deny all; }`):
 *                   nginx executes only index.php but *serves* every other file
 *                   below the docroot as plain TEXT, which is how the key used to
 *                   leak from this very file. Where the vhost cannot deny it, put
 *                   the file outside the web root instead - /etc/qu_lt/
 *                   settings.ini.php is looked for as well.
 *   4. environment  ELEVENLABS_API_KEY · ELEVENLABS_VOICE_ID · ELEVENLABS_MODEL_ID
 *                   ELEVENLABS_LANGUAGE · ELEVENLABS_SPEED · ELEVENLABS_BASE_URL
 *                   MATH_TTS_SETTINGS (path of the settings file)
 *                   MATH_TTS_CONFIG (path of the legacy JSON file)
 *                   MATH_TTS_SECRET (signing key for the phrase tokens)
 *
 * Copy settings.ini.default.php to settings.ini.php and fill in `api_key`; with an
 * empty `voice_id` the first voice of the account is remembered in
 * cache/tts/voice.json and used from then on.
 *
 * The key never leaves the server: not the page, not the cache, not the API
 * responses, not this source file. The cache is public on purpose (it is only
 * children's audio).
 */

/** Where the MP3s live, relative to the web root (nginx serves this directly). */
const TTS_CACHE_DIR = 'cache/tts';
/** Per-IP counters for the endpoint that actually spends money. */
const TTS_LIMIT_DIR = 'cache/tts-limit';
/** Longest sentence we will ever synthesise (all real instructions are ~90). */
const TTS_MAX_CHARS = 400;
/** Sentences per request (the play screen asks for a whole quiz at once). */
const TTS_MAX_BATCH = 30;
/** Fresh generations allowed per IP per hour. */
const TTS_RATE_LIMIT = 120;
/** Fresh generations allowed in total per hour (backstop against abuse). */
const TTS_GLOBAL_LIMIT = 600;

/* --------------------------------------------------------------- config -- */

/** The canonical keys and the values a fresh install starts from. */
function tts_builtin_defaults(): array
{
    return [
        'api_key' => '',
        'voice_id' => '',
        // v3 is the model that speaks Lithuanian (multilingual_v2 does not).
        'model_id' => 'eleven_v3',
        'language_code' => 'lt',
        'output_format' => 'mp3_44100_128',
        // eleven_v3 only accepts stability (0.0 = creative, 0.5 = natural, 1.0 = robust).
        // Add similarity_boost / style / use_speaker_boost when using another model.
        'voice_settings' => ['stability' => 0.5],
        'speed' => '0.9',
        'base_url' => 'https://api.elevenlabs.io/v1',
        'timeout' => 30,
        // Signing key for the phrase tokens. Empty = derive one from the API
        // key, so a fresh install is protected with no extra config.
        'client_secret' => '',
    ];
}

/** The settings template shipped in the project (it holds no secrets). */
function tts_settings_defaults_file(): string
{
    return APP_DIR . '/settings.ini.default.php';
}

/**
 * The settings file to use, or null when only the shipped defaults exist. A path
 * in MATH_TTS_SETTINGS wins; otherwise settings.ini.php next to index.php, then
 * the copy outside the web root (/etc/qu_lt/settings.ini.php).
 *
 * The file next to index.php is only safe because the vhost denies it
 * (`location ~* \.ini\.php$ { deny all; }`): nginx serves every other file below
 * the document root as plain text - that is how the API key used to leak from
 * lib/Tts.php - so where that rule is missing the file has to live outside the
 * docroot.
 */
function tts_settings_file(): ?string
{
    static $found = false;
    if ($found !== false) {
        return $found;
    }

    $found = null;
    $candidates = [];
    $env = getenv('MATH_TTS_SETTINGS');
    if (is_string($env) && trim($env) !== '') {
        $candidates[] = trim($env);
    }
    $candidates[] = APP_DIR . '/settings.ini.php';
    $candidates[] = '/etc/qu_lt/settings.ini.php';

    foreach ($candidates as $file) {
        if (is_file($file) && is_readable($file)) {
            $found = $file;
            break;
        }
    }
    return $found;
}

/**
 * One settings file as an array: a PHP file that `return`s the array
 * (settings.ini.php) or - the legacy install - a JSON document. Anything
 * unexpected reads as empty, so a broken file only means "keep the defaults".
 */
function tts_read_settings_file(string $file): array
{
    if ($file === '' || !is_file($file) || !is_readable($file)) {
        return [];
    }

    if (strcasecmp(substr($file, -5), '.json') === 0) {
        $raw = json_decode((string) @file_get_contents($file), true);
        return is_array($raw) ? $raw : [];
    }

    // The PHP file is *included*, never echoed, and a stray blank line around
    // <?php must not end up in an API response - hence the output buffer.
    ob_start();
    try {
        $data = include $file;
    } catch (Throwable $e) {
        $data = null;
    }
    ob_end_clean();

    return is_array($data) ? $data : [];
}

/**
 * Overlay one settings array on another. Only known keys count and an empty
 * value never overwrites a filled one, so a file only has to mention what it
 * really wants to change.
 */
function tts_merge_config(array $config, array $raw): array
{
    foreach (tts_builtin_defaults() as $key => $default) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        $value = $raw[$key];
        if ($key === 'voice_settings') {
            if (is_array($value) && $value !== []) {
                $config[$key] = $value;
            }
            continue;
        }
        if ($key === 'timeout') {
            if (is_numeric($value)) {
                $config[$key] = (int) $value;
            }
            continue;
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            $text = is_string($value) ? trim($value) : (string) $value;
            if ($text !== '') {
                $config[$key] = $text;
            }
        }
    }
    return $config;
}

/** The values a config starts from: the shipped template + the built-ins. */
function tts_defaults(): array
{
    static $defaults = null;
    if ($defaults === null) {
        $defaults = tts_merge_config(
            tts_builtin_defaults(),
            tts_read_settings_file(tts_settings_defaults_file())
        );
    }
    return $defaults;
}

/** The legacy JSON settings file (still read, so old installs keep working). */
function tts_config_file(): string
{
    $path = getenv('MATH_TTS_CONFIG');
    return is_string($path) && trim($path) !== '' ? trim($path) : '/etc/qu_lt/math-tts.json';
}

/**
 * The whole configuration: shipped defaults, then the legacy JSON file, then
 * settings.ini.php, then the environment variables (which win over every file).
 */
function tts_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = tts_defaults();
    // The legacy JSON file first, so settings.ini.php overrides it.
    $config = tts_merge_config($config, tts_read_settings_file(tts_config_file()));
    $settings = tts_settings_file();
    if ($settings !== null) {
        $config = tts_merge_config($config, tts_read_settings_file($settings));
    }

    foreach ([
        'api_key' => 'ELEVENLABS_API_KEY',
        'voice_id' => 'ELEVENLABS_VOICE_ID',
        'model_id' => 'ELEVENLABS_MODEL_ID',
        'language_code' => 'ELEVENLABS_LANGUAGE',
        'speed' => 'ELEVENLABS_SPEED',
        'base_url' => 'ELEVENLABS_BASE_URL',
        'client_secret' => 'MATH_TTS_SECRET',
    ] as $key => $envName) {
        $value = getenv($envName);
        if (is_string($value) && trim($value) !== '') {
            $config[$key] = trim($value);
        }
    }

    return $config;
}

function tts_enabled(): bool
{
    return tts_config()['api_key'] !== '';
}

/* --------------------------------------------------------------- client -- */

/**
 * The secret behind the phrase tokens. It never reaches the browser. Rotate the
 * tokens independently of the ElevenLabs key by setting `client_secret` in
 * /etc/qu_lt/math-tts.json (or MATH_TTS_SECRET in the environment).
 */
function tts_client_secret(): string
{
    $configured = (string) (tts_config()['client_secret'] ?? '');
    if ($configured !== '') {
        return $configured;
    }
    // Fall back to a value derived from the API key, which never leaves the server.
    return hash('sha256', 'math-tts-phrase-v1|' . (string) tts_config()['api_key']);
}

/** Symbols are read as words - keep in sync with SYMBOL_WORDS in speech.js. */
const TTS_SYMBOL_WORDS = [
    '>' => 'daugiau', '<' => 'mažiau', '=' => 'lygu', '≠' => 'nelygu',
    '+' => 'plius', '-' => 'minus', '−' => 'minus',
];

/** The two "read by the child" types: silent unless the author opted in. */
const TTS_STORY_TYPES = ['story', 'storyChoice'];

/** Per-type default instruction - keep in sync with quiz-types.js. */
function tts_default_instructions(): array
{
    return [
        'compare' => 'Suskaičiuok ir įrašyk reikiamą ženklą.',
        'compareGrid' => 'Palygink skaičius ir įrašyk ženklą.',
        'compareExpressions' => 'Suskaičiuok abi puses ir įrašyk reikiamą ženklą.',
        'count' => 'Suskaičiuok ir įrašyk skaičių.',
        'countAndCompare' => 'Suskaičiuok, įrašyk skaičius ir palygink.',
        'outline' => 'Apibrauk kortelę, kurioje yra daugiau.',
        'crossOut' => 'Nubrauk kortelę, kuri neteisinga.',
        'write' => 'Įrašyk teisingą skaičių.',
        'story' => 'Perskaityk pasakojimą ir atsakyk į klausimą.',
        'storyChoice' => 'Perskaityk pasakojimą ir atsakyk į klausimą.',
        'addPicture' => 'Parašyk ir atlik pavaizduotus sudėties veiksmus.',
        'subtractPicture' => 'Suskaičiuok, kiek nubraukta, ir parašyk atimties veiksmą.',
        'missingNumber' => 'Įrašyk trūkstamą skaičių.',
        'missingNumberSub' => 'Įrašyk trūkstamą skaičių.',
        'mixedOps' => 'Suskaičiuok ir įrašyk atsakymus.',
        'domino' => 'Suskaičiuok taškus abiejose pusėse ir parašyk atsakymą.',
        'subtractDomino' => 'Suskaičiuok taškus abiejose pusėse ir parašyk skirtumą.',
        'multiCheck' => 'Pažymėk veiksmus, kurie duoda nurodytą skaičių.',
        'subtractCheck' => 'Pažymėk atimties veiksmus, kurių atsakymas yra nurodytas skaičius.',
        'pickAddends' => 'Sudėk du skaičius ir nubrauk tą, kurio nereikia.',
        'subtractPair' => 'Atimk du skaičius ir nubrauk tą, kurio nereikia.',
        'equalSums' => 'Sudėk skaičius taip, kad abiejų veiksmų gautum tiek pat.',
        'equalDifferences' => 'Atimk skaičius taip, kad abiejų veiksmų gautum tiek pat.',
        'drawMissing' => 'Kiek dar trūksta? Nupiešk trūkstamus.',
        'crossOutExtra' => 'Turi likti tiek, kiek parašyta. Nubrauk, kiek reikia atimti.',
        'match' => 'Sujunk veiksmą su jį vaizduojančiu paveikslėliu.',
    ];
}

/**
 * The spoken form of a value: exactly what speech.js `speakable()` builds, so
 * the phrase the client asks for is the phrase the server signed.
 */
function tts_speakable(mixed $value): string
{
    $text = is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    // NB: ASCII escapes only, in /u mode - PCRE's \v would also eat the byte
    // 0x85 inside a Lithuanian character like "ą".
    $text = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}]+/u', ' ', $text);
    $text = (string) preg_replace_callback(
        '/[<>≠=+\-−]/u',
        static fn(array $m): string => ' ' . TTS_SYMBOL_WORDS[$m[0]] . ' ',
        $text
    );
    $text = (string) preg_replace('/[ \t\n\r\f\x{000B}]+/u', ' ', $text);
    return trim($text);
}

/** May this question have a 🔊 at all? (mirrors sayAllowed() in speech.js) */
function tts_say_allowed(array $question): bool
{
    $say = $question['say'] ?? null;
    if ($say !== null) {
        return !($say === false || $say === 'false' || $say === 0 || $say === '0');
    }
    return !in_array((string) ($question['type'] ?? ''), TTS_STORY_TYPES, true);
}

/**
 * Every phrase the app can build out of one question. The client works out its
 * sentences with its own rules (speechText + speakable); instead of copying
 * those rules exactly, the server offers every form they can produce and signs
 * them all. A phrase outside this set can never be synthesised.
 *
 * @return list<string>
 */
function tts_question_phrases(array $question): array
{
    $type = (string) ($question['type'] ?? '');
    $instruction = trim((string) ($question['instruction'] ?? ''));
    if ($instruction === '') {
        $instruction = (string) (tts_default_instructions()[$type] ?? '');
    }

    $candidates = [];
    if ($instruction !== '' && tts_say_allowed($question)) {
        $custom = trim((string) ($question['readAloud'] ?? ''));
        $hint = trim((string) ($question['hint'] ?? ''));
        if ($custom !== '' && $custom !== $instruction) {
            $candidates[] = tts_speakable($custom);
        } elseif ($hint !== '' && !preg_match('/[<>≠=]/u', $hint)) {
            // The instruction and its hint are read as one sentence. A hint that
            // only spells out the symbols would read as nonsense ("daugiau –
            // daugiau"), so it is skipped - same rule as speechText().
            $sep = preg_match('/[.!?…]$/u', $instruction) ? ' ' : '. ';
            $candidates[] = tts_speakable($instruction . $sep . $hint);
        } else {
            $candidates[] = tts_speakable($instruction);
        }
    }

    // A `write` question reads every condition out loud (its own small 🔊).
    $items = is_array($question['items'] ?? null) ? $question['items'] : [];
    if ($items === [] && $type === 'write') {
        $candidates[] = tts_speakable('Kiek yra 2 + 2?');
    }
    foreach ($items as $item) {
        if (is_array($item)) {
            $candidates[] = tts_speakable($item['prompt'] ?? '');
        }
    }

    $phrases = [];
    foreach ($candidates as $phrase) {
        if ($phrase !== '' && mb_strlen($phrase) <= TTS_MAX_CHARS && !in_array($phrase, $phrases, true)) {
            $phrases[] = $phrase;
        }
    }
    return $phrases;
}

/** The token that authorises exactly one phrase - and no other text. */
function tts_phrase_token(string $phrase): string
{
    return 'p1.' . hash_hmac('sha256', 'p1|' . $phrase, tts_client_secret());
}

function tts_phrase_token_valid(string $phrase, mixed $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(tts_phrase_token($phrase), $token);
}

/**
 * `phrase => token` for every sentence of one quiz. Only quizzes the server
 * itself produced (a file in data/quizzes, or a freshly generated one) get
 * these, so the client can never ask for text the app does not contain.
 *
 * @return array<string,string>
 */
function tts_quiz_tokens(array $quiz): array
{
    if (!tts_enabled()) {
        return [];
    }
    $tokens = [];
    foreach (($quiz['questions'] ?? []) as $question) {
        if (!is_array($question)) {
            continue;
        }
        foreach (tts_question_phrases($question) as $phrase) {
            $tokens[$phrase] = tts_phrase_token($phrase);
        }
    }
    return $tokens;
}

/** A quiz payload with its phrase tokens attached (used by the API). */
function tts_quiz_with_tokens(?array $quiz): ?array
{
    if ($quiz === null) {
        return null;
    }
    $tokens = tts_quiz_tokens($quiz);
    if ($tokens !== []) {
        $quiz['tts'] = $tokens;
    }
    return $quiz;
}

/** The request body, read once (both the tokens and the texts live in it). */
function tts_raw_body(): string
{
    static $body = null;
    if ($body === null) {
        $body = (string) file_get_contents('php://input', false, null, 0, 65536);
    }
    return $body;
}

/**
 * The page has to come from this very host: a third-party page cannot spend the
 * credits. A request without Origin/Referer (a server-side tool) is not
 * rejected here - it still needs a signed phrase.
 */
function tts_origin_ok(): bool
{
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') {
        return true;
    }
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
        $value = $_SERVER[$key] ?? '';
        if (!is_string($value) || $value === '') {
            continue;
        }
        $origin = strtolower((string) parse_url($value, PHP_URL_HOST));
        if ($origin !== '' && $origin !== $host) {
            return false;
        }
    }
    return true;
}

/* ---------------------------------------------------------------- cache -- */

function tts_cache_path(?string $sub = null): string
{
    return APP_DIR . '/' . ($sub ?? TTS_CACHE_DIR);
}

/**
 * The cache directory, created on first use. Returns null when it cannot be
 * written - that is a deployment problem (the directory has to belong to the
 * user php-fpm runs as: `mkdir cache/tts && chown www-data cache/tts`).
 */
function tts_cache_dir(): ?string
{
    static $dir = false;
    if ($dir !== false) {
        return $dir;
    }
    $path = tts_cache_path();
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    $dir = is_dir($path) && is_writable($path) ? $path : null;
    return $dir;
}

/**
 * One sentence, cleaned up: valid UTF-8, single spaces, trimmed. The JS sends
 * the same normalised string, so both sides hash the same text.
 */
function tts_normalize_text(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
    $text = preg_replace('/\s+/u', ' ', is_string($text) ? $text : $value);
    $text = trim(is_string($text) ? $text : $value);
    if ($text === '' || !mb_check_encoding($text, 'UTF-8')) {
        return '';
    }
    return mb_strlen($text) > TTS_MAX_CHARS ? '' : $text;
}

/** The voice id, from the config or from the file we remembered it in. */
function tts_voice_id(): string
{
    $configured = (string) tts_config()['voice_id'];
    if ($configured !== '') {
        return $configured;
    }
    $dir = tts_cache_dir();
    $memory = $dir === null ? '' : $dir . '/voice.json';
    if ($memory !== '' && is_file($memory)) {
        $saved = json_decode((string) @file_get_contents($memory), true);
        if (is_array($saved) && isset($saved['voice_id']) && is_string($saved['voice_id'])) {
            return $saved['voice_id'];
        }
    }
    return '';
}

/**
 * Everything that changes the sound of a sentence. It is part of the cache
 * key, so switching voice or model simply makes new files instead of silently
 * serving the old voice.
 */
function tts_signature(): array
{
    $c = tts_config();
    return [
        'voice_id' => tts_voice_id(),
        'model_id' => (string) $c['model_id'],
        'language_code' => (string) $c['language_code'],
        'output_format' => (string) $c['output_format'],
        'voice_settings' => is_array($c['voice_settings']) ? $c['voice_settings'] : [],
        'speed' => (string) $c['speed'],
    ];
}

function tts_key(string $text): string
{
    return sha1($text . "\n" . json_encode(tts_signature(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function tts_url(string $text): string
{
    return '/' . TTS_CACHE_DIR . '/' . tts_key($text) . '.mp3';
}

/** The saved MP3 of a sentence, or null when it still has to be generated. */
function tts_saved_file(string $text): ?string
{
    $dir = tts_cache_dir();
    if ($dir === null) {
        return null;
    }
    $file = $dir . '/' . tts_key($text) . '.mp3';
    return is_file($file) && (int) @filesize($file) > 0 ? $file : null;
}

/* ----------------------------------------------------------------- http -- */

function tts_error_message(string $body, int $status): string
{
    $json = json_decode($body, true);
    $detail = is_array($json) ? ($json['detail'] ?? null) : null;
    if (is_string($detail) && $detail !== '') {
        return $detail;
    }
    if (is_array($detail)) {
        if (isset($detail['message']) && is_string($detail['message'])) {
            return $detail['message'];
        }
        if (isset($detail[0]['msg']) && is_string($detail[0]['msg'])) {
            return $detail[0]['msg'];
        }
    }
    return 'HTTP ' . $status;
}

/** One call to the ElevenLabs API. Returns ['ok', 'status', 'body', 'error']. */
function tts_http(string $method, string $path, ?array $payload = null): array
{
    $c = tts_config();
    if ((string) $c['api_key'] === '') {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'tts-not-configured'];
    }

    $url = rtrim((string) $c['base_url'], '/') . $path;
    $headers = ['xi-api-key: ' . $c['api_key'], 'Accept: ' . ($method === 'POST' ? 'audio/mpeg' : 'application/json')];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => max(5, (int) $c['timeout']),
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = (string) json_encode(
            $payload ?? [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
    $options[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl-unavailable'];
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if (!is_string($body)) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $curlError !== '' ? $curlError : 'network-error'];
    }
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status, 'body' => $body, 'error' => tts_error_message($body, $status)];
    }
    return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => ''];
}

/** With no voice configured, remember the first voice of the account. */
function tts_resolve_voice(): string
{
    $voice = tts_voice_id();
    if ($voice !== '') {
        return $voice;
    }
    $dir = tts_cache_dir();
    if ($dir === null) {
        return '';
    }
    $res = tts_http('GET', '/voices');
    if (!$res['ok']) {
        return '';
    }
    $data = json_decode($res['body'], true);
    $first = is_array($data) ? ($data['voices'][0]['voice_id'] ?? '') : '';
    if (!is_string($first) || $first === '') {
        return '';
    }
    @file_put_contents($dir . '/voice.json', (string) json_encode(
        ['voice_id' => $first, 'saved_at' => date('c')],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    ));
    return $first;
}

/* ------------------------------------------------------------- generate -- */

/**
 * Generate one sentence and save it. Already-generated sentences are never sent
 * to ElevenLabs again.
 *
 * @return array{ok: bool, error?: string, url?: string, saved?: bool, bytes?: int}
 */
function tts_generate(string $text): array
{
    $url = tts_url($text);
    if (tts_saved_file($text) !== null) {
        return ['ok' => true, 'url' => $url, 'saved' => true, 'bytes' => (int) @filesize(tts_cache_path() . '/' . tts_key($text) . '.mp3')];
    }

    $dir = tts_cache_dir();
    if ($dir === null) {
        return ['ok' => false, 'error' => 'cache-dir-unwritable'];
    }
    $voice = tts_resolve_voice();
    if ($voice === '') {
        return ['ok' => false, 'error' => 'voice-unavailable'];
    }

    $c = tts_config();
    // The voice id may have just been discovered, so build the key after it.
    $key = tts_key($text);
    $url = '/' . TTS_CACHE_DIR . '/' . $key . '.mp3';

    $payload = ['text' => $text, 'model_id' => (string) $c['model_id']];
    if ((string) $c['language_code'] !== '') {
        $payload['language_code'] = (string) $c['language_code'];
    }
    if (is_array($c['voice_settings']) && $c['voice_settings'] !== []) {
        $payload['voice_settings'] = $c['voice_settings'];
    }
    if (is_numeric($c['speed']) && (float) $c['speed'] > 0 && abs((float) $c['speed'] - 1.0) > 0.001) {
        $payload['speed'] = (float) $c['speed'];
    }

    $path = '/text-to-speech/' . rawurlencode($voice) . '?output_format=' . rawurlencode((string) $c['output_format']);
    $res = tts_http('POST', $path, $payload);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error'], 'status' => $res['status']];
    }

    $bytes = strlen($res['body']);
    if ($bytes < 256) {
        return ['ok' => false, 'error' => 'empty-audio'];
    }

    // Write beside the target and rename, so a half-written file is never served.
    $file = $dir . '/' . $key . '.mp3';
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.part';
    if (@file_put_contents($tmp, $res['body'], LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'cache-write-failed'];
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'cache-write-failed'];
    }
    @chmod($file, 0644);
    // A sidecar with the sentence, so the cache can be inspected/rebuilt by hand.
    @file_put_contents($dir . '/' . $key . '.json', (string) json_encode([
        'text' => $text,
        'key' => $key,
        'bytes' => $bytes,
        'created' => date('c'),
        'signature' => tts_signature(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    return ['ok' => true, 'url' => $url, 'saved' => false, 'bytes' => $bytes];
}

/* ---------------------------------------------------------- rate limit -- */

/** Increment an hourly counter in $file; false once $limit is reached. */
function tts_counter_bump(string $file, int $limit): bool
{
    $hour = date('YmdH');
    $state = ['hour' => $hour, 'count' => 0];
    if (is_file($file)) {
        $raw = json_decode((string) @file_get_contents($file), true);
        if (is_array($raw) && ($raw['hour'] ?? '') === $hour) {
            $state = ['hour' => $hour, 'count' => (int) ($raw['count'] ?? 0)];
        }
    }
    if ($state['count'] >= $limit) {
        return false;
    }
    $state['count']++;
    @file_put_contents($file, (string) json_encode($state), LOCK_EX);
    return true;
}

/**
 * Best-effort budget for fresh generations (cache hits are free): per IP and,
 * as a backstop, in total - so rotating IPs cannot drain the account.
 */
function tts_rate_ok(string $ip, int $limit): bool
{
    $dir = tts_cache_path(TTS_LIMIT_DIR);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return true; // No counter directory: rather serve the child than block it.
    }
    if (!tts_counter_bump($dir . '/.rl-' . sha1($ip) . '.json', $limit)) {
        return false;
    }
    return tts_counter_bump($dir . '/.global.json', TTS_GLOBAL_LIMIT);
}

function tts_client_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwarded) && $forwarded !== '') {
        $first = trim(explode(',', $forwarded)[0]);
        if ($first !== '') {
            return $first;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/* ------------------------------------------------------------------ api -- */

function tts_cached_count(): int
{
    $dir = tts_cache_dir();
    if ($dir === null) {
        return 0;
    }
    return count(glob($dir . '/*.mp3') ?: []);
}

/** Small, secret-free description of the voice setup (used by the play screen). */
function tts_status(): array
{
    $c = tts_config();
    return [
        'ok' => true,
        'enabled' => tts_enabled(),
        'voice_id' => tts_voice_id(),
        'model_id' => (string) $c['model_id'],
        'language_code' => (string) $c['language_code'],
        'output_format' => (string) $c['output_format'],
        'max_chars' => TTS_MAX_CHARS,
        'max_batch' => TTS_MAX_BATCH,
        'cache_dir' => TTS_CACHE_DIR,
        'cache_writable' => tts_cache_dir() !== null,
        'cached_sentences' => tts_cached_count(),
        'settings_file' => (string) (tts_settings_file() ?? ''),
        'config_found' => is_file(tts_config_file()),
    ];
}

/** What is already saved on disk (handy to check that nothing is re-created). */
function tts_cached_list(): array
{
    $dir = tts_cache_dir();
    $files = $dir === null ? [] : (glob($dir . '/*.mp3') ?: []);
    $items = [];
    $bytes = 0;
    foreach ($files as $file) {
        $size = (int) @filesize($file);
        $bytes += $size;
        $meta = [];
        $sidecar = substr($file, 0, -4) . '.json';
        if (is_file($sidecar)) {
            $raw = json_decode((string) @file_get_contents($sidecar), true);
            if (is_array($raw)) {
                $meta = $raw;
            }
        }
        $items[] = [
            'key' => basename($file, '.mp3'),
            'text' => $meta['text'] ?? '',
            'bytes' => $size,
            'created' => $meta['created'] ?? '',
            'url' => '/' . TTS_CACHE_DIR . '/' . basename($file),
        ];
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string) $b['created'], (string) $a['created']));
    return [
        'ok' => true,
        'count' => count($items),
        'bytes' => $bytes,
        'items' => array_slice($items, 0, 500),
    ];
}

/**
 * The phrases the client asked for, each with the token that authorises it.
 *
 * POST /api/tts  {"items": [{"text": "...", "token": "p1...."}]}
 * GET  /api/tts?text=...&token=...
 *
 * The older `{"texts": [...]}` shape is still read, but without a token none
 * of those phrases can be generated any more.
 *
 * @return list<array{text:string,token:string}>
 */
function tts_request_items(string $method): array
{
    $items = [];
    $add = static function (mixed $text, mixed $token) use (&$items): void {
        if (is_string($text)) {
            $items[] = ['text' => $text, 'token' => is_string($token) ? $token : ''];
        }
    };

    if ($method === 'GET') {
        if (isset($_GET['text']) && is_string($_GET['text'])) {
            $add($_GET['text'], $_GET['token'] ?? '');
        }
        return $items;
    }

    $raw = json_decode(tts_raw_body(), true);
    if (!is_array($raw)) {
        return $items;
    }
    $list = $raw['items'] ?? null;
    if (is_string($list)) {
        $list = [$list];
    }
    if (is_array($list)) {
        foreach ($list as $entry) {
            if (is_array($entry)) {
                $add($entry['text'] ?? null, $entry['token'] ?? null);
            } else {
                $add($entry, is_array($raw['tokens'] ?? null) ? ($raw['tokens'][$entry] ?? null) : null);
            }
        }
        return $items;
    }

    $texts = $raw['texts'] ?? null;
    if (is_string($texts)) {
        $texts = [$texts];
    }
    if (is_array($texts)) {
        foreach ($texts as $text) {
            $add($text, null);
        }
    }
    return $items;
}

function tts_api(string $method, string $rest): void
{
    if ($rest === 'status' || isset($_GET['status'])) {
        json_out(tts_status());
        return;
    }
    if ($rest === 'list' || isset($_GET['list'])) {
        json_out(tts_cached_list());
        return;
    }

    if (!tts_enabled()) {
        json_out(['ok' => false, 'error' => 'tts-not-configured', 'items' => []]);
        return;
    }

    // Only a page served from this host may talk to the endpoint at all.
    if (!tts_origin_ok()) {
        json_out(['ok' => false, 'error' => 'forbidden-origin', 'items' => []], 403);
        return;
    }

    $requested = tts_request_items($method);
    $texts = [];
    foreach ($requested as $entry) {
        $text = tts_normalize_text($entry['text']);
        if ($text === '') {
            json_out([
                'ok' => false,
                'error' => mb_strlen(trim((string) $entry['text'])) > TTS_MAX_CHARS ? 'text-too-long' : 'bad-text',
                'items' => [],
            ], 400);
            return;
        }
        $seen = false;
        foreach ($texts as $existing) {
            if ($existing['text'] === $text) {
                $seen = true;
                break;
            }
        }
        if (!$seen) {
            $texts[] = ['text' => $text, 'token' => $entry['token']];
        }
    }
    if ($texts === []) {
        json_out(['ok' => false, 'error' => 'no-text', 'items' => []], 400);
        return;
    }
    if (count($texts) > TTS_MAX_BATCH) {
        json_out(['ok' => false, 'error' => 'too-many-texts', 'items' => []], 400);
        return;
    }

    $ip = tts_client_ip();
    $items = [];
    $reserved = 0;
    foreach ($texts as $entry) {
        $text = $entry['text'];
        // The phrase itself has to be signed: the token is bound to this exact
        // sentence, so a client cannot swap in text of its own.
        if (!tts_phrase_token_valid($text, $entry['token'])) {
            $items[] = ['text' => $text, 'url' => null, 'error' => 'bad-phrase'];
            continue;
        }
        if (tts_saved_file($text) !== null) {
            $items[] = ['text' => $text, 'url' => tts_url($text), 'cached' => true];
            continue;
        }
        // Only a sentence that really has to be generated costs budget.
        if (!tts_rate_ok($ip, TTS_RATE_LIMIT)) {
            $items[] = ['text' => $text, 'url' => null, 'error' => 'rate-limit'];
            continue;
        }
        $reserved++;
        $result = tts_generate($text);
        if ($result['ok']) {
            $items[] = ['text' => $text, 'url' => $result['url'], 'cached' => false];
        } else {
            $items[] = ['text' => $text, 'url' => null, 'error' => (string) ($result['error'] ?? 'failed')];
        }
    }

    json_out([
        'ok' => true,
        'generated' => $reserved,
        'voice_id' => tts_voice_id(),
        'model_id' => (string) tts_config()['model_id'],
        'items' => $items,
    ]);
}
