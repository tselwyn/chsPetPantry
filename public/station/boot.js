// PFPMS Station boot (docs/design/50-design-station.md §7.7, BH-06, BH-07): the start watchdog, the pfpms-sw Trusted
// Types policy, the service worker registration and the first-start wait, the failure screen and Repair.
// Self-contained on purpose: it must still work when every other file of the Station is broken, so it loads nothing
// statically, builds its failure screen with createElement and textContent only, and loads the app (js/app.js) only
// after the first-start wait. It starts by itself only in a browser page that has #app.

/** The watchdog (ms): app.js must call __pfpmsStarted() within this time (counted again from the end of a first-start
 *  wait), or the failure screen shows. */
export const WATCHDOG_MS = 15000;
/** The first-start wait (ms) for the new worker to take control before the app loads from the network. */
export const WORKER_WAIT_MS = 8000;
/** api/ping.php must answer within this time (ms). */
export const PING_TIMEOUT_MS = 5000;
/** The sessionStorage key of the start state ({attempts, reloaded}). */
export const STORAGE_KEY = 'pfpms-boot';
/** Identical to the same keys of js/copy.js (source_rules.test.js compares them): boot cannot import copy.js. */
export const BOOT_COPY = Object.freeze({
  boot_failed: 'The Station could not start. Your records on this tablet are not affected.',
  try_again: 'Try again',
  repair_app: 'Repair the app',
  repairing: 'Repairing the app…',
  repair_offline: 'Repair needs a connection. Your records are safe; try again when the tablet is online.',
});

/**
 * Before the app is loaded: 'import' (go on), 'reload' (once, after a hard reload bypassed the worker) or 'wait'
 * (first start: wait for the verified worker).
 * @param {{hasWorkerApi: boolean, controlled: boolean, activeRegistration: boolean, reloadedOnce: boolean}} facts
 * @returns {'import'|'reload'|'wait'}
 */
export function bootPlan({ hasWorkerApi, controlled, activeRegistration, reloadedOnce }) {
  if (!hasWorkerApi || controlled) return 'import';
  if (activeRegistration) return reloadedOnce ? 'import' : 'reload'; // a hard reload bypassed the worker: reload once, never loop
  return 'wait';                                                        // first start: wait for the verified worker
}

/**
 * The failure screen's button order: Repair first only after 3 failed starts AND while the server answers.
 * @param {{attempts: number, pingOk: boolean}} facts
 * @returns {Array<'repair'|'retry'>}
 */
export function failureOrder({ attempts, pingOk }) {
  return attempts >= 3 && pingOk ? ['repair', 'retry'] : ['retry', 'repair'];
}

/**
 * 'reload' when the new worker took control (controllerchange after its clients.claim()); 'import' when registration
 * rejected (the desktop browser pane; logged), the kill worker posted SW_KILLED, or waitMs passed (offline, slow precache).
 * @param {object} options
 * @param {ServiceWorkerContainer} options.container
 * @param {() => Promise<unknown>} options.register registers sw.php
 * @param {{setTimeout: Function, clearTimeout: Function}} options.timers
 * @param {number} [options.waitMs]
 * @param {(line: string) => void} [options.log]
 * @returns {Promise<'reload'|'import'>}
 */
export function waitForWorker({ container, register, timers, waitMs = WORKER_WAIT_MS, log = () => {} }) {
  return new Promise((resolve) => {
    let settled = false;
    let timer = null;
    const onChange = () => finish('reload');
    const onMessage = (e) => { if (e?.data?.type === 'SW_KILLED') finish('import'); };
    function finish(how) {
      if (settled) return;
      settled = true;
      timers.clearTimeout(timer);
      container.removeEventListener('controllerchange', onChange);
      container.removeEventListener('message', onMessage);
      resolve(how);
    }
    container.addEventListener('controllerchange', onChange);
    container.addEventListener('message', onMessage);
    timer = timers.setTimeout(() => finish('import'), waitMs);
    Promise.resolve().then(register).catch((e) => { log('service worker registration failed: ' + (e?.message ?? e)); finish('import'); });
  });
}

/**
 * GET ../api/ping.php within timeoutMs: true only for a 200 JSON {ok: true}. Never throws.
 * @param {{fetch: Function, timers: {setTimeout: Function, clearTimeout: Function}, timeoutMs?: number}} options
 * @returns {Promise<boolean>}
 */
export async function ping({ fetch, timers, timeoutMs = PING_TIMEOUT_MS }) {
  const ctrl = new AbortController();
  const timer = timers.setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const res = await fetch('../api/ping.php', { headers: { 'X-PFPMS-Client': 'station', Accept: 'application/json' }, credentials: 'omit',
      cache: 'no-store', redirect: 'error', mode: 'same-origin', signal: ctrl.signal });
    if (!res.ok || !(res.headers.get('content-type') ?? '').startsWith('application/json')) return false;
    return (await res.json())?.ok === true;
  } catch { return false; } finally { timers.clearTimeout(timer); }
}

/**
 * Repair (D-08): only while ping answers. Unregisters this scope's workers and deletes the pfpms-shell-* caches, then
 * reloads (the first-start rule installs and verifies the worker again). Never touches the tablet's stored records.
 * @param {object} options
 * @param {Function} options.fetch
 * @param {{setTimeout: Function, clearTimeout: Function}} options.timers
 * @param {ServiceWorkerContainer|null} options.container
 * @param {CacheStorage|null} options.caches
 * @param {string} options.scope this page's scope URL (http://…/station/)
 * @param {() => void} options.reload
 * @returns {Promise<'offline'|'repaired'>}
 */
export async function repair({ fetch, timers, container, caches, scope, reload }) {
  if (!(await ping({ fetch, timers }))) return 'offline';
  for (const r of (await container?.getRegistrations?.()) ?? []) if (r.scope.startsWith(scope)) await r.unregister();
  for (const name of (await caches?.keys?.()) ?? []) if (name.startsWith('pfpms-shell-')) await caches.delete(name);
  reload();
  return 'repaired';
}

/**
 * The whole start, with the platform injected (b: see browserBoot()).
 * @param {object} b {global, storage, timers, trustedTypes, serviceWorker, caches, fetch, reload, scope, document, importApp, log}
 * @returns {Promise<void>}
 */
export async function boot(b) {
  const state = readState(b.storage);
  state.attempts += 1;
  writeState(b.storage, state);
  let failed = false;
  let watchdog = null;
  const arm = () => { watchdog = b.timers.setTimeout(() => { failed = true; showFailure(b, state); }, WATCHDOG_MS); };
  arm();
  // app.js calls this first, before it mounts "Starting…" or makes any network call; false means the watchdog fired.
  b.global.__pfpmsStarted = () => {
    if (failed) return false;
    b.timers.clearTimeout(watchdog);
    writeState(b.storage, { attempts: 0, reloaded: false });
    return true;
  };
  let policy = null;
  try {
    policy = b.trustedTypes?.createPolicy('pfpms-sw', { createScriptURL: (u) => { if (u === 'sw.php') return u; throw new TypeError('pfpms-sw refuses ' + u); } }) ?? null;
  } catch { policy = null; }
  const container = b.serviceWorker;
  const scriptUrl = policy ? policy.createScriptURL('sw.php') : 'sw.php';
  let registration = null;
  // The one way sw.php is registered (the policy can be created only once). app.js gets it too: when this start had no
  // registration (a refused first start, e.g. sw.php's 503 under maintenance), it registers again after a server answer.
  const registerWorker = container ? () => container.register(scriptUrl, { scope: './', updateViaCache: 'none' }) : null;
  const register = async () => { registration = await registerWorker(); };
  const controlledAtLoad = Boolean(container?.controller);
  const existing = container && !controlledAtLoad ? await container.getRegistration().catch(() => null) : null;
  const plan = bootPlan({ hasWorkerApi: Boolean(container), controlled: controlledAtLoad, activeRegistration: Boolean(existing?.active), reloadedOnce: state.reloaded });
  // A planned reload is not a failed start: it does not count as an attempt, and the watchdog cannot fire during it.
  const plannedReload = () => { b.timers.clearTimeout(watchdog); writeState(b.storage, { attempts: state.attempts - 1, reloaded: true }); b.reload(); };
  if (plan === 'reload') { plannedReload(); return; }
  if (plan === 'wait') {
    // The first-start wait has its own bound (WORKER_WAIT_MS). The watchdog stops for it and starts again in full when
    // it ends, so on a slow first start the app's own load gets the whole WATCHDOG_MS, not what the wait left of it.
    if (!failed) b.timers.clearTimeout(watchdog);
    if ((await waitForWorker({ container, register, timers: b.timers, log: b.log })) === 'reload') { plannedReload(); return; }
    if (!failed) arm();
  } else if (container) {
    await register().catch((e) => b.log('service worker registration failed: ' + (e?.message ?? e)));
  }
  let app;
  try { app = await b.importApp(); } catch (e) { b.log('the app did not load: ' + (e?.message ?? e)); showFailure(b, state); return; }
  try {
    await app.start({ registration, controlledAtLoad, started: () => b.global.__pfpmsStarted(),
      repair: () => repair({ fetch: b.fetch, timers: b.timers, container, caches: b.caches, scope: b.scope, reload: b.reload }), registerWorker });
  } catch (e) { b.log('the app stopped: ' + (e?.message ?? e)); showFailure(b, state); }
}

/** {attempts, reloaded} of this tab's earlier starts; {attempts: 0, reloaded: false} when absent or unreadable. */
function readState(storage) {
  try {
    const s = JSON.parse(storage?.getItem(STORAGE_KEY) ?? 'null');
    if (s !== null && typeof s === 'object') {
      return { attempts: Number.isInteger(s.attempts) && s.attempts > 0 ? s.attempts : 0, reloaded: s.reloaded === true };
    }
  } catch { /* unreadable or refused: start counting again */ }
  return { attempts: 0, reloaded: false };
}

function writeState(storage, s) {
  try { storage?.setItem(STORAGE_KEY, JSON.stringify({ attempts: s.attempts, reloaded: s.reloaded })); } catch { /* storage refused */ }
}

/** One element, with its attributes and its text set through textContent (never parsed). */
function make(doc, tag, attrs = {}, text = null) {
  const node = doc.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
  if (text !== null) node.textContent = text;
  return node;
}

/**
 * Replaces #app's children with the failure screen: Try again first, then the order of failureOrder() once ping has
 * answered. Try again reloads; Repair the app shows its progress and, offline, says so and removes nothing.
 */
function showFailure(b, state) {
  const doc = b.document;
  const root = doc?.getElementById('app');
  if (!root) return;
  const labels = { retry: BOOT_COPY.try_again, repair: BOOT_COPY.repair_app };
  const first = make(doc, 'button', { class: 'button button-primary' });
  const second = make(doc, 'button', { class: 'button' });
  const status = make(doc, 'p', { class: 'status', 'aria-live': 'polite' });
  const actions = make(doc, 'div', { class: 'actions' });
  actions.append(first, second);
  const section = make(doc, 'section', { class: 'boot-failed', role: 'alert' });
  section.append(make(doc, 'h1', {}, BOOT_COPY.boot_failed), actions, status);
  let order = ['retry', 'repair'];
  const draw = (o) => { order = o; first.textContent = labels[o[0]]; second.textContent = labels[o[1]]; };
  let repairing = false;
  const run = {
    retry: () => b.reload(),
    repair: async () => {
      if (repairing) return;
      repairing = true;
      status.textContent = BOOT_COPY.repairing;
      const result = await repair({ fetch: b.fetch, timers: b.timers, container: b.serviceWorker, caches: b.caches, scope: b.scope, reload: b.reload });
      if (result === 'offline') status.textContent = BOOT_COPY.repair_offline;
      repairing = false;
    },
  };
  first.addEventListener('click', () => run[order[0]]());
  second.addEventListener('click', () => run[order[1]]());
  draw(order);
  root.replaceChildren(section);
  ping({ fetch: b.fetch, timers: b.timers }).then((pingOk) => draw(failureOrder({ attempts: state.attempts, pingOk })));
}

function browserBoot(win) {
  let storage = null;
  try { storage = win.sessionStorage; } catch { storage = null; }
  return { global: win, storage, timers: { setTimeout: win.setTimeout.bind(win), clearTimeout: win.clearTimeout.bind(win) },
    trustedTypes: win.trustedTypes ?? null, serviceWorker: win.navigator.serviceWorker ?? null, caches: win.caches ?? null,
    fetch: win.fetch.bind(win), reload: () => win.location.reload(), scope: new URL('./', win.location.href).href,
    document: win.document, importApp: () => import('./js/app.js'), log: (m) => win.console.info('[pfpms] ' + m) };
}
if (typeof window !== 'undefined' && window.document?.getElementById('app')) boot(browserBoot(window));
