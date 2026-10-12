/**
 * speech.js — the 🔊 button: ElevenLabs reads the task instruction aloud.
 *
 * Every sentence is synthesised on the server **once** and saved as an MP3
 * (/cache/tts/<sha1>.mp3), so the second tap - on this device or on another
 * child's - only downloads a static file. This module only asks the server for
 * urls (`POST /api/tts`), keeps them in memory and plays the audio.
 *
 * Playback goes through the Web Audio API on purpose: the AudioContext is
 * resumed inside the tap, so a sentence that still had to be generated can
 * start playing a moment later without being blocked by the browser's
 * autoplay rules (Safari in particular refuses a plain `audio.play()` after an
 * `await`). When Web Audio is missing we fall back to an <audio> element.
 */

const API = '/api/tts';
/** Sentences per request: short requests keep the server responsive. */
const BATCH = 3;
/** Decoded clips kept in memory (they are a few dozen kB each). */
const BUFFER_LIMIT = 40;

/**
 * The sentences the server signed for the quizzes that were loaded
 * (`sentence => token`). A token is an HMAC of that one sentence, so the
 * endpoint only ever synthesises text that really occurs in the app - and a
 * sentence without a token gets no 🔊 at all.
 */
const tokens = new Map();

/** Remember the tokens that came with a quiz payload (call on every load). */
export function setSpeechTokens(map) {
  if (!map || typeof map !== 'object') return;
  for (const [sentence, token] of Object.entries(map)) {
    if (typeof sentence === 'string' && typeof token === 'string') tokens.set(sentence, token);
  }
}

/** Is this exact sentence allowed to be synthesised? */
export function speechTokenFor(sentence) {
  return tokens.get(sentence) || '';
}

/** Symbols are read as words - dropped in raw, ">" would be read as a pause. */
const SYMBOL_WORDS = { '>': 'daugiau', '<': 'mažiau', '=': 'lygu', '≠': 'nelygu', '+': 'plius', '-': 'minus', '−': 'minus' };

/**
 * The sentence that is spoken and used as the cache key. It must stay
 * byte-identical to PHP `tts_normalize_text()` output, otherwise the client
 * asks for a url the server never saved.
 */
export function speakable(value) {
  return String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]+/g, ' ')
    .replace(/[<>≠=+\-−]/g, (m) => ` ${SYMBOL_WORDS[m]} `)
    .replace(/[ \t\n\r\f\v]+/g, ' ')
    .trim();
}

/**
 * What the 🔊 button of a question reads: the author's `readAloud` when it is
 * set, otherwise the instruction plus the small hint underneath it (a
 * seven-year-old cannot read the hint either).
 *
 * Hints that only explain the symbols ("Daugiau – >, mažiau – <") are skipped:
 * read out loud they would say "daugiau – daugiau, mažiau – mažiau", which
 * explains nothing.
 */
export function speechText(question) {
  const q = question || {};
  const instruction = String(q.instruction ?? '').trim();
  const custom = String(q.readAloud ?? '').trim();
  if (custom !== '' && custom !== instruction) return custom;
  const hint = String(q.hint ?? '').trim();
  if (hint === '' || /[<>≠=]/.test(hint)) return instruction;
  return /[.!?…]$/.test(instruction) ? `${instruction} ${hint}` : `${instruction}. ${hint}`;
}

/** The two "pasakojimo uždavinys" types: they are read by the child, not to it. */
const STORY_TYPES = new Set(['story', 'storyChoice']);

/**
 * May this question have a 🔊 button at all?
 *
 * Story problems are meant to be READ, so they are silent by default - the
 * author opts in per question with `"say": true` (the generator has a checkbox
 * next to the story type). Every other type keeps its button unless it is
 * explicitly silenced with `"say": false`.
 */
export function sayAllowed(question) {
  const q = question || {};
  if (typeof q.say === 'boolean') return q.say;
  return !STORY_TYPES.has(String(q.type || ''));
}

let status = null;
let statusPromise = null;
let lastError = '';
let ctx = null;
let source = null; // currently playing AudioBufferSourceNode
let element = null; // <audio> fallback while it plays
let playing = null; // { button, sentence }

const urls = new Map(); // sentence -> mp3 url
const buffers = new Map(); // sentence -> decoded AudioBuffer
const pending = new Map(); // sentence -> promise (url or buffer)

/* ---------------------------------------------------------------- server -- */

async function post(body) {
  const res = await fetch(API, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    let code = `HTTP ${res.status}`;
    try {
      const data = await res.json();
      if (data && data.error) code = String(data.error);
    } catch (e) {
      /* not JSON - keep the plain HTTP status */
    }
    throw new Error(code);
  }
  return res.json();
}

/** Is the voice configured on the server? Cached for the session. */
export function speechStatus() {
  if (statusPromise) return statusPromise;
  statusPromise = fetch(`${API}?status=1`, { headers: { Accept: 'application/json' } })
    .then((res) => (res.ok ? res.json() : null))
    .then((data) => {
      status = data && typeof data === 'object' ? data : { enabled: false };
      return status;
    })
    .catch(() => {
      status = { enabled: false };
      return status;
    });
  return statusPromise;
}

/** The last status we know (false until speechStatus() answered). */
export function speechEnabled() {
  return !!(status && status.enabled);
}

/**
 * Turn a list of sentences into mp3 urls, generating only what is missing.
 * Collects the result in `urls`.
 */
async function fetchUrls(sentences) {
  // Each phrase travels with the token the server signed for it.
  const items = sentences.map((text) => ({ text, token: tokens.get(text) || '' }));
  const data = await post({ items });
  lastError = '';
  for (const item of data.items || []) {
    if (item && item.url) urls.set(item.text, item.url);
    else if (item && item.error) lastError = item.error;
  }
  if (!data.ok && !lastError) lastError = data.error || 'failed';
  return sentences.map((s) => urls.get(s) || null);
}

/** The url of one sentence, generating it when it is not saved yet. */
function ensureUrl(sentence) {
  if (urls.has(sentence)) return Promise.resolve(urls.get(sentence));
  const running = pending.get(sentence);
  if (running) return running;
  const promise = fetchUrls([sentence])
    .then((list) => list[0] || null)
    .catch((e) => {
      lastError = e && e.message ? String(e.message) : 'network';
      return null;
    })
    .finally(() => pending.delete(sentence));
  pending.set(sentence, promise);
  return promise;
}

/* ------------------------------------------------------------- playback -- */

function audioContext() {
  const Ctor = window.AudioContext || window.webkitAudioContext;
  if (!Ctor) return null;
  if (!ctx) {
    try {
      ctx = new Ctor();
    } catch (e) {
      return null;
    }
  }
  return ctx;
}

function keepBuffer(sentence, buffer) {
  buffers.set(sentence, buffer);
  while (buffers.size > BUFFER_LIMIT) {
    buffers.delete(buffers.keys().next().value);
  }
}

/** Download + decode one clip (once per sentence). */
function loadBuffer(sentence) {
  if (buffers.has(sentence)) return Promise.resolve(buffers.get(sentence));
  const running = pending.get(`b:${sentence}`);
  if (running) return running;
  const promise = ensureUrl(sentence).then(async (url) => {
    if (!url) return null;
    const res = await fetch(url, { cache: 'force-cache' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const raw = await res.arrayBuffer();
    const context = audioContext();
    if (!context) return null;
    const buffer = await context.decodeAudioData(raw);
    keepBuffer(sentence, buffer);
    return buffer;
  });
  pending.set(`b:${sentence}`, promise);
  return promise.finally(() => pending.delete(`b:${sentence}`));
}

function setState(button, state) {
  if (!button) return;
  button.dataset.state = state;
  button.classList.toggle('is-loading', state === 'loading');
  button.classList.toggle('is-playing', state === 'playing');
  button.classList.toggle('is-error', state === 'error');
  const icon = button.querySelector('.say-btn__icon');
  if (icon) {
    icon.textContent = { idle: '🔊', loading: '⏳', playing: '🔈', error: '😕' }[state] || '🔊';
  }
}

/** Stop whatever is playing (called when leaving the quiz too). */
export function stopSpeech() {
  if (source) {
    source.onended = null;
    try {
      source.stop();
    } catch (e) {
      /* already finished */
    }
    source.disconnect?.();
    source = null;
  }
  if (element) {
    try {
      element.pause();
    } catch (e) {
      /* ignore */
    }
    element = null;
  }
  if (playing) setState(playing.button, 'idle');
  playing = null;
}

function playBuffer(sentence, buffer, button) {
  const context = audioContext();
  stopSpeech();
  const node = context.createBufferSource();
  node.buffer = buffer;
  node.connect(context.destination);
  node.onended = () => {
    if (source === node) stopSpeech();
  };
  source = node;
  playing = { button, sentence };
  setState(button, 'playing');
  node.start();
}

/** Last resort when the browser has no Web Audio (or cannot decode the mp3). */
function playElement(url, sentence, button) {
  const audio = new Audio(url);
  stopSpeech();
  element = audio;
  playing = { button, sentence };
  setState(button, 'playing');
  audio.addEventListener('ended', () => {
    if (element === audio) stopSpeech();
  });
  audio.addEventListener('error', () => {
    if (element === audio) stopSpeech();
  });
  return audio.play().then(
    () => true,
    () => {
      stopSpeech();
      return false;
    }
  );
}

/** Play one sentence. Resolves to true when it started. */
async function speak(sentence, button) {
  const context = audioContext();
  if (context && context.state === 'suspended') {
    try {
      await context.resume(); // inside the tap, so later playback is allowed
    } catch (e) {
      /* fall through to the <audio> path */
    }
  }

  let buffer = buffers.get(sentence);
  if (!buffer) {
    try {
      buffer = await loadBuffer(sentence);
    } catch (e) {
      buffer = null;
    }
  }
  if (buffer && audioContext()) {
    playBuffer(sentence, buffer, button);
    return true;
  }

  const url = urls.get(sentence) || (await ensureUrl(sentence));
  if (!url) return false;
  return playElement(url, sentence, button);
}

async function onSayClick(button, sentence) {
  if (button.dataset.state === 'loading') return; // already on its way
  if (playing && playing.button === button) {
    stopSpeech(); // a second tap stops it
    return;
  }
  stopSpeech();
  setState(button, 'loading');
  const started = await speak(sentence, button);
  if (!started) {
    setState(button, 'error');
    if (lastError) console.warn(`[speech] ${lastError}: ${sentence}`);
    window.setTimeout(() => {
      if (button.dataset.state === 'error') setState(button, 'idle');
    }, 2500);
  }
}

/**
 * The 🔊 button of one question (or of one condition inside it).
 * Returns null when the text cannot be spoken (empty, or longer than the
 * server limit). `small` gives the compact variant used next to a prompt.
 */
export function sayButton(text, { small = false } = {}) {
  const sentence = speakable(text);
  if (sentence === '' || sentence.length > 400) return null;
  // No token = the server never signed this phrase, so it stays silent
  // (a handwritten quiz in the builder, or a sentence the app does not contain).
  if (!tokens.has(sentence)) return null;

  const button = document.createElement('button');
  button.type = 'button';
  button.className = small ? 'say-btn say-btn--sm' : 'say-btn';
  button.dataset.sentence = sentence;
  button.dataset.state = 'idle';
  button.title = 'Paklausyti, ką reikia daryti';
  button.setAttribute('aria-label', `Paklausyti užduoties: ${sentence}`);
  button.innerHTML = '<span class="say-btn__icon" aria-hidden="true">🔊</span>';
  button.addEventListener('click', () => onSayClick(button, sentence));
  return button;
}

/**
 * Warm the cache in the background: the sentences of the quiz that is on
 * screen are generated (if new) and decoded, so the first tap plays at once.
 * Small batches, one after another - never a long request.
 */
export async function prefetchSpeech(texts) {
  const info = await speechStatus();
  if (!info || !info.enabled) return;

  const list = [...new Set((texts || []).map(speakable).filter((s) => s !== '' && s.length <= 400))];
  const missing = list.filter((s) => !urls.has(s));
  for (let i = 0; i < missing.length; i += BATCH) {
    const batch = missing.slice(i, i + BATCH);
    try {
      await fetchUrls(batch);
    } catch (e) {
      return; // offline or the server is not configured - the tap will retry
    }
    await new Promise((resolve) => window.setTimeout(resolve, 50));
  }

  if (!audioContext()) return;
  for (const sentence of list) {
    try {
      await loadBuffer(sentence);
    } catch (e) {
      /* the clip will simply be loaded on the tap */
    }
  }
}
