// The Station's composition root (docs/design/50-design-station.md §7.2, §7.5, X-2; S2 spec §3.17): the tablet
// state machine, the primary-window lock, the start-up order, the timers, the chrome controller, the device screen,
// and the dev-only hooks. boot.js imports this file once its first-start wait is over and calls start(); this file
// never imports boot.js (that would evaluate a second copy of it and boot again).
import { browserEnv } from './env.js';
import { openDb, STORES } from './db.js';
import { createClock } from './clock.js';
import { createApi, ApiError } from './api.js';
import { createDevice, codeCheck, outboxStats, RegistrationError, STARTUP_HEARTBEAT_MS, TRIGGER_GAP_MS } from './device.js';
import { createUpdater } from './update.js';
import { createRouter, allowedIn, homeOf } from './router.js';
import { useDocument, mount, el } from './dom.js';
import { format, normalise } from './registration_code.js';
import { parseDb } from './canonical.js';
import * as chromeView from './views/chrome.js';
import * as startingView from './views/starting.js';
import * as deviceView from './views/device.js';
import * as loginView from './views/login.js';
import * as aboutView from './views/about.js';
import * as wipeView from './views/wipe.js';
import * as elsewhereView from './views/elsewhere.js';

/** clock.tick() and the update check run this often (ms). */
export const TICK_MS = 15000;
/** A registered tablet's heartbeat runs this often (ms). */
export const HEARTBEAT_MS = 300000;

/** The S2 stand-in for S3's session.js: nobody is ever signed in. */
const LOCKED_SESSION = Object.freeze({
  state: () => 'LOCKED', lock() {}, onRevokedGrants() {}, onOfflineDisabled() {}, tick() {}, touch() {}, hasVaultKey: () => false, on() {},
});
/** States in which the database closing under the page is expected (the wipe, or this window lost the lock). */
const NO_RELOAD_STATES = Object.freeze(['REPLACED', 'WIPING', 'ERASED']);
/** States in which this window never contacts the server: the header shows no connection chip at all. */
const NO_CHIP_STATES = Object.freeze(['ELSEWHERE', 'REPLACED', 'NO_STORAGE']);

/**
 * The primary-window lock (X-2): 'held' for the page's life, 'busy' when another window holds it, 'unsupported'
 * without Web Locks (one window by convention), 'denied' when the browser refuses the Locks API here (Chromium's
 * SecurityError when site data is blocked for the origin: IndexedDB is refused there too). onLost runs when another
 * window steals a lock this page held.
 * @param {LockManager|null} locks
 * @param {{steal?: boolean, onLost?: () => void}} [options]
 * @returns {Promise<'held'|'busy'|'unsupported'|'denied'>}
 */
export function claimPrimary(locks, { steal = false, onLost = () => {} } = {}) {
  if (!locks) return Promise.resolve('unsupported'); // no Web Locks: one window by convention (runbook)
  return new Promise((resolve) => {
    let granted = false;
    const ended = (e) => {
      if (granted) { if (e?.name === 'AbortError') onLost(); return; } // another window stole it
      resolve('denied'); // refused before any grant: the promise never hangs
    };
    try {
      locks.request('pfpms-primary', steal ? { steal: true } : { ifAvailable: true }, (lock) => {
        if (lock === null) { resolve('busy'); return undefined; }
        granted = true;
        resolve('held');
        return new Promise(() => {}); // held for the page's life
      }).catch(ended);
    } catch (e) {
      ended(e);
    }
  });
}

/**
 * The device view's behaviour (prepare, typing, the QR scan loop, the press); views/device.js only renders.
 * @param {object} options
 * @param {import('./env.js').Env} options.env
 * @param {import('./device.js').Device} options.device
 * @param {Element} options.root #app
 * @param {(model: object, actions: object) => void} options.mountView mounts views/device.js's render() with the chrome
 * @param {() => void} options.onContinue the registered view's Continue
 * @param {number} [options.scanMs] how often a camera frame is checked for a QR code
 * @returns {{show: () => Promise<void>, stop: () => void, pause: () => void, model: () => object|null}}
 *   pause(): the page was hidden while the view shows: the camera stops and the view goes back to ready
 */
export function createDeviceScreen({ env, device, root, mountView, onContinue, scanMs = 250 }) {
  let model = null;
  let detector = null;
  let interval = null;
  let generation = 0; // a stop() or a new show() makes the work of the earlier one draw nothing
  const draw = () => mountView(model, actions);

  const stopScan = () => {
    if (interval !== null) { env.clearInterval(interval); interval = null; }
    const stream = model?.stream;
    if (stream) {
      for (const track of stream.getTracks()) track.stop();
      model.stream = null;
    }
  };

  const actions = {
    onInput(text) {
      if (!model) return;
      model.code = String(text ?? '');
      model.check = codeCheck(model.code);
      deviceView.updateCheck(root, { check: model.check, phase: model.phase });
    },
    async onScan() {
      if (!model || detector === null || model.phase !== 'ready') return;
      const gen = generation;
      let stream;
      try {
        stream = await env.camera();
      } catch {
        if (gen !== generation) return;
        model.message = { key: 'camera_refused', params: {} };
        model.phase = 'ready';
        draw();
        return;
      }
      if (gen !== generation || model.phase !== 'ready') { for (const track of stream.getTracks()) track.stop(); return; }
      model.phase = 'scanning';
      model.stream = stream;
      model.message = null;
      draw();
      let busy = false;
      interval = env.setInterval(async () => {
        if (busy) return;
        busy = true;
        try {
          const codes = await detector.detect(root.querySelector('video.scanner'));
          if (gen !== generation || model.phase !== 'scanning') return;
          const hit = (codes ?? []).map((c) => c?.rawValue).find((v) => typeof v === 'string' && codeCheck(v) === 'ok');
          if (hit === undefined) return;
          model.code = format(normalise(hit));
          model.check = 'ok';
          stopScan();
          model.phase = 'ready';
          model.focus = 'register';
          draw();
        } catch {
          // a detect() rejection: the next tick retries
        } finally {
          busy = false;
        }
      }, scanMs);
    },
    onStopScan() {
      if (!model) return;
      stopScan();
      model.phase = 'ready';
      draw();
    },
    async onSubmit() {
      if (!model || model.check !== 'ok' || !['ready', 'scanning'].includes(model.phase)) return;
      const gen = generation;
      stopScan();
      model.phase = 'sending';
      model.message = null;
      draw();
      try {
        const r = await device.register(model.code);
        if (gen !== generation) return;
        model.phase = 'registered';
        model.result = { site: r.site, label: r.label };
        model.storageRefused = !r.storagePersisted;
      } catch (e) {
        if (gen !== generation) return;
        model.message = e instanceof RegistrationError
          ? { key: e.key, params: { ...e.params, incident: e.incident } }
          : { key: 'reg_failed', params: {} };
        model.phase = 'ready';
        model.focus = 'code';
      }
      draw();
    },
    onContinue() { onContinue(); },
  };

  return {
    async show() {
      stopScan();
      const gen = ++generation;
      model = { phase: 'preparing', check: 'empty', message: null, canScan: false, storageRefused: false, result: null, code: '', stream: null, focus: 'code' };
      if (device.registered()) {
        // Registered while this view was left (the press went on in the background): only Continue is left to do.
        const info = device.info();
        model.phase = 'registered';
        model.result = { site: info?.site_name ?? '', label: info?.label ?? '' };
        draw();
        return;
      }
      draw();
      detector = await env.barcodeDetector();
      if (gen !== generation) return;
      model.canScan = detector !== null;
      const p = await device.prepare();
      if (gen !== generation) return;
      model.phase = p.ok ? 'ready' : 'blocked';
      draw();
    },
    stop() {
      generation += 1;
      stopScan();
    },
    pause() {
      if (model?.phase !== 'scanning') { stopScan(); return; }
      stopScan();
      model.phase = 'ready';
      draw();
    },
    model: () => model,
  };
}

/** A copy of a dump value: a CryptoKey as {CryptoKey: {type, extractable, algorithm, usages}}, bytes as hex. */
function plain(v) {
  if (v === null || typeof v !== 'object') return v;
  if (Object.prototype.toString.call(v) === '[object CryptoKey]') {
    return { CryptoKey: { type: v.type, extractable: v.extractable, algorithm: plain(v.algorithm), usages: [...v.usages] } };
  }
  if (v instanceof ArrayBuffer || ArrayBuffer.isView(v)) {
    const bytes = v instanceof ArrayBuffer ? new Uint8Array(v) : new Uint8Array(v.buffer, v.byteOffset, v.byteLength);
    return [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
  }
  if (Array.isArray(v)) return v.map(plain);
  return Object.fromEntries(Object.entries(v).map(([k, x]) => [k, plain(x)]));
}

/** Dev only: one read-only pass over the seven stores; meta as an object by key, the others as arrays in key order. */
async function dumpDb(db) {
  const names = Object.keys(STORES);
  const raw = await db.tx(names, 'readonly', async (t) => {
    const out = {};
    const keys = await t.keys('meta');
    const values = await t.all('meta');
    out.meta = Object.fromEntries(keys.map((k, i) => [String(k), values[i]]));
    for (const name of names) if (name !== 'meta') out[name] = await t.all(name);
    return out;
  });
  return plain(raw);
}

/**
 * Starts the Station (S2 spec §3.17), in this order: started(), then Starting…, both before any network call; the
 * update rule; the primary-window lock; the database; the clock, transport and device; a wipe in progress before
 * anything else; api/session.php; then the device view or the lock screen (after the start-up heartbeat); the timers.
 * @param {{registration?: ServiceWorkerRegistration|null, controlledAtLoad?: boolean, started: () => boolean, repair: () => Promise<'offline'|'repaired'>,
 *   registerWorker?: (() => Promise<ServiceWorkerRegistration>)|null}} boot what boot.js passes (start(bootInfo));
 *   registerWorker registers sw.php as boot.js does (its policy and options), null without a worker API
 * @param {{env?: import('./env.js').Env, createSession?: Function|null}} [deps] env defaults to browserEnv();
 *   createSession is S3's seam
 * @returns {Promise<{state: () => string}>} resolves once the start is done (an ELSEWHERE window: once that screen
 *   shows); state() is the tablet state from then on
 */
export async function start(boot, deps = {}) {
  // ---- 1. started(), then Starting…: no network call happens before this ----
  const env = deps.env ?? browserEnv();
  let tabletState = 'STARTING';
  const handle = { state: () => tabletState };
  // false: the watchdog already showed the failure screen (this file arrived late). Stop without touching #app, so
  // its Try again and Repair the app stay (the shell itself shows Starting… until now).
  if (boot.started() === false) return handle;
  useDocument(env.document);
  const root = env.document.getElementById('app');
  let primary = false;
  /** onLost() ran: this window is no longer the tablet's primary window (every await of a start checks it). */
  const lost = () => !primary;
  let db = null;
  let clock = null;
  let api = null;
  let device = null;
  let deviceScreen = null;
  let session = LOCKED_SESSION;
  let currentView = null;
  let startingPhase = 'starting';
  let loginChecking = false;
  let elsewherePhase = 'elsewhere';
  let wipeModel = { phase: 'erasing' };
  let aboutModel = null;
  let aboutGen = 0;
  let lastOfflineCode = null;
  let routerStarted = false;
  let stealing = false;
  let sessionCall = null;          // api/session.php in flight (single flight)
  let workerCall = null;           // boot.registerWorker() in flight (single flight)
  let lastVisibleSessionAt = -Infinity; // env.mono() of the last 'visible' refresh while unregistered
  const intervals = new Set();
  const offs = [];
  let heartbeatTimer = null;

  // The chrome controller: the header model of §4.2, re-rendered in place.
  const chromeHost = el('div', { class: 'chrome' });
  const chromeModel = { orgName: null, site: null, label: null, build: env.shellBuild(), connectivity: 'unknown', banners: new Set(), minimal: false };
  const chrome = {
    banner(kind, on) {
      if (chromeModel.banners.has(kind) === Boolean(on)) return;
      if (on) chromeModel.banners.add(kind); else chromeModel.banners.delete(kind);
      chrome.render();
    },
    connectivity(state) {
      if (chromeModel.connectivity === state) return;
      chromeModel.connectivity = state;
      chrome.render();
    },
    setOrg(name) {
      const v = typeof name === 'string' && name !== '' ? name : null;
      if (chromeModel.orgName === v) return;
      chromeModel.orgName = v;
      chrome.render();
    },
    setTablet(info) {
      chromeModel.site = info?.site_name ?? null;
      chromeModel.label = info?.label ?? null;
      chrome.render();
    },
    render() { chromeHost.replaceChildren(...chromeView.render(chromeModel)); },
  };

  /** The chrome, one view's section and the footer, mounted into #app. */
  function drawScreen(view, section) {
    chromeModel.minimal = view === 'wipe';
    chrome.render();
    const foot = view === 'wipe' ? null : chromeView.footer({ build: env.shellBuild(), aboutLink: view !== 'about' && allowedIn(tabletState, 'about') });
    mount(root, chromeHost, section, foot);
  }

  const loginModel = () => ({ orgName: chromeModel.orgName, site: device?.info()?.site_name ?? null, label: device?.info()?.label ?? null,
    build: env.shellBuild(), checking: loginChecking });

  /** The router's render(name): draws that view now (the device view through the device screen). */
  function render(name) {
    const previous = currentView;
    if (previous === 'device' && name !== 'device') deviceScreen?.stop();
    currentView = name;
    switch (name) {
      case 'device':
        void deviceScreen?.show();
        break;
      case 'login':
        drawScreen('login', loginView.render(loginModel(), {}));
        break;
      case 'about':
        if (previous !== 'about' || aboutModel === null) aboutModel = freshAbout();
        void showAbout();
        break;
      case 'wipe':
        drawScreen('wipe', wipeView.render(wipeModel, { onRegisterAgain: () => env.reload() }));
        break;
      case 'elsewhere':
        drawScreen('elsewhere', elsewhereView.render({ phase: elsewherePhase }, { onUseHere: () => { void useHere(); } }));
        break;
      default:
        drawScreen('starting', startingView.render({ phase: startingPhase }, { onRetry: () => env.reload() }));
    }
    if (primary && name !== previous) void updater.check();
  }

  const router = createRouter({ env, render, allowed: (n) => allowedIn(tabletState, n), fallback: () => homeOf(tabletState) });

  /** Moves to a tablet state and shows a view (the state's home view by default). A replaced window stays replaced. */
  function enter(state, view = homeOf(state)) {
    if (tabletState === 'REPLACED' && state !== 'REPLACED') return; // X-2: nothing brings a replaced window back
    tabletState = state;
    // No chip where nothing will ever be asked of the server; back to Connecting… when this window starts after all.
    if (NO_CHIP_STATES.includes(state)) chromeModel.connectivity = 'none';
    else if (chromeModel.connectivity === 'none') chromeModel.connectivity = 'unknown';
    if (!routerStarted) {
      routerStarted = true;
      if (env.hash() !== '#/' + view) env.setHash('#/' + view);
      router.start();
    } else {
      router.go(view);
    }
  }
  const redraw = (view) => { if (currentView === view) render(view); };

  render('starting');

  // ---- 2. the update rule (db is still null here) ----
  const conditions = {
    tabletState: () => tabletState,
    sessionState: () => session.state(),
    draftOpen: async () => ((await db?.count('drafts').catch(() => 0)) ?? 0) > 0,
    syncInFlight: () => false,
    registrationPending: () => device?.registrationPending() ?? false,
  };
  const updater = createUpdater({
    env, getDb: () => db, registration: boot.registration ?? null, controlledAtLoad: boot.controlledAtLoad === true, conditions,
    onReload: () => { if (primary && !NO_RELOAD_STATES.includes(tabletState)) env.reload(); },
  });
  updater.start();

  const clearTimers = () => {
    for (const id of intervals) env.clearInterval(id);
    intervals.clear();
    heartbeatTimer = null;
    while (offs.length > 0) offs.pop()();
  };

  // ---- 12. this window lost the lock ----
  const hooks = {
    onKeysDropped: (reason) => session.lock('hard', reason),
    onRevokedGrants: (ids) => session.onRevokedGrants(ids),
    onOfflineDisabled: () => session.onOfflineDisabled(),
    onUpdateNeeded: () => { void updater.update('build'); },
    onConfig: (config) => {
      const shown = () => JSON.stringify([chromeModel.orgName, chromeModel.site, chromeModel.label]);
      const before = shown();
      if (typeof config?.organisation_name === 'string') chrome.setOrg(config.organisation_name);
      chrome.setTablet(device?.info() ?? null);
      if (shown() !== before) redraw('login');
    },
    onConnectivity: (state, code) => {
      if (lost() || NO_CHIP_STATES.includes(tabletState)) return; // a late answer in a window that shows no chip
      if (state === 'offline') lastOfflineCode = code ?? null; else lastOfflineCode = null;
      chrome.connectivity(state);
    },
    onHeartbeatOk: () => retryWorker(),
    // The storage closed or was cleared under a stuck Retire wipe (no 410): the wipe cannot go on, and a fresh start
    // resumes meta.wipe or, when the storage is gone, shows Register this tablet. A replaced window never gets here.
    onStorageLost: () => env.reload(),
    drainForWipe: null,
  };
  function onLost() {
    tabletState = 'REPLACED'; // first: the database's own close() below must not reload the window
    primary = false;
    clearTimers();
    deviceScreen?.stop();
    // Before the close: a running wipe, a wait or a late answer goes no further in this window (no deletion, no
    // confirmation, no screen change); the new primary window resumes the wipe from meta.wipe.
    device?.stop();
    try { db?.close(); } catch { /* already closed */ }
    hooks.onKeysDropped('replaced');
    elsewherePhase = 'replaced';
    enter('REPLACED', 'elsewhere');
  }

  // About's buttons (declared before the early returns below, which would leave a later const uninitialised)
  const aboutActions = {
    async onRepair() {
      if (aboutModel?.repairState === 'repairing') return;
      aboutModel.repairState = 'repairing';
      redrawAbout();
      let result = 'offline';
      try { result = await device.repair(); } catch (e) { env.log('repair failed: ' + (e?.name ?? e)); }
      // 'pending': a sent registration body is kept, and Repair's reload would lose it (X-3).
      if (result === 'offline' || result === 'pending') { aboutModel.repairState = result; redrawAbout(); }
    },
    async onUpdateNow() { await updater.updateNow(); },
    onBack() { router.go(homeOf(tabletState)); },
  };

  // ---- 3. the primary window ----
  const claimed = await claimPrimary(env.locks, { onLost });
  if (claimed === 'busy') {
    elsewherePhase = 'elsewhere';
    enter('ELSEWHERE', 'elsewhere'); // never opens the database, sends heartbeats or checks an update
    return handle;
  }
  if (claimed === 'denied') {
    // The browser refuses the Locks API here, and with it this origin's storage: the storage message, never a hang.
    env.log('the primary-window lock was refused');
    startingPhase = 'no_storage';
    enter('NO_STORAGE', 'starting');
    return handle;
  }
  await asPrimary();
  return handle;

  async function useHere() {
    if (stealing || tabletState !== 'ELSEWHERE') return;
    stealing = true;
    try {
      const got = await claimPrimary(env.locks, { steal: true, onLost });
      if (got !== 'held') { if (got === 'denied') env.log('the primary-window lock was refused'); return; }
      enter('STARTING', 'starting');
      await asPrimary();
    } catch (e) {
      env.log('the app stopped: ' + (e?.message ?? e));
    } finally {
      stealing = false;
    }
  }

  /**
   * Steps 4-9 for the window that holds the lock. Another window may steal it during any await below (onLost() then
   * ran): from that await on this window opens, starts and shows nothing more.
   */
  async function asPrimary() {
    primary = true;
    if (boot.registration?.waiting) updater.activateAtColdStart(); // before any other view; the reload follows

    // ---- 4. the database ----
    const onClosed = (why) => {
      if (why === 'close') return; // the page closed its own connection (onLost, a wipe's or eraseLocally()'s destroy)
      if (NO_RELOAD_STATES.includes(tabletState) || device?.wiping()) return; // the wipe handles it
      env.reload(); // storage was deleted or cleared under the page: a fresh start decides
    };
    let opened;
    try {
      opened = await openDb(env.indexedDB, { onBlocked: () => chrome.banner('close_other_window', true), onClosed });
    } catch (e) {
      if (lost()) return;
      env.log('the database did not open: ' + (e?.name ?? e));
      startingPhase = 'no_storage';
      enter('NO_STORAGE', 'starting');
      return;
    }
    if (lost()) { try { opened.close(); } catch { /* already closed */ } return; } // replaced while it opened
    db = opened;
    chrome.banner('close_other_window', false);

    // ---- 5. clock, transport, device, the device screen, the S3 seam ----
    clock = createClock({ env, db });
    try { await clock.load(); } catch (e) { if (!lost()) env.log('the clock was not read: ' + (e?.name ?? e)); }
    if (lost()) return;
    api = createApi({ env, clock, device: () => device?.credentials() ?? null });
    device = createDevice({
      env, db, api, clock,
      ui: {
        wipe(model) {
          if (lost()) return; // belt: a replaced window never shows a wipe (device.stop() already ended it)
          if (model?.phase === 'blocked') {
            const previous = wipeModel.phase === 'blocked' ? wipeModel.previous : wipeModel.phase;
            wipeModel = { ...wipeModel, phase: 'blocked', previous };
          } else {
            wipeModel = { ...model };
          }
          enter('WIPING', 'wipe');
        },
        erased(model) {
          if (lost()) return;
          wipeModel = { phase: 'erased', final: model?.final ?? 'unknown' };
          enter('ERASED', 'wipe');
        },
        banner: (kind, on) => chrome.banner(kind, on),
      },
      inflight: { begin: (kind) => updater.begin(kind), end: (kind) => updater.end(kind) },
      bootRepair: () => boot.repair(),
      hooks,
    });
    await device.load();
    if (lost()) { device.stop(); return; }
    chrome.setTablet(device.info());
    deviceScreen = createDeviceScreen({
      env, device, root,
      mountView: (model, actions) => { if (currentView === 'device') drawScreen('device', deviceView.render(model, actions)); },
      onContinue: () => afterRegistration(),
    });
    if (deps.createSession) session = deps.createSession({ env, db, api, clock, device, updater, chrome });

    // ---- 6. a wipe in progress owns the screen ----
    if (await device.resumeWipe()) {
      if (lost()) return;
      tabletState = 'WIPING';
      // A stuck Retire wipe asks the server when the tablet wakes or comes online (an Administrator's Erase now).
      listen('online', () => { void device.heartbeat({ reason: 'online' }); });
      listen('visible', () => { clock.tick(); void device.heartbeat({ reason: 'visible' }); });
      listen('hidden', () => { void clock.flush(); });
      return;
    }
    if (lost()) return;

    // ---- 7. api/session.php: the CSRF token, the organisation name, dev_relax ----
    await fetchSession();
    if (lost()) return;

    // ---- 8. not registered: the device view ----
    if (!device.registered()) {
      enter('UNREGISTERED', 'device');
      startTimers();
      return;
    }

    // ---- 9. registered: the lock screen, disabled until the start-up heartbeat answers or 5 s pass ----
    loginChecking = true;
    enter('REGISTERED', 'login');
    await device.heartbeat({ reason: 'startup', timeoutMs: STARTUP_HEARTBEAT_MS });
    if (lost() || device.wiping() || tabletState !== 'REGISTERED') return; // replaced, or a directive or a 410 took over
    loginChecking = false;
    redraw('login');
    if (!(await env.persisted())) void env.persist();
    if (lost()) return;
    startTimers();
  }

  /**
   * api/session.php (single flight): the CSRF token, the organisation name (merged into meta.config, shown in the
   * header) and dev_relax; any answer sets the chip.
   * @returns {Promise<object|null>} its body, or null
   */
  function fetchSession() {
    sessionCall ??= (async () => {
      let info = null;
      try {
        info = await api.get('api/session.php', { session: true, timeoutMs: 5000 });
        hooks.onConnectivity('online');
        retryWorker();
      } catch (e) {
        if (e instanceof ApiError) hooks.onConnectivity(e.offline ? 'offline' : 'online', e.code);
        else env.log('api/session.php failed: ' + (e?.message ?? e));
      }
      if (lost()) return null;
      let config = null;
      try { config = (await db.get('meta', 'config')) ?? null; } catch { config = null; }
      if (typeof info?.organisation_name === 'string') {
        config = { ...(config ?? {}), organisation_name: info.organisation_name };
        try { await db.put('meta', config, 'config'); } catch (e) { if (!lost()) env.log('the configuration was not saved: ' + (e?.name ?? e)); }
      }
      if (lost()) return null;
      chrome.setOrg(config?.organisation_name ?? null);
      if (info?.dev_relax === true) {
        // dev-only hooks (D-12): begin
        globalThis.__pfpms = { debug: {
          dump: () => dumpDb(db),
          state: () => tabletState,
          heartbeat: () => device.heartbeat({ reason: 'startup' }),
          skipDelay: () => { throw new Error('pfpms: skipDelay arrives in S4'); },
          record: () => { throw new Error('pfpms: record arrives in S5'); },
          draft: () => { throw new Error('pfpms: draft arrives in S4'); },
        } };
        // dev-only hooks (D-12): end
        chrome.banner('dev_relax', true);
      }
      return info;
    })().finally(() => { sessionCall = null; });
    return sessionCall;
  }

  /**
   * After a server answer (a heartbeat's 200, api/session.php, About's ping): when this page has no worker
   * registration (boot.js's register() was refused, e.g. by sw.php's 503 under maintenance on a first start), register
   * sw.php again, through boot.js's policy and options, and hand the result to the update rule. At most one call in
   * flight, so at most one per answer; never during a wipe (it removes this scope's workers) or in a replaced window.
   * The same holds when the registration arrives: by then the tablet may be wiping or erased (the erase may already
   * have removed the workers), so it is removed; or another window may hold the tablet, and it runs on that same
   * registration, so it is left alone. Neither is adopted.
   */
  function retryWorker() {
    if (typeof boot.registerWorker !== 'function' || updater.registration() !== null || workerCall !== null) return;
    if (lost() || device?.wiping()) return;
    workerCall = Promise.resolve()
      .then(() => boot.registerWorker())
      .then(async (registration) => {
        if (lost()) return;
        if (device?.wiping()) {
          try { await registration?.unregister(); } catch (e) { env.log('service worker not removed: ' + (e?.name ?? e)); }
          return;
        }
        updater.adopt(registration);
      }, (e) => { env.log('service worker registration failed: ' + (e?.message ?? e)); })
      .finally(() => { workerCall = null; });
  }

  /**
   * An unregistered tablet has no heartbeat: 'online', and 'visible' at most every TRIGGER_GAP_MS, ask
   * api/session.php again, so the chip (and the organisation name) never stays at what the start saw.
   */
  function refreshUnregistered(reason) {
    if (lost() || tabletState !== 'UNREGISTERED') return;
    if (reason === 'visible') {
      const now = env.mono();
      if (now - lastVisibleSessionAt < TRIGGER_GAP_MS) return;
      lastVisibleSessionAt = now;
    }
    void fetchSession();
  }

  // ---- 10. timers and listeners ----
  function every(ms, fn) { intervals.add(env.setInterval(fn, ms)); }
  function listen(event, fn) { offs.push(env.on(event, fn)); }
  function startHeartbeatTimer() {
    if (heartbeatTimer !== null || lost()) return;
    heartbeatTimer = env.setInterval(() => { void device.heartbeat({ reason: 'timer' }); }, HEARTBEAT_MS);
    intervals.add(heartbeatTimer);
  }
  function startTimers() {
    if (lost()) return;
    every(TICK_MS, () => {
      clock.tick();
      chrome.banner('update_ready', updater.waiting());
      if (!lost()) void updater.check();
    });
    if (device.registered()) startHeartbeatTimer();
    lastVisibleSessionAt = env.mono(); // the start just asked api/session.php
    listen('online', () => { if (device.registered()) void device.heartbeat({ reason: 'online' }); else refreshUnregistered('online'); });
    listen('offline', () => hooks.onConnectivity('offline', 'network'));
    listen('visible', () => {
      clock.tick();
      if (device.registered()) void device.heartbeat({ reason: 'visible' }); else refreshUnregistered('visible');
    });
    listen('hidden', () => {
      void clock.flush();
      if (currentView === 'device') deviceScreen?.pause(); else deviceScreen?.stop(); // the camera never runs in the background
    });
    listen('input', () => updater.inputSeen());
    chrome.banner('update_ready', updater.waiting());
    void updater.update('boot');
  }

  // ---- 11. after a registration (the device view's Continue) ----
  function afterRegistration() {
    if (lost() || tabletState !== 'UNREGISTERED' || !device.registered()) return;
    loginChecking = false;
    chrome.setTablet(device.info());
    enter('REGISTERED', 'login');
    startHeartbeatTimer();
  }

  // ---- About this tablet ----
  function freshAbout() {
    const info = device?.info() ?? null;
    return { label: info?.label ?? null, site: info?.site_name ?? null, build: env.shellBuild(), lastContact: null,
      keyReceived: device?.registered() ? null : 'unregistered', unsynced: 0, offsetMs: clock?.offsetMs() ?? null, storageKb: null,
      persisted: false, canCheck: false, updateWaiting: updater.waiting(), repairState: null, captivePortal: lastOfflineCode === 'not_json' };
  }
  function redrawAbout() { if (currentView === 'about') drawScreen('about', aboutView.render(aboutModel, aboutActions)); }
  /** Draws About with the facts the tablet holds, then asks the server whether it receives the tablet's key. */
  async function showAbout() {
    const gen = ++aboutGen;
    const m = aboutModel;
    const info = device?.info() ?? null;
    m.label = info?.label ?? null;
    m.site = info?.site_name ?? null;
    m.offsetMs = clock?.offsetMs() ?? null;
    m.updateWaiting = updater.waiting();
    m.captivePortal = lastOfflineCode === 'not_json';
    try {
      const last = db === null ? null : await db.get('meta', 'last_heartbeat_at');
      const ms = parseDb(last);
      m.lastContact = ms === null ? null : env.formatTime(ms);
      m.unsynced = db === null ? 0 : (await outboxStats(db)).pending;
    } catch (e) {
      env.log('about: ' + (e?.name ?? e));
    }
    m.storageKb = await env.estimateKb();
    m.persisted = await env.persisted();
    if (gen !== aboutGen) return;
    redrawAbout();
    if (!device?.registered()) { m.keyReceived = 'unregistered'; return; }
    try {
      const ping = await api.get('api/ping.php', { device: true, timeoutMs: 5000 });
      const received = ping?.authorization_received;
      m.keyReceived = typeof received === 'boolean' ? received : 'unknown'; // "No" only on a 200 that says false
      hooks.onConnectivity('online');
      retryWorker();
    } catch (e) {
      m.keyReceived = 'unknown'; // the check failed (offline, 5xx): "Not known", never "Checking…" for as long as About shows
      if (e instanceof ApiError && e.offline) hooks.onConnectivity('offline', e.code);
      m.captivePortal = lastOfflineCode === 'not_json';
    }
    if (gen !== aboutGen) return;
    redrawAbout();
  }
}
