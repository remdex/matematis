/**
 * figures.js — SVG "figure" library used by the quiz renderer.
 *
 * Every figure is a self-contained SVG (viewBox 0 0 100 100) painted with
 * `currentColor`, so a palette name is enough to give it a colour.
 * Details (eyes, spots, paper bands) use translucent black/white so they work
 * with any palette colour.
 */

/** Tailwind-ish palette names -> hex. */
export const COLORS = {
  sky: '#0ea5e9',
  blue: '#3b82f6',
  indigo: '#6366f1',
  violet: '#8b5cf6',
  purple: '#a855f7',
  fuchsia: '#d946ef',
  pink: '#ec4899',
  rose: '#f43f5e',
  red: '#ef4444',
  orange: '#fb923c',
  amber: '#f59e0b',
  yellow: '#facc15',
  lime: '#84cc16',
  green: '#22c55e',
  emerald: '#10b981',
  teal: '#14b8a6',
  cyan: '#06b6d4',
  slate: '#64748b',
};

export const COLOR_NAMES = Object.keys(COLORS);

const OUT = 'fill="currentColor" stroke="rgba(0,0,0,.18)" stroke-width="4" stroke-linejoin="round"';
const OUTS = 'fill="currentColor" stroke="rgba(0,0,0,.16)" stroke-width="3.5" stroke-linejoin="round"';
const DARK = 'rgba(0,0,0,.30)';
const MID = 'rgba(0,0,0,.20)';
const LIGHT = 'rgba(255,255,255,.72)';

/** Resolve a palette name (or raw hex) to a hex colour. */
export function colorOf(name) {
  if (typeof name !== 'string' || !name) return COLORS.sky;
  const key = name.trim();
  if (/^#[0-9a-f]{3,8}$/i.test(key)) return key;
  return COLORS[key] || COLORS.sky;
}

/** shape name -> inner SVG markup. */
export const SHAPES = {
  triangle: `<polygon ${OUT} points="50,10 92,86 8,86"/>`,
  square: `<rect ${OUT} x="14" y="14" width="72" height="72" rx="9"/>`,
  circle: `<circle ${OUT} cx="50" cy="50" r="38"/>`,
  oval: `<ellipse ${OUT} cx="50" cy="50" rx="44" ry="32"/>`,
  diamond: `<polygon ${OUT} points="50,6 94,50 50,94 6,50"/>`,
  pentagon: `<polygon ${OUT} points="50,6 93,36 79,88 21,88 7,36"/>`,
  hexagon: `<polygon ${OUT} points="50,4 90,27 90,73 50,96 10,73 10,27"/>`,
  star: `<polygon ${OUT} points="50,4 63,37 98,38 70,59 80,94 50,73 20,94 30,59 2,38 37,37"/>`,
  heart: `<path ${OUT} d="M50 90C22 68 8 50 8 34 8 17 22 7 36 7c9 0 14 6 14 12 0-6 5-12 14-12 14 0 28 10 28 27 0 16-14 34-42 56z"/>`,
  cross: `<path ${OUT} d="M23 7 50 34 77 7l16 16-27 27 27 27-16 16-27-27-27 27L7 77l27-27L7 23z"/>`,
  ball: `<circle ${OUT} cx="50" cy="50" r="38"/><path d="M12 50q38-24 76 0" fill="none" stroke="${MID}" stroke-width="5"/><path d="M50 12q22 38 0 76" fill="none" stroke="${MID}" stroke-width="5"/>`,
  apple: `<path ${OUT} d="M50 24C40 11 19 15 17 34c-2 23 15 57 33 57s35-34 33-57C81 15 60 11 50 24z"/><path d="M50 26c0-11 4-16 13-19" fill="none" stroke="${DARK}" stroke-width="6" stroke-linecap="round"/><path d="M62 16c12-11 25-4 22 5-3 9-17 8-22-5z" fill="${LIGHT}"/>`,
  flower: `<circle ${OUT} cx="50" cy="25" r="18"/><circle ${OUT} cx="73" cy="43" r="18"/><circle ${OUT} cx="64" cy="70" r="18"/><circle ${OUT} cx="36" cy="70" r="18"/><circle ${OUT} cx="27" cy="43" r="18"/><circle cx="50" cy="50" r="14" fill="${LIGHT}" stroke="${MID}" stroke-width="3"/>`,
  fish: `<path ${OUTS} d="M22 50c0-17 22-27 44-22 14 3 22 14 26 22-4 8-12 19-26 22-22 5-44-5-44-22z"/><polygon ${OUTS} points="26,50 6,32 6,68"/><circle cx="74" cy="42" r="5" fill="${DARK}"/>`,
  bug: `<circle ${OUTS} cx="50" cy="58" r="33"/><circle cx="50" cy="20" r="14" fill="${DARK}"/><circle cx="34" cy="50" r="7" fill="${MID}"/><circle cx="66" cy="50" r="7" fill="${MID}"/><circle cx="40" cy="74" r="7" fill="${MID}"/><circle cx="62" cy="74" r="7" fill="${MID}"/><path d="M50 30v58" stroke="${DARK}" stroke-width="4"/>`,
  candy: `<circle ${OUTS} cx="50" cy="50" r="26"/><polygon points="27,50 6,30 6,70" fill="${LIGHT}"/><polygon points="73,50 94,30 94,70" fill="${LIGHT}"/><path d="M36 36q14 14 0 28" fill="none" stroke="${LIGHT}" stroke-width="6"/>`,
  carrot: `<polygon ${OUT} points="50,94 22,34 78,34"/><ellipse cx="36" cy="22" rx="8" ry="13" fill="${COLORS.green}" transform="rotate(-22 36 22)"/><ellipse cx="50" cy="17" rx="8" ry="14" fill="${COLORS.green}"/><ellipse cx="64" cy="22" rx="8" ry="13" fill="${COLORS.green}" transform="rotate(22 64 22)"/>`,
  leaf: `<path ${OUT} d="M50 5c36 15 42 57 0 90C8 62 14 20 50 5z"/><path d="M50 14v74" stroke="${MID}" stroke-width="4" fill="none"/>`,
  pencil: `<rect ${OUT} x="36" y="14" width="28" height="56" rx="4"/><polygon fill="currentColor" stroke="rgba(0,0,0,.18)" stroke-width="4" stroke-linejoin="round" points="36,68 64,68 50,95"/><rect x="36" y="6" width="28" height="12" rx="4" fill="${DARK}"/><rect x="36" y="44" width="28" height="10" fill="${LIGHT}"/>`,
  pen: `<rect ${OUT} x="39" y="12" width="22" height="62" rx="6"/><polygon fill="currentColor" stroke="rgba(0,0,0,.18)" stroke-width="4" stroke-linejoin="round" points="39,72 61,72 50,95"/><rect x="39" y="26" width="22" height="8" fill="${LIGHT}"/><rect x="61" y="14" width="6" height="18" rx="3" fill="${DARK}"/>`,
  crayon: `<rect ${OUT} x="38" y="28" width="24" height="52" rx="4"/><polygon fill="currentColor" stroke="rgba(0,0,0,.18)" stroke-width="4" stroke-linejoin="round" points="38,28 62,28 50,5"/><rect x="38" y="44" width="24" height="14" fill="${LIGHT}"/>`,
  eraser: `<rect ${OUT} x="12" y="32" width="76" height="38" rx="12"/><rect x="12" y="44" width="76" height="14" fill="${LIGHT}"/>`,
  soap: `<rect ${OUT} x="14" y="30" width="72" height="42" rx="16"/><circle cx="34" cy="44" r="6" fill="${LIGHT}"/><circle cx="52" cy="38" r="4" fill="${LIGHT}"/><circle cx="66" cy="52" r="5" fill="${LIGHT}"/>`,
  sponge: `<rect ${OUT} x="14" y="34" width="72" height="36" rx="10"/><circle cx="32" cy="46" r="5" fill="${MID}"/><circle cx="50" cy="56" r="6" fill="${MID}"/><circle cx="68" cy="44" r="5" fill="${MID}"/>`,
  lollipop: `<rect x="46" y="58" width="8" height="34" rx="4" fill="${DARK}"/><circle ${OUTS} cx="50" cy="36" r="27"/><path d="M50 36a12 12 0 0 1 12 12" fill="none" stroke="${LIGHT}" stroke-width="5"/>`,
  butterfly: `<ellipse ${OUTS} cx="28" cy="34" rx="17" ry="20"/><ellipse ${OUTS} cx="72" cy="34" rx="17" ry="20"/><ellipse ${OUTS} cx="32" cy="68" rx="14" ry="17"/><ellipse ${OUTS} cx="68" cy="68" rx="14" ry="17"/><rect x="45" y="26" width="10" height="56" rx="5" fill="${DARK}"/>`,
  cloud: `<rect ${OUTS} x="16" y="46" width="68" height="26" rx="13"/><circle ${OUTS} cx="34" cy="46" r="18"/><circle ${OUTS} cx="58" cy="40" r="22"/><circle ${OUTS} cx="76" cy="50" r="15"/>`,
};

export const SHAPE_NAMES = Object.keys(SHAPES);

/**
 * Emoji work as figures too: `{ "emoji": "🍎", "count": 4 }` or simply
 * `{ "shape": "🍎", "count": 4 }`. They are drawn as SVG <text> so they scale
 * with the cell exactly like the drawn shapes do.
 */
export const EMOJI_FIGURES = [
  '🍎', '🍏', '🍌', '🍓', '🍇', '🍊', '🥕', '🍪', '🧁', '🎈',
  '⭐', '❤️', '🌼', '🌈', '🐟', '🐞', '🦋', '🐣', '🐢', '🐝',
  '⚽', '🎲', '🚗', '🎁', '🧩', '🖍️', '✏️', '🔵', '⭕', '🔺',
];

/** True when the value is an emoji (or any pictographic glyph) rather than a shape name. */
export function isEmojiFigure(value) {
  const s = String(value ?? '').trim();
  if (!s || SHAPES[s]) return false;
  try {
    return /\p{Extended_Pictographic}/u.test(s);
  } catch {
    return false; // very old browser without unicode property escapes
  }
}

/** Render one figure - a drawn shape or an emoji. */
export function shapeSvg(shape, { color = 'sky', extraClass = '' } = {}) {
  if (isEmojiFigure(shape)) {
    return (
      `<svg class="fig ${extraClass}" viewBox="0 0 100 100" aria-hidden="true" focusable="false">` +
      `<text x="50" y="52" text-anchor="middle" dominant-baseline="central" font-size="76">${escapeText(shape)}</text>` +
      `</svg>`
    );
  }
  const inner = SHAPES[shape] || SHAPES.circle;
  return `<svg class="fig ${extraClass}" viewBox="0 0 100 100" style="color:${colorOf(color)}" aria-hidden="true" focusable="false">${inner}</svg>`;
}

function escapeText(value) {
  return String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * A domino tile: `left` and `right` are pip counts (0-6), drawn as a
 * horizontal tile with the two halves separated by a divider. The child reads
 * the pips and writes `left + right = ...`.
 *
 * Not `.fig` on purpose: a domino keeps its own width (see `.domino` in
 * input.css) instead of filling an equal-sized grid cell. The pips are drawn
 * fat on purpose - a seven year old has to be able to count them on a phone.
 */
export function dominoHtml(left, right, { color = 'slate' } = {}) {
  const a = Math.max(0, Math.min(6, Math.floor(Number(left) || 0)));
  const b = Math.max(0, Math.min(6, Math.floor(Number(right) || 0)));
  const pip = (cx, cy) => `<circle cx="${cx}" cy="${cy}" r="8.6" fill="${colorOf(color)}"/>`;
  const half = (value, offset) =>
    PIP_POSITIONS[value].map(([col, row]) => pip(offset + 15 + col * 25, 19 + row * 25)).join('');
  return (
    `<svg class="domino" viewBox="0 0 158 88" role="img" aria-label="${a} ir ${b}">` +
    `<rect x="3" y="3" width="152" height="82" rx="14" fill="#fff" stroke="#cbd5e1" stroke-width="5"/>` +
    `<line x1="79" y1="10" x2="79" y2="78" stroke="#cbd5e1" stroke-width="5" stroke-linecap="round"/>` +
    half(a, 0) +
    half(b, 79) +
    `</svg>`
  );
}

/** Pip positions inside one domino half, as [column, row] of a 3x3 layout. */
const PIP_POSITIONS = {
  0: [],
  1: [[1, 1]],
  2: [[0, 0], [2, 2]],
  3: [[0, 0], [1, 1], [2, 2]],
  4: [[0, 0], [2, 0], [0, 2], [2, 2]],
  5: [[0, 0], [2, 0], [1, 1], [0, 2], [2, 2]],
  6: [[0, 0], [0, 1], [0, 2], [2, 0], [2, 1], [2, 2]],
};

/** A group spec may be a bare number, {shape,count} or {kind:'figures',...}. */
export function normalizeSide(side, fallbackColor = 'sky') {
  if (typeof side === 'number') return { kind: 'number', value: Math.max(0, Math.floor(side)), color: fallbackColor };
  if (typeof side === 'string' && /^\d+$/.test(side)) return { kind: 'number', value: parseInt(side, 10), color: fallbackColor };
  const s = side && typeof side === 'object' ? side : {};
  const kind = s.kind === 'number' ? 'number' : 'figures';
  if (kind === 'number') {
    return { kind, value: Math.max(0, Math.floor(Number(s.value) || 0)), color: s.color || fallbackColor };
  }
  const figures = s.figures && typeof s.figures === 'object' ? s.figures : s;
  const count = Math.max(0, Math.min(60, Math.floor(Number(figures.count) || 0)));
  const raw = String(figures.emoji || figures.shape || '').trim();
  const shape = SHAPES[raw] || isEmojiFigure(raw) ? raw : 'circle';
  return {
    kind: 'figures',
    shape,
    count,
    layout: figures.layout || 'grid',
    color: figures.color || s.color || fallbackColor,
  };
}

/** How many figures fit on one row. */
export function figuresPerRow(count) {
  if (count <= 3) return Math.max(1, count);
  if (count <= 6) return 3;
  if (count <= 10) return 5;
  return 6;
}

/**
 * Render a group of figures as a grid.
 * `perRow` caps the row length (small panels need shorter rows).
 * `cross` strikes through that many figures at the end of the group - the
 * "take away" picture of a subtraction (see `subtractPicture`).
 */
export function figuresHtml(spec, { extraClass = '', perRow: forcePerRow = 0, cross = 0 } = {}) {
  const s = normalizeSide(spec);
  if (s.kind === 'number') return numberBox(s.value, s.color);
  let perRow = figuresPerRow(s.count);
  if (forcePerRow > 0) perRow = Math.min(perRow, forcePerRow);
  perRow = Math.max(1, Math.min(perRow, s.count));
  // Very long rows get shorter figures so they still fit on a phone.
  const shrink = perRow >= 5 ? 'fig--sm' : '';
  const takeAway = Math.max(0, Math.min(s.count, Math.floor(Number(cross) || 0)));
  const items = Array.from({ length: s.count }, (_, i) => {
    const gone = i >= s.count - takeAway ? ' is-crossed' : '';
    return `<div class="fig-cell${gone}">${shapeSvg(s.shape, { color: s.color, extraClass: shrink })}</div>`;
  }).join('');
  return `<div class="fig-grid ${extraClass}" style="--per-row:${perRow}">${items}</div>`;
}

/** Big plain number panel (used when a side is a number, not figures). */
export function numberBox(value, color = 'sky') {
  return `<div class="number-box" style="color:${colorOf(color)}">${value}</div>`;
}

/** Render any side spec inside a "panel". */
export function sideHtml(side, opts = {}) {
  const s = normalizeSide(side);
  if (s.kind === 'number') return `<div class="panel">${numberBox(s.value, s.color)}</div>`;
  return `<div class="panel">${figuresHtml(s, opts)}</div>`;
}
