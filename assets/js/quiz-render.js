/**
 * quiz-render.js — the renderer framework.
 *
 * A quiz is plain JSON. This module turns that JSON into DOM:
 *
 *   - a *type registry*: every question type registers itself with
 *       { name, label, normalize(q, quiz), answer(q),
 *         instruction(q), answerText(q), create(q, ctx) }
 *   - normalization, so half-written JSON still renders something sensible
 *   - answer derivation, so simple quizzes do not need to repeat the answer
 *
 * The instance returned by `create()` has this contract:
 *
 *   { el, touched(), check() -> boolean, reveal(), clear(), destroy() }
 *
 * `ctx.changed()` must be called whenever the child's answer state changes;
 * `ctx.check` is called by the player once, after the whole quiz was answered.
 */

import { COLORS, COLOR_NAMES, normalizeSide } from './figures.js';

const TYPES = new Map();

/** Register a question type. Called by quiz-types.js at start-up. */
export function register(def) {
  if (!def || !def.name || typeof def.create !== 'function') {
    throw new Error('register(): a question type needs { name, create() }');
  }
  def.label = def.label || def.name;
  TYPES.set(def.name, def);
  return def;
}

export function typeDef(name) {
  return TYPES.get(String(name || '')) || null;
}

/** All symbols a child may pick, in display order. */
export const SYMBOLS = ['>', '<', '=', '≠'];

export const SYMBOL_TEXT = {
  '>': 'daugiau',
  '<': 'mažiau',
  '=': 'lygu',
  '≠': 'nelygu',
};

/** Escaping helper - all author-supplied text goes through this. */
export function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Small DOM builder used by the question types. */
export function h(tag, attrs = {}, inner = '') {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === undefined || v === null || v === false) continue;
    if (k === 'class') node.className = v;
    else if (k === 'html') node.innerHTML = v;
    else if (k === 'text') node.textContent = v;
    else node.setAttribute(k, v);
  }
  if (inner) node.innerHTML = inner;
  return node;
}

export const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Lift an answer control out from under the floating check bar (#check-bar).
 *
 * The bar is `position: sticky; bottom: 0` and on a phone it covers the bottom
 * quarter of the screen, so the number pad or the symbol buttons can end up
 * underneath it. A tap meant for a covered button hits a bar button instead, and
 * a check locks every card with a half-finished answer on it - which looks
 * exactly like "it says incorrect although my answer is right". So the pad lifts
 * itself above the bar before the child starts tapping digits.
 */
export function liftAboveBar(el, extra = 12) {
  if (!el || typeof window === 'undefined' || typeof el.getBoundingClientRect !== 'function') return;
  const bar = document.getElementById('check-bar');
  const limit = bar ? bar.getBoundingClientRect().top : window.innerHeight;
  const hidden = Math.round(el.getBoundingClientRect().bottom - limit);
  if (hidden > 0) window.scrollBy({ top: hidden + extra, behavior: 'smooth' });
}

/**
 * Create an SVG element. NOTE: document.createElement('svg') returns an HTML
 * element that silently paints nothing - always use this helper.
 */
export function svgNode(tag, attrs = {}) {
  const node = document.createElementNS(SVG_NS, tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === undefined || v === null) continue;
    node.setAttribute(k, v);
  }
  return node;
}

/* ---------------------------------------------------------- normalization -- */

function slug(value) {
  return String(value ?? '')
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64);
}

function text(value, fallback = '') {
  const s = typeof value === 'string' ? value.trim() : '';
  return s || fallback;
}

function integer(value, fallback = 0) {
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : fallback;
}

export function colorName(value, fallback = 'sky') {
  const key = text(value);
  if (COLORS[key]) return key;
  return COLOR_NAMES.includes(fallback) ? fallback : 'sky';
}

/** Question symbols (defaults to > < =). */
export function symbolsOf(q) {
  const list = Array.isArray(q.symbols) && q.symbols.length ? q.symbols : [SYMBOLS[0], SYMBOLS[1], SYMBOLS[2]];
  const clean = [...new Set(list.map((s) => String(s).trim()).filter((s) => SYMBOLS.includes(s)))];
  return clean.length ? clean : ['>', '<', '='];
}

/** Value of a side/group: how many things, or the plain number shown. */
export function sideValue(side) {
  const s = normalizeSide(side);
  return s.kind === 'number' ? s.value : s.count;
}

/**
 * Which symbol belongs between a and b.
 * When the child may only choose between "=" and "≠" the answer for two
 * different amounts is "≠" (that is how the workbook uses it).
 */
export function symbolFor(a, b, symbols = SYMBOLS) {
  const list = Array.isArray(symbols) && symbols.length ? symbols : SYMBOLS;
  if (a === b) return list.includes('=') ? '=' : '=';
  const onlyEquality = list.length === 2 && list.includes('=') && list.includes('≠');
  if (onlyEquality) return '≠';
  return a > b ? '>' : '<';
}

export function normalizeQuiz(raw, { source = 'library' } = {}) {
  const input = raw && typeof raw === 'object' ? raw : {};
  const quiz = {
    id: slug(input.id) || 'testas',
    title: text(input.title, 'Testas'),
    subtitle: text(input.subtitle),
    emoji: text(input.emoji, '🧮'),
    color: colorName(input.color),
    grade: Math.min(6, Math.max(1, Math.trunc(Number(input.grade)) || 1)),
    source,
    questions: [],
  };
  const list = Array.isArray(input.questions) ? input.questions : [];
  quiz.questions = list
    .map((q, i) => normalizeQuestion(q, i, quiz))
    .filter(Boolean);
  return quiz;
}

export function normalizeQuestion(raw, index, quiz) {
  const input = raw && typeof raw === 'object' ? raw : {};
  const type = text(input.type);
  const def = typeDef(type);
  const q = { ...input, type, index, def };
  q.id = slug(input.id) || `q${index + 1}`;
  q.instruction = text(input.instruction, def ? def.defaultInstruction : 'Atsakyk į klausimą.');
  q.hint = text(input.hint);
  q.readAloud = text(input.readAloud, q.instruction);
  // The per-question read-aloud switch (a story problem is silent by default).
  if (input.say !== undefined && input.say !== null) {
    q.say = !(input.say === false || input.say === 'false' || input.say === 0 || input.say === '0');
  }
  if (!def) {
    q.unsupported = true;
    q.answer = input.answer ?? null;
    return q;
  }
  if (typeof def.normalize === 'function') def.normalize(q, quiz);
  if (q.answer === undefined || q.answer === null) q.answer = def.answer ? def.answer(q) : null;
  return q;
}

/** Derive the answer for a normalized question (null when not derivable). */
export function deriveAnswer(q) {
  const def = q.def || typeDef(q.type);
  return def && def.answer ? def.answer(q) : null;
}

/** Human readable solution, used on the summary screen. */
export function answerText(q) {
  const def = q.def || typeDef(q.type);
  return def && def.answerText ? def.answerText(q) : '';
}

/**
 * The sentence the player shows after a wrong answer. Types whose answer is a
 * picture (circled / crossed cards) override it, because card ids like "k2"
 * mean nothing to a child.
 */
export function revealText(q) {
  const def = q.def || typeDef(q.type);
  if (def && typeof def.revealText === 'function') return def.revealText(q);
  return `Teisingas atsakymas: ${answerText(q)}`;
}

/* --------------------------------------------------------------- answers -- */

/** Compare the child's answer with the expected one (order matters, unlike sets). */
export function sameValue(a, b) {
  if (Array.isArray(b)) {
    const x = Array.isArray(a) ? a : [];
    return x.length === b.length && b.every((v) => x.includes(v));
  }
  return String(a) === String(b);
}

/** Create a question instance (falls back to a friendly "unsupported" card). */
export function createQuestion(q, ctx) {
  const def = q.def || typeDef(q.type);
  if (!def) return unsupportedQuestion(q, ctx);
  return def.create(q, ctx);
}

function unsupportedQuestion(q, ctx) {
  const node = h('div', { class: 'unsupported' });
  node.innerHTML = `<p class="text-xl font-bold">🤔 Toks klausimas dar nepalaikomas</p><p class="mt-2 text-slate-600">Tipas: <code>${esc(q.type || '?')}</code></p>`;
  return {
    el: node,
    touched: () => false,
    check: () => false,
    reveal: () => {},
    clear: () => {},
    destroy: () => {},
  };
}
