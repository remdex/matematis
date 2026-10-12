<?php
declare(strict_types=1);

/**
 * Generator.php — builds practice quizzes that follow the same maths rules as
 * the hand written quizzes in data/quizzes/.
 *
 * Rules (based on the workbook and the hand made tests):
 *   · amounts always stay between 1 and `max` (5, 10 or 20)
 *   · every answer is computed here - it is never guessed or repeated
 *   · "=" cases do appear, but they are never the majority of an exercise
 *   · a figure group shows at most 10 figures (bigger amounts are written as
 *     plain numbers), so the child can still count them
 *   · outline cards have exactly one clear winner (most/least), or exactly two
 *     equal cards for "tiek pat" - never an ambiguous tie
 *   · crossOut cards contain one or two mistakes that contradict the picture
 *     (a wrong label or a wrong sign); the rest are correct
 *   · a + b never exceeds `max`; successor/predecessor stay inside 1..max
 *   · a "count" exercise uses 2-3 groups with different amounts
 */

const GEN_SHAPES = [
    'triangle', 'square', 'circle', 'diamond', 'star', 'heart', 'apple', 'fish',
    'flower', 'bug', 'candy', 'ball', 'leaf', 'butterfly', 'cloud', 'carrot',
    'lollipop', 'hexagon', 'pentagon', 'oval', 'pencil', 'pen', 'crayon',
    'eraser', 'soap', 'sponge',
];

/** Emoji work as figures too - they are drawn as SVG text and scale with the cell. */
const GEN_EMOJI = [
    '🍎', '🍏', '🍌', '🍓', '🍇', '🍊', '🥕', '🍪', '🧁', '🎈',
    '⭐', '❤️', '🌼', '🌈', '🐟', '🐞', '🦋', '🐣', '🐢', '🐝',
    '⚽', '🎲', '🚗', '🎁', '🧩', '🖍️', '✏️',
];

const GEN_COLORS = [
    'sky', 'emerald', 'rose', 'violet', 'amber', 'teal', 'fuchsia', 'indigo',
    'orange', 'lime', 'cyan', 'pink', 'red', 'green', 'purple', 'yellow',
];

/**
 * Names used by the story problems: nominative, dative, and the pronoun that
 * stands in for the subject of the second sentence.
 */
const GEN_STORY_NAMES = [
    ['Jonukas', 'Jonukui', 'jis'],
    ['Morta', 'Mortai', 'ji'],
    ['Rūta', 'Rūtai', 'ji'],
    ['Benas', 'Benui', 'jis'],
    ['Tadas', 'Tadui', 'jis'],
    ['Lėja', 'Lėjai', 'ji'],
    ['Rokas', 'Rokui', 'jis'],
    ['Kamila', 'Kamilai', 'ji'],
    ['Džiugas', 'Džiugui', 'jis'],
    ['Gabija', 'Gabijai', 'ji'],
    ['Nojus', 'Nojui', 'jis'],
    ['Emilija', 'Emilijai', 'ji'],
    ['Adomas', 'Adomui', 'jis'],
    ['Austėja', 'Austėjai', 'ji'],
];

/**
 * Things the story problems are about: the form after a number (accusative
 * plural, "turėjo 5 obuolius") and the form in the question (genitive plural,
 * "kiek liko obuolių").
 */
const GEN_STORY_THINGS = [
    ['obuolius', 'obuolių'],
    ['saldainius', 'saldainių'],
    ['riešutus', 'riešutų'],
    ['kankorėžius', 'kankorėžių'],
    ['braškes', 'braškių'],
    ['lipdukus', 'lipdukų'],
    ['sausainius', 'sausainių'],
    ['gėles', 'gėlių'],
    ['kačiukus', 'kačiukų'],
    ['šuniukus', 'šuniukų'],
    ['žaisliukus', 'žaisliukų'],
    ['kamuolius', 'kamuolių'],
    ['pieštukus', 'pieštukų'],
    ['žuvytes', 'žuvyčių'],
    ['morkas', 'morkų'],
    ['bananus', 'bananų'],
    ['spurgas', 'spurgų'],
];

const GEN_FOCUSES = ['compare', 'count', 'draw', 'sum', 'sub', 'mixed'];
const GEN_MAX_LIMIT = 20;
/** How many quizzes "generate N at once" may ask for. */
const GEN_BATCH_LIMIT = 20;
/** How many tasks one generated quiz may contain. */
const GEN_TASKS_LIMIT = 50;
/** Safety net for "20 tests x 50 tasks" style requests. */
const GEN_BATCH_TASKS_LIMIT = 1000;

/**
 * Which question types exist and which builders can produce each of them.
 * The order here is the order the home screen picker shows them in.
 */
const GEN_TYPE_BUILDERS = [
    'compare' => [['gen_compare_figures', null], ['gen_compare_number', null], ['gen_compare_equality', null]],
    'compareGrid' => [['gen_compare_grid', null]],
    'compareExpressions' => [['gen_compare_expressions', null]],
    'count' => [['gen_count', null]],
    'countAndCompare' => [['gen_count_compare', null]],
    'outline' => [['gen_outline', 'more'], ['gen_outline', 'less'], ['gen_outline', 'equal']],
    'crossOut' => [['gen_cross_out_number', null], ['gen_cross_out_symbol', null]],
    'write' => [['gen_write', null]],
    'story' => [['gen_story', '+'], ['gen_story', '-'], ['gen_story', 'zero'], ['gen_story', 'gift'], ['gen_story', 'giftLeft']],
    'storyChoice' => [['gen_story_choice', 'who'], ['gen_story_choice', 'which'], ['gen_story_choice', 'equal'], ['gen_story_choice', 'change']],
    'addPicture' => [['gen_add_picture', null]],
    'subtractPicture' => [['gen_subtract_picture', null]],
    'missingNumber' => [['gen_missing_number', '+']],
    'missingNumberSub' => [['gen_missing_number', '-']],
    'mixedOps' => [['gen_mixed_ops', null]],
    'domino' => [['gen_domino', null]],
    'subtractDomino' => [['gen_subtract_domino', null]],
    'pickAddends' => [['gen_pick_addends', null]],
    'subtractPair' => [['gen_subtract_pair', null]],
    'equalSums' => [['gen_equal_sums', null]],
    'equalDifferences' => [['gen_equal_differences', null]],
    'multiCheck' => [['gen_multi_check', null]],
    'subtractCheck' => [['gen_subtract_check', null]],
    'drawMissing' => [['gen_draw_missing', null]],
    'crossOutExtra' => [['gen_cross_out_extra', null]],
];

/** How the home screen groups the types, with the label of each chip. */
const GEN_TYPE_GROUPS = [
    'Palyginimas' => [
        'compare' => 'Palyginti grupes ( > < = )',
        'compareGrid' => 'Palyginimų lentelė',
    ],
    'Skaičiavimas' => [
        'count' => 'Suskaičiuoti ir įrašyti',
        'countAndCompare' => 'Suskaičiuoti ir palyginti',
        'write' => 'Įrašyti skaičių',
        'mixedOps' => 'Sudėtis ir atimtis kartu',
    ],
    'Veiksmai' => [
        'compareExpressions' => 'Suskaičiuoti ir palyginti veiksmus ( > < = )',
    ],
    'Pasakojimai' => [
        'story' => 'Pasakojimo uždavinys (įrašyti skaičių)',
        'storyChoice' => 'Pasakojimo uždavinys (pasirinkti atsakymą)',
    ],
    'Braižymas' => [
        'outline' => 'Apibraukti kortelę',
        'crossOut' => 'Nubraukti klaidingą',
    ],
    'Sudėtis' => [
        'addPicture' => 'Sudėtis iš paveikslėlio',
        'missingNumber' => 'Įrašyti trūkstamą skaičių',
        'domino' => 'Sudėtis iš domino',
        'pickAddends' => 'Sudėti du skaičius',
        'equalSums' => 'Abi pusės vienodos',
        'multiCheck' => 'Pažymėti tinkamus veiksmus',
        'drawMissing' => 'Nupiešti trūkstamus',
    ],
    'Atimtis' => [
        'subtractPicture' => 'Atimtis iš paveikslėlio',
        'missingNumberSub' => 'Įrašyti trūkstamą skaičių',
        'subtractDomino' => 'Atimtis iš domino',
        'subtractPair' => 'Atimti du skaičius',
        'equalDifferences' => 'Abi pusės vienodos',
        'subtractCheck' => 'Pažymėti tinkamus veiksmus',
        'crossOutExtra' => 'Nubraukti, kiek atimti',
    ],
];

/** Title, emoji and colour of a quiz built from one group of types. */
const GEN_GROUP_LOOK = [
    'Palyginimas' => ['Palyginimų testas', '⚖️', 'sky'],
    'Skaičiavimas' => ['Skaičiavimo testas', '🔢', 'emerald'],
    'Veiksmai' => ['Veiksmų palyginimo testas', '🧮', 'teal'],
    'Pasakojimai' => ['Pasakojimų testas', '📖', 'teal'],
    'Braižymas' => ['Braižymo testas', '🖍️', 'amber'],
    'Sudėtis' => ['Sudėties testas', '➕', 'indigo'],
    'Atimtis' => ['Atimties testas', '➖', 'rose'],
];

/** The old `focus` presets still work - each expands into a list of types. */
const GEN_FOCUS_TYPES = [
    'compare' => ['compare', 'compareGrid', 'countAndCompare'],
    'count' => ['count', 'countAndCompare', 'write', 'mixedOps'],
    'draw' => ['outline', 'crossOut'],
    'sum' => ['addPicture', 'domino', 'pickAddends', 'equalSums', 'multiCheck', 'drawMissing', 'missingNumber'],
    'sub' => ['subtractPicture', 'subtractDomino', 'subtractPair', 'equalDifferences', 'subtractCheck', 'crossOutExtra', 'missingNumberSub'],
];

/** Titles of the presets above (used when a quiz is asked for by focus name). */
const GEN_FOCUS_LOOK = [
    'compare' => ['Palyginimų testas', '⚖️', 'sky'],
    'count' => ['Skaičiavimo testas', '🔢', 'emerald'],
    'draw' => ['Braižymo testas', '🖍️', 'amber'],
    'sum' => ['Sudėties testas', '➕', 'indigo'],
    'sub' => ['Atimties testas', '➖', 'rose'],
];

/** Every type the generator can build (in the order of the picker). */
function gen_all_types(): array
{
    return array_keys(GEN_TYPE_BUILDERS);
}

/** The groups used by the home screen picker. */
function gen_type_groups(): array
{
    return GEN_TYPE_GROUPS;
}

/** Keep only the known types, drop duplicates, sort into the picker order. */
function gen_clean_types(mixed $types): array
{
    $list = is_array($types) ? $types : explode(',', (string) $types);
    $wanted = [];
    foreach ($list as $type) {
        $type = trim((string) $type);
        if ($type !== '' && isset(GEN_TYPE_BUILDERS[$type])) {
            $wanted[$type] = true;
        }
    }
    return array_values(array_filter(gen_all_types(), static fn(string $type): bool => isset($wanted[$type])));
}

/** The types behind an old style focus name (unknown / mixed = everything). */
function gen_focus_types(string $focus): array
{
    $focus = strtolower(trim($focus));
    if ($focus === '' || $focus === 'mixed') {
        return gen_all_types();
    }
    return GEN_FOCUS_TYPES[$focus] ?? gen_all_types();
}

/**
 * A selection that is exactly one of the presets keeps the preset name (and
 * with it the stars the child already earned for that kind of quiz).
 */
function gen_focus_for_types(array $types): string
{
    foreach (GEN_FOCUS_TYPES as $focus => $focusTypes) {
        $set = array_values(array_intersect(gen_all_types(), $focusTypes));
        if ($set === $types) {
            return $focus;
        }
    }
    return '';
}

/** Title / emoji / colour of a quiz made of exactly these types. */
function gen_look(array $types, bool $all): array
{
    if (!$all) {
        foreach (GEN_GROUP_LOOK as $group => [$title, $emoji, $color]) {
            if (array_diff($types, array_keys(GEN_TYPE_GROUPS[$group])) === []) {
                return [$title, $emoji, $color];
            }
        }
    }
    return ['Naujas testas', gen_pick(['✨', '🎲', '🚀', '🐣', '🌈', '🧩']), gen_pick(GEN_COLORS)];
}

function gen_pick(array $list): mixed
{
    return $list[random_int(0, count($list) - 1)];
}

function gen_int(int $min, int $max): int
{
    return random_int($min, $max);
}

/** Clamp the requested number range to what the exercises can show. */
function gen_max(int $max): int
{
    return max(5, min(GEN_MAX_LIMIT, $max));
}

/** How many figures a group may show: at most 10, at most max. */
function gen_fig_max(int $max): int
{
    return min(10, gen_max($max));
}

function gen_figures(int $count, ?string $shape = null, ?string $color = null): array
{
    return [
        'shape' => $shape ?? gen_pick(GEN_SHAPES),
        'count' => $count,
        'color' => $color ?? gen_pick(GEN_COLORS),
    ];
}

/** Any figure: a drawn shape or an emoji (about half of them are emoji). */
function gen_figure_name(): string
{
    return gen_int(0, 1) === 0 ? gen_pick(GEN_SHAPES) : gen_pick(GEN_EMOJI);
}

/** One side of a comparison: `count` figures (a shape or an emoji). */
function gen_side(int $count, ?string $shape = null): array
{
    return ['kind' => 'figures'] + gen_figures($count, $shape ?? gen_figure_name());
}

/**
 * Two amounts. Equal pairs happen (like in the workbook) but stay a minority.
 *
 * @return array{0:int,1:int}
 */
function gen_pair(int $min, int $max, int $equalInEight = 2): array
{
    if (gen_int(1, 8) <= $equalInEight) {
        $value = gen_int($min, $max);
        return [$value, $value];
    }
    do {
        $a = gen_int($min, $max);
        $b = gen_int($min, $max);
    } while ($a === $b);
    return [$a, $b];
}

/** Symbol sets used by the hand made tests: > < = , or = ≠ ("lygu ar nelygu"). */
function gen_symbols(int $inequalityInFour = 3): array
{
    return gen_int(1, 4) <= $inequalityInFour ? ['>', '<', '='] : ['=', '≠'];
}

/* ------------------------------------------------------------ generators -- */

/** Palygink dvi grupes (page 14 style). */
function gen_compare_figures(int $i, int $max): array
{
    $figMax = gen_fig_max($max);
    [$a, $b] = gen_pair(1, $figMax);
    $shape = gen_figure_name();
    // Both sides show the same figure in about half of the exercises, so the
    // child really has to count instead of comparing two different pictures.
    $sameShape = gen_int(0, 1) === 1;
    $instructions = [
        'Suskaičiuok figūras ir įrašyk reikiamą ženklą.',
        'Palygink figūrų skaičių.',
        'Kurioje pusėje yra daugiau?',
    ];
    return [
        'id' => "q{$i}",
        'type' => 'compare',
        'instruction' => gen_pick($instructions),
        'left' => gen_side($a, $sameShape ? $shape : gen_figure_name()),
        'right' => gen_side($b, $sameShape ? $shape : gen_figure_name()),
    ];
}

/** Figūros prieš skaičių (page 16 style). */
function gen_compare_number(int $i, int $max): array
{
    $figMax = gen_fig_max($max);
    $value = gen_int(1, $figMax);
    $other = gen_int(1, $max);
    return [
        'id' => "q{$i}",
        'type' => 'compare',
        'instruction' => 'Palygink figūras ir skaičių.',
        'left' => gen_side($value),
        'right' => ['kind' => 'number', 'value' => $other, 'color' => gen_pick(GEN_COLORS)],
    ];
}

/** Lygu ar nelygu? (page 15, 4 pratimas). */
function gen_compare_equality(int $i, int $max): array
{
    [$a, $b] = gen_pair(1, $max, 3);
    return [
        'id' => "q{$i}",
        'type' => 'compare',
        'instruction' => 'Ar skaičiai lygūs? Įrašyk = arba ≠.',
        'left' => ['kind' => 'number', 'value' => $a, 'color' => 'sky'],
        'right' => ['kind' => 'number', 'value' => $b, 'color' => 'violet'],
        'symbols' => ['=', '≠'],
    ];
}

/** Suskaičiuok ir įrašyk skaičių (page 15, 3 pratimas). */
function gen_count(int $i, int $max): array
{
    $figMax = gen_fig_max($max);
    $wanted = gen_int(2, min(3, $figMax));
    $scenes = [];
    $used = [];
    for ($s = 1; $s <= $wanted; $s++) {
        do {
            $count = gen_int(2, $figMax);
        } while (in_array($count, $used, true));
        $used[] = $count;
        $scenes[] = ['id' => "s{$s}", 'figures' => gen_figures($count, gen_figure_name())];
    }
    return [
        'id' => "q{$i}",
        'type' => 'count',
        'instruction' => gen_pick(['Suskaičiuok ir įrašyk skaičių.', 'Parašyk, kiek yra daiktų.']),
        'scenes' => $scenes,
    ];
}

/** Dvi grupės, skaičiai ir ženklas (page 15, 3 pratimas). */
function gen_count_compare(int $i, int $max): array
{
    $figMax = gen_fig_max($max);
    [$a, $b] = gen_pair(1, $figMax);
    // 50/50: same figure on both sides (harder, must count) or two different ones.
    $shape = gen_figure_name();
    $sameShape = gen_int(0, 1) === 1;
    return [
        'id' => "q{$i}",
        'type' => 'countAndCompare',
        'instruction' => 'Suskaičiuok, įrašyk skaičius ir palygink.',
        'left' => ['figures' => gen_figures($a, $sameShape ? $shape : gen_figure_name())],
        'right' => ['figures' => gen_figures($b, $sameShape ? $shape : gen_figure_name())],
    ];
}

/** Apibrauk kortelę (page 16-17 style) — always with one clear solution. */
function gen_outline(int $i, int $max, ?string $criterion = null): array
{
    $figMax = gen_fig_max($max);
    $criterion = $criterion ?? gen_pick(['more', 'less', 'equal']);
    $shape = gen_figure_name();
    $cards = [];
    $answer = [];

    if ($criterion === 'equal') {
        // Exactly two cards with the same amount, the third one differs.
        $pair = gen_int(2, max(2, $figMax - 2));
        $odd = $pair;
        while ($odd === $pair) {
            $odd = gen_int(1, $figMax);
        }
        $counts = [$pair, $pair, $odd];
        shuffle($counts);
        foreach ($counts as $c => $count) {
            $id = 'k' . ($c + 1);
            $cards[] = ['id' => $id, 'figures' => gen_figures($count, $shape)];
            if ($count === $pair) {
                $answer[] = $id;
            }
        }
        $instruction = 'Apibrauk korteles, kuriose figūrų yra tiek pat.';
    } else {
        // Three different amounts, so the winner is unambiguous.
        $values = [];
        while (count($values) < 3) {
            $value = gen_int(1, $figMax);
            if (!in_array($value, $values, true)) {
                $values[] = $value;
            }
        }
        shuffle($values);
        $target = $criterion === 'more' ? max($values) : min($values);
        foreach ($values as $c => $value) {
            $id = 'k' . ($c + 1);
            $cards[] = ['id' => $id, 'figures' => gen_figures($value, $shape)];
            if ($value === $target) {
                $answer = [$id];
            }
        }
        $instruction = $criterion === 'more'
            ? 'Apibrauk kortelę, kurioje yra daugiau figūrų.'
            : 'Apibrauk kortelę, kurioje yra mažiau figūrų.';
    }

    return [
        'id' => "q{$i}",
        'type' => 'outline',
        'instruction' => $instruction,
        'hint' => 'Brėžk ratuką apie kortelę',
        'cards' => $cards,
        'answer' => $answer,
    ];
}

/** Nubrauk neteisingą skaičių (page 17, 4 pratimas). */
function gen_cross_out_number(int $i, int $max): array
{
    $figMax = gen_fig_max($max);
    $cards = [];
    $answer = [];
    $mistakes = gen_int(1, 2);
    $wrongAt = [];
    while (count($wrongAt) < $mistakes) {
        $slot = gen_int(0, 3);
        if (!in_array($slot, $wrongAt, true)) {
            $wrongAt[] = $slot;
        }
    }

    for ($c = 0; $c < 4; $c++) {
        $count = gen_int(1, $figMax);
        $label = (string) $count;
        if (in_array($c, $wrongAt, true)) {
            do {
                $delta = gen_int(1, 2) * (gen_int(0, 1) === 1 ? 1 : -1);
                $label = (string) ($count + $delta);
            } while ($label === (string) $count || (int) $label < 1 || (int) $label > $figMax + 2);
            $answer[] = 'k' . ($c + 1);
        }
        $cards[] = ['id' => 'k' . ($c + 1), 'figures' => gen_figures($count, gen_figure_name()), 'label' => $label];
    }

    return [
        'id' => "q{$i}",
        'type' => 'crossOut',
        'instruction' => $mistakes > 1
            ? 'Nubrauk korteles, kuriose skaičius neteisingas.'
            : 'Nubrauk kortelę, kurioje skaičius neteisingas.',
        'hint' => 'Brauk per kortelę, kuri klysta',
        'cards' => $cards,
        'answer' => $answer,
    ];
}

/** Nubrauk kortelę, kurioje ženklas neteisingas (page 17 style). */
function gen_cross_out_symbol(int $i, int $max): array
{
    $figMax = gen_fig_max($max);
    $cards = [];
    $answer = [];
    $wrongAt = gen_int(0, 2);

    for ($c = 0; $c < 3; $c++) {
        [$a, $b] = gen_pair(1, $figMax, 2);
        $symbol = $a === $b ? '=' : ($a > $b ? '>' : '<');
        if ($c === $wrongAt) {
            $symbol = gen_pick(array_values(array_diff(['>', '<', '='], [$symbol])));
            $answer[] = 'k' . ($c + 1);
        }
        $cards[] = [
            'id' => 'k' . ($c + 1),
            'compare' => ['left' => gen_side($a), 'right' => gen_side($b), 'symbol' => $symbol],
        ];
    }

    return [
        'id' => "q{$i}",
        'type' => 'crossOut',
        'instruction' => 'Nubrauk kortelę, kurioje ženklas neteisingas.',
        'cards' => $cards,
        'answer' => $answer,
    ];
}

/** Lentelė su daug palyginimų (page 15, 4 pratimas). */
function gen_compare_grid(int $i, int $max): array
{
    $rows = [];
    for ($r = 0; $r < 6; $r++) {
        [$a, $b] = gen_pair(1, $max, 2);
        $rows[] = ['left' => $a, 'right' => $b];
    }
    return [
        'id' => "q{$i}",
        'type' => 'compareGrid',
        'instruction' => 'Palygink skaičius ir įrašyk ženklą.',
        'symbols' => gen_symbols(),
        'rows' => $rows,
    ];
}

/**
 * One side of a `compareExpressions` row: `a + b` or `a − b`, never negative and
 * never bigger than what a child can still count (an addend stays inside a
 * countable group, a difference is at least 1).
 *
 * @return array{a:int,b:int,op:string,value:int}
 */
function gen_expression(int $max): array
{
    $cap = gen_addend_max($max);
    if (gen_int(0, 1) === 0) {
        [$a, $b] = gen_split(gen_int(2, $max), $cap);
        $op = '+';
    } else {
        [$a, $b] = gen_difference($max, $cap);
        $op = '-';
    }
    return ['a' => $a, 'b' => $b, 'op' => $op, 'value' => gen_op_apply($op, $a, $b)];
}

/**
 * An expression whose value is exactly `$target` (`3 + 4` or `9 − 2`), used to
 * build the "=" rows of `compareExpressions`. Null when the target cannot be
 * made (it always can for 1 <= target <= max, but the caller stays safe).
 *
 * @return array{a:int,b:int,op:string,value:int}|null
 */
function gen_expression_for(int $target, int $max): ?array
{
    $cap = gen_addend_max($max);
    $options = [];
    if ($target >= 2) {
        $options[] = '+';
    }
    if ($target >= 1 && $cap > $target) {
        $options[] = '-';
    }
    if ($options === []) {
        return null;
    }
    $op = gen_pick($options);
    if ($op === '+') {
        [$a, $b] = gen_split($target, $cap);
    } else {
        $a = gen_int($target + 1, $cap);
        $b = $a - $target;
    }
    return ['a' => $a, 'b' => $b, 'op' => $op, 'value' => $target];
}

/**
 * Suskaičiuok abi puses ir įrašyk ženklą: `3 + 4 ? 9 − 2`. Each side is an
 * addition or a subtraction of its own (random), the child compares the two
 * results with  >  <  or  = .
 *
 * Rules: a row never prints the same expression twice (that would make "=" a
 * give-away), no row repeats, every fourth row is built as an "=" case - but
 * only one of them, so "=" is never the majority.
 */
function gen_compare_expressions(int $i, int $max): array
{
    $rows = [];
    $asked = [];
    $equals = 0;

    for ($r = 1; $r <= gen_int(2, 3); $r++) {
        do {
            $left = gen_expression($max);
            $right = (gen_int(1, 4) === 1 && $equals < 1)
                ? (gen_expression_for($left['value'], $max) ?? gen_expression($max))
                : gen_expression($max);
            $symbol = $left['value'] === $right['value'] ? '=' : ($left['value'] > $right['value'] ? '>' : '<');
            $sameText = $left['a'] === $right['a'] && $left['op'] === $right['op'] && $left['b'] === $right['b'];
            $key = "{$left['a']}{$left['op']}{$left['b']}|{$symbol}|{$right['a']}{$right['op']}{$right['b']}";
        } while (isset($asked[$key]) || $sameText || ($symbol === '=' && $equals >= 1));
        $asked[$key] = true;
        if ($symbol === '=') {
            $equals++;
        }
        $rows[] = ['id' => "e{$r}", 'left' => ['a' => $left['a'], 'b' => $left['b'], 'op' => $left['op']], 'right' => ['a' => $right['a'], 'b' => $right['b'], 'op' => $right['op']]];
    }

    return [
        'id' => "q{$i}",
        'type' => 'compareExpressions',
        'instruction' => 'Suskaičiuok abi puses ir įrašyk reikiamą ženklą.',
        // Always the three signs - "≠" is not an answer to "is this more or less".
        'symbols' => ['>', '<', '='],
        'rows' => $rows,
    ];
}

/** Įrašyk teisingą skaičių (page 17 / "Ar jau moku?" style). */
function gen_write(int $i, int $max): array
{    $max = gen_max($max);
    $items = [];
    $asked = [];
    $wanted = gen_int(2, 3);
    for ($w = 1; $w <= $wanted; $w++) {
        do {
            $kind = gen_int(0, 3);
            if ($kind === 0 && $max >= 4) {
                // a + b <= max, single digit addends where possible (and never
                // more than 10 things to count in one group)
                $cap = gen_addend_max($max);
                [$a, $b] = gen_split(gen_int(2, $max), $cap);
                $prompt = "Kiek yra {$a} + {$b}?";
                $answer = $a + $b;
            } elseif ($kind === 1) {
                $n = gen_int(1, $max - 1);
                $prompt = "Koks skaičius eina po {$n}?";
                $answer = $n + 1;
            } elseif ($kind === 2) {
                $n = gen_int(2, $max);
                $prompt = "Koks skaičius eina prieš {$n}?";
                $answer = $n - 1;
            } else {
                $n = gen_int(1, $max - 2);
                $prompt = "Kuris skaičius yra didesnis už {$n} ir mažesnis už " . ($n + 2) . '?';
                $answer = $n + 1;
            }
        } while (in_array($prompt, $asked, true) || $answer < 1 || $answer > $max);
        $asked[] = $prompt;
        $items[] = ['id' => "w{$w}", 'prompt' => $prompt, 'answer' => (string) $answer];
    }

    return [
        'id' => "q{$i}",
        'type' => 'write',
        'instruction' => 'Įrašyk teisingą skaičių.',
        'items' => $items,
    ];
}

/**
 * The noun form that follows a number in Lithuanian: 2-9 (and 22-29, 32-39 ...)
 * take the plural ("3 obuolius"), while 10, 11-19 and whole tens take the
 * genitive plural ("13 obuolių"). The generator never uses 1 here, so only
 * these two forms are needed.
 */
function gen_noun_form(int $n, string $acc, string $gen): string
{
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    return ($mod10 >= 2 && $mod10 <= 9 && ($mod100 < 11 || $mod100 > 19)) ? $acc : $gen;
}

/**
 * ucfirst() for Lithuanian text: the built-in one only knows ASCII letters,
 * so "žaisliukų" would keep its lowercase ž.
 */
function gen_ucfirst(string $text): string
{
    return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
}

/**
 * Pasakojimo uždavinys (`story`): a little story with numbers in it and the
 * child writes the answer. Several variants appear:
 *   · subtraction  - "turėjo 5, atidavė 3, kiek liko?"
 *   · addition     - "turėjo 5, davė dar 3, kiek dabar turi?"
 *   · zero         - "neturėjo, bet kitas davė 2, kiek dabar turi?" (0 + b)
 *   · gift         - "neturėjo, kitas turėjo 5 ir 2 atidavė, kiek dabar turi?" (0 + b)
 *   · giftLeft     - the same story, but asking the giver ("kiek liko?") (a − b)
 * The two given numbers stay ≥ 2 (also in the zero variants), and their noun
 * form follows the number ("5 obuolius", "13 obuolių"); both "neturėjo" (zero)
 * and the question take the genitive plural ("obuolių"), which is correct
 * whatever the answer is.
 */
function gen_story(int $i, int $max, ?string $op = null): array
{
    $max = gen_max($max);
    $op = in_array($op, ['+', '-', 'zero', 'gift', 'giftLeft'], true) ? $op : gen_pick(['+', '-']);

    [$name1, $dat1, $pron1] = gen_pick(GEN_STORY_NAMES);
    do {
        [$name2, $dat2] = gen_pick(GEN_STORY_NAMES);
    } while ($name2 === $name1);
    [$acc, $gen] = gen_pick(GEN_STORY_THINGS);

    if ($op === 'zero') {
        // "{name1} neturėjo obuolių, bet {name2} davė 2 obuolius." → 0 + b
        $b = gen_int(2, $max);
        $story = "{$name1} neturėjo {$gen}, bet {$name2} davė {$b} " . gen_noun_form($b, $acc, $gen)
            . ". Kiek {$gen} dabar turi {$name1}?";
        $answer = $b;
    } elseif ($op === 'gift' || $op === 'giftLeft') {
        // "{name1} neturėjo obuolių, {name2} turėjo 5 obuolius ir 2 atidavė {dat1}."
        $a = gen_int(3, $max);
        $b = gen_int(2, $a - 1);
        $story = "{$name1} neturėjo {$gen}, {$name2} turėjo {$a} " . gen_noun_form($a, $acc, $gen)
            . " ir {$b} atidavė {$dat1}. ";
        if ($op === 'giftLeft') {
            $story .= "Kiek {$gen} liko {$dat2}?";
            $answer = $a - $b;
        } else {
            $story .= "Kiek {$gen} dabar turi {$name1}?";
            $answer = $b;
        }
    } elseif ($op === '-') {
        $a = gen_int(3, $max);
        $b = gen_int(2, $a - 1);
        $story = "{$name1} turėjo {$a} " . gen_noun_form($a, $acc, $gen)
            . '. ' . gen_ucfirst($pron1) . " atidavė {$b} " . gen_noun_form($b, $acc, $gen) . " {$dat2}. "
            . "Kiek {$gen} liko {$dat1}?";
        $answer = $a - $b;
    } else {
        $a = gen_int(2, $max - 2);
        $b = gen_int(2, $max - $a);
        $story = "{$name1} turėjo {$a} " . gen_noun_form($a, $acc, $gen)
            . ". {$name2} davė {$dat1} dar {$b} " . gen_noun_form($b, $acc, $gen) . '. '
            . "Kiek {$gen} dabar turi {$name1}?";
        $answer = $a + $b;
    }

    return [
        'id' => "q{$i}",
        'type' => 'story',
        'instruction' => $story,
        'answer' => $answer,
    ];
}

/**
 * Pasakojimo uždavinys su pasirinkimais (`storyChoice`): a comparison story
 * whose answer is one of a few buttons. Four variants:
 *   · who    - two children, "kas turi daugiau / mažiau?"
 *   · which  - one child, two different things, "ko turi daugiau / mažiau?"
 *   · equal  - "ar turi po lygiai, ar vienas turi daugiau?"
 *   · change - one child first gives some away, then the two are compared
 * The two amounts in `who`/`which`/`equal` are always different, so
 * "daugiau/mažiau" has exactly one answer.
 */
function gen_story_choice(int $i, int $max, string $variant = 'who'): array
{
    $max = gen_max($max);
    [$name1] = gen_pick(GEN_STORY_NAMES);
    do {
        [$name2] = gen_pick(GEN_STORY_NAMES);
    } while ($name2 === $name1);
    [$acc, $gen] = gen_pick(GEN_STORY_THINGS);
    [$a, $b] = gen_pair(2, $max, 0);

    switch ($variant) {
        case 'which':
            // Two different things one child has - "ko turi mažiau?"
            do {
                [$acc2, $gen2] = gen_pick(GEN_STORY_THINGS);
            } while ($gen2 === $gen);
            $ask = gen_int(0, 1) === 0 ? 'daugiau' : 'mažiau';
            $story = "{$name1} turi {$a} " . gen_noun_form($a, $acc, $gen)
                . " ir {$b} " . gen_noun_form($b, $acc2, $gen2)
                . ". Ko {$name1} turi {$ask}?";
            $won = $ask === 'daugiau' ? ($a > $b ? $gen : $gen2) : ($a > $b ? $gen2 : $gen);
            $options = [gen_ucfirst($gen), gen_ucfirst($gen2)];
            $answer = gen_ucfirst($won);
            break;

        case 'equal':
            $equal = gen_int(0, 1) === 1;
            if ($equal) {
                $b = $a;
            }
            $story = "{$name1} turi {$a} " . gen_noun_form($a, $acc, $gen)
                . ", {$name2} turi {$b} " . gen_noun_form($b, $acc, $gen)
                . ". Ar {$name1} ir {$name2} turi po lygiai {$gen}, ar vienas turi daugiau?";
            $options = ['Po lygiai', "{$name1} turi daugiau", "{$name2} turi daugiau"];
            $answer = $a === $b ? 'Po lygiai' : ($a > $b ? "{$name1} turi daugiau" : "{$name2} turi daugiau");
            break;

        case 'change':
            // One child gives some away, then the two amounts are compared.
            [$a, $b] = gen_difference($max, $max);
            $remain = $a - $b;
            do {
                $c = gen_int(2, $max);
            } while ($c === $remain);
            $ask = gen_int(0, 1) === 0 ? 'daugiau' : 'mažiau';
            $story = "{$name1} turėjo {$a} " . gen_noun_form($a, $acc, $gen)
                . " ir {$b} atidavė. {$name2} turi {$c} " . gen_noun_form($c, $acc, $gen)
                . ". Kas dabar turi {$ask} {$gen}?";
            $won = $ask === 'daugiau' ? ($remain > $c ? $name1 : $name2) : ($remain > $c ? $name2 : $name1);
            $options = [$name1, $name2];
            $answer = $won;
            break;

        case 'who':
        default:
            $ask = gen_int(0, 1) === 0 ? 'daugiau' : 'mažiau';
            $story = "{$name1} turi {$a} " . gen_noun_form($a, $acc, $gen)
                . ", {$name2} turi {$b} " . gen_noun_form($b, $acc, $gen)
                . ". Kas turi {$ask} {$gen}?";
            $won = $ask === 'daugiau' ? ($a > $b ? $name1 : $name2) : ($a > $b ? $name2 : $name1);
            $options = [$name1, $name2];
            $answer = $won;
            break;
    }

    return [
        'id' => "q{$i}",
        'type' => 'storyChoice',
        'instruction' => $story,
        'options' => $options,
        'answer' => $answer,
    ];
}

/* ------------------------------------------ addition / subtraction core -- */

/**
 * Do the maths of one equation. `+` and `−` are the only operations the
 * equation exercises use, so every builder below just picks one and shares the
 * rest of the machinery (the sum and the difference exercises are the same
 * exercise with a different sign).
 */
function gen_op_apply(string $op, int $a, int $b): int
{
    return $op === '-' ? $a - $b : $a + $b;
}

/**
 * How big one addend may get.
 *
 * The sum itself goes up to `max` (so "iki 20" really means up to 20), but a
 * single addend stays inside something a child can still count or picture:
 * at most 10 things in a group, at most 6 pips on one half of a domino.
 */
function gen_addend_max(int $max, int $cap = 10): int
{
    return max(1, min($cap, gen_max($max)));
}

/**
 * Split `$target` into two addends, each at most `$cap`.
 *
 * @return array{0:int,1:int}
 */
function gen_split(int $target, int $cap): array
{
    $low = max(1, $target - $cap);
    $high = max($low, min($cap, $target - 1));
    $a = gen_int($low, $high);
    return [$a, $target - $a];
}

/**
 * A minuend and a subtrahend whose difference is at least 1, so there is always
 * something left to count (`$cap` keeps the minuend inside a countable group,
 * exactly like `gen_split()` does for the addends).
 *
 * @return array{0:int,1:int}
 */
function gen_difference(int $max, int $cap = 10): array
{
    $a = gen_int(2, gen_addend_max($max, $cap));
    return [$a, gen_int(1, $a - 1)];
}

/**
 * Shuffle a bank so that tapping the numbers in the order they are shown (the
 * two leftmost, then the next two) is NOT a valid answer - otherwise the child
 * would not have to think at all. Subtracting is not commutative, so the pair
 * is checked in the order it is printed.
 */
function gen_shuffle_bank(array $bank, int $target, string $op = '+'): array
{
    $tries = [];
    for ($attempt = 0; $attempt < 240; $attempt++) {
        shuffle($bank);
        if (gen_op_apply($op, (int) $bank[0], (int) $bank[1]) !== $target) {
            return $bank;
        }
        $tries[implode(',', $bank)] = true;
        if (count($tries) >= 24) {
            break; // every order is trivial: nothing better to find
        }
    }
    return $bank;
}

/** Parašyk ir atlik pavaizduotus sudėties veiksmus (page 18 style). */
function gen_add_picture(int $i, int $max): array
{
    $part = gen_addend_max($max);
    [$a, $b] = gen_split(gen_int(2, $max), $part);

    // One equation per question: with a single row the two picture groups get
    // the whole card, so the figures stay big enough to count on a phone.
    return [
        'id' => "q{$i}",
        'type' => 'addPicture',
        'instruction' => 'Suskaičiuok abi grupes ir parašyk atsakymą.',
        'rows' => [['id' => 'r1', 'left' => gen_side($a), 'right' => gen_side($b)]],
    ];
}

/**
 * Įrašyk vieną trūkstamą skaičių veiksme (`[ ] − 7 = 2`): the equation prints
 * two numbers and one empty box, and the box sits on the **left** (the first
 * number) or in the **middle** (the second number) - the number the child writes
 * is never the result, otherwise it is just "count the answer" again and not a
 * search for the missing number.
 */
function gen_missing_number(int $i, int $max, ?string $op = '+'): array
{
    $op = $op === '-' ? '-' : '+';
    $cap = gen_addend_max($max);
    $rows = [];
    $asked = [];

    for ($r = 1; $r <= gen_int(2, 3); $r++) {
        do {
            if ($op === '-') {
                // a − b ≥ 1, so there is always a difference to find.
                [$a, $b] = gen_difference($max, $cap);
            } else {
                [$a, $b] = gen_split(gen_int(2, $max), $cap);
            }
            // Never "right": the missing number must not be the answer.
            $blank = gen_int(0, 1) === 0 ? 'left' : 'middle';
            $key = "{$a},{$b},{$blank}";
        } while (in_array($key, $asked, true));
        $asked[] = $key;
        $rows[] = ['id' => "m{$r}", 'a' => $a, 'b' => $b, 'blank' => $blank];
    }

    return [
        'id' => "q{$i}",
        'type' => $op === '-' ? 'missingNumberSub' : 'missingNumber',
        'instruction' => 'Įrašyk trūkstamą skaičių.',
        'rows' => $rows,
    ];
}

/**
 * Sudėtis ir atimtis viename veiksme (`3 + 4 = [ ]`, `9 − 2 = [ ]`). Every row
 * picks its own sign, and the first two rows always take different signs, so the
 * exercise really mixes both operations; only the results are asked for.
 */
function gen_mixed_ops(int $i, int $max): array
{
    $cap = gen_addend_max($max);
    $count = gen_int(2, 3);
    $first = gen_int(0, 1) === 0 ? '+' : '-';
    $rows = [];
    $asked = [];

    for ($r = 1; $r <= $count; $r++) {
        if ($r === 1) {
            $op = $first;
        } elseif ($r === 2) {
            $op = $first === '+' ? '-' : '+';
        } else {
            $op = gen_pick(['+', '-']);
        }
        do {
            [$a, $b] = $op === '-' ? gen_difference($max, $cap) : gen_split(gen_int(2, $max), $cap);
            $key = "{$a},{$b},{$op}";
        } while (in_array($key, $asked, true));
        $asked[] = $key;
        $rows[] = ['id' => "x{$r}", 'a' => $a, 'b' => $b, 'op' => $op];
    }

    return [
        'id' => "q{$i}",
        'type' => 'mixedOps',
        'instruction' => 'Suskaičiuok ir įrašyk atsakymus.',
        'rows' => $rows,
    ];
}

/** Sudėtis iš domino kauliukų (page 20 style). */
function gen_domino(int $i, int $max): array
{
    // A domino half shows at most 6 pips, so a single tile adds up to 12.
    $cap = gen_addend_max($max, 6);
    [$a, $b] = gen_split(gen_int(2, min(2 * $cap, $max)), $cap);

    return [
        'id' => "q{$i}",
        'type' => 'domino',
        'instruction' => 'Suskaičiuok taškus abiejose pusėse ir parašyk atsakymą.',
        'rows' => [['id' => 'd1', 'left' => $a, 'right' => $b]],
    ];
}

/** Pažymėk veiksmus, kuriais gautum nurodytą skaičių (page 20, task 3). */
function gen_multi_check(int $i, int $max): array
{
    $cap = gen_addend_max($max);
    $target = gen_int(2, $max);
    $wantedFits = 2;

    $fit = static fn(): array => gen_split($target, $cap);
    $miss = static function () use ($cap, $target): array {
        for ($try = 0; $try < 60; $try++) {
            $a = gen_int(1, $cap);
            $b = gen_int(1, $cap);
            if ($a + $b !== $target) {
                return [$a, $b];
            }
        }
        return [$cap, max(1, $cap - 1)];
    };

    $items = [];
    $asked = [];
    $boxes = gen_int(6, 9);
    $plan = array_merge(array_fill(0, $wantedFits, true), array_fill(0, $boxes - $wantedFits, false));
    shuffle($plan);
    foreach ($plan as $fits) {
        for ($try = 0; $try < 60; $try++) {
            [$a, $b] = $fits ? $fit() : $miss();
            if (!isset($asked["{$a}+{$b}"]) && !isset($asked["{$b}+{$a}"])) {
                break;
            }
        }
        $asked["{$a}+{$b}"] = true;
        $items[] = ['id' => 'c' . (count($items) + 1), 'a' => $a, 'b' => $b];
    }

    return [
        'id' => "q{$i}",
        'type' => 'multiCheck',
        'instruction' => "Pažymėk sudėties veiksmus, kuriais gautum skaičių {$target}.",
        'target' => $target,
        'items' => $items,
    ];
}

/** Sudėk du skaičius iš duotų skaičių, nereikalingą nubrauk (page 19, task 2). */
function gen_pick_addends(int $i, int $max): array
{
    $cap = gen_addend_max($max);
    $items = [];

    for ($it = 1; $it <= gen_int(2, 3); $it++) {
        $target = gen_int(2, $max);
        [$a, $b] = gen_split($target, $cap);
        // The third number must not make a second correct pair.
        $noise = min($target, $cap);
        for ($try = 0; $try < 60; $try++) {
            $candidate = gen_int(1, $cap);
            if ($candidate + $a !== $target && $candidate + $b !== $target) {
                $noise = $candidate;
                break;
            }
        }
        $bank = [$a, $b, $noise];
        $items[] = ['id' => "b{$it}", 'target' => $target, 'bank' => gen_shuffle_bank($bank, $target)];
    }

    return [
        'id' => "q{$i}",
        'type' => 'pickAddends',
        'instruction' => 'Sudėk du skaičius ir nubrauk tą, kurio nereikia.',
        'items' => $items,
    ];
}

/** [ ] + [ ] = [ ] + [ ] – abi pusės turi duoti tiek pat (page 19, task 3). */
function gen_equal_sums(int $i, int $max): array
{
    $items = [];

    for ($it = 1; $it <= gen_int(2, 3); $it++) {
        // 2 can only be 1 + 1, which makes every arrangement right.
        $target = gen_int(3, $max);
        $half = intdiv($target, 2);
        $a = gen_int(1, $half);
        $c = $a;
        if ($half > 1) {
            do {
                $c = gen_int(1, $half);
            } while ($c === $a);
        }
        $bank = [$a, $target - $a, $c, $target - $c];
        $items[] = ['id' => "b{$it}", 'target' => $target, 'bank' => gen_shuffle_bank($bank, $target)];
    }

    return [
        'id' => "q{$i}",
        'type' => 'equalSums',
        'instruction' => 'Sudėk skaičius taip, kad abiejų veiksmų gautum tiek pat, kiek parodyta trikampyje.',
        'items' => $items,
    ];
}

/** Kiek apskritimų dar trūksta? Nupiešk juos (page 19, task 1). */
function gen_draw_missing(int $i, int $max): array
{
    $figMax = gen_addend_max($max);
    $items = [];
    $asked = [];

    for ($it = 1; $it <= gen_int(2, 3); $it++) {
        do {
            $target = gen_int(2, $figMax);
        } while (isset($asked[$target]));
        $asked[$target] = true;
        $items[] = [
            'id' => "m{$it}",
            'target' => $target,
            'have' => gen_int(0, max(0, $target - 1)),
            // Always some spare cells, so filling every cell is not the answer.
            'cells' => min(12, $target + gen_int(1, 3)),
            'shape' => gen_figure_name(),
            'color' => gen_pick(GEN_COLORS),
        ];
    }

    return [
        'id' => "q{$i}",
        'type' => 'drawMissing',
        'instruction' => 'Langelėje parašyta, kiek apskritimų turi būti. Kiek dar trūksta? Nupiešk juos.',
        'items' => $items,
    ];
}

/* --------------------------------------------- subtraction generators -- */

/** Suskaičiuok daiktus ir parašyk atimties veiksmą (page 18 style). */
function gen_subtract_picture(int $i, int $max): array
{
    // The picture is one group of countable things, so the minuend is capped
    // at 10 just like an addend group (`right` counts the crossed out ones).
    [$a, $b] = gen_difference($max);

    return [
        'id' => "q{$i}",
        'type' => 'subtractPicture',
        'instruction' => 'Suskaičiuok daiktus ir parašyk skirtumą.',
        'rows' => [['id' => 'r1', 'left' => gen_side($a), 'right' => $b]],
    ];
}

/**
 * A third bank number that does not accidentally create a second valid
 * subtraction (in either order, because subtraction is not commutative).
 */
function gen_noise_number(array $pair, int $target, int $max): int
{
    $taken = array_flip($pair);
    $free = [];
    foreach (range(1, max(1, $max - 1)) as $candidate) {
        if (isset($taken[$candidate])) {
            continue;
        }
        $clashes = false;
        foreach ($pair as $value) {
            if (gen_op_apply('-', $candidate, $value) === $target || gen_op_apply('-', $value, $candidate) === $target) {
                $clashes = true;
                break;
            }
        }
        if (!$clashes) {
            $free[] = $candidate;
        }
    }
    return $free === [] ? max(1, min($max - 1, $target + 1)) : gen_pick($free);
}

/** Atimtis iš domino kauliukų (page 20 style). */
function gen_subtract_domino(int $i, int $max): array
{
    // A domino half shows at most 6 pips, so one tile never asks for more.
    $cap = gen_addend_max($max, 6);
    $a = gen_int(2, $cap);
    $b = gen_int(1, $a - 1);

    return [
        'id' => "q{$i}",
        'type' => 'subtractDomino',
        'instruction' => 'Suskaičiuok taškus abiejose pusėse ir parašyk skirtumą.',
        'rows' => [['id' => 'd1', 'left' => $a, 'right' => $b]],
    ];
}

/** Pažymėk atimties veiksmus, kurių atsakymas nurodytas (page 20, task 3). */
function gen_subtract_check(int $i, int $max): array
{
    $max = gen_max($max);
    // The difference must leave room for a bigger minuend (a - b = target).
    $target = gen_int(1, max(1, $max - 2));
    $wantedFits = 2;

    $fit = static function () use ($max, $target): array {
        $b = gen_int(1, max(1, $max - $target));
        return [$target + $b, $b];
    };
    $miss = static function () use ($max, $target): array {
        for ($try = 0; $try < 60; $try++) {
            $a = gen_int(2, $max);
            $b = gen_int(1, $a - 1);
            if ($a - $b !== $target) {
                return [$a, $b];
            }
        }
        return [$max, 1];
    };

    $items = [];
    $asked = [];
    $boxes = gen_int(6, 9);
    $plan = array_merge(array_fill(0, $wantedFits, true), array_fill(0, $boxes - $wantedFits, false));
    shuffle($plan);
    foreach ($plan as $fits) {
        for ($try = 0; $try < 60; $try++) {
            [$a, $b] = $fits ? $fit() : $miss();
            if (!isset($asked["{$a}-{$b}"])) {
                break;
            }
        }
        $asked["{$a}-{$b}"] = true;
        $items[] = ['id' => 'c' . (count($items) + 1), 'a' => $a, 'b' => $b];
    }

    return [
        'id' => "q{$i}",
        'type' => 'subtractCheck',
        'instruction' => "Pažymėk atimties veiksmus, kurių atsakymas yra {$target}.",
        'target' => $target,
        'items' => $items,
    ];
}

/** Atimk du skaičius iš duotų skaičių, nereikalingą nubrauk (page 19, task 2). */
function gen_subtract_pair(int $i, int $max): array
{
    $items = [];

    for ($it = 1; $it <= gen_int(2, 3); $it++) {
        [$a, $b] = gen_difference($max, GEN_MAX_LIMIT);
        $target = $a - $b;
        // The third number must not make a second correct subtraction, in any
        // order (subtracting is not commutative).
        $items[] = [
            'id' => "b{$it}",
            'target' => $target,
            'bank' => gen_shuffle_bank([$a, $b, gen_noise_number([$a, $b], $target, $max)], $target, '-'),
        ];
    }

    return [
        'id' => "q{$i}",
        'type' => 'subtractPair',
        'instruction' => 'Atimk du skaičius ir nubrauk tą, kurio nereikia.',
        'items' => $items,
    ];
}

/** [ ] − [ ] = [ ] − [ ] – abiejų veiksmų skirtumas turi būti toks pat (page 19, task 3). */
function gen_equal_differences(int $i, int $max): array
{
    $max = gen_max($max);
    $items = [];

    for ($it = 1; $it <= gen_int(2, 3); $it++) {
        // Both sides need a different subtrahend, otherwise every arrangement
        // of the same pair would be right.
        $target = gen_int(1, max(1, $max - 2));
        $b1 = gen_int(1, $max - $target);
        $b2 = $b1;
        for ($try = 0; $try < 60 && $b2 === $b1; $try++) {
            $b2 = gen_int(1, $max - $target);
        }
        $bank = [$target + $b1, $b1, $target + $b2, $b2];
        $items[] = ['id' => "b{$it}", 'target' => $target, 'bank' => gen_shuffle_bank($bank, $target, '-')];
    }

    return [
        'id' => "q{$i}",
        'type' => 'equalDifferences',
        'instruction' => 'Atimk skaičius taip, kad abiejų veiksmų skirtumas būtų, kiek parodyta trikampyje.',
        'items' => $items,
    ];
}

/** Nubrauk tiek, kad liktų nurodytas skaičius (page 19, task 1 with a −). */
function gen_cross_out_extra(int $i, int $max): array
{
    // A frame holds at most 10 circles (that is what a child can still count).
    $cap = gen_addend_max($max);
    $items = [];
    $asked = [];

    for ($it = 1; $it <= gen_int(2, 3); $it++) {
        do {
            $have = gen_int(3, max(3, $cap));
        } while (isset($asked[$have]));
        $asked[$have] = true;
        $items[] = [
            'id' => "m{$it}",
            // Cross out a few circles (1-4), never the whole frame.
            'target' => $have - gen_int(1, max(1, min(4, $have - 1))),
            'have' => $have,
            'shape' => gen_figure_name(),
            'color' => gen_pick(GEN_COLORS),
        ];
    }

    return [
        'id' => "q{$i}",
        'type' => 'crossOutExtra',
        'instruction' => 'Turi likti tiek, kiek parašyta. Nubrauk, kiek reikia atimti.',
        'items' => $items,
    ];
}

/* --------------------------------------------------------------- the quiz -- */

/** Lithuanian plural for the subtitle (1 užduotis / 2 užduotys / 10 užduočių). */
function gen_plural(int $n, string $one, string $few, string $many): string
{
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 === 1 && $mod100 !== 11) {
        return $one;
    }
    if ($mod10 >= 2 && $mod10 <= 9 && ($mod100 < 11 || $mod100 > 19)) {
        return $few;
    }
    return $many;
}

/**
 * Build a quiz out of the requested question types.
 *
 * The plan is one builder per selected type (the picker shows one chip per type),
 * cycled when more tasks are asked for than the plan has entries. When every type
 * is selected the quiz is the old "mixed" one.
 *
 * @param list<string> $types  question types (empty = all of them)
 * @param int          $max    highest amount used (5, 10 or 20)
 * @param int          $tasks  how many tasks the quiz should have (0 = one round of the plan)
 * @param string       $focus  the preset name this came from, if any (keeps the old ids)
 */
function quiz_generate_types(array $types, int $max = 10, int $tasks = 0, string $focus = ''): array
{
    $max = gen_max($max);
    $types = gen_clean_types($types);
    if ($types === []) {
        $types = gen_all_types();
    }
    $all = $types === gen_all_types();

    // One bucket of builders per selected type.
    $buckets = [];
    foreach ($types as $type) {
        $buckets[$type] = GEN_TYPE_BUILDERS[$type];
    }

    // Default amount = every builder of every selected type once (unchanged):
    // `?types=domino` alone still gives exactly one exercise.
    $default = 0;
    foreach ($buckets as $builders) {
        $default += count($builders);
    }

    // A BALANCED plan: the selected types take turns, each cycling through its
    // own builders, so every type ends up with (almost) the same number of
    // questions - a type with three builders must not fill most of the quiz
    // just because it was ticked next to a type with a single builder. The type
    // order is reshuffled for every round, so the questions are interleaved in a
    // random order instead of being grouped by type.
    $rounds = 1;
    foreach ($buckets as $builders) {
        $rounds = max($rounds, count($builders));
    }
    $makePlan = static function () use ($types, $buckets, $rounds): array {
        $plan = [];
        for ($round = 0; $round < $rounds; $round++) {
            $order = $types;
            shuffle($order);
            foreach ($order as $type) {
                $builders = $buckets[$type];
                $plan[] = $builders[$round % count($builders)];
            }
        }
        return $plan;
    };
    $plan = $makePlan();

    // The plan sets the flavour of the quiz; asking for more tasks than the plan
    // has cycles through it again (new random numbers every time), asking for
    // fewer simply stops earlier. Every refill is itself a balanced plan, so a
    // longer quiz stays level too.
    $wanted = $tasks > 0 ? min(GEN_TASKS_LIMIT, max(1, $tasks)) : $default;
    $questions = [];
    $pool = $plan;
    $i = 1;
    while (count($questions) < $wanted) {
        if ($pool === []) {
            $pool = $makePlan();
        }
        [$builder, $argument] = array_shift($pool);
        $questions[] = $argument === null ? $builder($i, $max) : $builder($i, $max, $argument);
        $i++;
    }

    $focus = strtolower(trim($focus));
    if ($focus === '') {
        $focus = gen_focus_for_types($types);
    }
    $id = 'generated-' . ($focus !== '' ? $focus : ($all ? 'mixed' : 'custom-' . implode('-', $types)));
    [$title, $emoji, $color] = $focus !== '' && isset(GEN_FOCUS_LOOK[$focus])
        ? GEN_FOCUS_LOOK[$focus]
        : gen_look($types, $all);

    $count = count($questions);

    return [
        'id' => $id,
        'title' => $title,
        'subtitle' => 'Sugeneruota pagal taisykles · skaičiai iki ' . $max . ' · ' . $count . ' '
            . gen_plural($count, 'užduotis', 'užduotys', 'užduočių'),
        'emoji' => $emoji,
        'color' => $color,
        // Everything here is made for the first class (the app groups by class).
        'grade' => 1,
        'focus' => $focus !== '' ? $focus : ($all ? 'mixed' : 'custom'),
        'types' => $types,
        'max' => $max,
        'tasks' => $count,
        'questions' => $questions,
    ];
}

/**
 * Build a whole quiz of one kind (the old presets: compare | count | draw | sum | mixed).
 *
 * @param string $focus compare | count | draw | sum | mixed
 * @param int    $max   highest amount used (5, 10 or 20)
 * @param int    $tasks how many tasks the quiz should have (0 = all of the focus plan)
 */
function quiz_generate(string $focus = 'mixed', int $max = 10, int $tasks = 0): array
{
    $focus = strtolower(trim($focus));
    if (!in_array($focus, GEN_FOCUSES, true)) {
        $focus = 'mixed';
    }
    if ($focus === 'mixed') {
        return quiz_generate_types(gen_all_types(), $max, $tasks, '');
    }
    return quiz_generate_types(gen_focus_types($focus), $max, $tasks, $focus);
}

/**
 * Several fresh quizzes in one go (the "kiek testų" selector, 1-20).
 *
 * @param list<string>|string $types question types, or a focus name for the old links
 * @return list<array>
 */
function quiz_generate_batch(mixed $types = [], int $max = 10, int $count = 1, int $tasks = 0, string $focus = ''): array
{
    $count = max(1, min(GEN_BATCH_LIMIT, $count));
    // Do not build absurd amounts of JSON in one response.
    if ($tasks > 0 && $count * $tasks > GEN_BATCH_TASKS_LIMIT) {
        $tasks = (int) floor(GEN_BATCH_TASKS_LIMIT / $count);
    }
    if (is_string($types) && $focus === '') {
        // Backwards compatible call: quiz_generate_batch('draw', ...)
        $focus = strtolower(trim($types));
        $types = gen_focus_types($focus);
        if (!in_array($focus, GEN_FOCUSES, true)) {
            $focus = 'mixed';
        }
    }
    $batch = [];
    for ($i = 0; $i < $count; $i++) {
        $batch[] = quiz_generate_types(is_array($types) ? $types : [], $max, $tasks, $focus);
    }
    return $batch;
}

/**
 * Apply the generator's two INDEPENDENT read-aloud switches.
 *
 * Backs the two "įgarsinti" checkboxes in the picker (both off by default):
 *  - $others: the global one next to "Užduotys: visos" - every question that
 *    is NOT a story problem (true = voice it, false = silence it);
 *  - $story:  the one inside the Pasakojimai group - the story/storyChoice
 *    problems only (they are silent by default: a word problem is meant to be
 *    READ, so this is what voices them).
 *
 * A null argument keeps that group's own default (a speaker everywhere but
 * stories).
 *
 * @param  list<array>  $quizzes  modified in place (questions get `say`)
 * @param  bool|null    $others   voice/silence the non-story questions
 * @param  bool|null    $story    voice/silence the story questions
 */
function gen_voice_apply(array &$quizzes, ?bool $others, ?bool $story): void
{
    foreach ($quizzes as &$quiz) {
        if (!isset($quiz['questions']) || !is_array($quiz['questions'])) {
            continue;
        }
        foreach ($quiz['questions'] as $index => $question) {
            $isStory = in_array($question['type'] ?? '', ['story', 'storyChoice'], true);
            $value = $isStory ? $story : $others;
            if ($value !== null) {
                $quiz['questions'][$index]['say'] = $value;
            }
        }
    }
    unset($quiz);
}
