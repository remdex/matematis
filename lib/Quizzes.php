<?php
declare(strict_types=1);

/**
 * Quizzes.php — reads, normalises and validates quiz JSON files.
 *
 * A quiz file lives in data/quizzes/<id>.json and looks like:
 *
 *   {
 *     "id": "palygink-figuras",
 *     "title": "Palygink figūras",
 *     "subtitle": "Įrašyk ženklą > < =",
 *     "emoji": "⚖️",
 *     "color": "sky",
 *     "grade": 1,
 *     "questions": [ { "type": "compare", ... } ]
 *   }
 *
 * `grade` is the school class (year) the quiz is made for - the picker groups
 * the tests by it. The full schema is documented in README.md.
 */

const QUIZ_TYPES = [
    'compare', 'compareGrid', 'compareExpressions', 'compareResults', 'count', 'countAndCompare', 'outline', 'crossOut', 'write', 'story', 'storyChoice', 'storyMore', 'storyRead',
    'addPicture', 'subtractPicture', 'missingNumber', 'missingNumberSub', 'mixedOps',
    'domino', 'subtractDomino',
    'multiCheck', 'subtractCheck', 'mixedCheck', 'pickAddends', 'subtractPair',
    'equalSums', 'equalDifferences', 'drawMissing', 'crossOutExtra', 'match',
];
const QUIZ_SYMBOLS = ['>', '<', '=', '≠'];
const QUIZ_COLORS = ['sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'slate'];
const QUIZ_GRADE_MIN = 1;
const QUIZ_GRADE_MAX = 6;
const QUIZ_GRADE_DEFAULT = 1;

/** Figure names the renderer can draw (keep in sync with assets/js/figures.js). */
const QUIZ_SHAPES = [
    'triangle', 'square', 'circle', 'oval', 'diamond', 'pentagon', 'hexagon', 'star',
    'heart', 'cross', 'ball', 'apple', 'flower', 'fish', 'bug', 'candy', 'carrot',
    'leaf', 'pencil', 'pen', 'crayon', 'eraser', 'soap', 'sponge', 'lollipop',
    'butterfly', 'cloud',
];

/* --------------------------------------------------------------- helpers -- */

function quiz_data_dir(): string
{
    return APP_DIR . '/data/quizzes';
}

function quiz_id_ok(string $id): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $id);
}

function quiz_slugs(): array
{
    $files = glob(quiz_data_dir() . '/*.json') ?: [];
    $ids = array_map(static fn(string $f): string => basename($f, '.json'), $files);
    sort($ids, SORT_NATURAL);
    return $ids;
}

function json_out(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function quiz_text(mixed $value, string $fallback = ''): string
{
    if (!is_string($value)) {
        return $fallback;
    }
    $value = trim($value);
    return $value === '' ? $fallback : $value;
}

/** School class a quiz is meant for (falls back to the first class). */
function quiz_grade(mixed $value): int
{
    if (is_string($value) && ctype_digit($value)) {
        $value = (int) $value;
    }
    if (!is_int($value) || $value < QUIZ_GRADE_MIN || $value > QUIZ_GRADE_MAX) {
        return QUIZ_GRADE_DEFAULT;
    }
    return $value;
}

/* ---------------------------------------------------------------- loading -- */

/** Decoded JSON of one quiz file (no normalisation). */
function quiz_raw(string $id): ?array
{
    if (!quiz_id_ok($id)) {
        return null;
    }
    $file = quiz_data_dir() . '/' . $id . '.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/**
 * The envelope every consumer (API, page, builder) gets: id, title, meta and
 * the question list. Per-question defaults are filled in by the JS renderer,
 * which is the single source of truth for the question schema.
 */
function quiz_normalize(array $raw, ?string $id = null): array
{
    $title = quiz_text($raw['title'] ?? null, 'Testas');
    $quiz = [
        'id' => quiz_id_ok($id ?? '') ? $id : (quiz_id_ok((string) ($raw['id'] ?? '')) ? (string) $raw['id'] : 'testas'),
        'title' => $title,
        'subtitle' => quiz_text($raw['subtitle'] ?? null),
        'emoji' => quiz_text($raw['emoji'] ?? null, '🧮'),
        'color' => in_array($raw['color'] ?? '', QUIZ_COLORS, true) ? (string) $raw['color'] : 'sky',
        'grade' => quiz_grade($raw['grade'] ?? null),
        'questions' => [],
    ];

    $questions = is_array($raw['questions'] ?? null) ? $raw['questions'] : [];
    foreach (array_values($questions) as $i => $question) {
        if (!is_array($question)) {
            continue;
        }
        $type = quiz_text($question['type'] ?? null);
        if ($type === '' || !in_array($type, QUIZ_TYPES, true)) {
            continue; // unknown types are dropped from the API payload
        }
        $question['type'] = $type;
        $question['id'] = quiz_text($question['id'] ?? null, 'q' . ($i + 1));
        $question['instruction'] = quiz_text($question['instruction'] ?? null, 'Atsakyk į klausimą.');
        $quiz['questions'][] = $question;
    }

    return $quiz;
}

function quiz_load(string $id): ?array
{
    $raw = quiz_raw($id);
    return $raw === null ? null : quiz_normalize($raw, $id);
}

function quiz_summary(array $quiz): array
{
    return [
        'id' => $quiz['id'],
        'title' => $quiz['title'],
        'subtitle' => $quiz['subtitle'],
        'emoji' => $quiz['emoji'],
        'color' => $quiz['color'],
        'grade' => $quiz['grade'],
        'count' => count($quiz['questions']),
    ];
}

function quiz_list(): array
{
    $out = [];
    foreach (quiz_slugs() as $id) {
        $quiz = quiz_load($id);
        if ($quiz !== null && $quiz['questions'] !== []) {
            $out[] = quiz_summary($quiz);
        }
    }
    return $out;
}

/**
 * The picker shows the tests grouped by the class they are made for.
 *
 * @return list<array{grade:int,quizzes:list<array>}>
 */
function quiz_list_grouped(): array
{
    $groups = [];
    foreach (quiz_list() as $summary) {
        $groups[$summary['grade']][] = $summary;
    }
    ksort($groups, SORT_NUMERIC);
    $out = [];
    foreach ($groups as $grade => $quizzes) {
        $out[] = ['grade' => (int) $grade, 'quizzes' => $quizzes];
    }
    return $out;
}

/** Random existing quiz - powering the 🎲 button. */
function quiz_random_id(?string $exclude = null): ?string
{
    $ids = [];
    foreach (quiz_slugs() as $id) {
        $quiz = quiz_load($id);
        if ($quiz !== null && $quiz['questions'] !== [] && $id !== $exclude) {
            $ids[] = $id;
        }
    }
    if ($ids === []) {
        return null;
    }
    return $ids[random_int(0, count($ids) - 1)];
}

/* -------------------------------------------------------- answer checking -- */

/** How many things a side shows (figures count or the number itself). */
function quiz_side_value(mixed $side): ?int
{
    if (is_int($side) || (is_string($side) && ctype_digit($side))) {
        return (int) $side;
    }
    if (!is_array($side)) {
        return null;
    }
    if (($side['kind'] ?? null) === 'number') {
        return isset($side['value']) && is_numeric($side['value']) ? (int) $side['value'] : null;
    }
    if (isset($side['count']) && is_numeric($side['count'])) {
        return (int) $side['count'];
    }
    if (isset($side['figures']['count']) && is_numeric($side['figures']['count'])) {
        return (int) $side['figures']['count'];
    }
    return null;
}

function quiz_symbol_value(int $a, int $b, array $symbols): string
{
    if ($a === $b) {
        return '=';
    }
    if (count($symbols) === 2 && in_array('=', $symbols, true) && in_array('≠', $symbols, true)) {
        return '≠';
    }
    return $a > $b ? '>' : '<';
}

/** A domino pip count: a whole number 0-6, or null when it is not one. */
function quiz_domino_pips(mixed $value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }
    $n = (int) $value;
    return ($n >= 0 && $n <= 6) ? $n : null;
}

/**
 * The pairing key of `match`: the operation the picture stands for. Adding is
 * commutative (the two groups may be swapped), subtracting is not (6 − 2 and
 * 2 − 6 are different pictures).
 */
function quiz_match_key(mixed $a, mixed $b, string $op = '+'): ?string
{
    $a = quiz_side_value($a);
    $b = quiz_side_value($b);
    if ($a === null || $b === null) {
        return null;
    }
    return $op === '-' ? $a . '-' . $b : min($a, $b) . '+' . max($a, $b);
}

/** The operation of a `match` question (`op` is optional, `+` by default). */
function quiz_question_op(array $question): string
{
    return ($question['op'] ?? '+') === '-' ? '-' : '+';
}

/**
 * The maths of one equation. `+` and `−` are the only operations the equation
 * exercises use, so everything below picks an operation and shares the rest.
 */
function quiz_op_apply(string $op, int $a, int $b): int
{
    return $op === '-' ? $a - $b : $a + $b;
}

/**
 * The operation of one item of a `mixedCheck` question (a mixed check-list: the
 * rows of one card carry their own sign, unlike `multiCheck` / `subtractCheck`
 * where the whole question shares the type's). Same rule as the JS renderer.
 */
function quiz_item_op(mixed $item): string
{
    return (is_array($item) ? ($item['op'] ?? '') : '') === '-' ? '-' : '+';
}

/**
 * Where the single empty box of a `missingNumber` row sits: on the left (the
 * first number), in the middle (the second) or on the right (the result).
 * Anything else means "left" - the same default the JS renderer uses.
 */
function quiz_missing_blank(mixed $value): string
{
    $blank = is_string($value) ? strtolower(trim($value)) : '';
    return in_array($blank, ['left', 'middle', 'right'], true) ? $blank : 'left';
}

/** The number that belongs in the empty box of a `missingNumber` row. */
function quiz_missing_value(int $a, int $b, int $result, string $blank): int
{
    if ($blank === 'middle') {
        return $b;
    }
    if ($blank === 'right') {
        return $result;
    }
    return $a;
}

/**
 * One side of a `compareExpressions` row - `a + b` or `a − b`. A `sum: [a, b]`
 * shortcut is allowed, exactly like in `match`.
 *
 * @return array{a:int,b:int,op:string,value:int}|null
 */
function quiz_expression(mixed $node): ?array
{
    $e = is_array($node) ? $node : [];
    $pair = is_array($e['sum'] ?? null) ? array_values($e['sum']) : [];
    $a = $e['a'] ?? ($pair[0] ?? null);
    $b = $e['b'] ?? ($pair[1] ?? null);
    if (!is_numeric($a) || !is_numeric($b)) {
        return null;
    }
    $op = ($e['op'] ?? '+') === '-' ? '-' : '+';
    return [
        'a' => (int) $a,
        'b' => (int) $b,
        'op' => $op,
        'value' => quiz_op_apply($op, (int) $a, (int) $b),
    ];
}

/**
 * The bank exercises: which operation they use and whether both sides of the
 * equation have to make the target (`equalSums` / `equalDifferences`).
 *
 * @return array{op:string,equal:bool}|null
 */
function quiz_bank_exercise(string $type): ?array
{
    switch ($type) {
        case 'pickAddends':
            return ['op' => '+', 'equal' => false];
        case 'subtractPair':
            return ['op' => '-', 'equal' => false];
        case 'equalSums':
            return ['op' => '+', 'equal' => true];
        case 'equalDifferences':
            return ['op' => '-', 'equal' => true];
    }
    return null;
}

/**
 * Indexes of the first two bank numbers that make the target with `op` (chip
 * order). Subtracting is not commutative, so the pair is checked in the order
 * it is printed - `6 - 2` and `2 - 6` are different answers.
 *
 * @return array{0:int,1:int}|null
 */
function quiz_bank_pair_indexes(array $bank, int $target, string $op): ?array
{
    for ($i = 0; $i < count($bank); $i++) {
        for ($j = 0; $j < count($bank); $j++) {
            if ($i !== $j && quiz_op_apply($op, (int) $bank[$i], (int) $bank[$j]) === $target) {
                return [$i, $j];
            }
        }
    }
    return null;
}

/**
 * The first two numbers of the bank that make the target (chip order),
 * e.g. "1+2" or "6-2" - the same pair `bankPair()` finds in quiz-types.js.
 */
function quiz_bank_pair(array $bank, int $target, string $op = '+'): string
{
    $pair = quiz_bank_pair_indexes($bank, $target, $op);
    return $pair === null ? '' : $bank[$pair[0]] . $op . $bank[$pair[1]];
}

/**
 * Both sides of `[ ] + [ ] = [ ] + [ ]` from the same bank, e.g. "3+1=2+2".
 *
 * Every pair is tried, because the first pair that works on its own may leave
 * two numbers that do not - the same search `bankSplit()` does in quiz-types.js.
 */
function quiz_bank_split(array $bank, int $target, string $op = '+'): string
{
    $count = count($bank);
    for ($i = 0; $i < $count; $i++) {
        for ($j = 0; $j < $count; $j++) {
            if ($i === $j || quiz_op_apply($op, (int) $bank[$i], (int) $bank[$j]) !== $target) {
                continue;
            }
            $rest = [];
            foreach ($bank as $k => $value) {
                if ($k !== $i && $k !== $j) {
                    $rest[] = (int) $value;
                }
            }
            $restCount = count($rest);
            for ($a = 0; $a < $restCount; $a++) {
                for ($b = 0; $b < $restCount; $b++) {
                    if ($a !== $b && quiz_op_apply($op, $rest[$a], $rest[$b]) === $target) {
                        return $bank[$i] . $op . $bank[$j] . '=' . $rest[$a] . $op . $rest[$b];
                    }
                }
            }
        }
    }
    return '';
}

/** Bank numbers of one `pickAddends` / `equalSums` item. */
function quiz_bank_of(mixed $item): array
{
    $bank = [];
    foreach ((array) ($item['bank'] ?? []) as $value) {
        if (is_numeric($value)) {
            $bank[] = (int) $value;
        }
    }
    return $bank;
}

/** The answer a quiz author would expect - used to warn about mismatches. */
function quiz_derived_answer(array $question): mixed
{
    $type = $question['type'] ?? '';
    $symbols = array_values(array_intersect((array) ($question['symbols'] ?? []), QUIZ_SYMBOLS));
    if ($symbols === []) {
        $symbols = ['>', '<', '='];
    }

    switch ($type) {
        case 'compare':
            $a = quiz_side_value($question['left'] ?? null);
            $b = quiz_side_value($question['right'] ?? null);
            return ($a === null || $b === null) ? null : quiz_symbol_value($a, $b, $symbols);

        case 'compareGrid':
            $rows = is_array($question['rows'] ?? null) ? $question['rows'] : [];
            $answers = [];
            foreach ($rows as $row) {
                $a = quiz_side_value($row['left'] ?? null);
                $b = quiz_side_value($row['right'] ?? null);
                if ($a === null || $b === null) {
                    return null;
                }
                $answers[] = quiz_symbol_value($a, $b, $symbols);
            }
            return $answers;

        case 'compareExpressions':
            $rows = (array) ($question['rows'] ?? []);
            $answers = [];
            foreach ($rows as $row) {
                $left = quiz_expression($row['left'] ?? null);
                $right = quiz_expression($row['right'] ?? null);
                if ($left === null || $right === null) {
                    return null;
                }
                $answers[] = quiz_symbol_value($left['value'], $right['value'], $symbols);
            }
            return $answers === [] ? null : $answers;

        // The scaffolded twin: the child writes BOTH results down and then the
        // sign, so every row answers [left result, sign, right result].
        case 'compareResults':
            $rows = (array) ($question['rows'] ?? []);
            $answers = [];
            foreach ($rows as $row) {
                $left = quiz_expression($row['left'] ?? null);
                $right = quiz_expression($row['right'] ?? null);
                if ($left === null || $right === null) {
                    return null;
                }
                $answers[] = [
                    (string) $left['value'],
                    quiz_symbol_value($left['value'], $right['value'], $symbols),
                    (string) $right['value'],
                ];
            }
            return $answers === [] ? null : $answers;

        case 'count':
            $scenes = (array) ($question['scenes'] ?? []);            if ($scenes === [] && isset($question['scene'])) {
                $scenes = [['figures' => $question['scene']['figures'] ?? $question['scene']]];
            }
            $answers = [];
            foreach ($scenes as $scene) {
                $value = quiz_side_value($scene['figures'] ?? $scene);
                if ($value === null) {
                    return null;
                }
                $answers[] = (string) $value;
            }
            return $answers === [] ? null : $answers;

        case 'countAndCompare':
            $a = quiz_side_value($question['left']['figures'] ?? $question['left'] ?? null);
            $b = quiz_side_value($question['right']['figures'] ?? $question['right'] ?? null);
            if ($a === null || $b === null) {
                return null;
            }
            return ['left' => (string) $a, 'right' => (string) $b, 'symbol' => quiz_symbol_value($a, $b, $symbols)];

        case 'write':
            $items = (array) ($question['items'] ?? []);
            $answers = [];
            foreach ($items as $item) {
                if (!isset($item['answer'])) {
                    return null;
                }
                $answers[] = (string) $item['answer'];
            }
            return $answers === [] ? null : $answers;

        case 'story':
        case 'storyChoice':
        case 'storyMore':
        case 'storyRead':
            // A word problem: the answer is what the author wrote (a number, or
            // the id/label of the option to tap) - it cannot be derived.
            return $question['answer'] ?? null;

        case 'addPicture':
        case 'subtractPicture':
            $op = $type === 'subtractPicture' ? '-' : '+';
            $rows = (array) ($question['rows'] ?? []);
            $answers = [];
            foreach ($rows as $row) {
                $a = quiz_side_value($row['left'] ?? null);
                $b = quiz_side_value($row['right'] ?? null);
                if ($a === null || $b === null) {
                    return null;
                }
                $value = quiz_op_apply($op, $a, $b);
                if (($question['write'] ?? 'all') === 'result') {
                    $answers[] = (string) $value;
                } else {
                    $answers[] = (string) $a;
                    $answers[] = (string) $b;
                    $answers[] = (string) $value;
                }
            }
            return $answers === [] ? null : $answers;

        case 'domino':
        case 'subtractDomino':
            $op = $type === 'subtractDomino' ? '-' : '+';
            $rows = (array) ($question['rows'] ?? []);
            $answers = [];
            foreach ($rows as $row) {
                $tile = is_array($row['domino'] ?? null) ? $row['domino'] : $row;
                $a = quiz_domino_pips($tile['left'] ?? null);
                $b = quiz_domino_pips($tile['right'] ?? null);
                if ($a === null || $b === null) {
                    return null;
                }
                $value = quiz_op_apply($op, $a, $b);
                if (($question['write'] ?? 'all') === 'result') {
                    $answers[] = (string) $value;
                } else {
                    $answers[] = (string) $a;
                    $answers[] = (string) $b;
                    $answers[] = (string) $value;
                }
            }
            return $answers === [] ? null : $answers;

        case 'missingNumber':
        case 'missingNumberSub':
            $op = $type === 'missingNumberSub' ? '-' : '+';
            $rows = (array) ($question['rows'] ?? []);
            $answers = [];
            foreach ($rows as $row) {
                if (!is_numeric($row['a'] ?? null) || !is_numeric($row['b'] ?? null)) {
                    return null;
                }
                $a = (int) $row['a'];
                $b = (int) $row['b'];
                $answers[] = (string) quiz_missing_value(
                    $a,
                    $b,
                    quiz_op_apply($op, $a, $b),
                    quiz_missing_blank($row['blank'] ?? null)
                );
            }
            return $answers === [] ? null : $answers;

        case 'mixedOps':
            $rows = (array) ($question['rows'] ?? []);
            $answers = [];
            foreach ($rows as $row) {
                if (!is_numeric($row['a'] ?? null) || !is_numeric($row['b'] ?? null)) {
                    return null;
                }
                $a = (int) $row['a'];
                $b = (int) $row['b'];
                // Same rule as the JS normalisation: "−" only when something is left.
                $op = ($row['op'] ?? '') === '-' && $a > $b ? '-' : '+';
                $answers[] = (string) quiz_op_apply($op, $a, $b);
            }
            return $answers === [] ? null : $answers;

        case 'multiCheck':
        case 'subtractCheck':
            $op = $type === 'subtractCheck' ? '-' : '+';
            $target = $question['target'] ?? null;
            $items = array_values((array) ($question['items'] ?? []));
            if (!is_numeric($target)) {
                return null;
            }
            $answers = [];
            foreach ($items as $i => $item) {
                if (!is_numeric($item['a'] ?? null) || !is_numeric($item['b'] ?? null)) {
                    return null;
                }
                if (quiz_op_apply($op, (int) $item['a'], (int) $item['b']) === (int) $target) {
                    $answers[] = quiz_text($item['id'] ?? null) ?: 'c' . ($i + 1);
                }
            }
            return $answers;

        case 'mixedCheck':
            // Every row carries its own sign ("+" or "-").
            $target = $question['target'] ?? null;
            $items = array_values((array) ($question['items'] ?? []));
            if (!is_numeric($target)) {
                return null;
            }
            $answers = [];
            foreach ($items as $i => $item) {
                if (!is_numeric($item['a'] ?? null) || !is_numeric($item['b'] ?? null)) {
                    return null;
                }
                $op = quiz_item_op($item);
                if (quiz_op_apply($op, (int) $item['a'], (int) $item['b']) === (int) $target) {
                    $answers[] = quiz_text($item['id'] ?? null) ?: 'c' . ($i + 1);
                }
            }
            return $answers;

        case 'pickAddends':
        case 'subtractPair':
        case 'equalSums':
        case 'equalDifferences':
            $exercise = quiz_bank_exercise($type) ?? ['op' => '+', 'equal' => false];
            $items = (array) ($question['items'] ?? []);
            $answers = [];
            foreach ($items as $item) {
                if (!is_numeric($item['target'] ?? null)) {
                    return null;
                }
                $bank = quiz_bank_of($item);
                $answers[] = $exercise['equal']
                    ? quiz_bank_split($bank, (int) $item['target'], $exercise['op'])
                    : quiz_bank_pair($bank, (int) $item['target'], $exercise['op']);
            }
            return $answers === [] ? null : $answers;

        case 'drawMissing':
            $items = (array) ($question['items'] ?? []);
            $answers = [];
            foreach ($items as $item) {
                if (!is_numeric($item['target'] ?? null)) {
                    return null;
                }
                $answers[] = (string) (int) $item['target'];
            }
            return $answers === [] ? null : $answers;

        case 'crossOutExtra':
            $items = (array) ($question['items'] ?? []);
            $answers = [];
            foreach ($items as $item) {
                if (!is_numeric($item['target'] ?? null) || !is_numeric($item['have'] ?? null)) {
                    return null;
                }
                $answers[] = (string) ((int) $item['have'] - (int) $item['target']);
            }
            return $answers === [] ? null : $answers;

        case 'match':
            $op = quiz_question_op($question);
            $left = (array) ($question['left'] ?? []);
            $right = array_values((array) ($question['right'] ?? []));
            $answers = [];
            $used = [];
            foreach ($left as $i => $item) {
                $key = quiz_match_key($item['a'] ?? null, $item['b'] ?? null, $op);
                if ($key === null) {
                    return null;
                }
                $fits = [];
                foreach ($right as $r) {
                    if (quiz_match_key($r['left'] ?? null, $r['right'] ?? null, $op) === $key) {
                        $fits[] = $r;
                    }
                }
                if (count($fits) !== 1) {
                    return null;
                }
                $rightId = quiz_text($fits[0]['id'] ?? null) ?: 'r' . ($i + 1);
                if (in_array($rightId, $used, true)) {
                    return null;
                }
                $used[] = $rightId;
                $leftId = quiz_text($item['id'] ?? null) ?: 'l' . ($i + 1);
                $answers[] = $leftId . '>' . $rightId;
            }
            return $answers === [] ? null : $answers;

        default:
            return null;
    }
}

/* ------------------------------------------------------------ validation -- */

/** Every `shape` / `emoji` value inside a question. */
function quiz_collect_figures(mixed $node, array &$found): void
{
    if (!is_array($node)) {
        return;
    }
    foreach ($node as $key => $value) {
        if (($key === 'shape' || $key === 'emoji') && is_string($value) && trim($value) !== '') {
            $found[] = trim($value);
        } elseif (is_array($value)) {
            quiz_collect_figures($value, $found);
        }
    }
}

/** A figure is valid when it is a known shape name or an emoji. */
function quiz_figure_ok(string $figure): bool
{
    if (in_array($figure, QUIZ_SHAPES, true)) {
        return true;
    }
    return (bool) preg_match('/\p{Extended_Pictographic}/u', $figure);
}

/**
 * Validate author supplied JSON (the builder screen).
 * Returns ['ok' => bool, 'errors' => [], 'warnings' => [], 'quiz' => envelope].
 */
function quiz_validate(mixed $raw): array
{
    $errors = [];
    $warnings = [];

    if (!is_array($raw)) {
        return ['ok' => false, 'errors' => ['Šaknis turi būti JSON objektas { ... }'], 'warnings' => [], 'quiz' => null];
    }
    if ($raw !== [] && array_is_list($raw)) {
        return ['ok' => false, 'errors' => ['Šaknis turi būti JSON objektas { ... }, o ne masyvas [ ... ]'], 'warnings' => [], 'quiz' => null];
    }

    if (quiz_text($raw['title'] ?? null) === '') {
        $warnings[] = 'Nėra „title“ – bus rodoma „Testas“.';
    }
    if (!array_key_exists('grade', $raw)) {
        $warnings[] = 'Nėra „grade“ – testas bus rodomas „' . QUIZ_GRADE_DEFAULT . ' klasė“ grupėje (pvz. "grade": 1).';
    } elseif (!is_int($raw['grade']) && !(is_string($raw['grade']) && ctype_digit($raw['grade']))) {
        $errors[] = 'Laukas „grade“ turi būti skaičius (pvz. 1).';
    } elseif ((int) $raw['grade'] < QUIZ_GRADE_MIN || (int) $raw['grade'] > QUIZ_GRADE_MAX) {
        $errors[] = 'Laukas „grade“ turi būti tarp ' . QUIZ_GRADE_MIN . ' ir ' . QUIZ_GRADE_MAX . ' klasės.';
    }
    foreach (array_keys($raw) as $key) {
        if (!in_array($key, ['id', 'title', 'subtitle', 'emoji', 'color', 'grade', 'questions', 'focus', 'types', 'max', 'tasks'], true)) {
            $warnings[] = "Nežinomas laukas „{$key}“ – jis bus ignoruojamas.";
        }
    }
    if (isset($raw['color']) && !in_array($raw['color'], QUIZ_COLORS, true)) {
        $warnings[] = 'Spalva „' . $raw['color'] . '“ nežinoma, bus naudojama „sky“.';
    }

    $questions = $raw['questions'] ?? null;
    if (!is_array($questions) || $questions === []) {
        $errors[] = 'Reikia bent vieno klausimo lauke „questions“.';
        return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings, 'quiz' => quiz_normalize($raw)];
    }

    foreach (array_values($questions) as $i => $question) {
        $n = $i + 1;
        if (!is_array($question)) {
            $errors[] = "#{$n}: klausimas turi būti objektas.";
            continue;
        }
        $type = quiz_text($question['type'] ?? null);
        if ($type === '') {
            $errors[] = "#{$n}: trūksta lauko „type“.";
            continue;
        }
        if (!in_array($type, QUIZ_TYPES, true)) {
            $errors[] = "#{$n}: nežinomas tipas „{$type}“. Galimi: " . implode(', ', QUIZ_TYPES) . '.';
            continue;
        }
        if (quiz_text($question['instruction'] ?? null) === '') {
            $warnings[] = "#{$n} ({$type}): nėra „instruction“ – bus rodoma bendra instrukcija.";
        }
        $figures = [];
        quiz_collect_figures($question, $figures);
        foreach (array_unique($figures) as $figure) {
            if (!quiz_figure_ok($figure)) {
                $warnings[] = "#{$n} ({$type}): figūra „{$figure}“ nežinoma – gali būti nupieštas apskritimas. Naudok figūros pavadinimą arba emoji.";
            }
        }
        if (isset($question['symbols'])) {
            foreach ((array) $question['symbols'] as $symbol) {
                if (!in_array($symbol, QUIZ_SYMBOLS, true)) {
                    $errors[] = "#{$n}: ženklas „{$symbol}“ netinka. Galimi: > < = ≠.";
                }
            }
        }
        if (array_key_exists('say', $question) && !is_bool($question['say'])) {
            $errors[] = "#{$n} ({$type}): laukas „say“ turi būti true arba false (ar įgarsinti šį klausimą).";
        }

        switch ($type) {
            case 'compare':
                if (quiz_side_value($question['left'] ?? null) === null) {
                    $errors[] = "#{$n} (compare): trūksta „left“ (figūros arba skaičius).";
                }
                if (quiz_side_value($question['right'] ?? null) === null) {
                    $errors[] = "#{$n} (compare): trūksta „right“ (figūros arba skaičius).";
                }
                break;

            case 'compareGrid':
                $rows = $question['rows'] ?? null;
                if (!is_array($rows) || $rows === []) {
                    $errors[] = "#{$n} (compareGrid): reikia ne tuščio masyvo „rows“.";
                } else {
                    foreach ($rows as $j => $row) {
                        if (quiz_side_value($row['left'] ?? null) === null || quiz_side_value($row['right'] ?? null) === null) {
                            $errors[] = "#{$n} (compareGrid): eilutė " . ($j + 1) . ' be „left“/„right“ skaičių.';
                        }
                    }
                }
                break;

            case 'compareExpressions':
            case 'compareResults':
                $rows = $question['rows'] ?? null;
                if (!is_array($rows) || $rows === []) {
                    $errors[] = "#{$n} ({$type}): reikia ne tuščio masyvo „rows“.";
                    break;
                }
                foreach ($rows as $j => $row) {
                    foreach (['left', 'right'] as $side) {
                        $expr = quiz_expression($row[$side] ?? null);
                        if ($expr === null) {
                            $errors[] = "#{$n} ({$type}): eilutė " . ($j + 1) . " – „{$side}“ veiksmas be „a“/„b“ skaičių.";
                            continue;
                        }
                        if ($expr['op'] === '-' && $expr['a'] <= $expr['b']) {
                            $warnings[] = "#{$n} ({$type}): eilutė " . ($j + 1) . " – „{$side}“ atimtyje „a“ nemažesnis už „b“ (skirtumas 0 arba neigiamas).";
                        }
                    }
                }
                break;

            case 'count':
                $scenes = $question['scenes'] ?? ($question['scene'] ?? null);
                if (!is_array($scenes) || $scenes === []) {
                    $errors[] = "#{$n} (count): reikia „scenes“ masyvo su figūromis.";
                } elseif (quiz_derived_answer($question) === null) {
                    $errors[] = "#{$n} (count): nepavyko suskaičiuoti figūrų (trūksta „count“?).";
                }
                break;

            case 'countAndCompare':
                foreach (['left', 'right'] as $side) {
                    if (quiz_side_value($question[$side]['figures'] ?? $question[$side] ?? null) === null) {
                        $errors[] = "#{$n} (countAndCompare): trūksta „{$side}.figures.count“.";
                    }
                }
                break;

            case 'outline':
            case 'crossOut':
                $cards = $question['cards'] ?? null;
                if (!is_array($cards) || $cards === []) {
                    $errors[] = "#{$n} ({$type}): reikia „cards“ masyvo.";
                    break;
                }
                $ids = [];
                $looksWrong = [];
                foreach ($cards as $j => $card) {
                    $cardId = quiz_text($card['id'] ?? null);
                    if ($cardId === '') {
                        $errors[] = "#{$n} ({$type}): kortelė " . ($j + 1) . ' be „id“.';
                        continue;
                    }
                    if (in_array($cardId, $ids, true)) {
                        $errors[] = "#{$n} ({$type}): pasikartojantis kortelės id „{$cardId}“.";
                    }
                    $ids[] = $cardId;
                    if (!isset($card['figures']) && !isset($card['compare']) && !isset($card['label'])) {
                        $errors[] = "#{$n} ({$type}): kortelė „{$cardId}“ tuščia (reikia „figures“, „compare“ arba „label“).";
                    }
                    if ($type === 'crossOut' && isset($card['label'], $card['figures']['count'])
                        && (string) $card['label'] !== (string) $card['figures']['count']) {
                        $looksWrong[] = $cardId;
                    }
                }
                $answer = $question['answer'] ?? null;
                if (!is_array($answer) || $answer === []) {
                    $sample = $ids[0] ?? 'k1';
                    $errors[] = "#{$n} ({$type}): reikia „answer“ su teisingų kortelių id, pvz. [\"{$sample}\"].";
                } else {
                    foreach ($answer as $want) {
                        if (!in_array((string) $want, $ids, true)) {
                            $errors[] = "#{$n} ({$type}): „answer“ id „{$want}“ nėra tarp kortelių.";
                        }
                    }
                    foreach ($looksWrong as $cardId) {
                        if (!in_array($cardId, array_map('strval', $answer), true)) {
                            $warnings[] = "#{$n} ({$type}): kortelė „{$cardId}“ atrodo klaidinga, bet jos nėra „answer“ sąraše.";
                        }
                    }
                }
                break;

            case 'write':
                $items = $question['items'] ?? null;
                if (!is_array($items) || $items === []) {
                    $errors[] = "#{$n} (write): reikia „items“ masyvo.";
                    break;
                }
                foreach ($items as $j => $item) {
                    if (!isset($item['answer'])) {
                        $errors[] = "#{$n} (write): elementas " . ($j + 1) . ' be „answer“.';
                    }
                }
                break;

            case 'story':
            case 'storyChoice':
            case 'storyMore':
            case 'storyRead':
                $answer = $question['answer'] ?? null;
                if ($answer === null || $answer === '' || $answer === []) {
                    $errors[] = "#{$n} (story): reikia „answer“ – teisingo atsakymo.";
                    break;
                }
                $options = $question['options'] ?? null;
                if (is_array($options) && $options !== []) {
                    $labels = [];
                    foreach ($options as $option) {
                        if (is_array($option)) {
                            $labels[] = (string) ($option['id'] ?? '');
                            $labels[] = trim((string) ($option['label'] ?? ''));
                        } else {
                            $labels[] = trim((string) $option);
                        }
                    }
                    if (!in_array((string) $answer, $labels, true)) {
                        $errors[] = "#{$n} (story): „answer“ „{$answer}“ nėra tarp „options“ pasirinkimų.";
                    }
                } elseif (!is_numeric($answer)) {
                    $errors[] = "#{$n} (story): skaičiaus atsakymui „answer“ turi būti skaičius (arba pridėk „options“ sąrašą, kad būtų galima rinktis iš mygtukų).";
                }
                break;

            case 'addPicture':
            case 'subtractPicture':
                $op = $type === 'subtractPicture' ? '-' : '+';
                $rows = $question['rows'] ?? null;
                if (!is_array($rows) || $rows === []) {
                    $errors[] = "#{$n} ({$type}): reikia ne tuščio masyvo „rows“.";
                    break;
                }
                if (isset($question['write']) && !in_array($question['write'], ['all', 'result'], true)) {
                    $errors[] = "#{$n} ({$type}): „write“ gali būti tik \"all\" (visi langeliai) arba \"result\" (tik atsakymas).";
                }
                foreach ($rows as $j => $row) {
                    foreach (['left', 'right'] as $side) {
                        if (quiz_side_value($row[$side] ?? null) === null) {
                            $errors[] = "#{$n} ({$type}): eilutė " . ($j + 1) . " be „{$side}“ figūrų.";
                        }
                    }
                    if ($op === '-') {
                        $from = quiz_side_value($row['left'] ?? null);
                        $take = quiz_side_value($row['right'] ?? null);
                        if ($from !== null && $take !== null && $take >= $from) {
                            $warnings[] = "#{$n} ({$type}): eilutė " . ($j + 1) . " – atimama tiek pat arba daugiau, nei yra (liks 0). Imk mažesnį skaičių nei „left“.";
                        }
                    }
                }
                if (count($rows) > 1) {
                    $warnings[] = "#{$n} ({$type}): " . count($rows) . ' eilutės viename klausime – paveikslėliai ir skaičiai tampa maži. Kai viename klausime yra vienas veiksmas, viskas matosi telefone.';
                }
                break;

            case 'domino':
            case 'subtractDomino':
                $rows = $question['rows'] ?? null;
                if (!is_array($rows) || $rows === []) {
                    $errors[] = "#{$n} ({$type}): reikia ne tuščio masyvo „rows“.";
                    break;
                }
                foreach ($rows as $j => $row) {
                    $tile = is_array($row['domino'] ?? null) ? $row['domino'] : $row;
                    $pipsLeft = quiz_domino_pips($tile['left'] ?? null);
                    $pipsRight = quiz_domino_pips($tile['right'] ?? null);
                    if ($pipsLeft === null || $pipsRight === null) {
                        $errors[] = "#{$n} ({$type}): eilutė " . ($j + 1) . ' be „left“/„right“ taškų (sveikas skaičius 0-6).';
                    } elseif ($type === 'subtractDomino' && $pipsLeft < $pipsRight) {
                        $warnings[] = "#{$n} ({$type}): eilutė " . ($j + 1) . ' – „left“ mažesnis už „right“, skirtumas išeitų neigiamas. Didesnį taškų skaičių dėk į „left“.';
                    }
                }
                if (count($rows) > 1) {
                    $warnings[] = "#{$n} ({$type}): " . count($rows) . ' kauliukai viename klausime – taškai tampa per maži suskaičiuoti. Dėk po vieną kauliuką viename klausime.';
                }
                break;

            case 'missingNumber':
            case 'missingNumberSub':
                $op = $type === 'missingNumberSub' ? '-' : '+';
                $rows = $question['rows'] ?? null;
                if (!is_array($rows) || $rows === []) {
                    $errors[] = "#{$n} ({$type}): reikia ne tuščio masyvo „rows“.";
                    break;
                }
                foreach ($rows as $j => $row) {
                    if (!is_numeric($row['a'] ?? null) || !is_numeric($row['b'] ?? null)) {
                        $errors[] = "#{$n} ({$type}): eilutė " . ($j + 1) . ' be „a“/„b“ skaičių (tai du veiksmo skaičiai).';
                        continue;
                    }
                    if (isset($row['blank']) && !in_array(is_string($row['blank']) ? strtolower(trim($row['blank'])) : '', ['left', 'middle', 'right'], true)) {
                        $errors[] = "#{$n} ({$type}): eilutė " . ($j + 1) . ' – „blank“ gali būti tik "left", "middle" arba "right" (kur tuščias langelis).';
                    }
                    if (quiz_missing_blank($row['blank'] ?? null) === 'right') {
                        $warnings[] = "#{$n} ({$type}): eilutė " . ($j + 1) . ' – „blank“ = "right", todėl trūkstamas skaičius yra atsakymas (nieko ieškoti nereikia). Dėk "left" arba "middle".';
                    }
                    if ($op === '-' && (int) $row['a'] <= (int) $row['b']) {
                        $warnings[] = "#{$n} ({$type}): eilutė " . ($j + 1) . ' – atimama tiek pat arba daugiau, nei yra (skirtumas 0 arba neigiamas). Didesnį skaičių dėk į „a“.';
                    }
                }
                break;

            case 'mixedOps':
                $rows = $question['rows'] ?? null;
                if (!is_array($rows) || $rows === []) {
                    $errors[] = "#{$n} (mixedOps): reikia ne tuščio masyvo „rows“.";
                    break;
                }
                foreach ($rows as $j => $row) {
                    if (!is_numeric($row['a'] ?? null) || !is_numeric($row['b'] ?? null)) {
                        $errors[] = "#{$n} (mixedOps): eilutė " . ($j + 1) . ' be „a“/„b“ skaičių.';
                        continue;
                    }
                    $rowOp = $row['op'] ?? '+';
                    if (!in_array($rowOp, ['+', '-'], true)) {
                        $errors[] = "#{$n} (mixedOps): eilutė " . ($j + 1) . ' – „op“ gali būti tik "+" arba "-".';
                        continue;
                    }
                    if ($rowOp === '-' && (int) $row['a'] <= (int) $row['b']) {
                        $warnings[] = "#{$n} (mixedOps): eilutė " . ($j + 1) . ' – atimama tiek pat arba daugiau, nei yra, todėl bus sudėta. Didesnį skaičių dėk į „a“.';
                    }
                }
                break;

            case 'multiCheck':
            case 'subtractCheck':
                $op = $type === 'subtractCheck' ? '-' : '+';
                $items = $question['items'] ?? null;
                if (!is_array($items) || $items === []) {
                    $errors[] = "#{$n} ({$type}): reikia „items“ masyvo.";
                    break;
                }
                if (!is_numeric($question['target'] ?? null)) {
                    $errors[] = "#{$n} ({$type}): reikia skaičiaus „target“ (kokį " . ($op === '-' ? 'skirtumą' : 'sumą') . ' reikia surasti).';
                }
                foreach ($items as $j => $item) {
                    if (!is_numeric($item['a'] ?? null) || !is_numeric($item['b'] ?? null)) {
                        $errors[] = "#{$n} ({$type}): elementas " . ($j + 1) . ' be „a“/„b“ skaičių.';
                        continue;
                    }
                    if ($op === '-' && (int) $item['a'] < (int) $item['b']) {
                        $warnings[] = "#{$n} ({$type}): elementas " . ($j + 1) . ' – „a“ mažesnis už „b“, atimtis duotų neigiamą skaičių.';
                    }
                }
                if (!isset($question['answer']) && quiz_derived_answer($question) === []) {
                    $warnings[] = "#{$n} ({$type}): nė vienas veiksmas neduoda " . (int) $question['target'] . ' – nebus ką pažymėti.';
                }
                if (!isset($question['answer']) && count(quiz_derived_answer($question) ?: []) === count($items)) {
                    $warnings[] = "#{$n} ({$type}): visi veiksmai duoda " . (int) $question['target'] . ' – vaikui užtenka pažymėti visus. Įdėk ir netinkančių veiksmų.';
                }
                break;

            case 'mixedCheck':
                $items = $question['items'] ?? null;
                if (!is_array($items) || $items === []) {
                    $errors[] = "#{$n} (mixedCheck): reikia „items“ masyvo.";
                    break;
                }
                $target = $question['target'] ?? null;
                if (!is_numeric($target)) {
                    $errors[] = "#{$n} (mixedCheck): reikia skaičiaus „target“ (kokį atsakymą reikia surasti).";
                }
                foreach ($items as $j => $item) {
                    if (!is_numeric($item['a'] ?? null) || !is_numeric($item['b'] ?? null)) {
                        $errors[] = "#{$n} (mixedCheck): elementas " . ($j + 1) . ' be „a“/„b“ skaičių.';
                        continue;
                    }
                    if (!in_array($item['op'] ?? '', ['+', '-'], true)) {
                        $errors[] = "#{$n} (mixedCheck): elementas " . ($j + 1) . ' – „op“ gali būti tik "+" arba "-" (kiekvienas veiksmas turi savo ženklą).';
                        continue;
                    }
                    if ($item['op'] === '-' && (int) $item['a'] < (int) $item['b']) {
                        $warnings[] = "#{$n} (mixedCheck): elementas " . ($j + 1) . ' – „a“ mažesnis už „b“, atimtis duotų neigiamą skaičių.';
                    }
                }
                if (is_numeric($target) && !isset($question['answer'])) {
                    $fits = quiz_derived_answer($question) ?: [];
                    if ($fits === []) {
                        $warnings[] = "#{$n} (mixedCheck): nė vienas veiksmas neduoda " . (int) $target . ' – nebus ką pažymėti.';
                    } elseif (count($fits) === count($items)) {
                        $warnings[] = "#{$n} (mixedCheck): visi veiksmai duoda " . (int) $target . ' – vaikui užtenka pažymėti visus. Įdėk ir netinkančių veiksmų.';
                    } else {
                        // A mixed exercise should really mix: both signs must fit.
                        $fitSigns = [];
                        foreach ($items as $item) {
                            $itemOp = $item['op'] ?? '';
                            if (!is_numeric($item['a'] ?? null) || !is_numeric($item['b'] ?? null)) {
                                continue;
                            }
                            if (!in_array($itemOp, ['+', '-'], true)) {
                                continue;
                            }
                            if (quiz_op_apply($itemOp, (int) $item['a'], (int) $item['b']) === (int) $target) {
                                $fitSigns[$itemOp] = true;
                            }
                        }
                        if (count($fitSigns) === 1) {
                            $warnings[] = "#{$n} (mixedCheck): tinka tik „" . array_key_first($fitSigns) . '“ veiksmai – įdėk ir kito ženklo veiksmą, kuris duotų tą patį skaičių.';
                        }
                    }
                }
                break;

            case 'pickAddends':
            case 'subtractPair':
            case 'equalSums':
            case 'equalDifferences':
                $exercise = quiz_bank_exercise($type) ?? ['op' => '+', 'equal' => false];
                $op = $exercise['op'];
                $items = $question['items'] ?? null;
                if (!is_array($items) || $items === []) {
                    $errors[] = "#{$n} ({$type}): reikia „items“ masyvo.";
                    break;
                }
                $least = $exercise['equal'] ? 4 : 2;
                foreach ($items as $j => $item) {
                    $target = $item['target'] ?? null;
                    if (!is_numeric($target)) {
                        $errors[] = "#{$n} ({$type}): elementas " . ($j + 1) . ' be skaičiaus „target“.';
                        continue;
                    }
                    $bank = quiz_bank_of($item);
                    if (count($bank) < $least) {
                        $errors[] = "#{$n} ({$type}): elementui " . ($j + 1) . " reikia bent {$least} skaičių „bank“.";
                        continue;
                    }
                    $solution = $exercise['equal']
                        ? quiz_bank_split($bank, (int) $target, $op)
                        : quiz_bank_pair($bank, (int) $target, $op);
                    if ($solution === '') {
                        $warnings[] = "#{$n} ({$type}): iš banko (" . implode(' ', $bank) . ') neįmanoma gauti atsakymo ' . (int) $target . ' – patikrink skaičius.';
                    }
                    $tapOrder = $exercise['equal']
                        ? (quiz_op_apply($op, $bank[0], $bank[1]) === (int) $target && quiz_op_apply($op, $bank[2], $bank[3]) === (int) $target)
                        : (quiz_op_apply($op, $bank[0], $bank[1]) === (int) $target);
                    if ($tapOrder) {
                        $warnings[] = "#{$n} ({$type}): bankas sudėtas taip, kad spaudžiant skaičius iš eilės išeina teisingas atsakymas – perstatyk skaičius.";
                    }
                    $anyPairFails = false;
                    foreach ($bank as $x => $left) {
                        foreach ($bank as $y => $right2) {
                            if ($x !== $y && quiz_op_apply($op, (int) $left, (int) $right2) !== (int) $target) {
                                $anyPairFails = true;
                            }
                        }
                    }
                    if (!$anyPairFails) {
                        $warnings[] = "#{$n} ({$type}): banke bet kuri dviejų skaičių pora duoda " . (int) $target
                            . ' – duok skirtingus skaičius, kad reikėtų rinktis.';
                    }
                }
                break;

            case 'drawMissing':
                $items = $question['items'] ?? null;
                if (!is_array($items) || $items === []) {
                    $errors[] = "#{$n} (drawMissing): reikia „items“ masyvo.";
                    break;
                }
                foreach ($items as $j => $item) {
                    $target = $item['target'] ?? null;
                    if (!is_numeric($target) || (int) $target < 1 || (int) $target > 20) {
                        $errors[] = "#{$n} (drawMissing): elementas " . ($j + 1) . ' be „target“ (1-20).';
                        continue;
                    }
                    $have = $item['have'] ?? 0;
                    if (!is_numeric($have)) {
                        $errors[] = "#{$n} (drawMissing): „have“ turi būti skaičius.";
                    } elseif ((int) $have > (int) $target) {
                        $warnings[] = "#{$n} (drawMissing): jau nupiešta daugiau (" . (int) $have . ') nei reikia (' . (int) $target . ').';
                    }
                    if (!isset($item['cells'])) {
                        $warnings[] = "#{$n} (drawMissing): nėra „cells“ – bus rodomi " . max((int) $target + 2, 4)
                            . ' langeliai (daugiau nei reikia, kad vaikas turėtų suskaičiuoti).';
                    } elseif (!is_numeric($item['cells'])) {
                        $errors[] = "#{$n} (drawMissing): „cells“ turi būti skaičius.";
                    } elseif ((int) $item['cells'] < (int) $target) {
                        $errors[] = "#{$n} (drawMissing): „cells“ (" . (int) $item['cells'] . ') negali būti mažiau nei „target“ (' . (int) $target . ').';
                    } elseif ((int) $item['cells'] <= (int) $target) {
                        $warnings[] = "#{$n} (drawMissing): „cells“ lygus „target“ – vaikui užtenka užpildyti visus laisvus langelius. Duok bent 1–2 langelius daugiau.";
                    }
                }
                break;

            case 'crossOutExtra':
                $items = $question['items'] ?? null;
                if (!is_array($items) || $items === []) {
                    $errors[] = "#{$n} (crossOutExtra): reikia „items“ masyvo.";
                    break;
                }
                foreach ($items as $j => $item) {
                    $have = $item['have'] ?? null;
                    if (!is_numeric($have) || (int) $have < 2 || (int) $have > 20) {
                        $errors[] = "#{$n} (crossOutExtra): elementas " . ($j + 1) . ' be „have“ (kiek apskritimų nupiešta, 2-20).';
                        continue;
                    }
                    $target = $item['target'] ?? null;
                    if (!is_numeric($target) || (int) $target < 1) {
                        $errors[] = "#{$n} (crossOutExtra): elementas " . ($j + 1) . ' be „target“ (kiek turi likti, bent 1).';
                    } elseif ((int) $target >= (int) $have) {
                        $warnings[] = "#{$n} (crossOutExtra): „target“ (" . (int) $target . ') nemažesnis už „have“ (' . (int) $have . ') – nėra ko braukti.';
                    }
                }
                break;

            case 'match':
                $op = quiz_question_op($question);
                $left = $question['left'] ?? null;
                $right = $question['right'] ?? null;
                if (!is_array($left) || $left === []) {
                    $errors[] = "#{$n} (match): reikia „left“ masyvo su veiksmais.";
                }
                if (!is_array($right) || $right === []) {
                    $errors[] = "#{$n} (match): reikia „right“ masyvo su paveikslėliais.";
                }
                if (is_array($left) && is_array($right) && count($left) !== count($right)) {
                    $warnings[] = "#{$n} (match): „left“ ir „right“ nevienodo ilgio – dalis kortelių liks be poros.";
                }
                $leftIds = [];
                foreach ((array) $left as $j => $item) {
                    $id = quiz_text($item['id'] ?? null);
                    $pair = is_array($item['sum'] ?? null) ? $item['sum'] : [];
                    $sumA = $item['a'] ?? ($pair[0] ?? null);
                    $sumB = $item['b'] ?? ($pair[1] ?? null);
                    if (!is_numeric($sumA) || !is_numeric($sumB)) {
                        $errors[] = "#{$n} (match): veiksmas " . ($j + 1) . ' be „a“/„b“ skaičių.';
                    } elseif ($op === '-' && (int) $sumA < (int) $sumB) {
                        $warnings[] = "#{$n} (match): veiksmas " . ($j + 1) . ' – „a“ mažesnis už „b“, atimtis duotų neigiamą skaičių.';
                    }
                    if ($id === '') {
                        $errors[] = "#{$n} (match): veiksmas " . ($j + 1) . ' be „id“.';
                    } elseif (in_array($id, $leftIds, true)) {
                        $errors[] = "#{$n} (match): pasikartojantis id „{$id}“.";
                    }
                    $leftIds[] = $id;
                }
                $rightIds = [];
                foreach ((array) $right as $j => $item) {
                    $id = quiz_text($item['id'] ?? null);
                    foreach (['left', 'right'] as $side) {
                        if (quiz_side_value($item[$side] ?? null) === null) {
                            $errors[] = "#{$n} (match): paveikslėlis " . ($j + 1) . " be „{$side}“ figūrų grupės.";
                        }
                    }
                    if ($op === '-') {
                        $whole = quiz_side_value($item['left'] ?? null);
                        $taken = quiz_side_value($item['right'] ?? null);
                        if ($whole !== null && $taken !== null && $taken >= $whole) {
                            $warnings[] = "#{$n} (match): paveikslėlis " . ($j + 1) . ' – nubraukta tiek pat arba daugiau, nei yra (liks 0).';
                        }
                    }
                    if ($id === '') {
                        $errors[] = "#{$n} (match): paveikslėlis " . ($j + 1) . ' be „id“.';
                    } elseif (in_array($id, $rightIds, true)) {
                        $errors[] = "#{$n} (match): pasikartojantis id „{$id}“.";
                    }
                    $rightIds[] = $id;
                }
                $pairs = $question['answer'] ?? null;
                if ($pairs !== null) {
                    if (!is_array($pairs) || $pairs === []) {
                        $errors[] = "#{$n} (match): „answer“ turi būti porų masyvas, pvz. [\"l1>r2\"].";
                    } else {
                        foreach ($pairs as $pair) {
                            $parts = explode('>', (string) $pair);
                            if (count($parts) !== 2 || !in_array($parts[0], $leftIds, true) || !in_array($parts[1], $rightIds, true)) {
                                $errors[] = "#{$n} (match): pora „{$pair}“ neteisinga (reikia \"veiksmoId>paveikslėlioId\").";
                            }
                        }
                    }
                } elseif (quiz_derived_answer($question) === null) {
                    $errors[] = "#{$n} (match): porų nepavyksta išvesti automatiškai – įrašyk „answer“ sąrašą, pvz. [\"l1>r2\"].";
                }
                break;
        }

        // Author supplied answers must agree with the maths.
        if ($type !== 'outline' && $type !== 'crossOut' && array_key_exists('answer', $question)) {
            $derived = quiz_derived_answer($question);
            if ($derived !== null && $question['answer'] !== $derived && (string) json_encode($question['answer']) !== (string) json_encode($derived)) {
                $warnings[] = "#{$n}: nurodytas „answer“ nesutampa su suskaičiuotu (" . json_encode($derived, JSON_UNESCAPED_UNICODE) . ') – svarbiausias yra „answer“.';
            }
        }
    }

    $quiz = quiz_normalize($raw);
    if (count($quiz['questions']) !== count($questions)) {
        $errors[] = 'Kai kurie klausimai buvo praleisti (nežinomas tipas).';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'quiz' => $quiz,
    ];
}
