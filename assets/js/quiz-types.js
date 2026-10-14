/**
 * quiz-types.js — every question type the renderer knows about.
 *
 * Types: compare · compareGrid · compareExpressions · count · countAndCompare ·
 *        outline · crossOut · write · story · storyChoice · storyMore · storyRead · addPicture · subtractPicture ·
 *        missingNumber · missingNumberSub · mixedOps · domino · subtractDomino ·
 *        multiCheck · subtractCheck · mixedCheck · pickAddends · subtractPair · equalSums ·
 *        equalDifferences · drawMissing · crossOutExtra · match
 *
 * The addition and subtraction exercises are the same exercises with another
 * sign, so each family is built once and registered for both operations
 * (see the `pictureType` / `dominoType` / `checkType` / `bankType` factories).
 * Each one only has to describe itself; the player (app.js) drives the flow.
 */

import { figuresHtml, sideHtml, normalizeSide, colorOf, figuresPerRow, shapeSvg, dominoHtml } from './figures.js';
import { ScribbleLayer } from './scribble.js';
import { register, esc, h, svgNode, symbolsOf, symbolFor, sideValue, sameValue, SYMBOL_TEXT, liftAboveBar } from './quiz-render.js';
import { sayButton } from './speech.js';

/* ------------------------------------------------------------- fragments -- */

/**
 * Both sides of a comparison must use the same row length, otherwise the same
 * figure would be drawn bigger on the side with fewer items (and then the
 * picture lies about the amounts).
 */
function sharedPerRow(a, b, cap = 5) {
  return Math.max(1, Math.min(cap, Math.max(figuresPerRow(a), figuresPerRow(b), 1)));
}

/** An answer box the child fills with the number pad. */
function createSlot(id) {
  const button = h('button', { type: 'button', class: 'slot', 'data-slot': id, 'aria-label': 'Atsakymo langelis' });
  const valueEl = h('span', { class: 'slot-value', text: '?' });
  button.appendChild(valueEl);
  let value = '';
  let active = false;

  const paint = () => {
    valueEl.textContent = value === '' ? '?' : value;
    button.classList.toggle('has-value', value !== '');
    button.classList.toggle('is-active', active);
  };
  paint();

  return {
    el: button,
    get value() {
      return value;
    },
    setValue(v) {
      value = String(v ?? '').slice(0, 2);
      paint();
    },
    setActive(on) {
      active = !!on;
      paint();
    },
    isActive() {
      return active;
    },
    isEmpty() {
      return value === '';
    },
    flash(kind) {
      button.classList.remove('flash-ok', 'flash-bad');
      void button.offsetWidth;
      button.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
    },
    solution(v) {
      value = String(v);
      button.classList.add('slot--solution');
      paint();
    },
    reset() {
      value = '';
      button.classList.remove('slot--solution');
      paint();
    },
  };
}

/** Number pad: 1-9, then a wide 0 and backspace. */
function createKeypad(onKey) {
  const node = h('div', { class: 'keypad' });
  const layout = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '⌫'];
  for (const key of layout) {
    const wide = key === '0';
    const back = key === '⌫';
    const btn = h('button', {
      type: 'button',
      class: `key${wide ? ' key--wide' : ''}${back ? ' key--back' : ''}`,
      'data-key': key,
      'aria-label': back ? 'Ištrinti' : `Skaičius ${key}`,
      text: key,
    });
    btn.addEventListener('click', () => onKey(key));
    node.appendChild(btn);
  }
  return { el: node };
}

/** Big > < = ≠ buttons. */
function createSymbolPicker({ symbols, onPick }) {
  const node = h('div', { class: 'symbol-picker' });
  const buttons = symbols.map((sym) => {
    const btn = h('button', {
      type: 'button',
      class: 'symbol-btn',
      'data-symbol': sym,
      'aria-label': SYMBOL_TEXT[sym] || sym,
      'aria-pressed': 'false',
      text: sym,
    });
    btn.addEventListener('click', () => {
      onPick(sym);
      // Keep the picker itself above the sticky check bar (see liftAboveBar).
      liftAboveBar(node);
    });
    node.appendChild(btn);
    return btn;
  });
  return {
    el: node,
    mark(sym) {
      for (const btn of buttons) {
        const on = btn.dataset.symbol === sym;
        btn.classList.toggle('is-picked', on);
        btn.setAttribute('aria-pressed', String(on));
      }
    },
    flash(kind) {
      for (const btn of buttons) {
        btn.classList.remove('flash-ok', 'flash-bad');
        void btn.offsetWidth;
        btn.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
      }
    },
  };
}

function symbolSlot({ pick = false } = {}) {
  // With `pick` the box itself is a button: tapping it chooses which row of a
  // `compareExpressions` question the next sign goes into.
  const node = pick
    ? h('button', { type: 'button', class: 'symbol-slot symbol-slot--pick', 'aria-label': 'Vieta ženklui', text: '?' })
    : h('div', { class: 'symbol-slot', text: '?' });
  return {
    el: node,
    set(sym) {
      const next = sym || '?';
      if (node.textContent !== next) {
        node.textContent = next;
        node.classList.remove('pop');
        void node.offsetWidth;
        node.classList.add('pop');
      }
      node.classList.toggle('is-filled', !!sym);
    },
  };
}

/** Type into a 1-2 digit answer box. */
function typeIntoValue(value, key) {
  if (key === '⌫') return value.slice(0, -1);
  if (!/^\d$/.test(key)) return value;
  if (value.length >= 2) return value;
  return value + key;
}

/** A whole number from author JSON, or null when the value is not a number. */
function intOf(value) {
  if (value === null || value === undefined || value === '') return null;
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : null;
}

/**
 * A figure group + answer box + number pad. Shared by `count`,
 * `countAndCompare` and `write`.
 */
function createNumberInputs({ slots, onChanged }) {
  const state = new Map(); // id -> string
  const instances = new Map(); // id -> slot
  let activeId = slots[0].id;

  const syncActive = () => {
    for (const [id, slot] of instances) slot.setActive(id === activeId);
  };

  const setActive = (id) => {
    activeId = id;
    syncActive();
  };

  /** Advance to the next empty box (with a friendly rule for 1x numbers). */
  const advance = (currentId, key) => {
    const order = slots.map((s) => s.id);
    const idx = order.indexOf(currentId);
    const shouldAdvance = key !== '1' || (state.get(currentId) || '').length >= 2;
    if (!shouldAdvance) return;
    for (let i = 1; i <= order.length; i++) {
      const nextId = order[(idx + i) % order.length];
      if (nextId === currentId) break;
      if (!state.get(nextId)) {
        setActive(nextId);
        return;
      }
    }
    if (idx < order.length - 1) setActive(order[idx + 1]);
  };

  const wrapper = h('div', { class: 'number-inputs' });
  const cells = h('div', { class: 'input-grid' });

  for (const spec of slots) {
    const slot = createSlot(spec.id);
    state.set(spec.id, '');
    instances.set(spec.id, slot);
    const cell = h('div', { class: 'input-cell' });
    // A condition is a sentence of its own, so it can have its own 🔊 (`write`).
    if (spec.prompt || spec.say) {
      const line = h('div', { class: 'prompt-line' });
      if (spec.prompt) line.insertAdjacentHTML('beforeend', `<p class="prompt">${esc(spec.prompt)}</p>`);
      if (spec.say) {
        const say = sayButton(spec.say, { small: true });
        if (say) line.appendChild(say);
      }
      cell.appendChild(line);
    }
    if (spec.above) cell.insertAdjacentHTML('beforeend', spec.above);
    slot.el.addEventListener('click', () => {
      setActive(spec.id);
      onChanged(false);
      // Lift the pad above the sticky check bar before keys are tapped (see liftAboveBar).
      liftAboveBar(keypad.el);
    });
    cell.appendChild(slot.el);
    cells.appendChild(cell);
  }
  wrapper.appendChild(cells);

  const keypad = createKeypad((key) => {
    const current = state.get(activeId) || '';
    const next = typeIntoValue(current, key);
    state.set(activeId, next);
    instances.get(activeId).setValue(next);
    if (next !== '') advance(activeId, key);
    syncActive();
    onChanged(true);
  });
  wrapper.appendChild(keypad.el);

  syncActive();

  return {
    el: wrapper,
    get(id) {
      return state.get(id) || '';
    },
    values() {
      return slots.map((s) => state.get(s.id) || '');
    },
    touched() {
      return slots.some((s) => (state.get(s.id) || '') !== '');
    },
    flash(kind) {
      for (const slot of instances.values()) slot.flash(kind);
    },
    reveal(solution) {
      slots.forEach((s, i) => {
        state.set(s.id, String(solution[i] ?? ''));
        instances.get(s.id).solution(String(solution[i] ?? ''));
      });
    },
    reset() {
      slots.forEach((s) => {
        state.set(s.id, '');
        instances.get(s.id).reset();
      });
      setActive(slots[0].id);
    },
  };
}

/** One card on a scribble board (outline / crossOut). */
function cardHtml(card) {
  const parts = [];
  if (card.compare) {
    parts.push(
      `<div class="card-compare">` +
        `<div class="cmp-side">${figuresHtml(card.compare.left, { perRow: 3 })}</div>` +
        `<div class="card-symbol">${esc(card.compare.symbol)}</div>` +
        `<div class="cmp-side">${figuresHtml(card.compare.right, { perRow: 3 })}</div>` +
        `</div>`,
    );
  } else if (card.figures) {
    parts.push(`<div class="card-figures">${figuresHtml(card.figures, { perRow: 4 })}</div>`);
  }
  if (card.label !== undefined && card.label !== null && card.label !== '') {
    parts.push(`<div class="card-label">${esc(card.label)}</div>`);
  } else if (card.figures && card.compare === undefined) {
    parts.push(`<div class="card-chip" data-chip="?">?</div>`);
  }
  return parts.join('');
}

/** Shared implementation behind `outline` and `crossOut`. */
function createBoard(q, ctx, { markKind }) {
  const cards = q.cards;
  const board = h('div', { class: 'board' });
  const grid = h('div', { class: 'board-cards', 'data-count': String(cards.length) });
  for (const card of cards) {
    const node = h('div', { class: 'card', 'data-target': card.id });
    node.innerHTML = cardHtml(card);
    grid.appendChild(node);
  }
  const svg = svgNode('svg', { class: 'scribble', 'aria-hidden': 'true', focusable: 'false' });
  const tip = h('p', { class: 'board-tip' });
  board.append(grid, svg, tip);

  const selected = new Set();
  let revealed = false;

  const setTip = (text, kind = '') => {
    tip.textContent = text;
    tip.className = `board-tip ${kind}`.trim();
  };
  setTip(q.hint || (markKind === 'cross' ? 'Brauk per kortelę, kuri neteisinga' : 'Brėžk ratuką apie kortelę'));

  const layer = new ScribbleLayer(svg, {
    kind: markKind,
    getTargets: () => cards.map((c) => ({ id: c.id, el: grid.querySelector(`[data-target="${c.id}"]`) })),
    onCommit: (hits, points) => {
      if (revealed) return;
      const isStroke = points.length > 2;
      if (!hits.length) {
        setTip('🙂 Brauk taip, kad užkliūtum kortelę', 'is-hint');
        board.classList.remove('shake');
        void board.offsetWidth;
        board.classList.add('shake');
        return;
      }
      for (const id of hits) {
        if (isStroke) selected.add(id);
        else if (selected.has(id)) selected.delete(id);
        else selected.add(id);
      }
      layer.setMarks([...selected]);
      setTip(
        selected.size ? `Pažymėta kortelių: ${selected.size}` : q.hint || 'Brėžk ratuką apie kortelę',
        selected.size ? 'is-ok' : '',
      );
      ctx.changed();
    },
  });

  return {
    el: board,
    markKind,
    selected,
    layer,
    get revealed() {
      return revealed;
    },
    setRevealedLayers(v) {
      revealed = !!v;
      if (!revealed) return;
      // Show the real counts so the child can check the maths.
      cards.forEach((card) => {
        const chip = grid.querySelector(`[data-target="${card.id}"] .card-chip`);
        if (chip && card.figures) chip.textContent = String(card.figures.count);
      });
    },
    cardCount: cards.length,
  };
}

/* ------------------------------------------- addition / subtraction core -- */

/**
 * The two operations the equation exercises use. Adding and subtracting is the
 * same exercise with another sign, so every type below is built once and only
 * picks its operation here.
 */
const OPS = {
  '+': { sign: '+', apply: (a, b) => a + b },
  '-': { sign: '−', apply: (a, b) => a - b },
};

function opSpec(op) {
  return OPS[op] || OPS['+'];
}

/** The sign as it is printed (a real minus sign, not a hyphen). */
function opSign(op) {
  return opSpec(op).sign;
}

function opApply(a, b, op) {
  return opSpec(op).apply(a, b);
}

/**
 * The rows of an `addPicture` / `subtractPicture` / `domino` question: a
 * picture of the numbers plus `[ ] + [ ] = [ ]` (or `−`). All boxes of the
 * whole question share one number pad, so the `.input-cell`s are moved out of
 * `createNumberInputs()` into the equation (the same trick `countAndCompare`
 * uses).
 *
 * `write: 'result'` prints the two operands and only asks for the result.
 */
function createEquationRows({ rows, write, op = '+', onChanged }) {
  const full = write !== 'result';
  const sign = opSign(op);
  const node = h('div', { class: 'q-block q-add-rows' });
  const list = h('div', { class: 'add-rows' });
  // More than three short rows (mixedOps has six) take the 3 x 2 shape of the
  // comparison table instead of six stacked cards (2 columns from the sm width).
  if (rows.length > 3) list.classList.add('add-rows--two');

  const slots = [];
  rows.forEach((row) => {
    if (full) slots.push({ id: `${row.id}a` }, { id: `${row.id}b` });
    slots.push({ id: `${row.id}c` });
  });

  const inputs = createNumberInputs({ slots, onChanged: () => onChanged() });
  const cells = [...inputs.el.querySelectorAll('.input-cell')];
  let next = 0;
  const takeCell = (parent) => {
    const cell = cells[next++];
    if (!cell) return;
    cell.classList.add('input-cell--eq');
    parent.appendChild(cell);
  };

  rows.forEach((row) => {
    const rowEl = h('div', { class: 'add-row' });
    rowEl.insertAdjacentHTML('beforeend', row.picture);
    // A row may carry its own sign (`mixedOps`); everything else shares the
    // single sign of the question.
    const rowSign = row.op ? opSign(row.op) : sign;
    const eq = h('div', { class: 'add-equation' });
    if (full) {
      takeCell(eq);
      eq.insertAdjacentHTML('beforeend', `<span class="eq-sign">${rowSign}</span>`);
      takeCell(eq);
      eq.insertAdjacentHTML('beforeend', '<span class="eq-sign">=</span>');
    } else {
      eq.insertAdjacentHTML(
        'beforeend',
        `<span class="eq-num">${row.a}</span><span class="eq-sign">${rowSign}</span>` +
          `<span class="eq-num">${row.b}</span><span class="eq-sign">=</span>`,
      );
    }
    takeCell(eq);
    rowEl.appendChild(eq);
    list.appendChild(rowEl);
  });

  node.append(list, inputs.el);
  return { el: node, inputs };
}

/* ------------------------------------------------------------ number bank -- */

/** The two chips of `bank` that make `target` with `op` (chip order). */
function bankPair(bank, target, op = '+') {
  for (let i = 0; i < bank.length; i++) {
    for (let j = 0; j < bank.length; j++) {
      if (i !== j && opApply(bank[i], bank[j], op) === target) return [i, j];
    }
  }
  return null;
}

/**
 * A valid way to fill all four boxes from `bank`: two chip pairs that both make
 * `target`. Every pair is tried, because the first pair that works on its own
 * may leave two chips that do not (that is what makes a `subtractPair` bank
 * unsolvable if we only look at the first one).
 */
function bankSplit(bank, target, op = '+') {
  for (let i = 0; i < bank.length; i++) {
    for (let j = 0; j < bank.length; j++) {
      if (i === j || opApply(bank[i], bank[j], op) !== target) continue;
      const rest = bank.map((v, k) => [v, k]).filter(([, k]) => k !== i && k !== j);
      for (let a = 0; a < rest.length; a++) {
        for (let b = 0; b < rest.length; b++) {
          if (a !== b && opApply(rest[a][0], rest[b][0], op) === target) {
            return [i, j, rest[a][1], rest[b][1]];
          }
        }
      }
    }
  }
  return null;
}

/**
 * The "fill the boxes from the given numbers" widget behind `pickAddends` /
 * `subtractPair` (two boxes) and `equalSums` / `equalDifferences` (four boxes,
 * both sides have to make the target).
 *
 * Tapping a chip drops it into the next free box, tapping a box gives the chip
 * back. When every box is filled the leftover number is crossed out on its own,
 * so the child sees which one was not needed.
 */
function createChipBank({ items, mode, op = '+', onChanged }) {
  const equal = mode === 'equal';
  const sign = opSign(op);
  const perRow = equal ? 4 : 2;
  const node = h('div', { class: 'q-block q-bank' });
  const rows = [];
  let revealed = false;

  items.forEach((item) => {
    const state = new Array(perRow).fill(null); // box -> chip index
    const rowEl = h('div', { class: 'bank-row' });
    const eq = h('div', { class: 'bank-eq' });
    const slotEls = [];

    for (let s = 0; s < perRow; s++) {
      if (s > 0) eq.insertAdjacentHTML('beforeend', `<span class="eq-sign">${s === 2 ? '=' : sign}</span>`);
      const slot = createSlot(`${item.id}-${s}`);
      slot.el.classList.add('slot--sm');
      slot.el.addEventListener('click', () => {
        if (revealed || state[s] === null) return;
        state[s] = null;
        paint();
        onChanged();
      });
      slotEls.push(slot);
      eq.appendChild(slot.el);
    }
    if (equal) {
      const tri = h('div', { class: 'triangle-target' });
      tri.innerHTML =
        `<span class="tri-shape">${shapeSvg('triangle', { color: 'amber' })}</span>` +
        `<span class="tri-num">${item.target}</span>`;
      eq.appendChild(tri);
    } else {
      eq.appendChild(h('span', { class: 'target-box', text: String(item.target) }));
    }

    const chips = h('div', { class: 'bank-chips' });
    const chipEls = item.bank.map((value, ci) => {
      const chip = h('button', {
        type: 'button',
        class: 'bank-chip',
        'data-chip': String(ci),
        'aria-label': `Skaičius ${value}`,
        text: String(value),
      });
      chip.addEventListener('click', () => {
        if (revealed) return;
        const used = state.indexOf(ci);
        if (used !== -1) state[used] = null;
        else {
          const free = state.indexOf(null);
          if (free === -1) return;
          state[free] = ci;
        }
        paint();
        onChanged();
      });
      chips.appendChild(chip);
      return chip;
    });

    rowEl.append(eq, chips);
    node.appendChild(rowEl);
    rows.push({ item, state, slotEls, chipEls });

    function paint() {
      const full = state.every((v) => v !== null);
      slotEls.forEach((slot, s) => slot.setValue(state[s] === null ? '' : String(item.bank[state[s]])));
      chipEls.forEach((chip, ci) => {
        const used = state.includes(ci);
        chip.classList.toggle('is-used', used);
        chip.classList.toggle('is-crossed', !used && full);
      });
    }
  });

  return {
    el: node,
    /** Are both (or all four) boxes of row `i` filled? */
    filled(i) {
      return rows[i].state.every((v) => v !== null);
    },
    /** The numbers the child placed in row `i`, in box order. */
    values(i) {
      return rows[i].state.map((ci) => (ci === null ? '' : String(rows[i].item.bank[ci])));
    },
    check() {
      return rows.every(({ item, state }) => {
        if (state.some((v) => v === null)) return false;
        const nums = state.map((ci) => item.bank[ci]);
        if (equal) {
          return opApply(nums[0], nums[1], op) === item.target && opApply(nums[2], nums[3], op) === item.target;
        }
        return opApply(nums[0], nums[1], op) === item.target;
      });
    },
    touched() {
      return rows.some(({ state }) => state.some((v) => v !== null));
    },
    flash(kind) {
      rows.forEach(({ chipEls }) =>
        chipEls.forEach((chip) => {
          chip.classList.remove('flash-ok', 'flash-bad');
          void chip.offsetWidth;
          chip.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
        }),
      );
    },
    reveal() {
      revealed = true;
      rows.forEach(({ item, state, slotEls, chipEls }) => {
        const solution = item.solution || (equal ? bankSplit(item.bank, item.target, op) : bankPair(item.bank, item.target, op));
        state.fill(null);
        slotEls.forEach((slot, s) => {
          const chip = solution ? solution[s] : null;
          if (chip !== null && chip !== undefined) state[s] = chip;
          slot.solution(chip === null || chip === undefined ? '?' : String(item.bank[chip]));
        });
        chipEls.forEach((el, ci) => {
          const used = state.includes(ci);
          el.classList.toggle('is-used', used);
          el.classList.toggle('is-crossed', !used);
        });
      });
    },
    clear() {
      revealed = false;
      rows.forEach(({ state, slotEls, chipEls }) => {
        state.fill(null);
        slotEls.forEach((slot) => slot.reset());
        chipEls.forEach((el) => el.classList.remove('is-used', 'is-crossed', 'flash-ok', 'flash-bad'));
      });
    },
    destroy() {},
  };
}

/**
 * `pickAddends` / `equalSums` and their subtraction twins: pick numbers from a
 * bank and put them into `[ ] + [ ] = [target]` (or `−`, or both sides on four
 * boxes). One implementation, two operations, four types.
 */
function bankType({ name, label, op, equal, defaultInstruction }) {
  const same = equal
    ? 'Sudėk skaičius taip, kad abiejų veiksmų gautum tiek pat, kiek parodyta trikampyje.'
    : 'Sudėk du skaičius ir nubrauk tą, kurio nereikia.';
  return {
    name,
    label,
    defaultInstruction: defaultInstruction || same,
    normalize(q) {
      q.items = normalizeBankItems(q, equal ? 'equal' : 'pair', op);
    },
    answer(q) {
      return q.items.map((item) => {
        const solution = equal ? bankSplit(item.bank, item.target, op) : bankPair(item.bank, item.target, op);
        if (!solution) return '';
        const v = solution.map((ci) => item.bank[ci]);
        // Plain `+` / `-` in the answer string (it is compared with PHP and
        // printed on the summary screen, not rendered as a sign).
        return equal ? `${v[0]}${op}${v[1]}=${v[2]}${op}${v[3]}` : `${v[0]}${op}${v[1]}`;
      });
    },
    answerText(q) {
      return q.items
        .map((item, i) => {
          if (q.answer[i]) return equal ? q.answer[i] : `${q.answer[i]} = ${item.target}`;
          return equal ? `turi būti ${item.target}` : `Reikia ${op === '-' ? 'atimti' : 'sudėti'} ${item.target}`;
        })
        .join('  ·  ');
    },
    create(q, ctx) {
      const bank = createChipBank({ items: q.items, mode: equal ? 'equal' : 'pair', op, onChanged: () => ctx.changed() });
      return {
        el: bank.el,
        check: () => bank.check(),
        touched: () => bank.touched(),
        flash: (kind) => bank.flash(kind),
        reveal: () => bank.reveal(),
        clear: () => bank.clear(),
        destroy: () => bank.destroy(),
      };
    },
  };
}

/**
 * `multiCheck` / `subtractCheck` and their mixed twin `mixedCheck`: tick every
 * equation that gives `target` (any number of them). The printed `a + b` /
 * `a − b` may be replaced by the author's `text`.
 *
 * `mixed` is the only difference: with it every item carries its own sign
 * (`{ a, b, op }`, `op` = "+" or "-"), so one card holds both additions and
 * subtractions. Without it the whole question shares the type's `op`.
 */
function checkType({ name, label, op, defaultInstruction, mixed = false }) {
  /** The sign of one item: its own when the type mixes, else the type's. */
  const signOf = (it) => {
    if (!mixed) return op;
    return (it && it.op) === '-' ? '-' : '+';
  };
  return {
    name,
    label,
    defaultInstruction,
    normalize(q) {
      const raw = Array.isArray(q.items) ? q.items : [];
      q.items = raw
        .map((item, i) => {
          const it = item && typeof item === 'object' ? item : {};
          const a = Math.trunc(Number(it.a));
          const b = Math.trunc(Number(it.b));
          if (!Number.isFinite(a) || !Number.isFinite(b)) return null;
          const itemOp = signOf(it);
          return { id: String(it.id || `c${i + 1}`), a, b, op: itemOp, text: String(it.text || `${a} ${opSign(itemOp)} ${b}`) };
        })
        .filter(Boolean);
      if (!q.items.length) {
        q.items = mixed
          ? [
              { id: 'c1', a: 4, b: 4, op: '+', text: '4 + 4' },
              { id: 'c2', a: 6, b: 2, op: '-', text: '6 − 2' },
              { id: 'c3', a: 3, b: 5, op: '+', text: '3 + 5' },
              { id: 'c4', a: 7, b: 2, op: '-', text: '7 − 2' },
            ]
          : op === '-'
            ? [{ id: 'c1', a: 6, b: 2, op: '-', text: '6 − 2' }, { id: 'c2', a: 5, b: 3, op: '-', text: '5 − 3' }]
            : [{ id: 'c1', a: 4, b: 4, op: '+', text: '4 + 4' }, { id: 'c2', a: 3, b: 3, op: '+', text: '3 + 3' }];
      }
      if (!mixed) q.items.forEach((it) => { it.op = op; });
      const target = Math.trunc(Number(q.target));
      q.target = Number.isFinite(target) ? target : opApply(q.items[0].a, q.items[0].b, q.items[0].op);
      if (q.answer !== undefined && q.answer !== null) {
        q.answer = (Array.isArray(q.answer) ? q.answer : [q.answer]).map(String);
      }
    },
    answer(q) {
      if (Array.isArray(q.answer)) return q.answer;
      return q.items.filter((it) => opApply(it.a, it.b, it.op) === q.target).map((it) => it.id);
    },
    answerText(q) {
      const fits = q.items.filter((it) => opApply(it.a, it.b, it.op) === q.target);
      return fits.length ? `Tinka: ${fits.map((it) => it.text).join(', ')}` : 'Nėra tinkančio veiksmo';
    },
    revealText: () => 'Tinkami veiksmai pažymėti žaliai ✅',
    create(q, ctx) {
      let revealed = false;
      const selected = new Set();
      const node = h('div', { class: 'q-block q-multi' });
      node.insertAdjacentHTML(
        'beforeend',
        `<p class="prompt">${mixed ? 'Rezultatas' : op === '-' ? 'Skirtumas' : 'Suma'} turi būti ${q.target}</p>`,
      );
      const list = h('div', { class: 'check-list', 'data-count': String(q.items.length) });
      const shown = new Map();

      const paint = () => {
        q.items.forEach((item) => {
          const el = shown.get(item.id);
          if (!el) return;
          const picked = selected.has(item.id);
          el.btn.classList.toggle('is-picked', picked && !el.bad);
          el.btn.classList.toggle('is-solution', el.good);
          el.btn.classList.toggle('is-bad', el.bad);
          el.box.textContent = el.good ? '✓' : el.bad ? '✗' : picked ? '✓' : '';
          el.btn.setAttribute('aria-pressed', String(picked));
        });
      };

      q.items.forEach((item) => {
        const btn = h('button', { type: 'button', class: 'check-item', 'data-item': item.id, 'aria-pressed': 'false' });
        const box = h('span', { class: 'check-box' });
        const text = h('span', { class: 'check-text', text: item.text });
        btn.append(box, text);
        btn.addEventListener('click', () => {
          if (revealed) return;
          if (selected.has(item.id)) selected.delete(item.id);
          else selected.add(item.id);
          paint();
          ctx.changed();
        });
        list.appendChild(btn);
        shown.set(item.id, { btn, box, good: false, bad: false });
      });

      node.appendChild(list);

      return {
        el: node,
        check() {
          return sameValue([...selected], q.answer);
        },
        touched() {
          return selected.size > 0;
        },
        flash(kind) {
          list.querySelectorAll('.check-item').forEach((btn) => {
            btn.classList.remove('flash-ok', 'flash-bad');
            void btn.offsetWidth;
            btn.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
          });
        },
        reveal() {
          revealed = true;
          const wanted = new Set(q.answer.map(String));
          q.items.forEach((item) => {
            const el = shown.get(item.id);
            if (!el) return;
            el.good = wanted.has(item.id);
            el.bad = !el.good && selected.has(item.id);
          });
          paint();
        },
        clear() {
          revealed = false;
          selected.clear();
          shown.forEach((el) => {
            el.good = false;
            el.bad = false;
          });
          paint();
        },
        destroy() {},
      };
    },
  };
}

/* ---------------------------------------------------------------- types -- */
/* 1. compare ------------------------------------------------------------- */
register({
  name: 'compare',
  label: 'Palyginti dvi grupes (> < =)',
  defaultInstruction: 'Suskaičiuok ir įrašyk reikiamą ženklą.',
  normalize(q) {
    q.left = normalizeSide(q.left, 'sky');
    q.right = normalizeSide(q.right, 'amber');
    q.symbols = symbolsOf(q);
  },
  answer(q) {
    return symbolFor(sideValue(q.left), sideValue(q.right), q.symbols);
  },
  answerText(q) {
    return `${sideValue(q.left)} ${symbolFor(sideValue(q.left), sideValue(q.right), q.symbols)} ${sideValue(q.right)}`;
  },
  create(q, ctx) {
    let picked = null;
    let revealed = false;

    const node = h('div', { class: 'q-block q-compare' });
    const row = h('div', { class: 'compare-row' });
    const perRow = sharedPerRow(sideValue(q.left), sideValue(q.right));
    const leftSide = h('div', { class: 'compare-side' });
    leftSide.innerHTML = `${sideHtml(q.left, { perRow })}<span class="count-chip" data-chip="left">?</span>`;
    const slot = symbolSlot();
    const rightSide = h('div', { class: 'compare-side' });
    rightSide.innerHTML = `${sideHtml(q.right, { perRow })}<span class="count-chip" data-chip="right">?</span>`;
    row.append(leftSide, slot.el, rightSide);
    node.appendChild(row);

    const picker = createSymbolPicker({
      symbols: q.symbols,
      onPick: (sym) => {
        if (revealed) return;
        picked = sym;
        slot.set(sym);
        picker.mark(sym);
        ctx.changed();
      },
    });
    node.appendChild(picker.el);

    const paintChips = () => {
      node.querySelector('[data-chip="left"]').textContent = revealed ? String(sideValue(q.left)) : '?';
      node.querySelector('[data-chip="right"]').textContent = revealed ? String(sideValue(q.right)) : '?';
    };

    return {
      el: node,
      givenText() {
        return `${sideValue(q.left)} ${picked || '?'} ${sideValue(q.right)}`;
      },
      check() {
        return sameValue(picked, q.answer);
      },
      touched() {
        return picked !== null;
      },
      flash(kind) {
        slot.el.classList.remove('flash-ok', 'flash-bad');
        void slot.el.offsetWidth;
        slot.el.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
        picker.flash(kind);
      },
      reveal() {
        revealed = true;
        slot.set(q.answer);
        picker.mark(q.answer);
        paintChips();
      },
      clear() {
        revealed = false;
        picked = null;
        slot.set(null);
        picker.mark(null);
        paintChips();
      },
      destroy() {},
    };
  },
});

/* 2. compareGrid --------------------------------------------------------- */
register({
  name: 'compareGrid',
  label: 'Daug palyginimų lentelėje',
  defaultInstruction: 'Palygink skaičius ir įrašyk ženklą.',
  normalize(q) {
    q.symbols = symbolsOf(q);
    const rows = Array.isArray(q.rows) ? q.rows : [];
    q.rows = rows
      .map((r) => {
        const left = Math.trunc(Number(r && r.left));
        const right = Math.trunc(Number(r && r.right));
        if (!Number.isFinite(left) || !Number.isFinite(right)) return null;
        return { left, right, answer: symbolFor(left, right, q.symbols) };
      })
      .filter(Boolean);
  },
  answer(q) {
    return q.rows.map((r) => r.answer);
  },
  answerText(q) {
    return q.rows.map((r) => `${r.left} ${r.answer} ${r.right}`).join('  ·  ');
  },
  create(q, ctx) {
    const picks = new Array(q.rows.length).fill(null);
    let revealed = false;
    const node = h('div', { class: 'q-block q-grid' });
    const rowNodes = [];

    q.rows.forEach((r, i) => {
      const row = h('div', { class: 'grid-row' });
      const seg = h('div', { class: 'segmented' });
      const buttons = q.symbols.map((sym) => {
        const btn = h('button', {
          type: 'button',
          class: 'seg-btn',
          'data-symbol': sym,
          'aria-label': SYMBOL_TEXT[sym] || sym,
          text: sym,
        });
        btn.addEventListener('click', () => {
          if (revealed) return;
          picks[i] = sym;
          paintRow(i);
          ctx.changed();
          // Keep the row of signs clear of the sticky check bar (see liftAboveBar).
          liftAboveBar(row);
        });
        seg.appendChild(btn);
        return btn;
      });
      row.innerHTML = `<span class="grid-num">${r.left}</span>`;
      row.append(seg);
      row.insertAdjacentHTML('beforeend', `<span class="grid-num">${r.right}</span>`);
      node.appendChild(row);
      rowNodes.push({ row, buttons });
    });

    function paintRow(i) {
      rowNodes[i].buttons.forEach((btn) => {
        btn.classList.toggle('is-picked', btn.dataset.symbol === picks[i]);
        btn.setAttribute('aria-pressed', String(btn.dataset.symbol === picks[i]));
      });
    }

    return {
      el: node,
      givenText() {
        return q.rows.map((r, i) => `${r.left} ${picks[i] || '?'} ${r.right}`).join('  ·  ');
      },
      check() {
        return q.rows.every((r, i) => picks[i] === r.answer);
      },
      touched() {
        return picks.some((p) => p !== null);
      },
      flash(kind) {
        node.querySelectorAll('.seg-btn').forEach((btn) => {
          btn.classList.remove('flash-ok', 'flash-bad');
          void btn.offsetWidth;
          btn.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
        });
      },
      reveal() {
        revealed = true;
        q.rows.forEach((r, i) => {
          picks[i] = r.answer;
          paintRow(i);
        });
      },
      clear() {
        revealed = false;
        picks.fill(null);
        q.rows.forEach((_, i) => paintRow(i));
      },
      destroy() {},
    };
  },
});

/* 2b. compareExpressions ------------------------------------------------ */

/**
 * One side of a `compareExpressions` row: `3 + 4` or `9 − 2`. A `sum: [3, 4]`
 * shortcut is allowed, like in `match`; `op` is `+` unless it is `-`.
 */
function expressionOf(node) {
  const e = node && typeof node === 'object' ? node : {};
  const pair = Array.isArray(e.sum) ? e.sum : [];
  const a = intOf(e.a ?? pair[0]);
  const b = intOf(e.b ?? pair[1]);
  if (a === null || b === null) return null;
  const op = e.op === '-' ? '-' : '+';
  return { a, b, op, value: opApply(a, b, op) };
}

/** `3 + 4` as it is printed inside the equation. */
function expressionHtml(expr) {
  return (
    `<span class="eq-expr">` +
    `<span class="eq-num">${esc(String(expr.a))}</span>` +
    `<span class="eq-sign">${esc(opSign(expr.op))}</span>` +
    `<span class="eq-num">${esc(String(expr.b))}</span>` +
    `</span>`
  );
}

/** `3 + 4` as plain text (answers on the summary screen). */
function expressionText(expr) {
  return `${expr.a} ${opSign(expr.op)} ${expr.b}`;
}

/**
 * The `rows` of a compareExpressions / compareResults question: every row is two
 * equations (left and right) plus the sign that belongs between their results.
 */
function normalizeExpressionRows(q) {
  q.symbols = symbolsOf(q);
  const rows = Array.isArray(q.rows) ? q.rows : [];
  q.rows = rows
    .map((row, i) => {
      const r = row && typeof row === 'object' ? row : {};
      const left = expressionOf(r.left);
      const right = expressionOf(r.right);
      if (!left || !right) return null;
      return {
        id: String(r.id || `e${i + 1}`),
        left,
        right,
        answer: symbolFor(left.value, right.value, q.symbols),
      };
    })
    .filter(Boolean);
  if (!q.rows.length) {
    const left = expressionOf({ a: 3, b: 4, op: '+' });
    const right = expressionOf({ a: 9, b: 2, op: '-' });
    q.rows = [{ id: 'e1', left, right, answer: symbolFor(left.value, right.value, q.symbols) }];
  }
}

/**
 * Suskaičiuok abi puses ir įrašyk ženklą: `3 + 4 ? 9 − 2`. Every side is an
 * addition or a subtraction of its own and the child compares the two results
 * with  >  <  or  = . The empty box of a row is filled by one shared row of
 * sign buttons, so the equations can stay big: tap a box to choose the row the
 * next sign goes into (the game jumps to the next empty box by itself).
 */
register({
  name: 'compareExpressions',
  label: 'Suskaičiuoti ir palyginti veiksmus ( > < = )',
  defaultInstruction: 'Suskaičiuok abi puses ir įrašyk reikiamą ženklą.',
  normalize(q) {
    normalizeExpressionRows(q);
  },
  answer(q) {
    return q.rows.map((r) => r.answer);
  },
  answerText(q) {
    return q.rows.map((r) => `${expressionText(r.left)} ${r.answer} ${expressionText(r.right)}`).join('  ·  ');
  },
  create(q, ctx) {
    const picks = new Array(q.rows.length).fill(null);
    let active = 0;
    let revealed = false;

    const node = h('div', { class: 'q-block q-cmp' });
    const list = h('div', { class: 'add-rows' });
    const slots = q.rows.map((row, i) => {
      const rowEl = h('div', { class: 'add-row' });
      const eq = h('div', { class: 'add-equation' });
      eq.insertAdjacentHTML('beforeend', expressionHtml(row.left));
      const slot = symbolSlot({ pick: true });
      slot.el.addEventListener('click', () => {
        if (revealed) return;
        active = i;
        paint();
        liftAboveBar(picker.el);
      });
      eq.appendChild(slot.el);
      eq.insertAdjacentHTML('beforeend', expressionHtml(row.right));
      rowEl.appendChild(eq);
      list.appendChild(rowEl);
      return slot;
    });
    node.appendChild(list);

    const picker = createSymbolPicker({
      symbols: q.symbols,
      onPick: (sym) => {
        if (revealed) return;
        picks[active] = sym;
        const next = picks.indexOf(null);
        if (next !== -1) active = next;
        paint();
        ctx.changed();
        liftAboveBar(picker.el);
      },
    });
    node.appendChild(picker.el);

    function paint() {
      slots.forEach((slot, i) => {
        slot.set(picks[i]);
        slot.el.classList.toggle('is-active', i === active && !revealed);
      });
      picker.mark(picks[active]);
    }
    paint();

    return {
      el: node,
      givenText: () =>
        q.rows.map((r, i) => `${expressionText(r.left)} ${picks[i] || '?'} ${expressionText(r.right)}`).join('  ·  '),
      check: () => q.rows.every((r, i) => picks[i] === r.answer),
      touched: () => picks.some((p) => p !== null),
      flash(kind) {
        for (const slot of slots) {
          slot.el.classList.remove('flash-ok', 'flash-bad');
          void slot.el.offsetWidth;
          slot.el.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
        }
        picker.flash(kind);
      },
      reveal() {
        revealed = true;
        q.rows.forEach((r, i) => {
          picks[i] = r.answer;
        });
        paint();
      },
      clear() {
        revealed = false;
        picks.fill(null);
        active = 0;
        paint();
      },
      destroy() {},
    };
  },
});

/* 2c. compareResults ----------------------------------------------------- */

/**
 * The SCAFFOLDED version of `compareExpressions`. The two equations are printed
 * one above the other and the child first works out (and writes down) the result
 * of each side, then picks the sign between the two numbers:
 *
 *        7 − 1        ↓        8 − 1
 *        [ 6 ]     [ > < = ]     [ 7 ]
 *
 * Same JSON as `compareExpressions` (rows of two equations), so the one exercise
 * can be switched to the other just by changing `type`.
 */
register({
  name: 'compareResults',
  label: 'Suskaičiuoti ir palyginti veiksmus (įrašyti skaičius)',
  defaultInstruction: 'Suskaičiuok abi puses, įrašyk skaičius ir palygink.',
  normalize(q) {
    normalizeExpressionRows(q);
  },
  answer(q) {
    return q.rows.map((r) => [String(r.left.value), r.answer, String(r.right.value)]);
  },
  answerText(q) {
    return q.rows.map((r) => `${expressionText(r.left)} ${r.answer} ${expressionText(r.right)}`).join('  ·  ');
  },
  create(q, ctx) {
    const picks = new Array(q.rows.length).fill(null);
    let active = 0;
    let revealed = false;

    const node = h('div', { class: 'q-block q-cmp-results' });
    const list = h('div', { class: 'add-rows' });

    // Two number boxes per row (the result of the left and of the right side);
    // all of them share the single number pad at the bottom.
    const inputs = createNumberInputs({
      slots: q.rows.flatMap((row) => [{ id: `${row.id}x` }, { id: `${row.id}y` }]),
      onChanged: () => ctx.changed(),
    });
    const cells = [...inputs.el.querySelectorAll('.input-cell')];
    let next = 0;

    const slots = [];
    q.rows.forEach((row, i) => {
      const rowEl = h('div', { class: 'add-row' });
      const top = h('div', { class: 'cre-top' });
      top.insertAdjacentHTML('beforeend', expressionHtml(row.left));
      top.insertAdjacentHTML('beforeend', '<span class="cre-arrow" aria-hidden="true">↓</span>');
      top.insertAdjacentHTML('beforeend', expressionHtml(row.right));
      rowEl.appendChild(top);

      const bottom = h('div', { class: 'cre-bottom' });
      const leftCell = cells[next++];
      const rightCell = cells[next++];
      if (leftCell) {
        leftCell.classList.add('input-cell--eq');
        bottom.appendChild(leftCell);
      }
      const slot = symbolSlot({ pick: true });
      slot.el.addEventListener('click', () => {
        if (revealed) return;
        active = i;
        paint();
        liftAboveBar(picker.el);
      });
      bottom.appendChild(slot.el);
      if (rightCell) {
        rightCell.classList.add('input-cell--eq');
        bottom.appendChild(rightCell);
      }
      rowEl.appendChild(bottom);
      list.appendChild(rowEl);
      slots.push(slot);
    });
    node.appendChild(list);

    const picker = createSymbolPicker({
      symbols: q.symbols,
      onPick: (sym) => {
        if (revealed) return;
        picks[active] = sym;
        const nextEmpty = picks.indexOf(null);
        if (nextEmpty !== -1) active = nextEmpty;
        paint();
        ctx.changed();
        liftAboveBar(picker.el);
      },
    });
    node.appendChild(picker.el);
    node.appendChild(inputs.el);

    function paint() {
      slots.forEach((slot, i) => {
        slot.set(picks[i]);
        slot.el.classList.toggle('is-active', i === active && !revealed);
      });
      picker.mark(picks[active]);
    }
    paint();

    return {
      el: node,
      givenText: () =>
        q.rows
          .map((r, i) => `${inputs.get(`${r.id}x`) || '?'} ${picks[i] || '?'} ${inputs.get(`${r.id}y`) || '?'}`)
          .join('  ·  '),
      check: () =>
        q.rows.every(
          (r, i) =>
            inputs.get(`${r.id}x`) === String(r.left.value) &&
            inputs.get(`${r.id}y`) === String(r.right.value) &&
            picks[i] === r.answer,
        ),
      touched: () => inputs.touched() || picks.some((p) => p !== null),
      flash(kind) {
        inputs.flash(kind);
        for (const slot of slots) {
          slot.el.classList.remove('flash-ok', 'flash-bad');
          void slot.el.offsetWidth;
          slot.el.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
        }
        picker.flash(kind);
      },
      reveal() {
        revealed = true;
        inputs.reveal(q.rows.flatMap((r) => [String(r.left.value), String(r.right.value)]));
        q.rows.forEach((r, i) => {
          picks[i] = r.answer;
        });
        paint();
      },
      clear() {
        revealed = false;
        picks.fill(null);
        active = 0;
        inputs.reset();
        paint();
      },
      destroy() {},
    };
  },
});

/* 3. count --------------------------------------------------------------- */
register({
  name: 'count',
  label: 'Suskaičiuoti ir parašyti skaičių',
  defaultInstruction: 'Suskaičiuok ir įrašyk skaičių.',
  normalize(q) {
    const raw = Array.isArray(q.scenes) && q.scenes.length ? q.scenes : q.scene ? [{ id: 's1', figures: q.scene.figures || q.scene }] : [];
    q.scenes = raw
      .map((s, i) => {
        const spec = normalizeSide(s && (s.figures || s));
        if (spec.kind !== 'figures') return null;
        return { id: String((s && s.id) || `s${i + 1}`), figures: spec };
      })
      .filter(Boolean);
    if (!q.scenes.length) q.scenes = [{ id: 's1', figures: normalizeSide({ shape: 'circle', count: 3 }) }];
  },
  answer(q) {
    return q.scenes.map((s) => String(s.figures.count));
  },
  answerText(q) {
    return q.scenes.map((s, i) => `${i + 1}) ${s.figures.count}`).join('  ');
  },
  create(q, ctx) {
    const nodeEl = h('div', { class: 'q-block q-count' });
    const scenes = h('div', { class: 'scene-grid' });
    q.scenes.forEach((s) => {
      const card = h('div', { class: 'scene' });
      card.innerHTML = `<div class="scene-figures">${figuresHtml(s.figures, { perRow: 3 })}</div>`;
      scenes.appendChild(card);
    });
    nodeEl.appendChild(scenes);

    const inputs = createNumberInputs({
      slots: q.scenes.map((s, i) => ({ id: s.id, above: `<div class="scene-marker">${i + 1}</div>` })),
      onChanged: (typed) => ctx.changed(typed),
    });
    nodeEl.appendChild(inputs.el);

    return {
      el: nodeEl,
      givenText: () => inputs.values().map((v, i) => `${i + 1}) ${v || '?'}`).join('  '),
      check() {
        return sameValue(inputs.values(), q.answer);
      },
      touched: () => inputs.touched(),
      flash: (kind) => inputs.flash(kind),
      reveal: () => inputs.reveal(q.answer),
      clear: () => inputs.reset(),
      destroy() {},
    };
  },
});

/* 4. countAndCompare ----------------------------------------------------- */
register({
  name: 'countAndCompare',
  label: 'Suskaičiuoti ir palyginti',
  defaultInstruction: 'Suskaičiuok, įrašyk skaičius ir palygink.',
  normalize(q) {
    q.symbols = symbolsOf(q);
    q.left = { figures: normalizeSide((q.left && (q.left.figures || q.left)) || {}) };
    q.right = { figures: normalizeSide((q.right && (q.right.figures || q.right)) || {}) };
  },
  answer(q) {
    const a = q.left.figures.count;
    const b = q.right.figures.count;
    return { left: String(a), right: String(b), symbol: symbolFor(a, b, q.symbols) };
  },
  answerText(q) {
    const a = q.answer || {};
    return `${a.left} ${a.symbol} ${a.right}`;
  },
  create(q, ctx) {
    let picked = null;
    let revealed = false;

    const node = h('div', { class: 'q-block q-count-compare' });
    const perRow = sharedPerRow(q.left.figures.count, q.right.figures.count, 3);
    const row = h('div', { class: 'cac-row' });
    const leftCol = h('div', { class: 'cac-side' });
    const rightCol = h('div', { class: 'cac-side' });
    leftCol.innerHTML = `<div class="cac-figures">${figuresHtml(q.left.figures, { perRow })}</div>`;
    rightCol.innerHTML = `<div class="cac-figures">${figuresHtml(q.right.figures, { perRow })}</div>`;
    row.append(leftCol, rightCol);
    node.appendChild(row);

    const inputs = createNumberInputs({
      slots: [
        { id: 'left' },
        { id: 'right' },
      ],
      onChanged: () => ctx.changed(),
    });
    // Move the two answer boxes directly under their figure groups.
    const slotCells = [...inputs.el.querySelectorAll('.input-cell')];
    leftCol.appendChild(slotCells[0]);
    rightCol.appendChild(slotCells[1]);

    const slot = symbolSlot();
    const symbolRow = h('div', { class: 'cac-symbol-row' });
    symbolRow.appendChild(slot.el);
    node.appendChild(symbolRow);

    const picker = createSymbolPicker({
      symbols: q.symbols,
      onPick: (sym) => {
        if (revealed) return;
        picked = sym;
        slot.set(sym);
        picker.mark(sym);
        ctx.changed();
      },
    });
    node.appendChild(picker.el);
    node.appendChild(inputs.el);

    const expected = () => q.answer;

    return {
      el: node,
      givenText() {
        return `${inputs.get('left') || '?'} ${picked || '?'} ${inputs.get('right') || '?'}`;
      },
      check() {
        const want = expected();
        return inputs.get('left') === want.left && inputs.get('right') === want.right && picked === want.symbol;
      },
      touched() {
        return inputs.touched() || picked !== null;
      },
      flash(kind) {
        inputs.flash(kind);
        picker.flash(kind);
      },
      reveal() {
        revealed = true;
        const want = expected();
        inputs.reveal([want.left, want.right]);
        slot.set(want.symbol);
        picker.mark(want.symbol);
      },
      clear() {
        revealed = false;
        picked = null;
        inputs.reset();
        slot.set(null);
        picker.mark(null);
      },
      destroy() {},
    };
  },
});

/* 5. outline ------------------------------------------------------------- */
register({
  name: 'outline',
  label: 'Apibraukti kortelę (piešimas)',
  defaultInstruction: 'Apibrauk kortelę, kurioje yra daugiau.',
  normalize(q) {
    q.cards = normalizeCards(q, 'k');
    q.answer = Array.isArray(q.answer) ? q.answer.map(String).filter((id) => q.cards.some((c) => c.id === id)) : [];
  },
  answer(q) {
    return q.answer;
  },
  answerText(q) {
    return q.answer.length ? `Apibraukta: ${q.answer.join(', ')}` : 'Nėra teisingos kortelės';
  },
  revealText: () => 'Teisinga kortelė pažymėta mėlynai 🔵',
  create(q, ctx) {
    const board = createBoard(q, ctx, { markKind: 'outline' });
    return {
      el: board.el,
      check() {
        return sameValue([...board.selected], q.answer);
      },
      touched() {
        return board.selected.size > 0;
      },
      flash(kind) {
        board.el.classList.remove('flash-ok', 'flash-bad');
        void board.el.offsetWidth;
        board.el.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
      },
      reveal() {
        board.setRevealedLayers(true);
        board.layer.setEnabled(false);
        board.layer.setMarks(q.answer, 'reveal');
      },
      clear() {
        board.setRevealedLayers(false);
        board.layer.setEnabled(true);
        board.selected.clear();
        board.layer.setMarks([]);
        board.el.classList.remove('flash-ok', 'flash-bad');
      },
      destroy() {
        board.layer.destroy();
      },
    };
  },
});

/* 6. crossOut ------------------------------------------------------------ */
register({
  name: 'crossOut',
  label: 'Nubraukti neteisingą kortelę (piešimas)',
  defaultInstruction: 'Nubrauk kortelę, kuri neteisinga.',
  normalize(q) {
    q.cards = normalizeCards(q, 'k');
    q.answer = Array.isArray(q.answer) ? q.answer.map(String).filter((id) => q.cards.some((c) => c.id === id)) : [];
  },
  answer(q) {
    return q.answer;
  },
  answerText(q) {
    return q.answer.length ? `Nubraukta: ${q.answer.join(', ')}` : 'Nėra klaidingos kortelės';
  },
  revealText: () => 'Klaidingos kortelės pažymėtos mėlynai 🔵',
  create(q, ctx) {
    const board = createBoard(q, ctx, { markKind: 'cross' });
    return {
      el: board.el,
      check() {
        return sameValue([...board.selected], q.answer);
      },
      touched() {
        return board.selected.size > 0;
      },
      flash(kind) {
        board.el.classList.remove('flash-ok', 'flash-bad');
        void board.el.offsetWidth;
        board.el.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
      },
      reveal() {
        board.setRevealedLayers(true);
        board.layer.setEnabled(false);
        board.layer.setMarks(q.answer, 'reveal');
      },
      clear() {
        board.setRevealedLayers(false);
        board.layer.setEnabled(true);
        board.selected.clear();
        board.layer.setMarks([]);
        board.el.classList.remove('flash-ok', 'flash-bad');
      },
      destroy() {
        board.layer.destroy();
      },
    };
  },
});

/* 7. write --------------------------------------------------------------- */
register({
  name: 'write',
  label: 'Parašyti skaičių atsakyme',
  defaultInstruction: 'Įrašyk teisingą skaičių.',
  normalize(q) {
    const raw = Array.isArray(q.items) && q.items.length ? q.items : q.item ? [q.item] : [];
    q.items = raw
      .map((item, i) => {
        const answer = item && item.answer !== undefined ? String(item.answer).trim() : '';
        if (answer === '') return null;
        return { id: String((item && item.id) || `w${i + 1}`), prompt: String((item && item.prompt) || ''), answer };
      })
      .filter(Boolean);
    if (!q.items.length) q.items = [{ id: 'w1', prompt: 'Kiek yra 2 + 2?', answer: '4' }];
  },
  answer(q) {
    return q.items.map((i) => i.answer);
  },
  answerText(q) {
    return q.items.map((i) => i.answer).join('  ·  ');
  },
  create(q, ctx) {
    const nodeEl = h('div', { class: 'q-block q-write' });
    const inputs = createNumberInputs({
      // Every condition is read aloud by its own 🔊 button.
      slots: q.items.map((item) => ({ id: item.id, prompt: item.prompt, say: item.prompt })),
      onChanged: () => ctx.changed(),
    });
    nodeEl.appendChild(inputs.el);

    return {
      el: nodeEl,
      givenText: () => inputs.values().map((v) => v || '?').join('  ·  '),
      check() {
        return sameValue(inputs.values(), q.answer);
      },
      touched: () => inputs.touched(),
      flash: (kind) => inputs.flash(kind),
      reveal: () => inputs.reveal(q.answer),
      clear: () => inputs.reset(),
      destroy() {},
    };
  },
});

/* 7a. story — a word problem with ONE answer ----------------------------- */

/**
 * "Pasakojimo uždavinys": the story is the question's `instruction` (the big
 * text at the top), so the 🔊 next to it reads the whole task aloud. The child
 * gives exactly ONE answer:
 *
 *   · a number typed on the shared number pad  →  { "answer": 2 }
 *   · one of a few choices (a name/thing)      →  { "options": ["Rūta", "Benas"],
 *                                                   "answer": "Benas" }
 *
 * Without `options` the answer is a number in one empty box (like `write`);
 * with `options` the child taps one of the buttons. `answer` may name the
 * correct option by its `label` or (better) by its `id`.
 */
const storyDef = {
  name: 'story',
  label: 'Pasakojimo uždavinys (vienas atsakymas)',
  defaultInstruction: 'Perskaityk pasakojimą ir atsakyk į klausimą.',
  normalize(q) {
    const raw = Array.isArray(q.options) ? q.options : [];
    q.options = raw
      .map((option, i) => {
        if (option && typeof option === 'object') {
          const label = String(option.label ?? '').trim();
          return label === '' ? null : { id: String(option.id || `o${i + 1}`), label };
        }
        const label = String(option ?? '').trim();
        return label === '' ? null : { id: `o${i + 1}`, label };
      })
      .filter(Boolean);

    if (q.options.length) {
      const want = String(q.answer ?? '').trim();
      const hit = q.options.find((o) => o.id === want || o.label === want);
      q.answer = hit ? hit.id : want || null;
    } else {
      const n = intOf(q.answer);
      q.answer = n === null ? null : String(n);
    }
  },
  answer(q) {
    return q.answer;
  },
  answerText(q) {
    if (q.options && q.options.length) {
      const hit = q.options.find((o) => o.id === q.answer);
      return hit ? hit.label : String(q.answer ?? '');
    }
    return String(q.answer ?? '');
  },
  create(q, ctx) {
    // Number answer: one empty box + the shared number pad.
    if (!q.options.length) {
      const node = h('div', { class: 'q-block q-story q-story--number' });
      const inputs = createNumberInputs({ slots: [{ id: 'answer' }], onChanged: () => ctx.changed() });
      node.appendChild(inputs.el);
      return {
        el: node,
        givenText: () => inputs.values()[0] || '?',
        check: () => String(inputs.values()[0]) === String(q.answer),
        touched: () => inputs.touched(),
        flash: (kind) => inputs.flash(kind),
        reveal: () => inputs.reveal([q.answer]),
        clear: () => inputs.reset(),
        destroy() {},
      };
    }

    // Choice answer: exactly one option has to be tapped.
    let picked = null;
    let revealed = false;
    const node = h('div', { class: 'q-block q-story q-story--choice' });
    const list = h('div', { class: 'check-list', 'data-count': String(q.options.length) });
    const shown = new Map();

    const paint = () => {
      q.options.forEach((option) => {
        const el = shown.get(option.id);
        if (!el) return;
        const isPicked = picked === option.id;
        el.btn.classList.toggle('is-picked', isPicked && !el.bad);
        el.btn.classList.toggle('is-solution', el.good);
        el.btn.classList.toggle('is-bad', el.bad);
        el.box.textContent = el.good ? '✓' : el.bad ? '✗' : isPicked ? '✓' : '';
        el.btn.setAttribute('aria-pressed', String(isPicked));
      });
    };

    q.options.forEach((option) => {
      const btn = h('button', { type: 'button', class: 'check-item', 'data-option': option.id, 'aria-pressed': 'false' });
      const box = h('span', { class: 'check-box' });
      const text = h('span', { class: 'check-text', text: option.label });
      btn.append(box, text);
      btn.addEventListener('click', () => {
        if (revealed) return;
        picked = option.id;
        paint();
        ctx.changed();
      });
      list.appendChild(btn);
      shown.set(option.id, { btn, box, good: false, bad: false });
    });

    node.appendChild(list);

    return {
      el: node,
      givenText() {
        const hit = q.options.find((o) => o.id === picked);
        return hit ? hit.label : '?';
      },
      check() {
        return picked === q.answer;
      },
      touched() {
        return picked !== null;
      },
      flash(kind) {
        list.querySelectorAll('.check-item').forEach((btn) => {
          btn.classList.remove('flash-ok', 'flash-bad');
          void btn.offsetWidth;
          btn.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
        });
      },
      reveal() {
        revealed = true;
        q.options.forEach((option) => {
          const el = shown.get(option.id);
          if (!el) return;
          el.good = option.id === q.answer;
          el.bad = !el.good && picked === option.id;
        });
        paint();
      },
      clear() {
        revealed = false;
        picked = null;
        shown.forEach((el) => {
          el.good = false;
          el.bad = false;
        });
        paint();
      },
      destroy() {
        shown.clear();
      },
    };
  },
};

register(storyDef);

// The very same widget, meant for the choice-style comparison stories ("Kas
// surinko daugiau?"): it exists so the generator can offer them under their own
// chip. Hand-written quizzes can use `story` for both kinds.
register({
  ...storyDef,
  name: 'storyChoice',
  label: 'Pasakojimo uždavinys (pasirinkti atsakymą)',
});

// The difference story - "Kiek daugiau obuolių turi Lukas?" - again the same
// widget with its own chip. The answer is the DIFFERENCE of the two amounts
// (typed on the number pad), while the story text carries the comparison the
// child has to translate into "−".
register({
  ...storyDef,
  name: 'storyMore',
  label: 'Pasakojimo uždavinys (kiek daugiau / mažiau)',
});

// The "suvokimo užduotis" (comprehension task): a one-line reading task whose
// numbers are WORDS ("Iš dviejų atimk tris ir parašyk atsakymą.") or digits,
// while the whole task keeps ONE style. It is about decoding the sentence, so
// it is silent by default like the other story types; the answer is a number
// typed on the shared pad (the numeric branch of the story widget).
register({
  ...storyDef,
  name: 'storyRead',
  label: 'Suvokimo užduotis (perskaityk ir parašyk)',
  defaultInstruction: 'Perskaityk ir atsakyk į klausimą.',
});

/* 7b. missingNumber / missingNumberSub ----------------------------------- */

/**
 * Where the empty box of a `missingNumber` row sits. The blank is on the left
 * (the first number) or in the middle (the second number): `[ ] − 7 = 2`,
 * `9 − [ ] = 2`. `"right"` (the result) exists for a hand-written quiz, but then
 * the missing number is the answer itself, so the generator never uses it.
 */
const MISSING_POSITIONS = ['left', 'middle', 'right'];

/** The number that belongs in the empty box of a row. */
function missingValue(row) {
  if (row.blank === 'middle') return row.b;
  if (row.blank === 'right') return row.result;
  return row.a;
}

/**
 * One equation with a single empty box (`[ ] − 7 = 2`), so the child has to
 * find the number that is not written instead of the result. Only one box per
 * row and only one number to type, and the box is never where the result goes.
 */
function missingNumberType({ name, label, op, defaultInstruction }) {
  const sign = opSign(op);
  const numHtml = (n) => `<span class="eq-num">${esc(String(n))}</span>`;
  const signHtml = (s) => `<span class="eq-sign">${esc(s)}</span>`;

  return {
    name,
    label,
    defaultInstruction,
    normalize(q) {
      const rows = Array.isArray(q.rows) ? q.rows : [];
      q.rows = rows
        .map((row, i) => {
          const r = row && typeof row === 'object' ? row : {};
          const a = intOf(r.a);
          const b = intOf(r.b);
          if (a === null || b === null) return null;
          const blank = MISSING_POSITIONS.includes(r.blank) ? r.blank : 'left';
          return { id: String(r.id || `m${i + 1}`), a, b, blank, result: opApply(a, b, op) };
        })
        .filter(Boolean);
      if (!q.rows.length) {
        const a = op === '-' ? 5 : 4;
        const b = op === '-' ? 2 : 3;
        q.rows = [{ id: 'm1', a, b, blank: 'left', result: opApply(a, b, op) }];
      }
    },
    answer(q) {
      return q.rows.map((r) => String(missingValue(r)));
    },
    answerText(q) {
      return q.rows.map((r) => `${r.a} ${sign} ${r.b} = ${r.result}`).join('  ·  ');
    },
    create(q, ctx) {
      const nodeEl = h('div', { class: 'q-block q-missing' });
      const list = h('div', { class: 'add-rows' });
      const slots = q.rows.map((row) => ({ id: row.id }));
      const inputs = createNumberInputs({ slots, onChanged: () => ctx.changed() });
      const cells = [...inputs.el.querySelectorAll('.input-cell')];
      let next = 0;
      const takeCell = (parent) => {
        const cell = cells[next++];
        if (!cell) return;
        cell.classList.add('input-cell--eq');
        parent.appendChild(cell);
      };

      q.rows.forEach((row) => {
        const rowEl = h('div', { class: 'add-row' });
        const eq = h('div', { class: 'add-equation' });
        const push = (html) => eq.insertAdjacentHTML('beforeend', html);
        if (row.blank === 'left') takeCell(eq);
        else push(numHtml(row.a));
        push(signHtml(sign));
        if (row.blank === 'middle') takeCell(eq);
        else push(numHtml(row.b));
        push(signHtml('='));
        if (row.blank === 'right') takeCell(eq);
        else push(numHtml(row.result));
        rowEl.appendChild(eq);
        list.appendChild(rowEl);
      });

      nodeEl.append(list, inputs.el);

      return {
        el: nodeEl,
        givenText: () => inputs.values().map((v) => v || '?').join('  ·  '),
        check: () => sameValue(inputs.values(), q.answer),
        touched: () => inputs.touched(),
        flash: (kind) => inputs.flash(kind),
        reveal: () => inputs.reveal(q.answer),
        clear: () => inputs.reset(),
        destroy() {},
      };
    },
  };
}

register(missingNumberType({
  name: 'missingNumber',
  label: 'Įrašyti trūkstamą skaičių sudėtyje',
  op: '+',
  defaultInstruction: 'Įrašyk trūkstamą skaičių.',
}));

register(missingNumberType({
  name: 'missingNumberSub',
  label: 'Įrašyti trūkstamą skaičių atimtyje',
  op: '-',
  defaultInstruction: 'Įrašyk trūkstamą skaičių.',
}));

/* 7c. mixedOps ----------------------------------------------------------- */

/**
 * "Sudėtis ir atimtis kartu": the same `a ? b = [ ]` equation as the
 * missingNumber family, but every row picks its own sign, so addition and
 * subtraction come mixed inside one exercise (`3 + 4 = [ ]`, `9 − 2 = [ ]`).
 * Both numbers are printed and only the result is typed.
 */
register({
  name: 'mixedOps',
  label: 'Sudėtis ir atimtis kartu',
  defaultInstruction: 'Suskaičiuok ir įrašyk atsakymus.',
  normalize(q) {
    const rows = Array.isArray(q.rows) ? q.rows : [];
    q.rows = rows
      .map((row, i) => {
        const r = row && typeof row === 'object' ? row : {};
        const a = intOf(r.a);
        const b = intOf(r.b);
        if (a === null || b === null) return null;
        // "−" only when something is left (parity with quiz_derived_answer).
        const op = r.op === '-' && a > b ? '-' : '+';
        return { id: String(r.id || `x${i + 1}`), a, b, op, result: opApply(a, b, op) };
      })
      .filter(Boolean);
    if (!q.rows.length) q.rows = [{ id: 'x1', a: 5, b: 3, op: '+', result: 8 }];
  },
  answer(q) {
    return q.rows.map((r) => String(r.result));
  },
  answerText(q) {
    return q.rows.map((r) => `${r.a} ${opSign(r.op)} ${r.b} = ${r.result}`).join('  ·  ');
  },
  create(q, ctx) {
    const rows = q.rows.map((row) => ({ ...row, picture: '' }));
    const board = createEquationRows({ rows, write: 'result', onChanged: () => ctx.changed() });
    return {
      el: board.el,
      givenText: () => board.inputs.values().map((v) => v || '?').join('  ·  '),
      // Positional check: `sameValue()` ignores the order, which would let a
      // wrong row hide behind a duplicate result (two rows answering 8).
      check: () => board.inputs.values().every((v, i) => String(v) === String(q.answer[i])),
      touched: () => board.inputs.touched(),
      flash: (kind) => board.inputs.flash(kind),
      reveal: () => board.inputs.reveal(q.answer),
      clear: () => board.inputs.reset(),
      destroy() {},
    };
  },
});

/* 8. addPicture / subtractPicture ---------------------------------------- */

/**
 * The picture equation. Addition: two groups, then `[ ] + [ ] = [ ]`.
 * Subtraction: one group of things with the last `right` of them crossed out
 * ("take away"), then `[ ] − [ ] = [ ]`. Same widget and the same answer rule -
 * only the sign and the picture differ.
 */
function pictureType({ name, label, op, defaultInstruction }) {
  return {
    name,
    label,
    defaultInstruction,
    normalize(q) {
      q.write = q.write === 'result' ? 'result' : 'all';
      const rows = Array.isArray(q.rows) ? q.rows : [];
      q.rows = rows
        .map((row, i) => {
          const r = row && typeof row === 'object' ? row : {};
          const left = normalizeSide(r.left, 'sky');
          const right = normalizeSide(r.right, 'amber');
          const a = sideValue(left);
          const b = sideValue(right);
          if (a === null || b === null) return null;
          return { id: String(r.id || `r${i + 1}`), left, right, a, b, result: opApply(a, b, op) };
        })
        .filter(Boolean);
      if (!q.rows.length) {
        const minus = op === '-';
        const left = normalizeSide(minus ? { shape: 'circle', count: 5, color: 'sky' } : { shape: 'triangle', count: 4, color: 'amber' });
        const right = normalizeSide(minus ? 2 : { shape: 'square', count: 1, color: 'sky' });
        q.rows = [{ id: 'r1', left, right, a: minus ? 5 : 4, b: minus ? 2 : 1, result: minus ? 3 : 5 }];
      }
    },
    answer(q) {
      if (q.write === 'result') return q.rows.map((r) => String(r.result));
      return q.rows.flatMap((r) => [String(r.a), String(r.b), String(r.result)]);
    },
    answerText(q) {
      return q.rows.map((r) => `${r.a} ${opSign(op)} ${r.b} = ${r.result}`).join('  ·  ');
    },
    create(q, ctx) {
      const rows = q.rows.map((row) => {
        // One equation per card means the groups can use the whole width (4 per
        // row instead of 3), so the figures stay big enough to count on a phone.
        const perRow = Math.max(sharedPerRow(row.a, row.b, 4), 1);
        return {
          ...row,
          picture: op === '-'
            ? `<div class="add-picture add-picture--take">` +
              `<div class="eq-group">${figuresHtml(row.left, { cross: row.b })}</div>` +
              `</div>`
            : `<div class="add-picture">` +
              `<div class="eq-group">${figuresHtml(row.left, { perRow })}</div>` +
              `<span class="add-divider"></span>` +
              `<div class="eq-group">${figuresHtml(row.right, { perRow })}</div>` +
              `</div>`,
        };
      });
      const board = createEquationRows({ rows, write: q.write, op, onChanged: () => ctx.changed() });
      return {
        el: board.el,
        check: () => sameValue(board.inputs.values(), q.answer),
        touched: () => board.inputs.touched(),
        flash: (kind) => board.inputs.flash(kind),
        reveal: () => board.inputs.reveal(q.answer),
        clear: () => board.inputs.reset(),
        destroy() {},
      };
    },
  };
}

register(pictureType({
  name: 'addPicture',
  label: 'Sudėtis iš paveikslėlio',
  op: '+',
  defaultInstruction: 'Parašyk ir atlik pavaizduotus sudėties veiksmus.',
}));

register(pictureType({
  name: 'subtractPicture',
  label: 'Atimtis iš paveikslėlio',
  op: '-',
  defaultInstruction: 'Suskaičiuok, kiek nubraukta, ir parašyk atimties veiksmą.',
}));

/* 9. domino / subtractDomino --------------------------------------------- */

/** The domino equation: the two halves are read from the pips, the sum or the difference is written. */
function dominoType({ name, label, op, defaultInstruction }) {
  return {
    name,
    label,
    defaultInstruction,
    normalize(q) {
      q.write = q.write === 'result' ? 'result' : 'all';
      const rows = Array.isArray(q.rows) ? q.rows : [];
      q.rows = rows
        .map((row, i) => {
          const outer = row && typeof row === 'object' ? row : {};
          const r = outer.domino && typeof outer.domino === 'object' ? outer.domino : outer;
          const a = pipCount(r.left);
          const b = pipCount(r.right);
          if (a === null || b === null) return null;
          return {
            id: String(outer.id || r.id || `d${i + 1}`),
            a,
            b,
            result: opApply(a, b, op),
            color: String(r.color || 'slate'),
          };
        })
        .filter(Boolean);
      if (!q.rows.length) q.rows = [{ id: 'd1', a: 4, b: op === '-' ? 2 : 4, result: op === '-' ? 2 : 8, color: 'slate' }];
    },
    answer(q) {
      if (q.write === 'result') return q.rows.map((r) => String(r.result));
      return q.rows.flatMap((r) => [String(r.a), String(r.b), String(r.result)]);
    },
    answerText(q) {
      return q.rows.map((r) => `${r.a} ${opSign(op)} ${r.b} = ${r.result}`).join('  ·  ');
    },
    create(q, ctx) {
      const rows = q.rows.map((row) => ({
        ...row,
        picture: `<div class="eq-group">${dominoHtml(row.a, row.b, { color: row.color })}</div>`,
      }));
      const board = createEquationRows({ rows, write: q.write, op, onChanged: () => ctx.changed() });
      return {
        el: board.el,
        check: () => sameValue(board.inputs.values(), q.answer),
        touched: () => board.inputs.touched(),
        flash: (kind) => board.inputs.flash(kind),
        reveal: () => board.inputs.reveal(q.answer),
        clear: () => board.inputs.reset(),
        destroy() {},
      };
    },
  };
}

register(dominoType({
  name: 'domino',
  label: 'Sudėtis iš domino kauliukų',
  op: '+',
  defaultInstruction: 'Suskaičiuok taškus abiejose pusėse ir parašyk atsakymą.',
}));

register(dominoType({
  name: 'subtractDomino',
  label: 'Atimtis iš domino kauliukų',
  op: '-',
  defaultInstruction: 'Suskaičiuok taškus abiejose pusėse ir parašyk skirtumą.',
}));

/* 10. multiCheck / subtractCheck / mixedCheck ---------------------------- */
register(checkType({
  name: 'multiCheck',
  label: 'Pažymėti teisingus veiksmus',
  op: '+',
  defaultInstruction: 'Pažymėk veiksmus, kurie duoda nurodytą skaičių.',
}));

register(checkType({
  name: 'subtractCheck',
  label: 'Pažymėti teisingus atimties veiksmus',
  op: '-',
  defaultInstruction: 'Pažymėk atimties veiksmus, kurių atsakymas yra nurodytas skaičius.',
}));

register(checkType({
  name: 'mixedCheck',
  label: 'Pažymėti teisingus veiksmus (sudėtis ir atimtis)',
  op: '+',
  mixed: true,
  defaultInstruction: 'Pažymėk veiksmus, kurių atsakymas yra nurodytas skaičius.',
}));

/* 11. pickAddends / subtractPair ----------------------------------------- */
register(bankType({
  name: 'pickAddends',
  label: 'Sudėti du skaičius iš duotų skaičių',
  op: '+',
  equal: false,
  defaultInstruction: 'Sudėk du skaičius ir nubrauk tą, kurio nereikia.',
}));

register(bankType({
  name: 'subtractPair',
  label: 'Atimti du skaičius iš duotų skaičių',
  op: '-',
  equal: false,
  defaultInstruction: 'Atimk du skaičius ir nubrauk tą, kurio nereikia.',
}));

/* 12. equalSums / equalDifferences --------------------------------------- */
register(bankType({
  name: 'equalSums',
  label: 'Sudėti taip, kad abi pusės būtų lygios',
  op: '+',
  equal: true,
  defaultInstruction: 'Sudėk skaičius taip, kad abiejų veiksmų gautum tiek pat.',
}));

register(bankType({
  name: 'equalDifferences',
  label: 'Atimti taip, kad abi pusės būtų lygios',
  op: '-',
  equal: true,
  defaultInstruction: 'Atimk skaičius taip, kad abiejų veiksmų gautum tiek pat.',
}));

/**
 * One "frame of circles" row, shared by `drawMissing` ("turi būti") and its
 * subtraction twin `crossOutExtra` ("turi likti"): the goal box plus the frame
 * the child taps.
 */
function createFrameRow({ label, target, cols }) {
  const row = h('div', { class: 'draw-item' });
  row.innerHTML =
    `<div class="draw-goal"><span class="draw-goal-label">${esc(label)}</span>` +
    `<span class="target-box">${target}</span></div>` +
    `<div class="draw-frame" style="--cols:${cols}"></div>`;
  return { row, frame: row.querySelector('.draw-frame') };
}

/* 13. drawMissing -------------------------------------------------------- */
register({
  name: 'drawMissing',
  label: 'Nupiešti trūkstamus apskritimus',
  defaultInstruction: 'Kiek dar trūksta? Nupiešk trūkstamus.',
  normalize(q) {
    const raw = Array.isArray(q.items) ? q.items : [];
    q.items = raw
      .map((item, i) => {
        const it = item && typeof item === 'object' ? item : {};
        const target = Math.trunc(Number(it.target));
        if (!Number.isFinite(target) || target < 1 || target > 20) return null;
        const have = Math.max(0, Math.min(target, Math.trunc(Number(it.have)) || 0));
        // The frame is deliberately bigger than the number that must be there:
        // otherwise a child could just fill every empty cell and be right
        // without ever counting.
        const asked = Math.trunc(Number(it.cells));
        const cells = Number.isFinite(asked) && asked > 0
          ? Math.max(target, Math.min(30, asked))
          : Math.min(30, Math.max(target + 2, 4));
        const figure = normalizeSide(
          it.figures || { shape: it.shape || 'circle', color: it.color || 'sky' },
          'sky',
        );
        return {
          id: String(it.id || `m${i + 1}`),
          target,
          have,
          cells,
          shape: figure.shape || 'circle',
          color: figure.color || 'sky',
        };
      })
      .filter(Boolean);
    if (!q.items.length) q.items = [{ id: 'm1', target: 3, have: 1, cells: 6, shape: 'circle', color: 'sky' }];
  },
  answer(q) {
    return q.items.map((it) => String(it.target));
  },
  answerText(q) {
    return q.items
      .map((it) => `${it.target}: jau yra ${it.have}, trūksta ${it.target - it.have}`)
      .join('  ·  ');
  },
  revealText: () => 'Mėlyni apskritimai – kiek dar reikėjo nupiešti 🔵',
  create(q, ctx) {
    let revealed = false;
    const node = h('div', { class: 'q-block q-draw-missing' });
    const items = q.items.map((item) => {
      const { row, frame } = createFrameRow({
        label: 'turi būti',
        target: item.target,
        cols: Math.min(item.cells, 5),
      });
      const drawn = new Set();
      const cells = [];
      for (let c = 0; c < item.cells; c++) {
        const cell = h('button', { type: 'button', class: 'draw-cell', 'data-cell': String(c) });
        if (c < item.have) {
          cell.classList.add('is-given');
          cell.setAttribute('aria-label', 'Jau nupieštas apskritimas');
          cell.innerHTML = shapeSvg(item.shape, { color: item.color });
          cell.disabled = true;
        } else {
          cell.setAttribute('aria-label', 'Tuščias langelis – bakstelk ir nupieši');
          cell.addEventListener('click', () => {
            if (revealed) return;
            if (drawn.has(c)) drawn.delete(c);
            else drawn.add(c);
            paint();
            ctx.changed();
          });
        }
        frame.appendChild(cell);
        cells.push(cell);
      }
      node.appendChild(row);

      function paint() {
        cells.forEach((cell, c) => {
          if (c < item.have) return;
          const on = drawn.has(c);
          const solved = cell.classList.contains('is-solution');
          cell.classList.toggle('is-drawn', on && !solved);
          cell.innerHTML = on || solved ? shapeSvg(item.shape, { color: item.color }) : '';
        });
      }

      return { item, cells, drawn, paint };
    });

    return {
      el: node,
      check() {
        return items.every(({ item, drawn }) => item.have + drawn.size === item.target);
      },
      touched() {
        return items.some(({ drawn }) => drawn.size > 0);
      },
      flash(kind) {
        node.querySelectorAll('.draw-cell:not(.is-given)').forEach((cell) => {
          cell.classList.remove('flash-ok', 'flash-bad');
          void cell.offsetWidth;
          cell.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
        });
      },
      reveal() {
        revealed = true;
        items.forEach(({ item, cells, drawn, paint }) => {
          drawn.clear();
          cells.forEach((cell) => cell.classList.remove('is-solution'));
          const need = item.target - item.have;
          for (let c = item.have; c < Math.min(item.cells, item.have + need); c++) {
            cells[c].classList.add('is-solution');
          }
          paint();
        });
      },
      clear() {
        revealed = false;
        items.forEach(({ cells, drawn, paint }) => {
          drawn.clear();
          cells.forEach((cell) => cell.classList.remove('is-solution', 'flash-ok', 'flash-bad'));
          paint();
        });
      },
      destroy() {},
    };
  },
});

/* 14. crossOutExtra ------------------------------------------------------ */

/**
 * The subtraction twin of `drawMissing`: the frame is full of circles and the
 * child crosses out as many as have to go ("turi likti 3"). The check counts
 * the crossed out circles, so it does not matter which ones are taken.
 */
register({
  name: 'crossOutExtra',
  label: 'Nubraukti, kiek reikia atimti',
  defaultInstruction: 'Turi likti tiek, kiek parašyta. Nubrauk, kiek reikia atimti.',
  normalize(q) {
    const raw = Array.isArray(q.items) ? q.items : [];
    q.items = raw
      .map((item, i) => {
        const it = item && typeof item === 'object' ? item : {};
        const have = Math.trunc(Number(it.have));
        if (!Number.isFinite(have) || have < 2 || have > 20) return null;
        // At least one circle has to be crossed out and at least one has to stay.
        const wanted = Math.trunc(Number(it.target));
        const target = Number.isFinite(wanted) ? Math.max(1, Math.min(have - 1, wanted)) : have - 1;
        const figure = normalizeSide(
          it.figures || { shape: it.shape || 'circle', color: it.color || 'sky' },
          'sky',
        );
        return {
          id: String(it.id || `m${i + 1}`),
          target,
          have,
          shape: figure.shape || 'circle',
          color: figure.color || 'sky',
        };
      })
      .filter(Boolean);
    if (!q.items.length) q.items = [{ id: 'm1', target: 3, have: 6, shape: 'circle', color: 'sky' }];
  },
  answer(q) {
    return q.items.map((it) => String(it.have - it.target));
  },
  answerText(q) {
    return q.items.map((it) => `${it.have} − ${it.have - it.target} = ${it.target}`).join('  ·  ');
  },
  revealText: () => 'Nubraukti reikėjo tiek, kiek pažymėta 🟢',
  create(q, ctx) {
    let revealed = false;
    const node = h('div', { class: 'q-block q-draw-missing' });
    const items = q.items.map((item) => {
      const { row, frame } = createFrameRow({
        label: 'turi likti',
        target: item.target,
        cols: Math.min(item.have, 5),
      });
      const crossed = new Set();
      const cells = [];
      for (let c = 0; c < item.have; c++) {
        const cell = h('button', { type: 'button', class: 'draw-cell', 'data-cell': String(c) });
        cell.setAttribute('aria-label', 'Bakstelk ir nubrauksi apskritimą');
        cell.innerHTML = shapeSvg(item.shape, { color: item.color });
        cell.addEventListener('click', () => {
          if (revealed) return;
          if (crossed.has(c)) crossed.delete(c);
          else crossed.add(c);
          paint();
          ctx.changed();
        });
        frame.appendChild(cell);
        cells.push(cell);
      }
      node.appendChild(row);

      function paint() {
        cells.forEach((cell, c) => {
          const solved = cell.classList.contains('is-solution');
          cell.classList.toggle('is-crossed', crossed.has(c) || solved);
        });
      }

      return { item, cells, crossed, paint };
    });

    return {
      el: node,
      check() {
        return items.every(({ item, crossed }) => crossed.size === item.have - item.target);
      },
      touched() {
        return items.some(({ crossed }) => crossed.size > 0);
      },
      flash(kind) {
        node.querySelectorAll('.draw-cell').forEach((cell) => {
          cell.classList.remove('flash-ok', 'flash-bad');
          void cell.offsetWidth;
          cell.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
        });
      },
      reveal() {
        revealed = true;
        items.forEach(({ item, cells, crossed, paint }) => {
          crossed.clear();
          cells.forEach((cell) => cell.classList.remove('is-solution'));
          const need = item.have - item.target;
          for (let c = item.have - need; c < item.have; c++) cells[c].classList.add('is-solution');
          paint();
        });
      },
      clear() {
        revealed = false;
        items.forEach(({ cells, crossed, paint }) => {
          crossed.clear();
          cells.forEach((cell) => cell.classList.remove('is-solution', 'flash-ok', 'flash-bad'));
          paint();
        });
      },
      destroy() {},
    };
  },
});

/* 15. match -------------------------------------------------------------- */
register({
  name: 'match',
  label: 'Sujungti veiksmą su paveikslėliu (piešimas)',
  defaultInstruction: 'Sujunk veiksmą su jį vaizduojančiu paveikslėliu.',
  normalize(q) {
    q.result = q.result === true;
    q.op = q.op === '-' ? '-' : '+';
    q.left = (Array.isArray(q.left) ? q.left : [])
      .map((item, i) => {
        const it = item && typeof item === 'object' ? item : {};
        const pair = Array.isArray(it.sum) ? it.sum : [];
        const a = Math.trunc(Number(it.a ?? pair[0]));
        const b = Math.trunc(Number(it.b ?? pair[1]));
        if (!Number.isFinite(a) || !Number.isFinite(b)) return null;
        return { id: String(it.id || `l${i + 1}`), a, b };
      })
      .filter(Boolean);
    q.right = (Array.isArray(q.right) ? q.right : [])
      .map((item, i) => {
        const it = item && typeof item === 'object' ? item : {};
        const left = normalizeSide(it.left ?? it.leftFigures, 'sky');
        const right = normalizeSide(it.right ?? it.rightFigures, 'amber');
        if (left.kind !== 'figures' || right.kind !== 'figures') return null;
        return { id: String(it.id || `r${i + 1}`), left, right };
      })
      .filter(Boolean);
    if (!Array.isArray(q.answer) || !q.answer.length) q.answer = deriveMatchAnswer(q);
    q.answer = (Array.isArray(q.answer) ? q.answer : []).map(String);
    q.resultAnswer = q.left.map((l) => String(opApply(l.a, l.b, q.op)));
  },
  answer(q) {
    return q.answer;
  },
  answerText(q) {
    const pairs = q.answer.map((key) => {
      const l = q.left.find((item) => item.id === String(key).split('>')[0]);
      return l ? `${l.a} ${opSign(q.op)} ${l.b}` : String(key);
    });
    return pairs.length ? `Tinka: ${pairs.join(', ')}` : 'Nėra teisingų porų';
  },
  revealText: () => 'Teisingos poros sujungtos mėlynai 🔵',
  create(q, ctx) {
    let links = [];
    let revealed = false;

    const board = h('div', { class: 'board board--match' });
    const cols = h('div', { class: 'match-cols' });
    const leftCol = h('div', { class: 'match-col' });
    const rightCol = h('div', { class: 'match-col' });

    const inputs = q.result
      ? createNumberInputs({ slots: q.left.map((l) => ({ id: `${l.id}-res` })), onChanged: () => ctx.changed() })
      : null;
    const resultCells = inputs ? [...inputs.el.querySelectorAll('.input-cell')] : [];

    const leftEls = new Map();
    q.left.forEach((item, i) => {
      const card = h('div', { class: 'match-item', 'data-target': item.id });
      card.innerHTML = `<div class="match-sum"><span>${item.a} ${opSign(q.op)} ${item.b} =</span></div>`;
      if (inputs && resultCells[i]) {
        resultCells[i].classList.add('input-cell--eq');
        card.querySelector('.match-sum').appendChild(resultCells[i]);
      }
      leftCol.appendChild(card);
      leftEls.set(item.id, card);
    });

    const rightEls = new Map();
    q.right.forEach((item) => {
      const card = h('div', { class: 'match-item', 'data-target': item.id });
      const perRow = Math.max(sharedPerRow(sideValue(item.left), sideValue(item.right), 3), 1);
      card.innerHTML = q.op === '-'
        // Take away: one group with the taken away figures crossed out.
        ? `<div class="match-picture">` +
          `<div class="eq-group">${figuresHtml(item.left, { cross: sideValue(item.right) })}</div>` +
          `</div>`
        : `<div class="match-picture">` +
          `<div class="eq-group">${figuresHtml(item.left, { perRow })}</div>` +
          `<span class="match-plus">+</span>` +
          `<div class="eq-group">${figuresHtml(item.right, { perRow })}</div>` +
          `</div>`;
      rightCol.appendChild(card);
      rightEls.set(item.id, card);
    });

    cols.append(leftCol, rightCol);
    const svg = svgNode('svg', { class: 'scribble', 'aria-hidden': 'true', focusable: 'false' });
    const tip = h('p', { class: 'board-tip' });
    board.append(cols, svg, tip);

    // The number pad must stay outside the board - the scribble layer on top of
    // the board would swallow its clicks.
    const node = h('div', { class: 'q-block q-match' });
    node.appendChild(board);
    if (inputs) node.appendChild(inputs.el);

    const setTip = (text, kind = '') => {
      tip.textContent = text;
      tip.className = `board-tip ${kind}`.trim();
    };
    const paintTip = () => {
      setTip(
        links.length ? `Sujungta porų: ${links.length} / ${q.left.length}` : '',
        links.length ? 'is-ok' : '',
      );
    };
    setTip(q.hint || '✏️ Brėžk liniją nuo veiksmo iki paveikslėlio');

    const layer = new ScribbleLayer(svg, {
      mode: 'link',
      getTargets: () => [
        ...q.left.map((l) => ({ id: l.id, el: leftEls.get(l.id), side: 'left' })),
        ...q.right.map((r) => ({ id: r.id, el: rightEls.get(r.id), side: 'right' })),
      ],
      onLink: (pair, tapId) => {
        if (revealed) return;
        if (tapId) {
          const at = links.findIndex((p) => p.from === tapId || p.to === tapId);
          if (at === -1) return;
          links.splice(at, 1);
        } else if (pair) {
          // A card can only carry one line, so a new line replaces the old one.
          links = links.filter((p) => p.from !== pair.from && p.to !== pair.to);
          links.push(pair);
        } else {
          setTip('🙂 Linija turi prasidėti ir baigtis kortelėje', 'is-hint');
          board.classList.remove('shake');
          void board.offsetWidth;
          board.classList.add('shake');
          return;
        }
        layer.setLinks(links);
        paintTip();
        ctx.changed();
      },
    });

    return {
      el: node,
      check() {
        const ok = sameValue(links.map((p) => `${p.from}>${p.to}`), q.answer);
        if (!ok || !inputs) return ok;
        return sameValue(inputs.values(), q.resultAnswer);
      },
      touched() {
        return links.length > 0 || (!!inputs && inputs.touched());
      },
      flash(kind) {
        board.querySelectorAll('.match-item').forEach((card) => {
          card.classList.remove('flash-ok', 'flash-bad');
          void card.offsetWidth;
          card.classList.add(kind === 'ok' ? 'flash-ok' : 'flash-bad');
        });
        if (inputs) inputs.flash(kind);
      },
      reveal() {
        revealed = true;
        links = q.answer.map((key) => {
          const [from, to] = String(key).split('>');
          return { from, to };
        });
        layer.setLinks(links, 'reveal');
        layer.setEnabled(false);
        if (inputs) inputs.reveal(q.resultAnswer);
        setTip('Mėlynos linijos rodo teisingas poras 🔵');
      },
      clear() {
        revealed = false;
        links = [];
        layer.setLinks([]);
        layer.setEnabled(true);
        if (inputs) inputs.reset();
        paintTip();
        setTip(q.hint || '✏️ Brėžk liniją nuo veiksmo iki paveikslėlio');
      },
      destroy() {
        layer.destroy();
      },
    };
  },
});

/* ------------------------------------------------------------- helpers -- */

/** A domino pip count: a whole number 0-6, or null when it is not a number. */
function pipCount(value) {
  const n = Math.trunc(Number(value));
  return Number.isFinite(n) && n >= 0 && n <= 6 ? n : null;
}

/** Items of the bank exercises: a target and the numbers to pick from. */
function normalizeBankItems(q, mode, op = '+') {
  const equal = mode === 'equal';
  const least = equal ? 4 : 2;
  const raw = Array.isArray(q.items) ? q.items : [];
  const items = raw
    .map((item, i) => {
      const it = item && typeof item === 'object' ? item : {};
      const target = Math.trunc(Number(it.target));
      const bank = (Array.isArray(it.bank) ? it.bank : [])
        .map((value) => Math.trunc(Number(value)))
        .filter((value) => Number.isFinite(value) && value >= 0 && value <= 20);
      if (!Number.isFinite(target) || target < 1 || bank.length < least) return null;
      return { id: String(it.id || `b${i + 1}`), target, bank };
    })
    .filter(Boolean);
  if (!items.length) {
    if (equal) items.push(op === '-' ? { id: 'b1', target: 3, bank: [5, 2, 4, 1] } : { id: 'b1', target: 4, bank: [3, 1, 2, 2] });
    else items.push(op === '-' ? { id: 'b1', target: 3, bank: [5, 2, 1] } : { id: 'b1', target: 3, bank: [1, 2, 2] });
  }
  return items;
}

/**
 * The pairing key of `match`: the operation the picture stands for. Adding is
 * commutative (the two groups may be swapped), subtracting is not (6 − 2 and
 * 2 − 6 are different pictures).
 */
function matchKeyOf(a, b, op = '+') {
  return op === '-' ? `${a}-${b}` : [a, b].sort((x, y) => x - y).join('+');
}

/**
 * The `match` answer, derived from the equations and the picture groups - but
 * only when every equation fits exactly one picture (otherwise the author has
 * to spell the pairs out in `answer`).
 */
function deriveMatchAnswer(q) {
  if (!q.left.length || !q.right.length) return [];
  const op = q.op === '-' ? '-' : '+';
  const keys = q.right.map((r) => matchKeyOf(sideValue(r.left), sideValue(r.right), op));
  const out = [];
  for (const item of q.left) {
    const key = matchKeyOf(item.a, item.b, op);
    const fits = q.right.filter((_, i) => keys[i] === key);
    if (fits.length !== 1) return [];
    out.push(`${item.id}>${fits[0].id}`);
  }
  const ids = out.map((key) => key.split('>')[1]);
  return new Set(ids).size === ids.length ? out : [];
}

/** Cards for the drawing questions: figures and/or a comparison. */
function normalizeCards(q, prefix) {
  const raw = Array.isArray(q.cards) ? q.cards : [];
  return raw
    .map((card, i) => {
      const c = card && typeof card === 'object' ? card : {};
      const out = { id: String(c.id || `${prefix}${i + 1}`), color: colorOf(c.color) };
      if (c.compare && typeof c.compare === 'object') {
        out.compare = {
          left: normalizeSide(c.compare.left, 'sky'),
          right: normalizeSide(c.compare.right, 'amber'),
          symbol: String(c.compare.symbol || '?'),
        };
      } else if (c.figures) {
        out.figures = normalizeSide(c.figures, c.color || 'sky');
      }
      if (c.label !== undefined) out.label = String(c.label);
      return out;
    })
    .filter((c) => c.figures || c.compare || c.label !== undefined);
}
