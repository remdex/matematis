# 🧮 Matematis — JSON quiz player for 7-year-olds

A tiny, JSON-driven maths quiz player for first-graders, built from the exercises of
the Lithuanian workbook pages 14-31 (compare amounts with `>` `<` `=`, count objects,
compare two equations — `3 + 4 ? 9 − 2`, **circle** the right card, **cross out** the
wrong one, write the number, and seven kinds
of addition exercise — from a picture, from a domino, from the one empty box of an
equation, from a number bank, balanced sums, ticking the sums that fit, and joining a
sum to its picture — each of them with a subtraction twin, so adding and taking away are
practised the same way).

* Live: **<https://math.qu.lt>** (PHP 8.5 + nginx)
* Front end: **Tailwind CSS** (compiled, no CDN) + **Alpine.js** (self hosted ESM) + one
  small renderer framework written for this project (`assets/js/quiz-render.js`)
* Typography: **Nunito Sans** loaded straight from Google Fonts via `<link>` in
  `index.php` (preconnect + `css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,200..1000;1,6..12,200..1000`)
* No build step is needed to *add quizzes* — only for CSS (see [Rebuilding CSS](#rebuilding-the-css))

---

## 1. How it works

```
index.php            single entry point: page + /api/* JSON API (PHP)
lib/Quizzes.php      reads data/quizzes/*.json, validates author JSON
lib/Generator.php    generates a brand-new quiz on every request (✨ button)
lib/Tts.php          ElevenLabs voice for the instructions + the mp3 disk cache
settings.ini.default.php  settings template (copy to settings.ini.php, §4)
data/quizzes/*.json  the quizzes (this *is* the content)
cache/tts/*.mp3      the spoken instructions, generated once (see §4)
assets/css/input.css Tailwind source  ──build──▶ assets/css/app.css (shipped)
assets/js/quiz-render.js  renderer core: type registry, normalisation, answers
assets/js/quiz-types.js   the 26 question types (compare, count, addPicture, subtractPicture, …)
assets/js/scribble.js     finger/mouse drawing layer with hit testing
assets/js/figures.js      SVG figure library (shapes, objects, colours)
assets/js/app.js          Alpine app shell: picker → play → builder
assets/js/speech.js       the 🔊 button: talks to /api/tts and plays the answer
assets/js/confetti.js     confetti when every answer is right
```

Screens: **home** (tests grouped by class, 🎲 random, ✨ generator with an exercise
picker, ✏️ builder) → **play**
(all questions of the quiz on one page, checking buttons at the end) → **builder**.

Every screen has its own address and its own history entry: `/` (picker), `/t/<id>`
(one quiz - shareable, `/t/generated-mixed` included) and `/kurti` (builder). The browser
**Back button therefore walks through the app** (next quiz → previous quiz → home)
instead of leaving the page. Forward rebuilds what it can: a generated quiz that is no
longer in memory is generated again from the same settings (same kind of quiz, fresh
numbers, `batchQuery`/`batchPos` are stored in the history state). A deep link keeps its
URL and leaves the picker behind it, so Back leads home instead of out of the site.

### Which class is a quiz for?

Every quiz states the class it is written for (`"grade": 1`), and the home screen groups
the cards by class (**1 klasė**, **2 klasė**, …). Right now every test belongs to the
first class. Quizzes without `grade` land in the 1st class group (the builder warns
about it), which keeps older files working.

### Kid-friendly choices

| Choice | Why |
| --- | --- |
| Every question of a quiz on **one page**, like a workbook spread | the child sees how much is left and can go back any time |
| **Three checking buttons** at the bottom (sticky), all checking the whole quiz at once | nothing is judged in the middle of the work - no accidental "wrong!", and the child can choose how much help to take |
| · `📊 Kiek teisingai?` | only says the score ("Teisingai: 6 / 8 · klaidingai: 2"); nothing is marked, the boxes stay editable |
| · `🖍️ Pažymėti klaidas` | marks the wrong cards red, but does not give the answer away |
| · `✓ Tikrinti viską` | marks the wrong cards and reveals the right answer |
| Progress bar `atsakyta / viso` | visible progress, no score pressure |
| Own number pad (1–9, wide 0, ⌫) per counting question | the phone keyboard would cover the question |
| After checking: correct cards stay clean, wrong ones turn red and show the answer (circled/crossed cards are marked in blue) | teaches, instead of just scoring |
| `📊 Kiek teisingai?` on a perfect run celebrates like a full check (result card, stars, confetti) | there is nothing left to fix |
| `🔁 Bandyti iš naujo` clears everything for another go | practice as often as wanted |
| Confetti + best stars per quiz in `localStorage` (`mq.best`) | reward with visual noise instead of sounds |
| **🔊 next to every instruction** (when the server has an ElevenLabs key) | the child does not have to read: the task (and its hint) is read out loud - see §4 |
| Keyboard: digits, `Backspace`, `Enter` (= `✓ Tikrinti viską`) | works on a laptop too |
| **🖨️ Spausdinti** (play screen) | prints the quiz on screen as a black & white worksheet - see below |

### Print version

The `🖨️ Spausdinti` button at the end of the play screen opens the browser's print
dialog. Nothing is re-rendered: the play screen already *is* a worksheet (one framed box
per question, figures drawn, empty answer boxes in place), so the `@media print` rules in
`assets/css/input.css` only strip it down - the header, footer, sticky bars, checking
buttons, number pads, `> < =` pickers, 🔊 buttons and the drawing layer disappear, while
everything that stays is forced to plain black outlines on white (figures get
`filter: grayscale(1)`) and each question card keeps itself on one page
(`break-inside: avoid`). A task has **no box around it**: the cards lose their border,
round corners and padding entirely and the tasks are separated by a **1px dashed rule**
(`.question-card + .question-card { border-top: 1px dashed #000; margin/padding-top: 3.5mm }`),
which is much lighter and saves a lot of vertical space. A heading with `Vardas:` /
`Data:` lines is printed above the first question (the `.print-only` block).

Answer boxes print **empty**: the `?` placeholder of an unanswered `.slot` /
`.symbol-slot` (and the small compare count chip) is hidden, so the child writes the
number or sign into a plain frame by hand. A box that already holds an answer still
prints it.

They are also **small** (42px for a number, 34px for a sign) - a handwritten 1-2 digit
answer needs no more - and the rows of a pure calculation exercise (`mixedOps`,
`missingNumber`, `compareExpressions`) **flow next to each other** instead of one row per
line, so a whole question often fits on a single line. Rows that carry a picture keep the
full width (they are the only ones containing an `.eq-group`).

Choice rows (`.check-list .check-item` - used by `story` choice and by `multiCheck` /
`subtractCheck`) print as plain `[ ] option` lines: the **small tick box stays** (that is
how the answer is marked) and the big frame drawn around every option is dropped, which
keeps the list compact.

The sheet is **compact**: `@media print` lowers the root font size
(`html { font-size: 70% }`). Every widget size is written in `rem`/`clamp()`, so this one
number shrinks the answer boxes, the figures, the banks and the text together - a 24
task quiz goes from ~11 pages to ~7 on A4. Lower it (e.g. `62%`) for even denser pages,
raise it for bigger letters; tasks are separated by a dashed rule with 2mm around it and
the page margin is `10mm`. With all of the above a 12 task story quiz prints on **2** A4
pages and an 8 task one on a **single** page (~6-8 tasks a page).
> Gotcha: the app shell must be targeted as `body > .wrap > header` (/`footer`) - a bare
> `header` selector also matches the `<header class="question-head">` of every question
> and hides all the instructions in print.

This works from any browser print (Ctrl/Cmd+P on the play screen) as well - the button
only makes it discoverable.

---

## 2. Quiz JSON

A quiz is one file in `data/quizzes/<id>.json`. Only `type` and the type-specific
fields are required — instructions, answers, colours and titles get sensible defaults.

```json
{
  "id": "palygink-figuras",
  "title": "Palygink figūras",
  "subtitle": "Įrašyk ženklą  >  <  =",
  "emoji": "⚖️",
  "color": "sky",
  "grade": 1,
  "questions": [ { "type": "compare", "...": "..." } ]
}
```

* `id` — lowercase slug, also the file name (defaults to the file name)
* `emoji`, `color` — shown on the picker card (`sky`, `rose`, `emerald`, … 18 colours)
* `grade` — the school class (1–6) the quiz is made for; the picker groups by it
* `questions[]` — see below

### Common question fields

| Field | Meaning |
| --- | --- |
| `type` | one of the 26 types below |
| `instruction` | the big text at the top (also read aloud) |
| `hint` | small grey line under the instruction |
| `answer` | optional — for most types it is **calculated** from the numbers, so you usually omit it (only `outline`, `crossOut` and an ambiguous `match` need it) |

### 2.1 `compare` — two groups and a symbol

```json
{
  "type": "compare",
  "instruction": "Suskaičiuok figūras ir įrašyk reikiamą ženklą.",
  "left":  { "kind": "figures", "shape": "square", "count": 3, "color": "amber" },
  "right": { "kind": "figures", "shape": "square", "count": 2, "color": "amber" }
}
```

* `left` / `right` — a shortcut is allowed: `"left": 7` or `"left": { "shape": "star", "count": 5 }`
* `{ "kind": "number", "value": 7 }` shows a big digit instead of figures
* `symbols` — which buttons the child may choose: default `[">","<","="]`, or `["=","≠"]`
* the answer is derived from the two amounts (with `["=","≠"]` different amounts mean `≠`)

### 2.2 `compareGrid` — many comparisons at once

```json
{ "type": "compareGrid", "symbols": [">", "<", "="],
  "rows": [ { "left": 3, "right": 8 }, { "left": 7, "right": 7 } ] }
```

### 2.3 `count` — count and write the number

```json
{ "type": "count", "instruction": "Parašyk, kiek yra daiktų.",
  "scenes": [
    { "id": "s1", "figures": { "shape": "eraser", "count": 6, "color": "rose" } },
    { "id": "s2", "figures": { "shape": "soap",   "count": 6, "color": "sky"  } }
  ] }
```

### 2.4 `countAndCompare` — count both sides, then compare

```json
{ "type": "countAndCompare",
  "left":  { "figures": { "shape": "pen",    "count": 8, "color": "indigo" } },
  "right": { "figures": { "shape": "eraser", "count": 4, "color": "rose"   } } }
```

### 2.5 `outline` — circle the right card (SVG drawing)

```json
{ "type": "outline",
  "instruction": "Apibrauk kortelę, kurioje figūrų yra daugiau.",
  "cards": [
    { "id": "k1", "figures": { "shape": "triangle", "count": 4, "color": "amber" } },
    { "id": "k2", "figures": { "shape": "triangle", "count": 6, "color": "amber" } }
  ],
  "answer": ["k2"] }
```

### 2.6 `crossOut` — cross out the wrong card

Same shape as `outline`, but the child draws a line **through** the wrong card(s):

```json
{ "type": "crossOut",
  "instruction": "Nubrauk korteles, kuriose skaičius neteisingas.",
  "cards": [
    { "id": "k1", "figures": { "shape": "triangle", "count": 4, "color": "emerald" }, "label": "4" },
    { "id": "k2", "figures": { "shape": "circle",   "count": 5, "color": "sky"     }, "label": "3" }
  ],
  "answer": ["k2"] }
```

A card can contain `figures` **and/or** a `label` (a number or a symbol) **or** a whole
comparison `{ "compare": { "left": …, "right": …, "symbol": ">" } }` — that is how the
“nubrauk netinkamą ženklą” exercise is built.

| Card field | Meaning |
| --- | --- |
| `id` | required, unique inside the question, used by `answer` |
| `figures` | group of figures (see below) |
| `label` | text/number under the figures |
| `compare` | `{ left, right, symbol }` shown as two mini groups + a symbol |
| `answer` | array of card ids that are correct — **required** |

### 2.7 `write` — write the answer

```json
{ "type": "write",
  "items": [
    { "id": "w1", "prompt": "Koks skaičius eina prieš 10?", "answer": 9 },
    { "id": "w2", "prompt": "Kiek yra 4 + 3?", "answer": 7 }
  ] }
```

Every item is one condition, and every condition gets its own small 🔊 next to the
prompt (the instruction above it has the big one) — see §4. If there is exactly one item
and no `prompt`, no button is added (nothing to read).

### 2.8 `addPicture` / `subtractPicture` — write the equation of a picture

The workbook's "Parašyk ir atlik pavaizduotus sudėties veiksmus": a picture of two
groups, then `[ ] + [ ] = [ ]`.

```json
{ "type": "addPicture",
  "rows": [
    { "left": { "shape": "triangle", "count": 4, "color": "amber" },
      "right": { "shape": "square", "count": 1, "color": "sky" } }
  ] }
```

* `left` / `right` — a figure group, exactly like the `compare` sides (emoji work too)
* `write` — `"all"` (default, the child writes both groups and the sum) or `"result"`
  (the two addends are printed, only the sum is asked for)
* the answer (`4`, `1`, `5`) is derived from the picture
* **one equation per question**: give `rows` a single row. With several rows the pictures
  (and the domino pips) shrink until they cannot be counted on a phone any more, so the
  validator warns (`data/quizzes/sudetis-iki-10.json` has one row per card).

`subtractPicture` is the same exercise with a `−`: `left` is the group the child sees and
`right` is **how many of them are crossed out**, so only `left` is drawn (one group, its
last `right` figures struck through) and the equation becomes `[ ] − [ ] = [ ]`
(`{ "left": { "shape": "star", "count": 6, "color": "amber" }, "right": 2 }` asks for
`6 − 2 = 4`). `right` may be a plain number or a figure group — only its count is used.
A `right` that is not smaller than `left` would leave nothing, so the validator warns.

### 2.9 `domino` / `subtractDomino` — one tile, one equation

Same boxes as `addPicture`, but the picture is one domino tile: the two halves are the
addends, so `{ "left": 4, "right": 6 }` asks for `4 + 6 = 10`.

```json
{ "type": "domino", "write": "result",
  "rows": [ { "left": 3, "right": 2 } ] }
```

`left` / `right` are pip counts **0-6**; the answer is derived. The tile is drawn large
(pips of ~16 px on a phone) and, as with `addPicture`, it should be the only row of the
question — several dominoes in one card make the pips unreadable.

`subtractDomino` reads the same tile as `[ ] − [ ] = [ ]` (`3` and `2` pips → `3 − 2 = 1`);
put the bigger half in `left`, otherwise the difference is negative (the validator warns).

### 2.10 `multiCheck` / `subtractCheck` — tick every equation that fits

"Pažymėk sudėties veiksmus, kuriais gautum skaičių 8": a grid of sums with a tick box,
the child marks every one that equals `target` (any number of them).

```json
{ "type": "multiCheck", "target": 8,
  "items": [ { "a": 4, "b": 4 }, { "a": 3, "b": 3 }, { "a": 2, "b": 6 } ] }
```

Optional `text` replaces the printed `a + b`. The answer (the ids of the fitting sums)
is derived.

`subtractCheck` is the same with `a − b`, `target` is the answer the subtraction must
have (`{ "a": 6, "b": 3 }` fits `target: 3`) and the printed default is `a − b`. An
`a` smaller than `b` would go below zero, so the validator warns about it.

### 2.11 `pickAddends` / `subtractPair` — build an equation from the given numbers

"Atlik sudėties veiksmus. Nereikalingą skaičių nubrauk": `[ ] + [ ] = 3` plus a bank of
three numbers. Tapping a chip drops it into the next free box; when both boxes are
filled the leftover number is crossed out on its own.

```json
{ "type": "pickAddends",
  "items": [ { "target": 3, "bank": [1, 2, 2] }, { "target": 4, "bank": [2, 1, 2] } ] }
```

The check is the **arithmetic**: any pair of bank numbers that adds up to `target` is
accepted (a bank with two solutions is fine). The validator warns when the two
leftmost numbers already make the answer — the child should have to pick.

`subtractPair` asks for `[ ] − [ ] = target`, so the **order matters**: the child has to
put the bigger number in the first box (`target: 3` with the bank `[2, 5, 1]` is solved by
`5 − 2`). A third bank number that would make a second correct subtraction (in either
order) is not generated, and the validator warns about it.

### 2.12 `equalSums` / `equalDifferences` — `[ ] + [ ] = [ ] + [ ]`

"Taip įrašyk skaičius, kad abiejų veiksmų gautum trikampyje parašytą skaičių": the same
chip bank, but four boxes and the target shown in a triangle; both sides must equal it.

```json
{ "type": "equalSums", "items": [ { "target": 4, "bank": [3, 2, 1, 2] } ] }
```

Both sides must be built by the child: the validator warns when the numbers in the
order they are shown already form a valid split anyway.

`equalDifferences` puts a `−` in both sides (`[ ] − [ ] = [ ] − [ ]`) — the bank of
`target: 2` with `[1, 3, 5, 3]` is solved by `3 − 1 = 5 − 3`.

### 2.13 `drawMissing` / `crossOutExtra` — draw or cross out circles

"Kiek apskritimų dar trūksta? Nupiešk juos": a box with the number that must be there,
some circles already drawn, and empty cells the child taps to draw the rest.

```json
{ "type": "drawMissing",
  "items": [ { "target": 3, "have": 1, "cells": 6, "shape": "circle", "color": "sky" } ] }
```

* `have` — how many are already there (those cells are locked)
* `cells` — how many cells the frame shows. **Give it more cells than `target`**, otherwise
  the child only has to fill every free cell and never counts. Without `cells` the frame
  gets `max(target + 2, 4)` cells; the validator warns when `cells` is not bigger than
  `target`.
* the check counts the circles actually in the frame against `target` — drawing too many
  is wrong too

`crossOutExtra` is the subtraction twin ("Turi likti tiek, kiek parašyta"): the frame is
**full** of circles and the child crosses out as many as have to go, so `have` is what is
drawn and `target` is what has to stay (`{ "target": 3, "have": 6 }` → cross out 3). The
check counts the crossed out circles, so which ones are taken does not matter; a `target`
that is not smaller than `have` leaves nothing to do and the validator warns.

### 2.14 `missingNumber` / `missingNumberSub` — fill in the one empty box

The workbook's "Įrašyk trūkstamą skaičių": an equation with exactly **one** empty box,
printed in the run of the numbers. The box sits on the **left** (the first number) or in
the **middle** (the second number) — the number the child writes is **never the result**,
otherwise it is only "count the answer" and not a search for the missing number:

```json
{ "type": "missingNumberSub",
  "rows": [ { "a": 9, "b": 7, "blank": "left" },
            { "a": 8, "b": 1, "blank": "middle" } ] }
```

Those two rows print `[ ] − 7 = 2` (the answer is `9`) and `8 − [ ] = 1` (the answer is `7`).

* `a` / `b` — the two numbers of the equation (the result is computed from them)
* `blank` — where the empty box is: `"left"` (default) or `"middle"`. `"right"` (the
  result) is there in case a hand-written quiz wants it, but then the missing number
  *is* the answer and the validator warns about it. The generator only uses `left` and
  `middle`, picked at random
* there is **one** number to type per row, and all boxes of the question share one
  number pad; no picture shrinks the equation, so several rows in one question are fine
* the answer (the number of the empty box) is derived; the validator warns when a
  subtraction has `a <= b` (the difference would be 0 or negative)

`missingNumber` is the same with a `+` (`[ ] + 3 = 8`, `4 + 6 = [ ]`).

### 2.15 `match` — join a sum to its picture (drawing)

The child **draws a line** from a sum in the left column to the picture in the right
column (drawing over a card is not needed — the layer only looks at where the line
starts and ends). Tap a joined card to remove its line.

```json
{ "type": "match", "result": true,
  "left":  [ { "id": "l1", "a": 2, "b": 3 }, { "id": "l2", "a": 4, "b": 1 } ],
  "right": [
    { "id": "r1", "left": { "shape": "✏️", "count": 4 }, "right": { "shape": "🖍️", "count": 1 } },
    { "id": "r2", "left": { "shape": "🐟", "count": 2 }, "right": { "shape": "🐟", "count": 3 } }
  ] }
```

* `result: true` also adds a box after every sum (`2 + 3 = [ ]`) — the same number pad
  is shared by all of them
* the pairs are **derived** from the sums and the picture groups when every sum fits
  exactly one picture; otherwise write them out: `"answer": ["l1>r4", "l2>r1"]`
* `left` items may also be written as `{ "id": "l1", "sum": [2, 3] }`
* `op: "-"` turns the whole question into a subtraction: the left cards read
  `6 − 2 = [ ]`, the right card is drawn as a take-away picture (the `left` group with
  its last `right` figures crossed out) and the pictures are matched **by the exact
  pair** (`6 − 2` never matches a picture of `5 − 1`, even though both leave 4).
  Adding matches a picture with the same two numbers in any order.

```json
{ "type": "match", "op": "-", "result": true,
  "left":  [ { "id": "l1", "a": 6, "b": 2 } ],
  "right": [ { "id": "r1", "left": { "shape": "🍎", "count": 6 },
                "right": { "shape": "🍎", "count": 2 } } ] }
```

### 2.16 `compareExpressions` — `n + n ? n − n`

Suskaičiuok abi puses ir įrašyk ženklą: every side is a small equation of its own
(an addition **or** a subtraction, both sides independent), and the child compares the
two results with `>` `<` or `=`:

```json
{ "type": "compareExpressions",
  "symbols": [">", "<", "="],
  "rows": [
    { "id": "e1", "left": { "a": 3, "b": 4, "op": "+" },
                "right": { "a": 9, "b": 2, "op": "-" } },
    { "id": "e2", "left": { "sum": [6, 3] },
                "right": { "a": 10, "b": 3, "op": "-" } }
  ] }
```

The first row prints `3 + 4 ? 9 − 2` (both sides are 7, so the answer is `=`), the
second one `6 + 3 ? 10 − 3` (9 > 7, so `>`).

* a side is `{ a, b, op }` with `op` = `+` (default) or `-`, or the `sum: [a, b]`
  shortcut; the result of a side may not go below 1, so `a` has to be bigger than `b`
  in a `-` side (the validator warns)
* the answer is derived: the sign belongs between the two results
* the empty box is filled by **one row of sign buttons** shared by the whole question
  (like the number pad): tapping a `?` box says which row the next sign goes into, and
  the game jumps to the next empty box on its own — so the equations can stay big
* several rows in one question are normal (2-3 are generated): there is no picture whose
  size would suffer, and the equations stay big on a phone
* this is the "next step" after `countAndCompare` — there the amounts are counted from a
  picture, here both sides first have to be calculated

The generator builds it from the **Veiksmai** group (see §7);
`data/quizzes/palygink-veiksmus.json` is a hand written example.

### 2.17 `mixedOps` — sudėtis ir atimtis viename veiksme

"Sudėtis ir atimtis kartu": the same equation as `missingNumber`, but every row picks
its own sign, so addition and subtraction come mixed inside one exercise. Both numbers
are printed and the child writes only the result:

```json
{ "type": "mixedOps",
  "rows": [ { "a": 5, "b": 3, "op": "+" },
            { "a": 9, "b": 4, "op": "-" },
            { "a": 2, "b": 6, "op": "+" } ] }
```

Those rows print `5 + 3 = [ ]`, `9 − 4 = [ ]` and `2 + 6 = [ ]` (answers `8`, `5`, `8`).

* `a` / `b` — the two numbers of the equation (the result is computed from them)
* `op` — `"+"` (default) or `"-"`. A `-` whose `a <= b` would go below zero, so it is
  read as a `+` (in JS **and** PHP) and the validator warns about it
* there is **one** number to type per row, and all boxes of the question share one
  number pad; no picture shrinks the equation, so several rows in one question are fine
* the answer is derived (the results, in row order) and the check is **positional**:
  unlike most types it may not ignore the order, otherwise a wrong row could hide behind
  a duplicate result (`8 5 8` is not the same answer as `9 5 8`)

The generator builds it (2-3 rows per question, the first two rows always take different
signs) from the **Skaičiavimas** group; `data/quizzes/misrus-veiksmai.json` is a hand
written example.

### 2.18 `story` — a word problem with one answer

"Pasakojimo uždavinys": a short story asks a question (who has more, which is fewer,
how many are missing, …) and the child gives exactly **one** answer. The story *is* the
question's `instruction`, and every card has a single answer control.

**A story is meant to be READ, so it has NO 🔊 button by default** — a word problem that
is read out loud is no longer a reading exercise. Add `"say": true` to a story question
to give it the read-aloud button anyway (the generator does the same when the
**🔊 Įgarsinti** checkbox next to the story type is ticked, see §7). Every other type
keeps its button unless a question sets `"say": false`.

```json
{ "type": "story",
  "instruction": "Rūta į krepšelį įsidėjo 7 braškes, o Benas – 10 braškių. Kas surinko daugiau braškių?",
  "options": ["Rūta", "Benas"],
  "answer": "Benas" }
```

```json
{ "type": "story",
  "instruction": "Katė turi 9 žaisliukus, o šuniukas – 7. Kiek žaisliukų trūksta šuniukui, kad abu turėtų po lygiai?",
  "answer": 2 }
```

* **without `options`** the answer is a number typed on the shared number pad — one empty
  box, exactly like the `write` type; `answer` must be that number (the second example)
* **with `options`** the answer is a choice: the child taps one of the buttons (the
  options may be plain strings, or `{ "id": …, "label": … }` objects). `answer` names
  the correct option by its label or (safer) by its id
* the answer cannot be derived from the numbers, so `answer` is always required — the
  validator reports a missing one

`storyChoice` is the same widget, but meant for the choice-style comparison stories
("Kas surinko daugiau?"); it only exists so the generator can offer those under their
own chip. Hand-written quizzes can use `story` for both kinds.

`data/quizzes/pasakojimu-uzdaviniai.json` is the worked example (the stories from
the workbook, nine of them a choice and four a number). The generator builds
stories of its own from the **Pasakojimai** group (see §7): number-answer ones with
`story` and choice ones with `storyChoice`.

### Figures (group spec)

```json
{ "shape": "star", "count": 7, "color": "amber" }
```

Shapes: `triangle square circle oval diamond pentagon hexagon star heart cross ball
apple flower fish bug candy carrot leaf pencil pen crayon eraser soap sponge lollipop
butterfly cloud`. Everything is inline SVG painted with `currentColor`, so any palette
name works: `sky blue indigo violet purple fuchsia pink rose red orange amber yellow
lime green emerald teal cyan slate`.

**Emoji work as figures too** — write the glyph directly in `shape` or use the `emoji`
key, and it is drawn as SVG text, so it scales exactly like the drawn shapes:

```json
{ "emoji": "🍎", "count": 4 }
{ "shape": "🍎", "count": 4 }
```

Available emoji (see `assets/js/figures.js`): 🍎 🍏 🍌 🍓 🍇 🍊 🥕 🍪 🧁 🎈 ⭐ ❤️ 🌼 🌈 🐟 🐞 🦋 🐣 🐢 🐝 ⚽ 🎲 🚗 🎁 🧩 🖍️ ✏️ 🔵 ⭕ 🔺
Colours simply do not apply to emoji.

---

## 3. API (PHP)

| Request | Result |
| --- | --- |
| `GET /api/list` | `{ "quizzes": [ { id, title, subtitle, emoji, color, count } ] }` |
| `GET /api/quiz/<id>` | the quiz JSON (404 when unknown) |
| `GET /api/random?exclude=<id>` | a random existing quiz (🎲 button) |
| `GET /api/generate?types=a,b,c&max=5|10|20&count=1…20&tasks=1…50` | `{ "quizzes": [ … ] }` — freshly generated quizzes made of exactly those question types (`types` empty/unknown = every type). Two independent read-aloud switches: `&say=1` voices every **non-story** question, `&sayStory=1` voices the **story** problems; both off by default (omitting one leaves that group at its own default) |
| `GET /api/generate?focus=compare|count|draw|sum|sub|mixed&…` | the older preset form; each focus is a shorthand for a list of types (see §7) |
| `POST /api/validate` | validates author JSON → `{ ok, errors[], warnings[], quiz }` (builder screen) |
| `GET /api/tts?status=1` | `{ enabled, voice_id, model_id, cached_sentences, max_chars, … }` — no secrets; the page hides the 🔊 buttons when `enabled` is false |
| `GET /api/tts?text=<sentence>&token=<token>` | the mp3 url of one sentence, generating it when it is new (needs the sentence's own token, see §4) |
| `POST /api/tts` `{"items":[{"text":…,"token":…}]}` | the same for up to 30 sentences in one round trip (what the play screen uses; every item needs its token) |
| `GET /api/tts?list=1` | what is already saved in `cache/tts` (key, text, bytes, created, url) |
| `GET /cache/tts/<sha1>.mp3` | the audio itself — a **static file**, nginx never sends it through PHP |

Deep links: `https://math.qu.lt/t/palygink-figuras` starts that quiz, `/kurti` opens the
builder.

Validation catches the usual mistakes: unknown `type`, missing `left`/`right`, empty
`cards`, `answer` ids that do not exist, duplicate card ids, wrong symbols, and it
warns when a hand-written `answer` disagrees with the maths.

---

## 4. Voice — the 🔊 button (ElevenLabs)

Every question card carries a 🔊 button in its head, next to the instruction. Tapping it
reads the task out loud (instruction **and** its small hint, so a child who cannot read
yet is not left alone with the exercise). In a `write` question ("Įrašyk teisingą
skaičių.") **every condition has its own small 🔊 as well** — `Kiek yra 6 + 3?` is read
as "Kiek yra 6 plius 3?".

**Every sentence is generated exactly once.** The MP3 is written to
`cache/tts/<sha1>.mp3` (the sha1 is over the sentence + voice + model + settings) and from
then on the browser downloads that static file — the second tap, the next child, a
restart of php-fpm, nothing calls ElevenLabs again. `cache/tts/<sha1>.json` keeps the
sentence itself for easy inspection, and `GET /api/tts?list=1` lists what is saved.

| Piece | What it does |
| --- | --- |
| `lib/Tts.php` | config, cache, the ElevenLabs call, the `/api/tts` endpoint |
| `assets/js/speech.js` | the button, the url cache, playback (Web Audio, `<audio>` as fallback) |
| `settings.ini.php` | the settings, incl. the API key — next to `index.php`, `.gitignore`d, denied by the vhost (see below) |
| `settings.ini.default.php` | the committed template for that file (holds no keys) |
| `cache/tts/` | the generated MP3s, served by nginx as static files (`.gitignore`d) |

### Turning it on

1. Create an API key at <https://elevenlabs.io/app/settings/api-keys>.
2. Copy the shipped template to `settings.ini.php` — next to `index.php`, the name is
already `.gitignore`d — and put the key in (`api_key` is empty in the template → the
feature is simply off):

```bash
cp settings.ini.default.php settings.ini.php
chown root:www-data settings.ini.php && chmod 640 settings.ini.php
$EDITOR settings.ini.php                 # fill in the key
```

```php
return [
  'api_key' => 'sk_…',           // empty = no 🔊 buttons at all
  'voice_id' => '',              // empty = remember the account's first voice
  'model_id' => 'eleven_v3',     // the model that speaks Lithuanian
  'language_code' => 'lt',
  'output_format' => 'mp3_44100_128',
  'voice_settings' => ['stability' => 0.5],
  'speed' => 0.9,                // 1 = unchanged; part of the mp3 cache key!
  'client_secret' => '',         // signs the per-phrase tokens
];
```

**That file is only safe because the vhost denies it.** nginx executes just `index.php`
but serves every other file below `/raid1/sites/qu_lt/math` as plain text (`curl
https://math.qu.lt/lib/Tts.php` really returns the source — that is how the key used to
leak), so the `math.qu.lt` server block carries:

```nginx
location ~* \.ini\.php$ { deny all; }     # settings.ini.php is not a download
```

`curl https://math.qu.lt/settings.ini.php` → **403**. If that rule is ever lost, the
file *and the key* are served to the world — then move the file outside the web root
instead: `lib/Tts.php` also reads `/etc/qu_lt/settings.ini.php` (root:www-data 0640).
Resolution order: `$MATH_TTS_SETTINGS` → `settings.ini.php` →
`/etc/qu_lt/settings.ini.php` → the legacy `/etc/qu_lt/math-tts.json` → the shipped
defaults; a key that is missing or empty keeps the layer below, so the file only has to
mention what it changes.

3. `curl https://math.qu.lt/api/tts?status=1` → `"enabled": true`. The buttons appear
on the next page load; no restart, no build.

Notes

* **`eleven_v3` is the model for Lithuanian** (`eleven_multilingual_v2` does not speak
it). It only accepts `stability` (0.0 / 0.5 / 1.0) as a voice setting — add
`similarity_boost`, `style` or `use_speaker_boost` when you switch model. `voice_id` may
stay empty — the first voice of the account is then remembered in
`cache/tts/voice.json`. Copy a voice id from
<https://elevenlabs.io/app/voice-library> to pick a specific one; **after changing it
delete `cache/tts/*.mp3`**, otherwise the old files are still valid for their own key.
* `ELEVENLABS_LANGUAGE`, `ELEVENLABS_SPEED`, `ELEVENLABS_BASE_URL` (and
`MATH_TTS_SETTINGS` to move the settings file, `MATH_TTS_SECRET` for the token signing
key, `MATH_TTS_CONFIG` for the legacy JSON file). With php-fpm that needs `env[...]`
entries in the pool — the settings file is the simpler path.
* Deleting `cache/tts/*.mp3` is always safe: the sentences are generated again on the
next tap (that is the only thing that costs credits).

### How a sentence is chosen

| JSON field | Read aloud |
| --- | --- |
| a `story` / `storyChoice` question | **nothing** — silent unless the question sets `"say": true` |
| `item.prompt` of a `write` question | its own 🔊 (small, next to the prompt) — every condition is a sentence of its own |
| `readAloud` | when set, exactly that |
| `instruction` + `hint` | default: "instruction. hint" |
| `hint` only explaining symbols (`Daugiau – >, …`) | skipped — it would read as "daugiau – daugiau" |

Symbols are spoken as words (`>` → "daugiau", `=` → "lygu", `+` → "plius"), and the
text is trimmed the same way on both sides, so whitespace never creates a second file.
That derivation exists **twice** — `speechText()`/`speakable()` in `assets/js/speech.js`
and `tts_speakable()`/`tts_question_phrases()` in `lib/Tts.php`, which signs the
sentences the client will later ask for. Keep them in step: the PHP side is checked
against the JS one over the whole `data/quizzes/` corpus (the two sets must be
identical, or a 🔊 would answer `bad-phrase`).

### Safety rails

* **Only phrases the app really contains can be synthesised.** Every quiz payload from
  the API (a library quiz, a random one, a generated batch) comes with a `tts` map of
  `sentence => token` (`tts_quiz_with_tokens()` in `lib/Tts.php`). The token is an HMAC
  of that one sentence, so the client sends `{ text, token }` and the endpoint checks
  the pair (403/`bad-phrase` otherwise): a token cannot be replayed for other text, and
  text the app does not contain has no token at all. `speech.js` keeps the map and only
  renders a 🔊 for sentences the server signed.
* A consequence: a **hand-written quiz in the builder has no 🔊** — it was never
  server-minted. Load an existing quiz as an example (`Įkelti pavyzdį`) and its buttons
  work.
* The request also has to come from this host (403 `forbidden-origin`), so a third-party
  page cannot spend the credits.
* Rotate the signing key independently of the ElevenLabs key with `"client_secret"` in
  `/etc/qu_lt/settings.ini.php` (or `MATH_TTS_SECRET`); empty = derived from the API
  key. Changing it just invalidates the tokens already in flight.
* The ElevenLabs key stays on the server: the page only ever learns mp3 urls.
* Only sentences (≤ 400 characters, ≤ 30 per request) are accepted, and fresh
  generations are limited to 120 per IP per hour (`cache/tts-limit/`) plus 600 in
  total per hour (`.global.json`) — so even rotating IPs cannot drain the account.
  Cache hits are free and never counted.
* The play screen warms the cache in the background in batches of 3 sentences, so the
first tap usually plays instantly. Playback uses the Web Audio API (the `AudioContext`
is resumed inside the tap), because Safari refuses a plain `audio.play()` that happens
after an `await`.

---

## 5. Adding a quiz

1. Copy an existing file in `data/quizzes/` (e.g. `palygink-figuras.json`).
2. Change the file name → that is the quiz `id`.
3. Reload the page — the card appears in the picker, decorated with the saved stars.

No cache to clear, no database, nothing to register.

Prefer clicking things together? Open **✏️ Kurti savo** (`/kurti`): paste JSON,
**🔍 Patikrinti** validates it server-side, **▶️ Žaisti** saves it to `localStorage`
and plays it (it then shows up under *Mano testai* on the home screen).

---

## 6. How the drawing questions work

`assets/js/scribble.js` puts a transparent `<svg>` on top of the cards. A child draws
with finger or mouse; on `pointerup` the stroke is turned into a selection. The rules are
deliberately **conservative** — marking a card the child did not mean is worse than
marking nothing, because the tip under the board says how many cards are marked:

1. the loop **encloses** a card's centre (circling on paper), or
2. the stroke's **centre of mass** lies inside the card (a line through it), or
3. the stroke crosses **exactly one** card (a long slash over a single card).

A tap toggles a single card. This means a sloppy big circle can never grab the whole row,
and a wide "nubrauk" stroke cannot accidentally cross out the neighbours.

The child's ink fades out and an animated SVG mark snaps into place (amber loop / red
cross). After `✓ Tikrinti viską` the marks of wrong answers switch to blue dashed
"solution" marks and the counts of every card become visible.

The `match` questions use the same layer in **link mode**: the ink fades and a permanent
amber line is drawn between the two cards the stroke started and ended on. Link mode is
more forgiving (16 px slack around a card instead of 2 px), because a seven year old's
line rarely starts exactly on the card.

---

## 7. Generators (maths rules)

`lib/Generator.php` builds a new quiz on every request, following the same rules as the
hand made tests. The home screen keeps that picker collapsed behind the
**⚙️ Užduotys** button (it opens while the selection is edited and closes again when a
quiz is generated); it shows one chip per **question type**, so a generated quiz is
assembled from exactly the exercises that were ticked (a group name like **Sudėtis**
ticks its whole family, and nothing ticked means every type):

| Group | Types |
| --- | --- |
| Palyginimas | `compare`, `compareGrid` |
| Skaičiavimas | `count`, `countAndCompare`, `write`, `mixedOps` |
| Veiksmai | `compareExpressions` |
| Pasakojimai | `story`, `storyChoice` |
| Braižymas | `outline`, `crossOut` |
| Sudėtis | `addPicture`, `missingNumber`, `domino`, `pickAddends`, `equalSums`, `multiCheck`, `drawMissing` |
| Atimtis | `subtractPicture`, `missingNumberSub`, `subtractDomino`, `subtractPair`, `equalDifferences`, `subtractCheck`, `crossOutExtra` |

`match` is the only type the generator cannot build: arranging the pictures so that
every sum fits exactly one of them needs hand work.

* amounts stay between 1 and `max` (`iki 5`, `iki 10`, `iki 20`); a figure group shows
  at most 10 figures, bigger amounts are written as plain numbers
* both sides show the **same figure in about half** of the generated comparisons
  (counting, not just picture difference), the other half gets two different figures
* figures are a mix of drawn shapes and **emoji** (~50/50)
* the generated quizzes belong to the **1st class** (`"grade": 1`)
* every answer is computed server-side and shipped inside the JSON

The **"Kiek testų iš karto" slider (1–20, default 1)** asks the same endpoint for a
batch: the first quiz starts right away and the 🎲 button walks through the rest
(`Testas 3 / 10` is shown in the header); after the last one it builds a fresh batch.

Each quiz of that batch gets the amount of tasks chosen with the **"Kiek užduočių
teste" slider (1–50)**. The generator fills the exercise exactly: it walks the plan and,
when the plan runs out, builds a fresh (balanced) plan, so 50 tasks are not the same six
exercises twice.

Limits: one quiz holds at most 50 tasks (`GEN_TASKS_LIMIT`) and one response at most
1000 tasks (`GEN_BATCH_TASKS_LIMIT`) — asking for `count=20&tasks=50` returns the 20
quizzes asked for (1000 tasks); a larger request is trimmed instead of blowing up the
response.
* `=` appears, but never as the majority of an exercise (1 in 8 for figures, 1 in 4 for
  the "lygu ar nelygu" exercise)
* `outline`: exactly one card has the most/least figures, or exactly two cards hold the
  same amount for "tiek pat" - never an ambiguous tie
* `crossOut`: one or two cards are wrong, and their label contradicts the picture
  (0-2 off, never below 1) or the sign is wrong for the two groups
* `count`: 2-3 groups with different amounts
* `write`: `a + b ≤ max`, successor/predecessor stay in 1..max
* `compareExpressions`: both sides of `n + n ? n − n` are built independently (each
  side is an addition or a subtraction, so all four combinations appear), the result
  stays within `max` and no side goes below 1. The printed sides are never identical
  (that would make `=` a give-away), rows never repeat, and `=` is never the majority —
  every fourth row is deliberately built as an `=` case, but only one per question
* `missingNumber` / `missingNumberSub`: the empty box is on the **left or in the middle**
  (never on the result — the missing number must not be just the answer) and the equation
  uses numbers a child can handle — an addend of a picture stays inside a countable group
  (at most 10), a difference is at least 1
* `mixedOps`: one exercise mixes both operations — every row has its own `+`/`−` and the
  first two rows always use different signs. The equation stays inside `max` (a sum never
  exceeds it, a difference is never 0), and only the result is asked for
* `story`: a little story with numbers in it ("Jonukas turėjo 5 obuolius...") built from
  a random name and a random object, and the child writes the answer. **Both operations**
  are generated — the subtraction templates ("...atidavė 3..., kiek liko?") and the
  addition ones ("...davė dar 3..., kiek dabar turi?"); the two given numbers never drop
  below 2 (so the plural noun forms always fit) and the answer stays between 1 and `max`
* `storyChoice`: the same names and objects, but the answer is chosen from buttons. Four
  variants — `who` ("Kas turi daugiau?" between two children), `which` ("Ko turi
  mažiau?" between two things one child has), `equal` ("Ar turi po lygiai, ar vienas turi
  daugiau?", sometimes equal and sometimes not) and `change` (one child gives some away,
  then the two are compared). The two amounts are always different (except the deliberate
  `equal` case), so "daugiau/mažiau" has exactly one answer; the Lithuanian number forms
  (`gen_noun_form`) and the capitalised option labels (`gen_ucfirst`, which also knows
  ž/š/č) are shared with `story`
* `addPicture` / `domino` / `pickAddends` / `equalSums` / `multiCheck` / `drawMissing`:
  the sum or target stays within `max` (so **iki 20 really means up to 20**), but one
  **addend never shows more than 10 things**: a figure group holds at most 10 objects
  and a domino half has at most 6 pips (so one tile adds up to 12). Every generated
  `addPicture` / `domino` question is **one equation** (one picture gets the whole card).
  The two addends of
  a picture are never both the same group, a `pickAddends` bank
  holds exactly one pair that fits `target`, `multiCheck` has at least two fitting and
  two non-fitting sums, `drawMissing` frames always have spare cells, and no exercise
  is solvable by tapping the numbers/cells in the order they are shown
* the subtraction family follows the same rules with a `−`: the difference is never 0,
  the minuend of a picture stays inside a countable group (at most 10 figures, at most 6
  pips on a domino half), a `subtractPair` bank holds exactly one ordered pair that gives
  the target (so the bigger number has to be picked first), `equalDifferences` gives both
  sides a different subtrahend, and `crossOutExtra` always leaves at least one circle and
  takes at least one away

Foci (still accepted by the API, each expands into types): `compare`
(⚖️ Palyginimai), `count` (🔢 Skaičiuok — counting, writing and mixed `+`/`−`
operations), `draw` (🖍️ Braižyk),
`sum` (➕ Sudėtis — all seven addition exercises), `sub` (➖ Atimtis — all seven
subtraction exercises) and `mixed` (🌈 Viskas kartu — every
type). A focus is a *plan* of builders (5 to 8 entries): without the `tasks` parameter
the quiz gets exactly that many questions, otherwise the plan is cycled to reach the
requested amount.

A type selection is **balanced**: the ticked types take turns and each cycles through
its own builders, so every type ends up with (almost) the same number of questions — a
type with three builders never fills the quiz next to a type with one. The type order is
reshuffled for every round, so the questions are interleaved in a random order instead
of grouped by type. Without `tasks` the quiz still gets one question per builder of the
selected types (so `?types=domino` alone gives exactly one exercise, and every
`story`/`storyChoice` variant is shown).

The picker has **two independent “🔊 Įgarsinti” checkboxes** (both off by default), so a
generated test is silent until voice is asked for:

- the one next to **⚙️ Užduotys: visos** sends `say=1` and voices **every question
except the story problems** (`say=0` silences them);
- the one inside the **Pasakojimai** group sends `sayStory=1` and voices the
  `story`/`storyChoice` problems only — they are silent by default because a word
  problem is meant to be **read**.

`gen_voice_apply()` applies the two; omitting a parameter leaves that group at its own
default (a 🔊 everywhere except stories).

A selection that matches a preset exactly keeps the preset name, so the stars a child
already earned for "Sudėtis" (or "Atimtis") stay attached to it (see
`gen_focus_for_types()`); every other combination is stored under
`generated-custom-<types>`.

---

## 8. Rebuilding the CSS

`assets/css/app.css` is committed, so the site works as-is. After editing
`assets/css/input.css` (or adding Tailwind classes) rebuild it:

```bash
./build.sh          # installs tailwindcss in /tmp/quiz-tailwind, then compiles
```

Requires Node + npm **only for the build**. `node_modules` is deliberately kept outside
the web root.

The app font is **Nunito Sans** from
[Google Fonts](https://fonts.google.com/specimen/Nunito+Sans) (SIL Open Font License
1.1), embedded in the `<head>` of `index.php` exactly as Google hands it out:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,200..1000;1,6..12,200..1000&display=swap" rel="stylesheet">
```

That brings the variable font (weight 200–1000, optical size, italics) and Google's
`unicode-range` subsets — `latin-ext` is what covers `ąčęėįšųūž`. `input.css` therefore
has no `@font-face` rules any more, and `assets/fonts/` is gone.

Trade-off to be aware of: every page view now asks `fonts.googleapis.com` /
`fonts.gstatic.com` (a third-party request, which also sends the visitor's IP to Google).
If you'd rather avoid that, download the two `latin` + `latin-ext` woff2 files, put them
back in `assets/fonts/` and restore the `@font-face` rules in `input.css` — that is how
it was set up before this change.

> ⚠️ Two gotchas that already bit us once, keep them in mind:
> * Class names built at runtime (`"mark mark--" + kind`) are invisible to Tailwind's
>   scanner and get **removed** from `@layer components`. Keep such rules outside the
>   layer (see the animation section of `input.css`) or list them in
>   `tailwind.config.js → safelist`.
> * An SVG element created with `document.createElement('svg')` is an *HTML* element and
>   paints nothing. Use `svgNode()` from `quiz-render.js`.

Typography: **Nunito Sans** (`Segoe UI` / `Roboto` / `Arial` / `sans-serif` as fallbacks)
— a rounded, soft sans that stays very legible for first graders. The font itself is
loaded from Google Fonts (see above); if it fails to load, the fallbacks keep the layout
perfectly usable.

---

## 9. Server notes

The nginx vhost for `math.qu.lt` only executes PHP for the entry point pattern
(`index.php`), so **everything is routed through `index.php`**: unknown paths are handed
to it by `try_files $uri $uri/ /index.php?$query_string`, and `/api/...`, `/t/...` and
`/kurti` are parsed from `REQUEST_URI` there. Assets are served as static files.

Adding a `something.php` file would therefore be served as *source text* rather than
executed — put new API actions into `api_dispatch()` instead.

`lib/*.php` and `data/quizzes/*.json` are technically world-readable; they contain no
secrets, so do not put keys or private data next to them. The settings (the ElevenLabs
key) live in `settings.ini.php` next to `index.php`, `chown root:www-data && chmod 640`,
and stay private thanks to the vhost rule `location ~* \.ini\.php$ { deny all; }`
(the vhost is backed up as `/etc/nginx/conf.d/qu_lt.conf.bak-…`). Without that rule the
file has to move outside the web root — `/etc/qu_lt/settings.ini.php` works too, and
`lib/Tts.php` is the only reader of either. Never put a key into a file the vhost does
not deny: nginx serves it as plain text.

The nginx pattern also excludes `cache|lib|var|settings|…` from **PHP execution**, but
still serves them as files, which is why the generated MP3s in `cache/tts/` are plain
static downloads. The directory must belong to the user php-fpm runs as:

```bash
mkdir -p cache/tts cache/tts-limit && chown -R www-data:www-data cache
```
