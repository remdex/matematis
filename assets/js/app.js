/**
 * app.js — Alpine app shell for the quiz player.
 *
 * Screens: home (quiz chooser + generators) · play · builder (JSON editor).
 *
 * A quiz is played like a workbook page: **all questions are shown at once**
 * and there is exactly **one "Tikrinti" button** at the end. Nothing is marked
 * before that button is pressed.
 *
 * Question internals are plain DOM from the renderer framework, so all the
 * imperative bits (instances, DOM nodes) live outside Alpine's reactive state
 * on purpose.
 */

import Alpine from './vendor/alpine.esm.js';
import './quiz-types.js';
import { normalizeQuiz, createQuestion, revealText, answerText, esc } from './quiz-render.js';
import { SHAPE_NAMES, COLOR_NAMES, EMOJI_FIGURES } from './figures.js';
import { confettiBurst } from './confetti.js';
import { sayButton, sayAllowed, speechText, speechStatus, prefetchSpeech, stopSpeech, setSpeechTokens } from './speech.js';

const KEY_BEST = 'mq.best';
const KEY_CUSTOM = 'mq.custom';

const PRAISE = ['Puiku! 🎉', 'Teisingai! ⭐', 'Puikiai! 🌟', 'Skaičiuoji puikiai! 🚀'];

const pick = (list) => list[Math.floor(Math.random() * list.length)];

/** Lithuanian numeral agreement: 1 testas · 5 testai · 10 testų. */
function ltPlural(n, one, few, many) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod10 === 1 && mod100 !== 11) return one;
  if (mod10 >= 2 && mod10 <= 9 && (mod100 < 11 || mod100 > 19)) return few;
  return many;
}

/** Imperative session state (kept out of Alpine's proxy on purpose). */
const dom = {
  host: null,
  quiz: null,
  items: [], // { inst, card, body, result, question }
  checked: false,
  batch: [], // quizzes generated "N at once"
  batchPos: 0,
  batchQuery: null,
  preview: null, // question instance drawn in the hover preview tooltip
};

/**
 * Exercise-type previews are generated once per type and reused for the session,
 * so hovering the same chip twice never asks the server again.
 */
const previewCache = new Map();

/** The example in the preview panel is drawn at this fraction of its real size. */
const PREVIEW_SCALE = 0.7;

/** Quiz JSON comes from the PHP API - always root absolute (deep links work). */
const API_BASE = '/api/';

async function api(path, options) {
  const res = await fetch(`${API_BASE}${path}`, { headers: { Accept: 'application/json' }, ...options });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();
  // Quiz payloads carry a `tts` map of `sentence => token`: the read-aloud
  // endpoint only speaks phrases the server signed (see lib/Tts.php).
  if (data && typeof data === 'object') {
    if (data.tts) setSpeechTokens(data.tts);
    if (Array.isArray(data.quizzes)) {
      for (const quiz of data.quizzes) {
        if (quiz && quiz.tts) setSpeechTokens(quiz.tts);
      }
    }
  }
  return data;
}

function loadJSON(key, fallback) {
  try {
    const raw = localStorage.getItem(key);
    return raw ? JSON.parse(raw) : fallback;
  } catch {
    return fallback;
  }
}

function saveJSON(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
  } catch {
    /* private mode - ignore */
  }
}

/** Drop runtime-only fields before storing a quiz in localStorage. */
function storable(quiz) {
  return {
    ...quiz,
    questions: quiz.questions.map((q) => {
      const { def, ...rest } = q;
      return rest;
    }),
  };
}

/** Question types, described for the builder screen cheat-sheet. */
const TYPE_HELP = [
  { name: 'compare', text: 'Dvi grupės ir ženklas > < = (left, right; symbols galima ["=","≠"])' },
  { name: 'compareGrid', text: 'Lentelė su daug palyginimų (rows: [{left, right}])' },
  { name: 'count', text: 'Suskaičiuoti ir įrašyti skaičių (scenes: [{id, figures}])' },
  { name: 'countAndCompare', text: 'Dvi grupės, skaičiai ir ženklas (left, right)' },
  { name: 'outline', text: 'Apibraukti kortelę su SVG piešiniu (cards, answer: [id])' },
  { name: 'crossOut', text: 'Nubraukti kortelę (cards, answer: [id])' },
  { name: 'write', text: 'Parašyti skaičių (items: [{prompt, answer}])' },
  { name: 'addPicture', text: 'Sudėtis iš paveikslų: [ ]+[ ]=[ ] (rows: [{left, right}], write: "all"|"result")' },
  { name: 'subtractPicture', text: 'Atimtis iš paveikslo: [ ]−[ ]=[ ] – dešinė grupė nubraukta (rows: [{left, right}])' },
  { name: 'domino', text: 'Sudėtis iš domino: [ ]+[ ]=[ ] (rows: [{left, right}] – taškai 0-6)' },
  { name: 'subtractDomino', text: 'Atimtis iš domino: [ ]−[ ]=[ ] (rows: [{left, right}] – taškai 0-6)' },
  { name: 'multiCheck', text: 'Pažymėti, kurie veiksmai duoda target (items: [{a, b}])' },
  { name: 'subtractCheck', text: 'Pažymėti, kurių atimčių atsakymas yra target (items: [{a, b}])' },
  { name: 'pickAddends', text: 'Sudėti du skaičius iš banko (items: [{target, bank: [1,2,2]}])' },
  { name: 'subtractPair', text: 'Atimti du skaičius iš banko (items: [{target, bank: [5,2,1]}])' },
  { name: 'equalSums', text: 'Abi lygties pusės lygios (items: [{target, bank: [a,b,c,d]}])' },
  { name: 'equalDifferences', text: 'Abiejų atimčių skirtumas lygus target (items: [{target, bank: [a,b,c,d]}])' },
  { name: 'drawMissing', text: 'Nupiešti trūkstamus (items: [{target, have, cells – daugiau nei target}])' },
  { name: 'crossOutExtra', text: 'Nubraukti, kiek atimti (items: [{target – kiek liks, have – kiek yra}])' },
  { name: 'match', text: 'Sujungti veiksmą su paveikslu (left: [{id, a, b}], right: [{id, left, right}], op: "-" atimčiai)' },
];

const EXAMPLE_JSON = `{
  "id": "mano-testas",
  "title": "Mano testas",
  "subtitle": "Sukurtas ranka",
  "emoji": "🐣",
  "color": "sky",
  "grade": 1,
  "questions": [
    {
      "type": "compare",
      "instruction": "Palygink obuolius ir lapus.",
      "left":  { "shape": "apple", "count": 6, "color": "red" },
      "right": { "shape": "leaf",  "count": 4, "color": "green" }
    },
    {
      "type": "count",
      "instruction": "Suskaičiuok žvaigždes.",
      "scenes": [{ "id": "s1", "figures": { "shape": "star", "count": 7, "color": "amber" } }]
    },
    {
      "type": "outline",
      "instruction": "Apibrauk kortelę, kurioje yra daugiau.",
      "cards": [
        { "id": "k1", "figures": { "shape": "circle", "count": 2, "color": "sky" } },
        { "id": "k2", "figures": { "shape": "circle", "count": 5, "color": "sky" } }
      ],
      "answer": ["k2"]
    },
    {
      "type": "write",
      "instruction": "Įrašyk atsakymą.",
      "items": [{ "id": "w1", "prompt": "Kiek yra 5 + 3?", "answer": 8 }]
    }
  ]
}`;

function app() {
  return {
    /* ------------------------------------------------------------ state -- */
    screen: 'home',
    loading: false,
    error: '',
    quizzes: [],
    myQuizzes: [],
    bestStars: {},
    showHelp: false,

    // generators
    genMax: 10,
    genCount: 1,
    genTasks: 8,
    // Story problems are silent by default; this checkbox voices them.
    genSayStory: false,
    genGroups: {},
    genPresets: {},
    genTypes: [],
    genPickerOpen: false,
    // one exercise of a type, shown beside the picker while its chip is hovered
    preview: { type: '', label: '', loading: false, error: '' },

    // play
    quiz: null,
    total: 0,
    answered: 0,
    checked: false,
    correct: 0,
    stars: 0,
    allRight: false,
    scoreNote: '',
    scoreNoteKind: 'ok',
    batchLabel: '',
    nextLabel: '🎲 Kitas testas',
    // the 🔊 voice is only shown when the server has an ElevenLabs key
    speechOn: false,

    // builder
    json: EXAMPLE_JSON,
    validation: null,
    typeList: TYPE_HELP,
    shapes: SHAPE_NAMES,
    colors: COLOR_NAMES,
    emojis: EMOJI_FIGURES,

    /* -------------------------------------------------------- lifecycle -- */
    boot() {
      dom.host = this.$refs.qhost;
      this.quizzes = (window.__QUIZZES__ || []).map((q) => ({ ...q }));
      this.genGroups = window.__GEN_TYPE_GROUPS__ || {};
      this.genPresets = window.__GEN_PRESETS__ || {};
      this.bestStars = loadJSON(KEY_BEST, {});
      this.myQuizzes = loadJSON(KEY_CUSTOM, []);
      this.installKeyboard();

      // Does the server have a voice? If not, the 🔊 buttons stay hidden.
      speechStatus().then((info) => {
        this.speechOn = !!(info && info.enabled);
        this.syncSayButtons();
      });

      // The builder is a screen without a fetch, so it can be decided before the
      // history entry is recorded (/kurti keeps its own path).
      if (window.__START_SCREEN__ === 'builder') this.screen = 'builder';
      this.installHistory();

      // A deep link (/t/<id>) starts the quiz in the entry we already have.
      if (window.__START_QUIZ__) this.open(window.__START_QUIZ__, { push: false });
    },

    /* ----------------------------------------------------------- history -- */
    /**
     * Home, quiz and builder live in one page, but the browser Back button used
     * to leave the app completely. Each screen change now pushes its own entry
     * (with a shareable path: `/`, `/t/<id>`, `/kurti`) and `popstate` walks back
     * through them.
     */
    installHistory() {
      window.addEventListener('popstate', (event) => {
        this.restoreHistory(event.state || this.stateFromPath());
      });

      const deepLink = window.__START_QUIZ__
        ? {
            screen: 'play',
            quizId: window.__START_QUIZ__,
            path: `/t/${encodeURIComponent(window.__START_QUIZ__)}`,
            batchPos: null,
            batchQuery: null,
          }
        : null;

      if (this.screen === 'home' && !deepLink) {
        this.replaceHistory();
        return;
      }
      // A deep link (/t/<id> or /kurti) keeps its own URL, but gets the picker
      // behind it, so Back leads home instead of out of the app.
      history.replaceState({ screen: 'home', path: '/' }, '', '/');
      const state = deepLink || this.historyState();
      history.pushState(state, '', state.path);
    },

    /** The history entry that describes what is on screen right now. */
    historyState() {
      if (this.screen === 'builder') {
        return { screen: 'builder', path: '/kurti' };
      }
      if (this.screen === 'play' && dom.quiz) {
        const state = {
          screen: 'play',
          quizId: dom.quiz.id,
          path: `/t/${encodeURIComponent(dom.quiz.id)}`,
          // A generated quiz gets a fresh batch (and a position in it) on the way back.
          batchPos: dom.batch.length > 1 ? dom.batchPos : null,
          batchQuery: dom.batchQuery || null,
        };
        return state;
      }
      return { screen: 'home', path: '/' };
    },

    /** Rewrite the current entry (opening a page is not a Back stop). */
    replaceHistory() {
      const state = this.historyState();
      history.replaceState(state, '', state.path);
    },

    /** Add a Back stop for the screen we just moved to. */
    pushHistory() {
      const state = this.historyState();
      const now = history.state;
      if (
        now
        && now.screen === state.screen
        && now.quizId === state.quizId
        && now.batchPos === state.batchPos
        && now.batchQuery === state.batchQuery
      ) {
        return;
      }
      history.pushState(state, '', state.path);
    },

    /** The screen a history entry without state points at (an old bookmark). */
    stateFromPath() {
      const path = location.pathname.replace(/\/+$/, '');
      if (path === '/kurti') return { screen: 'builder', path };
      const match = path.match(/^\/t\/([^/]+)$/);
      if (match) return { screen: 'play', quizId: decodeURIComponent(match[1]), path };
      return { screen: 'home', path: '/' };
    },

    /** Back/Forward: show the screen that entry belongs to. */
    async restoreHistory(state) {
      if (!state) return;

      if (state.screen === 'builder') {
        this.showScreen('builder');
        return;
      }

      if (state.screen === 'play' && state.quizId) {
        const at = Number.isInteger(state.batchPos) ? state.batchPos : null;
        const here = this.screen === 'play' && dom.quiz && dom.quiz.id === state.quizId
          && (at === null || at === dom.batchPos);
        if (here) return;
        // The same batch is still in memory: just walk to that quiz.
        if (dom.batch.length > 1 && at !== null && dom.batch[at]) {
          dom.batchPos = at;
          this.startQuiz(dom.batch[at], { push: false });
          return;
        }
        // A generated batch that is gone: build the same kind of quiz again.
        if (state.batchQuery) {
          this.rebuildBatch(state.batchQuery, at);
          return;
        }
        if (this.myQuizzes.some((q) => q.id === state.quizId) || this.quizzes.some((q) => q.id === state.quizId)) {
          this.open(state.quizId, { push: false });
          return;
        }
        this.showScreen('home');
        return;
      }

      this.showScreen('home');
    },

    /** Rebuild a generated batch after Back/Forward (fresh numbers, same kind). */
    async rebuildBatch(query, pos) {
      this.loading = true;
      try {
        const data = await api(`generate?${query}`);
        const list = (data.quizzes || []).map((q) => normalizeQuiz(q, { source: 'generated' }));
        if (!list.length) {
          this.showScreen('home');
          return;
        }
        dom.batch = list;
        dom.batchQuery = query;
        dom.batchPos = Number.isInteger(pos) && list[pos] ? pos : 0;
        this.startQuiz(list[dom.batchPos], { push: false });
      } catch (e) {
        this.showScreen('home');
      } finally {
        this.loading = false;
      }
    },

    /* --------------------------------------------------------- keyboard -- */
    /**
     * With every question on one page a keystroke needs to know *which*
     * question it belongs to: the one with the active answer box, else the
     * first question that still has an empty box.
     */
    targetBody() {
      const bodies = [...dom.host.querySelectorAll('.question-body:not(.is-locked)')];
      return (
        bodies.find((b) => b.querySelector('.slot.is-active')) ||
        bodies.find((b) => b.querySelector('.slot:not(.has-value)')) ||
        bodies.find((b) => b.querySelector('.symbol-btn, .seg-btn')) ||
        bodies[0] ||
        null
      );
    },

    installKeyboard() {
      window.addEventListener('keydown', (e) => {
        if (this.screen !== 'play' || dom.checked || e.metaKey || e.ctrlKey) return;
        const tag = (document.activeElement && document.activeElement.tagName) || '';
        if (tag === 'INPUT' || tag === 'TEXTAREA') return;

        if (e.key === 'Enter') {
          e.preventDefault();
          if (this.answered > 0) this.checkAll();
          return;
        }
        const body = this.targetBody();
        if (!body) return;

        const key = e.key === 'Backspace' ? '⌫' : e.key;
        if (/^[0-9]$/.test(key) || key === '⌫') {
          const btn = body.querySelector(`.keypad [data-key="${key}"]`);
          if (btn) {
            e.preventDefault();
            btn.click();
          }
          return;
        }
        if (['>', '<', '=', '≠'].includes(e.key)) {
          const btn = body.querySelector(`.symbol-btn[data-symbol="${e.key}"], .seg-btn[data-symbol="${e.key}"]`);
          if (btn) {
            e.preventDefault();
            btn.click();
          }
        }
      });
    },

    /* ------------------------------------------------------------ home -- */
    starRow(n) {
      return '⭐'.repeat(n) + '☆'.repeat(Math.max(0, 3 - n));
    },

    starsFor(id) {
      return this.bestStars[id] || 0;
    },

    /** Drop whatever quiz is on screen (used when moving to another screen). */
    leavePlay() {
      this.destroyQuestions();
      this.quiz = null;
      dom.quiz = null;
      this.clearBatch();
    },

    /** Show a screen without touching the history (used by popstate). */
    showScreen(screen) {
      if (screen !== 'play') this.leavePlay();
      this.screen = screen;
      window.scrollTo({ top: 0, behavior: 'auto' });
    },

    goHome() {
      this.showScreen('home');
      this.pushHistory();
    },

    openBuilder() {
      this.leavePlay();
      this.screen = 'builder';
      this.pushHistory();
      window.scrollTo({ top: 0, behavior: 'auto' });
    },

    /** A single quiz (library or hand made) is not part of a generated batch. */
    clearBatch() {
      dom.batch = [];
      dom.batchPos = 0;
      dom.batchQuery = null;
      this.syncBatchLabels();
    },

    /**
     * Mirror the batch state into reactive strings - the buttons and the header
     * chip read these, and module level objects alone would not trigger a
     * re-render in Alpine.
     */
    syncBatchLabels() {
      const total = dom.batch.length;
      this.batchLabel = total > 1 ? `Testas ${dom.batchPos + 1} / ${total}` : '';
      // The chip above already shows the position, so the button stays short
      // (long labels wrap to three rows on a phone).
      this.nextLabel = total > 1 && dom.batchPos + 1 >= total
        ? `🎲 Dar ${total} ${ltPlural(total, 'testas', 'testai', 'testų')}`
        : '🎲 Kitas testas';
    },

    destroyQuestions() {
      for (const item of dom.items) item.inst.destroy?.();
      stopSpeech();
      dom.items = [];
      dom.checked = false;
      this.checked = false;
      if (dom.host) dom.host.innerHTML = '';
    },

    async open(id, { push = true } = {}) {
      const mine = this.myQuizzes.find((q) => q.id === id);
      if (mine) {
        this.clearBatch();
        this.startQuiz(normalizeQuiz(mine, { source: 'custom' }), { push });
        return;
      }
      this.loading = true;
      this.error = '';
      try {
        const quiz = normalizeQuiz(await api(`quiz/${encodeURIComponent(id)}`));
        this.clearBatch();
        this.startQuiz(quiz, { push });
      } catch (e) {
        this.error = 'Nepavyko įkelti testo 😕';
        // Do not leave a dangling /t/<id> entry behind for a missing quiz.
        if (this.screen !== 'home') this.showScreen('home');
        else this.replaceHistory();
      } finally {
        this.loading = false;
      }
    },

    /* ------------------------------------------------------- generators -- */
    /** Every question type the generator can build, in the picker order. */
    genTypeAll() {
      return Object.values(this.genGroups).flatMap((types) => Object.keys(types || {}));
    },
    genGroupTypes(group) {
      return Object.keys(this.genGroups[group] || {});
    },
    isGenType(type) {
      return this.genTypes.includes(type);
    },
    toggleGenType(type) {
      this.genTypes = this.isGenType(type) ? this.genTypes.filter((t) => t !== type) : [...this.genTypes, type];
    },
    isGenGroup(group) {
      const types = this.genGroupTypes(group);
      return types.length > 0 && types.every((t) => this.isGenType(t));
    },
    /** The group names are buttons too: one tap picks a whole family. */
    toggleGenGroup(group) {
      const types = this.genGroupTypes(group);
      this.genTypes = this.isGenGroup(group)
        ? this.genTypes.filter((t) => !types.includes(t))
        : [...new Set([...this.genTypes, ...types])];
    },
    selectAllGenTypes() {
      this.genTypes = this.genTypeAll();
    },
    clearGenTypes() {
      this.genTypes = [];
    },
    /** Ticked types, in the picker order (empty = all of them). */
    genPickedTypes() {
      return this.genTypeAll().filter((t) => this.isGenType(t));
    },
    /** Label of the button that opens the exercise picker. */
    genPickLabel() {
      const picked = this.genPickedTypes().length;
      const all = this.genTypeAll().length;
      if (!picked || picked === all) return '⚙️ Užduotys: visos';
      return `⚙️ Užduotys: ${picked} iš ${all}`;
    },
    /**
     * The id the next generated quizzes will carry. A selection that is exactly
     * one of the old presets keeps that name, so the stars the child earned for
     * "Sudėtis" or "Braižyk" stay visible (this must match gen_focus_for_types()
     * in lib/Generator.php).
     */
    genId() {
      const all = this.genTypeAll();
      const picked = this.genPickedTypes();
      if (!picked.length || picked.length === all.length) return 'generated-mixed';
      for (const [focus, types] of Object.entries(this.genPresets)) {
        const set = (types || []).filter((t) => all.includes(t));
        if (set.length === picked.length && set.every((t) => picked.includes(t))) {
          return `generated-${focus}`;
        }
      }
      return `generated-custom-${picked.join('-')}`;
    },
    genStars() {
      return this.starsFor(this.genId());
    },

    /** Open/close the exercise picker (closing it also empties the preview). */
    toggleGenPicker() {
      this.genPickerOpen = !this.genPickerOpen;
      if (!this.genPickerOpen) this.resetTypePreview();
    },

    /* ------------------------------------------------- exercise previews -- */
    /**
     * A live example of one exercise type, shown in the panel next to the
     * picker while a chip is hovered / focused / tapped.
     *
     * The example is generated by the server and drawn with the very same
     * renderer as a real quiz, so a preview can never show something the
     * exercise does not do - a screenshot per type would have to be re-made
     * after every style change and would silently drift.
     */
    showTypePreview(type, label) {
      this.preview.label = label;
      if (this.preview.type === type) return;
      this.preview.type = type;
      this.preview.error = '';
      this.renderTypePreview(type);
    },

    /** Empty the panel again (closing the picker, generating, starting a quiz). */
    resetTypePreview() {
      this.preview.type = '';
      this.preview.label = '';
      this.preview.loading = false;
      this.preview.error = '';
      this.destroyPreview();
    },

    destroyPreview() {
      if (dom.preview) {
        dom.preview.destroy?.();
        dom.preview = null;
      }
      // Drop the rendered example too, so the panel holds no live widget.
      const host = this.$refs.previewBody;
      if (host) {
        host.innerHTML = '';
        host.style.height = '';
      }
    },

    async renderTypePreview(type) {
      let quiz = previewCache.get(type);
      if (!quiz) {
        this.preview.loading = true;
        try {
          const data = await api(`generate?types=${encodeURIComponent(type)}&tasks=1&count=1&max=10`);
          quiz = normalizeQuiz((data.quizzes || [])[0], { source: 'generated' });
          if (!quiz.questions.length) throw new Error('empty');
          previewCache.set(type, quiz);
        } catch (e) {
          this.preview.loading = false;
          this.preview.error = 'Nepavyko parodyti pavyzdžio 😕';
          return;
        }
        this.preview.loading = false;
      }
      // The pointer may already have moved on to another chip.
      if (this.preview.type !== type) return;
      const host = this.$refs.previewBody;
      if (!host) return;
      this.destroyPreview();
      host.innerHTML = '';

      const question = quiz.questions[0];
      const card = document.createElement('section');
      card.className = 'question-card question-card--preview';
      card.innerHTML =
        '<header class="question-head"><div class="min-w-0">' +
        `<p class="question-instruction">${esc(question.instruction)}</p>` +
        (question.hint ? `<p class="question-hint">${esc(question.hint)}</p>` : '') +
        '</div></header>';

      const body = document.createElement('div');
      body.className = 'question-body';
      const inst = createQuestion(question, { quiz, question, index: 0, changed: () => {} });
      body.appendChild(inst.el);
      card.appendChild(body);

      // Drawn at PREVIEW_SCALE (see below): a transform does not change the
      // layout box, so the frame is made 1/scale wide and the panel height is
      // set from the scaled height by hand.
      const frame = document.createElement('div');
      frame.className = 'type-preview__frame';
      frame.style.width = `${(100 / PREVIEW_SCALE).toFixed(3)}%`;
      frame.style.transform = `scale(${PREVIEW_SCALE})`;
      frame.style.transformOrigin = 'top left';
      frame.appendChild(card);
      host.appendChild(frame);
      const cs = getComputedStyle(host);
      const pad = (parseFloat(cs.paddingTop) || 0) + (parseFloat(cs.paddingBottom) || 0);
      const scaled = Math.round(frame.offsetHeight * PREVIEW_SCALE + pad);
      if (scaled > 0) host.style.height = `${scaled}px`;

      // A preview is read-only: no taps, no voice button, no stray scroll jumps.
      body.style.pointerEvents = 'none';
      for (const say of host.querySelectorAll('.say-btn')) say.remove();
      dom.preview = inst;
    },

    async randomQuiz() {
      this.loading = true;
      this.error = '';
      try {
        const quiz = normalizeQuiz(await api(`random${dom.quiz ? `?exclude=${encodeURIComponent(dom.quiz.id)}` : ''}`));
        this.clearBatch();
        this.startQuiz(quiz);
      } catch (e) {
        this.error = 'Nepavyko išrinkti testo 😕';
      } finally {
        this.loading = false;
      }
    },

    /**
     * ✨ Generators: build `genCount` quizzes (1-20), each with `genTasks` tasks
     * (5-50), out of the question types ticked on the home screen; play them one
     * after another with the 🎲 button. Nothing ticked = every type (the old
     * "viskas kartu" quiz).
     */
    async newGenerated() {
      this.loading = true;
      this.error = '';
      try {
        const count = Math.min(20, Math.max(1, Number(this.genCount) || 1));
        const tasks = Math.min(50, Math.max(1, Number(this.genTasks) || 8));
        const types = this.genPickedTypes();
        const params = [`max=${this.genMax}`, `count=${count}`, `tasks=${tasks}`];
        if (types.length) params.push(`types=${encodeURIComponent(types.join(','))}`);
        if (this.genSayStory) params.push('say=1');
        const query = params.join('&');
        const data = await api(`generate?${query}`);
        const list = (data.quizzes || []).map((q) => normalizeQuiz(q, { source: 'generated' }));
        dom.batch = list;
        dom.batchPos = 0;
        dom.batchQuery = query;
        // The picker is only there while the selection is being edited.
        this.resetTypePreview();
        this.genPickerOpen = false;
        this.startQuiz(list[0]);
      } catch (e) {
        this.error = 'Nepavyko sugalvoti testo 😕';
      } finally {
        this.loading = false;
      }
    },

    /** Next quiz: further through the generated batch, else a fresh batch. */
    anotherQuiz() {
      if (dom.batch.length && dom.batchPos + 1 < dom.batch.length) {
        dom.batchPos += 1;
        this.startQuiz(dom.batch[dom.batchPos]);
        return;
      }
      const query = dom.batchQuery;
      this.clearBatch();
      if (query) this.newGenerated();
      else this.randomQuiz();
    },

    nextQuizLabel() {
      return this.nextLabel;
    },

    /* ------------------------------------------------------------ play -- */
    /**
     * Show a quiz. `push: false` means "this page is already the one the browser
     * asked for" (a deep link or a Back/Forward), so the entry is rewritten
     * instead of adding another Back stop.
     */
    startQuiz(candidate, { push = true } = {}) {
      const quiz = Alpine.raw(candidate);
      if (!quiz || !quiz.questions.length) {
        this.error = 'Teste nėra klausimų 😕';
        this.showScreen('home');
        return;
      }
      this.resetTypePreview();
      this.destroyQuestions();
      dom.quiz = quiz;
      this.quiz = quiz;
      this.total = quiz.questions.length;
      this.answered = 0;
      this.correct = 0;
      this.stars = 0;
      this.allRight = false;
      this.scoreNote = '';
      this.checked = false;
      this.syncBatchLabels();
      this.screen = 'play';
      if (push) this.pushHistory();
      else this.replaceHistory();
      this.$nextTick(() => {
        this.mountQuestions();
        window.scrollTo({ top: 0, behavior: 'auto' });
      });
    },

    mountQuestions() {
      dom.host.innerHTML = '';
      dom.items = [];
      const quiz = dom.quiz;

      quiz.questions.forEach((question, index) => {
        const card = document.createElement('section');
        card.className = 'question-card';

        const head = document.createElement('header');
        head.className = 'question-head';
        head.innerHTML =
          `<span class="question-number">${index + 1}</span>` +
          `<div class="min-w-0"><p class="question-instruction">${esc(question.instruction)}</p>` +
          `${question.hint ? `<p class="question-hint">${esc(question.hint)}</p>` : ''}</div>`;

        // 🔊 reads the task aloud (ElevenLabs, cached as an mp3 on the server).
        // Story problems are silent unless the author opted in (`say: true`).
        const say = sayAllowed(question) ? sayButton(speechText(question)) : null;
        if (say) head.appendChild(say);

        const body = document.createElement('div');
        body.className = 'question-body';

        const result = document.createElement('p');
        result.className = 'q-result';

        const inst = createQuestion(question, {
          quiz,
          question,
          index,
          changed: () => this.onAnswerChanged(index),
        });
        body.appendChild(inst.el);

        card.append(head, body, result);
        dom.host.appendChild(card);
        dom.items.push({ inst, card, body, result, question, say });
      });

      this.answered = 0;
      // Hide every 🔊 when there is no voice configured (the question head ones
      // and the per-condition ones the question types add themselves).
      this.syncSayButtons();
      // Generate/decode the sentences in the background so the first tap is instant.
      prefetchSpeech(this.speechSentences());
    },

    /** Every 🔊 of the quiz on screen - question heads and single conditions. */
    speechButtons() {
      return dom.host ? [...dom.host.querySelectorAll('.say-btn')] : [];
    },

    /** The sentences of the quiz on screen, without duplicates. */
    speechSentences() {
      return [...new Set(this.speechButtons().map((b) => b.dataset.sentence).filter(Boolean))];
    },

    syncSayButtons() {
      for (const button of this.speechButtons()) button.classList.toggle('is-off', !this.speechOn);
    },

    onAnswerChanged() {
      if (dom.checked) return;
      // A new answer makes the old "kiek teisingai?" score stale.
      this.scoreNote = '';
      this.answered = dom.items.filter((item) => item.inst.touched && item.inst.touched()).length;
    },

    progressPercent() {
      return this.total ? Math.round((this.answered / this.total) * 100) : 0;
    },

    /**
     * Check every answer at once.
     *
     * `mode` says how much the child is told:
     *   · `count` - only how many answers were right and how many wrong; nothing is
     *               marked and the boxes stay editable, so it can be fixed and
     *               checked again
     *   · `mark`  - the wrong cards get a red border, but not the answer
     *   · `full`  - the wrong cards are marked AND the right answer is revealed
     */
    checkAll(mode = 'full') {
      if (dom.checked || !dom.items.length) return;

      const results = dom.items.map((item) => ({ item, ok: !!item.inst.check() }));
      const total = results.length;
      const correct = results.filter((r) => r.ok).length;
      this.correct = correct;
      this.allRight = correct === total;

      // "Just the score" leaves everything as it is (only a perfect quiz ends the
      // round - there is nothing left to fix then).
      if (mode === 'count' && !this.allRight) {
        this.scoreNote = `Teisingai: ${correct} / ${total} · klaidingai: ${total - correct}`;
        this.scoreNoteKind = correct === 0 ? 'bad' : 'ok';
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }

      dom.checked = true;
      this.checked = true;
      this.scoreNote = '';

      for (const { item, ok } of results) {
        item.card.classList.add(ok ? 'is-correct' : 'is-wrong');
        item.body.classList.add('is-locked');
        if (ok) {
          item.result.className = 'q-result q-result--ok';
          item.result.textContent = pick(PRAISE);
          item.inst.flash?.('ok');
        } else {
          item.result.className = 'q-result q-result--bad';
          // `full` also reveals the solution in place, which overwrites what the
          // child wrote. The sentence therefore has to name the wrong answer
          // explicitly, otherwise the revealed numbers make the mistake look like
          // a correct answer (the child's input is gone from the screen).
          const mine = mode === 'full' && item.inst.touched?.() && typeof item.inst.givenText === 'function' ? item.inst.givenText() : '';
          item.result.textContent = mode !== 'full'
            ? '✗ Neteisingai'
            : mine
              ? `✗ Tavo atsakymas: ${mine} · Teisingas atsakymas: ${answerText(item.question)}`
              : `✗ ${revealText(item.question)}`;
          if (mode === 'full') item.inst.reveal?.();
          item.inst.flash?.('bad');
        }
      }

      this.stars = correct === 0 ? 1 : Math.max(1, Math.round((3 * correct) / total));

      const id = dom.quiz.id;
      if (this.stars > (this.bestStars[id] || 0)) {
        this.bestStars = { ...this.bestStars, [id]: this.stars };
        saveJSON(KEY_BEST, this.bestStars);
      }
      if (this.allRight) confettiBurst();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    /** Start over with empty boxes. */
    retry() {
      for (const item of dom.items) {
        item.card.classList.remove('is-correct', 'is-wrong');
        item.body.classList.remove('is-locked');
        item.result.className = 'q-result';
        item.result.textContent = '';
        item.inst.clear?.();
      }
      dom.checked = false;
      this.checked = false;
      this.correct = 0;
      this.stars = 0;
      this.allRight = false;
      this.scoreNote = '';
      this.answered = 0;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    /**
     * 🖨️ Spausdinimo versija: print the quiz on screen as a black & white
     * worksheet. Nothing is re-rendered - the play screen *is* the worksheet;
     * the `@media print` rules in input.css drop the screen-only chrome (the
     * sticky bars, the number pads, the > < = pickers, the 🔊 buttons) and force
     * everything that stays to plain black outlines on white.
     */
    printQuiz() {
      if (!dom.quiz) return;
      window.print();
    },

    /* --------------------------------------------------------- builder -- */
    async validateJson() {
      this.loading = true;
      try {
        const res = await fetch('/api/validate', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: this.json,
        });
        this.validation = await res.json();
      } catch (e) {
        this.validation = { ok: false, errors: ['Serveris neatsakė 😕'] };
      } finally {
        this.loading = false;
      }
    },

    playJson() {
      let raw;
      try {
        raw = JSON.parse(this.json);
      } catch (e) {
        this.validation = { ok: false, errors: [`Netaisyklingas JSON: ${e.message}`] };
        return;
      }
      const quiz = normalizeQuiz(raw, { source: 'custom' });
      if (!quiz.questions.length) {
        this.validation = { ok: false, errors: ['Teste nėra klausimų'] };
        return;
      }
      quiz.id = quiz.id.startsWith('my-') ? quiz.id : `my-${quiz.id}`;
      this.saveCustom(quiz);
      this.clearBatch();
      this.startQuiz(quiz);
    },

    saveCustom(quiz) {
      const clean = storable(quiz);
      this.myQuizzes = [clean, ...this.myQuizzes.filter((q) => q.id !== clean.id)].slice(0, 30);
      saveJSON(KEY_CUSTOM, this.myQuizzes);
    },

    deleteCustom(id) {
      this.myQuizzes = this.myQuizzes.filter((q) => q.id !== id);
      saveJSON(KEY_CUSTOM, this.myQuizzes);
    },

    async loadExampleIntoEditor(id) {
      this.loading = true;
      try {
        this.json = JSON.stringify(await api(`quiz/${encodeURIComponent(id)}`), null, 2);
        this.validation = null;
      } catch (e) {
        this.validation = { ok: false, errors: ['Nepavyko įkelti pavyzdžio'] };
      } finally {
        this.loading = false;
      }
    },
  };
}

Alpine.data('app', app);
window.Alpine = Alpine;
Alpine.start();
