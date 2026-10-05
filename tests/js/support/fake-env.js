// Test support (S2 spec §6.1): fakeEnv() implements every member of the Env of public/station/js/env.js (§3.1) over
// virtual clocks and timers, plus test controls. Not a suite (no .test.js suffix). Nothing here uses real time.
import { createMemoryIndexedDB } from './memory-idb.js';
import { fakeFetch } from './fake-fetch.js';

/** fakeEnv()'s wall clock: 2026-10-01 12:00:00.000 UTC. */
export const DEFAULT_NOW = 1790856000000;
/** The events env.on() and fire() know. */
export const EVENTS = Object.freeze(['online', 'offline', 'visible', 'hidden', 'input', 'hashchange']);
const SETTABLE = Object.freeze(['displayMode', 'persisted', 'persistResult', 'estimateKb', 'online', 'visible', 'shellBuild', 'hash',
  'camera', 'barcodeDetector']);
const MAX_TIMER_RUNS = 100000;

/**
 * Awaits setImmediate `turns` times, so IndexedDB events and promise chains settle.
 * @param {number} [turns]
 * @returns {Promise<void>}
 */
export async function flush(turns = 5) {
  for (let i = 0; i < turns; i++) await new Promise((resolve) => setImmediate(resolve));
}

const normaliseHash = (h) => {
  const s = String(h ?? '');
  if (s === '' || s === '#') return '';
  return s.startsWith('#') ? s : '#' + s;
};

/** options.barcodeDetector {results: [[raw, …], …]} → a fake detector: each detect() returns the next list, then []. */
function makeDetector(config) {
  if (config === null || config === undefined) return null;
  const results = (config.results ?? []).map((list) => [...list]);
  const detector = {
    detectCalls: 0,
    sources: [],
    async detect(source) {
      detector.detectCalls += 1;
      detector.sources.push(source);
      return (results.shift() ?? []).map((rawValue) => ({ rawValue }));
    },
  };
  return detector;
}

/**
 * Every Env member of §3.1, deterministic, plus test controls:
 * - advance(ms): wall and monotonic clocks advance together; due timers run in time order (each sees the time it was
 *   due), with flush() between them. Resolves when done; rethrows the first exception a timer threw (after all ran).
 * - sleep(ms): the device sleeps: the wall clock advances, the monotonic clock does not (as performance.now() during
 *   device sleep); then the due timers run (they fire on wake, an interval once).
 * - jumpWall(deltaMs): the wall clock alone jumps (negative: set back). Timers are not affected.
 * - set(name, value): displayMode, persisted, persistResult, estimateKb, online, visible, shellBuild, hash, camera,
 *   barcodeDetector (silently: no event).
 * - fire(event): calls the env.on() listeners of 'online', 'offline', 'visible', 'hidden', 'input' or 'hashchange'.
 *   online/offline and visible/hidden also set online() and visible() as the browser would.
 * - setHash(h) changes hash() and, when it changed, fires hashchange in a later task (as location.hash does).
 * - persist() resolves persistResult and, when it is true, sets persisted; persistCalls counts the calls.
 * - logs (lines given to log()), reloads (reload() calls), streams (every camera() stream), pendingTimers().
 * @param {object} [options] now, mono, displayMode, persisted, persistResult, estimateKb, online, visible, shellBuild,
 *   scope, hash, barcodeDetector, camera ('ok' | 'refused'), seed (for random()), and the platform objects indexedDB,
 *   fetch, locks, caches, serviceWorker, document (defaults: a memory IndexedDB, fakeFetch(), then null)
 */
export function fakeEnv(options = {}) {
  const has = (k) => Object.prototype.hasOwnProperty.call(options, k) && options[k] !== undefined;
  const state = {
    displayMode: has('displayMode') ? options.displayMode : 'standalone',
    persisted: has('persisted') ? options.persisted : true,
    persistResult: has('persistResult') ? options.persistResult : true,
    estimateKb: has('estimateKb') ? options.estimateKb : 812,
    online: has('online') ? options.online : true,
    visible: has('visible') ? options.visible : true,
    shellBuild: has('shellBuild') ? options.shellBuild : 'test+0000000000',
    hash: normaliseHash(has('hash') ? options.hash : ''),
    camera: has('camera') ? options.camera : 'ok',
    barcodeDetector: has('barcodeDetector') ? options.barcodeDetector : null,
  };
  let detector = makeDetector(state.barcodeDetector);
  let wall = has('now') ? options.now : DEFAULT_NOW;
  let mono = has('mono') ? options.mono : 1000;
  let timerClock = 0;               // advances with advance() and sleep(); timers are due on it
  const timers = new Map();         // id → {id, fn, args, due, period, seq}
  let nextTimerId = 1;
  let timerSeq = 0;
  let chain = Promise.resolve();    // advance() and sleep() run one at a time
  let x = (has('seed') ? options.seed : 1) >>> 0 || 1; // xorshift32 state (never 0)
  let uuidCounter = 0;
  const listeners = new Map(EVENTS.map((e) => [e, []]));

  const schedule = (fn, ms, args, repeat) => {
    if (typeof fn !== 'function') throw new TypeError('fake-env: timers take a function');
    const delay = Math.max(0, Number(ms) || 0);
    const id = nextTimerId++;
    timers.set(id, { id, fn, args, due: timerClock + delay, period: repeat ? Math.max(1, delay) : 0, seq: timerSeq++ });
    return id;
  };
  const clear = (id) => { timers.delete(id); };
  const nextDue = () => {
    let next = null;
    for (const t of timers.values()) if (next === null || t.due < next.due || (t.due === next.due && t.seq < next.seq)) next = t;
    return next;
  };
  const runTimers = async (target, moveClocks) => {
    const errors = [];
    let runs = 0;
    await flush();
    for (;;) {
      const t = nextDue();
      if (t === null || t.due > target) break;
      if (++runs > MAX_TIMER_RUNS) throw new Error('fake-env: more than 100000 timers ran in one advance (a timer loop?)');
      if (t.due > timerClock) {
        if (moveClocks) { wall += t.due - timerClock; mono += t.due - timerClock; }
        timerClock = t.due;
      }
      if (t.period > 0) {
        let due = t.due + t.period;
        if (due <= timerClock) due = timerClock + t.period; // after a sleep an interval fires once, then keeps its pace
        t.due = due;
        t.seq = timerSeq++;
      } else {
        timers.delete(t.id);
      }
      try { t.fn(...t.args); } catch (e) { errors.push(e); }
      await flush();
    }
    if (target > timerClock) {
      if (moveClocks) { wall += target - timerClock; mono += target - timerClock; }
      timerClock = target;
    }
    if (errors.length > 0) throw errors[0];
  };
  const serial = (run) => {
    const p = chain.then(run);
    chain = p.catch(() => {});
    return p;
  };

  const env = {
    // ---- the Env members (§3.1) ----
    now: () => wall,
    mono: () => mono,
    displayMode: () => state.displayMode,
    persisted: async () => state.persisted === true,
    persist: async () => {
      env.persistCalls += 1;
      const granted = state.persistResult === true;
      if (granted) state.persisted = true;
      return granted;
    },
    estimateKb: async () => state.estimateKb,
    online: () => state.online !== false,
    visible: () => state.visible === true,
    on(event, cb) {
      const list = listeners.get(event);
      if (!list) throw new TypeError('env.on: unknown event ' + event);
      const entry = { cb };
      list.push(entry);
      return () => {
        const i = list.indexOf(entry);
        if (i >= 0) list.splice(i, 1);
      };
    },
    hash: () => state.hash,
    setHash(h) {
      const v = normaliseHash(h);
      if (v === state.hash) return;
      state.hash = v;
      setImmediate(() => env.fire('hashchange'));
    },
    barcodeDetector: async () => detector,
    async camera() {
      if (state.camera === 'refused') throw new DOMException('Permission denied', 'NotAllowedError');
      if (state.camera !== 'ok') throw new TypeError(`fakeEnv: camera must be 'ok' or 'refused', not ${state.camera}`);
      const tracks = [1, 2].map((n) => ({
        kind: 'video', label: `fake camera track ${n}`, readyState: 'live', stopped: false,
        stop() { this.stopped = true; this.readyState = 'ended'; },
      }));
      const stream = {
        id: `fake-stream-${env.streams.length + 1}`,
        get active() { return tracks.some((t) => !t.stopped); },
        getTracks: () => [...tracks],
        getVideoTracks: () => [...tracks],
      };
      env.streams.push(stream);
      return stream;
    },
    locks: has('locks') ? options.locks : null,
    crypto: globalThis.crypto,
    random(n) {
      const out = new Uint8Array(n);
      for (let i = 0; i < n; i++) {
        x ^= x << 13; x >>>= 0;
        x ^= x >>> 17;
        x ^= x << 5; x >>>= 0;
        out[i] = x & 0xff;
      }
      return out;
    },
    uuid: () => '00000000-0000-4000-8000-' + (++uuidCounter).toString(16).padStart(12, '0'),
    indexedDB: has('indexedDB') ? options.indexedDB : createMemoryIndexedDB(),
    fetch: has('fetch') ? options.fetch : fakeFetch(),
    caches: has('caches') ? options.caches : null,
    serviceWorker: has('serviceWorker') ? options.serviceWorker : null,
    shellBuild: () => state.shellBuild,
    scope: () => (has('scope') ? options.scope : 'http://localhost:8088/station/'),
    reload() { env.reloads += 1; },
    setTimeout: (fn, ms, ...args) => schedule(fn, ms, args, false),
    clearTimeout: clear,
    setInterval: (fn, ms, ...args) => schedule(fn, ms, args, true),
    clearInterval: clear,
    log(line) { env.logs.push(String(line)); },
    formatTime: (ms) => new Date(ms).toISOString(),
    document: has('document') ? options.document : null,

    // ---- test controls ----
    logs: [],
    reloads: 0,
    streams: [],
    persistCalls: 0,
    /** @param {number} ms @returns {Promise<void>} */
    advance: (ms) => serial(() => runTimers(timerClock + Math.max(0, ms), true)),
    /** @param {number} ms @returns {Promise<void>} */
    sleep: (ms) => serial(() => {
      const d = Math.max(0, ms);
      wall += d;
      timerClock += d;
      return runTimers(timerClock, false);
    }),
    /** @param {number} deltaMs */
    jumpWall(deltaMs) { wall += deltaMs; },
    set(name, value) {
      if (!SETTABLE.includes(name)) throw new TypeError('fakeEnv.set: unknown name ' + name);
      state[name] = name === 'hash' ? normaliseHash(value) : value;
      if (name === 'barcodeDetector') detector = makeDetector(value);
    },
    fire(event) {
      const list = listeners.get(event);
      if (!list) throw new TypeError('fakeEnv.fire: unknown event ' + event);
      if (event === 'online' || event === 'offline') state.online = event === 'online';
      if (event === 'visible' || event === 'hidden') state.visible = event === 'visible';
      for (const entry of [...list]) entry.cb();
    },
    /** The number of timers and intervals still scheduled. */
    pendingTimers: () => timers.size,
  };
  return env;
}
