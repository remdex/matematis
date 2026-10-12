/**
 * scribble.js — lets a child draw with finger/mouse on top of a question board
 * and turns that scribble into a selection (SVG outlines / crosses).
 *
 * The layer is a transparent <svg> that covers the board. It records the drawn
 * polyline, then hit-tests it against the DOM rectangles of the selectable
 * targets ("cards"). A short stroke counts as a tap.
 *
 *   const layer = new ScribbleLayer(svgEl, {
 *     getTargets: () => [{ id, el }],
 *     onCommit: (hitIds, points) => {...},
 *     kind: 'outline' | 'cross', // only changes how the mark is drawn
 *   });
 *   layer.setMarks(['k2']);   // draws the snap-outline around card k2
 *
 * Hit testing is deliberately conservative - selecting a card the child did not
 * mean is worse than selecting nothing, because the tip tells them what got
 * marked. A card counts as aimed at when
 *   1. the loop encloses its centre (circling a card, as on paper), or
 *   2. the stroke's centre of mass lies inside the card (a line through it), or
 *   3. the stroke crosses exactly one card (a long slash over a single card).
 *
 * `mode: 'link'` (used by the "sujunk" questions) changes the meaning of a
 * stroke completely: the child draws a line from one card to another, so only
 * the *first* and the *last* point matter. A stroke that starts and ends on two
 * different cards links them; a short tap on an already linked card unlinks it.
 */

const TAP_LENGTH = 14; // px of travel below which we treat the stroke as a tap
const SELECT_SLACK = 2; // px an aim may miss a card by in select mode
const LINK_SLACK = 16; // ... and in link mode, where a 7 year old draws the line

/* ------------------------------------------------------------- geometry -- */

function pointInPolygon(pt, poly) {
  let inside = false;
  for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
    const a = poly[i];
    const b = poly[j];
    if (a.y > pt.y !== b.y > pt.y && pt.x < ((b.x - a.x) * (pt.y - a.y)) / (b.y - a.y) + a.x) {
      inside = !inside;
    }
  }
  return inside;
}

function pointInRect(pt, r) {
  return pt.x >= r.x && pt.x <= r.x + r.w && pt.y >= r.y && pt.y <= r.y + r.h;
}

function segIntersectsRect(p1, p2, r) {
  // Either endpoint inside -> hit.
  if (pointInRect(p1, r) || pointInRect(p2, r)) return true;
  const corners = [
    { x: r.x, y: r.y },
    { x: r.x + r.w, y: r.y },
    { x: r.x + r.w, y: r.y + r.h },
    { x: r.x, y: r.y + r.h },
  ];
  const ccw = (a, b, c) => (c.y - a.y) * (b.x - a.x) > (b.y - a.y) * (c.x - a.x);
  const segsCross = (a, b, c, d) => ccw(a, c, d) !== ccw(b, c, d) && ccw(a, b, c) !== ccw(a, b, d);
  for (let i = 0; i < 4; i++) {
    if (segsCross(p1, p2, corners[i], corners[(i + 1) % 4])) return true;
  }
  return false;
}

function polyHitsRect(pts, r) {
  if (pts.length === 1) return pointInRect(pts[0], r);
  for (let i = 0; i < pts.length - 1; i++) {
    if (segIntersectsRect(pts[i], pts[i + 1], r)) return true;
  }
  // A large lasso may fully enclose a card without touching its border.
  const centre = { x: r.x + r.w / 2, y: r.y + r.h / 2 };
  return pointInPolygon(centre, pts);
}

function inflate(r, n) {
  return { x: r.x - n, y: r.y - n, w: r.w + n * 2, h: r.h + n * 2 };
}

function strokeLength(pts) {
  let len = 0;
  for (let i = 1; i < pts.length; i++) {
    len += Math.hypot(pts[i].x - pts[i - 1].x, pts[i].y - pts[i - 1].y);
  }
  return len;
}

/* ------------------------------------------------------------ the layer -- */

export class ScribbleLayer {
  constructor(svg, { getTargets, onCommit, onLink, kind = 'outline', mode = 'select', enabled = true } = {}) {
    this.svg = svg;
    this.getTargets = getTargets || (() => []);
    this.onCommit = onCommit || (() => {});
    this.onLink = onLink || (() => {});
    this.kind = kind;
    this.mode = mode;
    this.slack = mode === 'link' ? LINK_SLACK : SELECT_SLACK;
    this.enabled = enabled;
    this.locked = false;
    this.drawing = false;
    this.points = [];
    this.markIds = [];
    this.linkPairs = [];

    this.svg.innerHTML = '<g class="marks"></g><polyline class="ink" fill="none" points="" />';
    this.marksG = this.svg.querySelector('.marks');
    this.ink = this.svg.querySelector('.ink');

    this._down = this._down.bind(this);
    this._move = this._move.bind(this);
    this._up = this._up.bind(this);

    this.svg.addEventListener('pointerdown', this._down);
    this.svg.addEventListener('pointermove', this._move);
    this.svg.addEventListener('pointerup', this._up);
    this.svg.addEventListener('pointercancel', this._up);
    this.svg.addEventListener('contextmenu', (e) => e.preventDefault());

    this._ro = new ResizeObserver(() => this.renderMarks());
    this._ro.observe(svg);
  }

  destroy() {
    this.svg.removeEventListener('pointerdown', this._down);
    this.svg.removeEventListener('pointermove', this._move);
    this.svg.removeEventListener('pointerup', this._up);
    this.svg.removeEventListener('pointercancel', this._up);
    this._ro.disconnect();
  }

  setEnabled(on) {
    this.enabled = !!on;
    this.svg.classList.toggle('is-locked', !this.enabled);
  }

  /** id list currently marked on the board. */
  setMarks(ids, kind = this.kind) {
    this.kind = kind;
    this.markIds = [...new Set(ids)];
    this.renderMarks();
  }

  /**
   * Pairs currently linked on the board, as `[{ from, to }]` (`kind: 'link'`
   * amber, `'reveal'` blue dashed). Only used in link mode.
   */
  setLinks(pairs, kind = this.kind) {
    this.kind = kind;
    this.linkPairs = (pairs || []).filter((p) => p && p.from && p.to);
    this.renderMarks();
  }

  markKind() {
    return this.kind;
  }

  clearInk() {
    this.points = [];
    this.ink.setAttribute('points', '');
  }

  /* ------------------------------------------------------- coordinates -- */

  _rectOf(el) {
    const r = el.getBoundingClientRect();
    const o = this.svg.getBoundingClientRect();
    return { x: r.left - o.left, y: r.top - o.top, w: r.width, h: r.height };
  }

  _targets() {
    return this.getTargets().filter((t) => t && t.el && t.el.isConnected);
  }

  targetAt(point) {
    for (const t of this._targets()) {
      if (pointInRect(point, inflate(this._rectOf(t.el), this.slack))) return t.id;
    }
    return null;
  }

  /**
   * Which cards a stroke selects (see the policy at the top of this file).
   */
  hitsForPath(pts) {
    const targets = this._targets();
    if (pts.length < 2) return [];

    const enclosed = targets.filter((t) => {
      const r = this._rectOf(t.el);
      return pointInPolygon({ x: r.x + r.w / 2, y: r.y + r.h / 2 }, pts);
    });
    if (enclosed.length) return enclosed.map((t) => t.id);

    const centroid = pts.reduce((acc, p) => ({ x: acc.x + p.x / pts.length, y: acc.y + p.y / pts.length }), { x: 0, y: 0 });
    const under = targets.find((t) => pointInRect(centroid, inflate(this._rectOf(t.el), 10)));
    if (under) return [under.id];

    const crossed = targets.filter((t) => polyHitsRect(pts, this._rectOf(t.el)));
    return crossed.length === 1 ? [crossed[0].id] : [];
  }

  /* ----------------------------------------------------------- pointer -- */

  _local(e) {
    const o = this.svg.getBoundingClientRect();
    return { x: e.clientX - o.left, y: e.clientY - o.top };
  }

  _down(e) {
    if (!this.enabled || this.locked) return;
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    e.preventDefault();
    this.drawing = true;
    this.points = [this._local(e)];
    try {
      this.svg.setPointerCapture(e.pointerId);
    } catch {
      /* ignore */
    }
    this._paintInk();
  }

  _move(e) {
    if (!this.drawing) return;
    e.preventDefault();
    const p = this._local(e);
    const last = this.points[this.points.length - 1];
    if (!last || Math.hypot(p.x - last.x, p.y - last.y) > 2) {
      this.points.push(p);
      this._paintInk();
    }
  }

  _up(e) {
    if (!this.drawing) return;
    this.drawing = false;
    const pts = this.points;
    this.points = [];
    try {
      this.svg.releasePointerCapture(e.pointerId);
    } catch {
      /* ignore */
    }
    this._paintInk();

    const missed = { x: -999, y: -999 };
    const isTap = strokeLength(pts) < TAP_LENGTH;

    // Fade the ink away again - the snap marks tell the story.
    this.ink.classList.add('ink--fading');
    window.setTimeout(() => {
      this.ink.classList.remove('ink--fading');
      this.ink.setAttribute('points', '');
    }, 220);

    if (this.mode === 'link') {
      const tapId = isTap ? this.targetAt(pts[0] || missed) : null;
      let pair = null;
      if (!isTap) {
        const from = this.targetAt(pts[0] || missed);
        const to = this.targetAt(pts[pts.length - 1] || missed);
        if (from && to && from !== to) pair = { from, to };
      }
      this.onLink(pair, tapId, pts);
      return;
    }

    const hits = isTap
      ? [this.targetAt(pts[0] || missed)].filter(Boolean)
      : this.hitsForPath(pts);
    this.onCommit(hits, pts);
  }

  _paintInk() {
    this.ink.setAttribute('points', this.points.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '));
  }

  /* ------------------------------------------------------------ marks -- */

  renderMarks() {
    const targets = this._targets();
    const byId = new Map(targets.map((t) => [t.id, t]));
    const parts = [];
    if (this.mode === 'link') {
      for (const pair of this.linkPairs) {
        const a = byId.get(pair.from);
        const b = byId.get(pair.to);
        if (!a || !b) continue;
        const ra = this._rectOf(a.el);
        const rb = this._rectOf(b.el);
        if (!ra.w || !rb.w) continue;
        parts.push(this._linkSvg(ra, rb, `${pair.from}>${pair.to}`));
      }
    }
    for (const id of this.mode === 'link' ? [] : this.markIds) {
      const t = byId.get(id);
      if (!t) continue;
      const r = this._rectOf(t.el);
      if (!r.w || !r.h) continue;
      if (this.kind === 'cross') parts.push(this._crossSvg(r, id));
      else parts.push(this._outlineSvg(r, id));
    }
    this.marksG.innerHTML = parts.join('');
    // force a reflow-free animation restart for freshly added marks
    this.marksG.querySelectorAll('.mark').forEach((el) => {
      const anim = el.getAnimations ? el.getAnimations() : [];
      anim.forEach((a) => a.play());
    });
  }

  _outlineSvg(r, id) {
    const pad = 7;
    const x = r.x - pad;
    const y = r.y - pad;
    const w = r.w + pad * 2;
    const h = r.h + pad * 2;
    const rx = Math.min(30, w * 0.14, h * 0.24);
    const reveal = this.kind === 'reveal';
    return (
      `<g class="mark mark--${reveal ? 'reveal' : 'outline'}" data-target="${id}">` +
      `<rect x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${w.toFixed(1)}" height="${h.toFixed(1)}" rx="${rx.toFixed(1)}" ` +
      `fill="${reveal ? 'rgba(14,165,233,.12)' : 'rgba(245,158,11,.14)'}" ` +
      `stroke="${reveal ? '#0ea5e9' : '#f59e0b'}" stroke-width="9" stroke-linecap="round" ` +
      `stroke-dasharray="${reveal ? '14 12' : '100'}" pathLength="${reveal ? 1 : 100}" stroke-dashoffset="${reveal ? 0 : 100}" ` +
      `stroke-linejoin="round"/>` +
      `</g>`
    );
  }

  /** A connector between two cards, always drawn left card -> right card. */
  _linkSvg(ra, rb, key) {
    const reveal = this.kind === 'reveal';
    const [a, b] = ra.x + ra.w / 2 <= rb.x + rb.w / 2 ? [ra, rb] : [rb, ra];
    const x1 = a.x + a.w;
    const y1 = a.y + a.h / 2;
    const x2 = b.x;
    const y2 = b.y + b.h / 2;
    const dash = reveal
      ? 'stroke-dasharray="14 12"'
      : 'pathLength="100" stroke-dasharray="100" stroke-dashoffset="100"';
    return (
      `<g class="mark mark--${reveal ? 'reveal' : 'link'}" data-target="${key}">` +
      `<line x1="${x1.toFixed(1)}" y1="${y1.toFixed(1)}" x2="${x2.toFixed(1)}" y2="${y2.toFixed(1)}" ` +
      `stroke="${reveal ? '#0ea5e9' : '#f59e0b'}" stroke-width="${reveal ? 7 : 8}" stroke-linecap="round" ${dash}/>` +
      `</g>`
    );
  }

  _crossSvg(r, id) {
    const pad = 4;
    const x1 = r.x + pad;
    const y1 = r.y + pad;
    const x2 = r.x + r.w - pad;
    const y2 = r.y + r.h - pad;
    const reveal = this.kind === 'reveal';
    const color = reveal ? '#0ea5e9' : '#f43f5e';
    const dash = reveal ? 'stroke-dasharray="14 12" stroke-dashoffset="0"' : 'pathLength="100" stroke-dasharray="100" stroke-dashoffset="100"';
    return (
      `<g class="mark mark--${reveal ? 'reveal' : 'cross'}" data-target="${id}">` +
      `<line x1="${x1.toFixed(1)}" y1="${y1.toFixed(1)}" x2="${x2.toFixed(1)}" y2="${y2.toFixed(1)}" stroke="${color}" stroke-width="11" stroke-linecap="round" ${dash}/>` +
      `<line x1="${x2.toFixed(1)}" y1="${y1.toFixed(1)}" x2="${x1.toFixed(1)}" y2="${y2.toFixed(1)}" stroke="${color}" stroke-width="11" stroke-linecap="round" ${dash}/>` +
      `</g>`
    );
  }
}
