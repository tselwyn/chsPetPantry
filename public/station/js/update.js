// The service-worker update rule (docs/design/50-design-station.md §7.7, BH-05, BH-06): when the page may post
// SKIP_WAITING to a waiting worker, when it reloads after the worker took over, and the hourly update throttle after
// a failed install. Imports nothing; the page's platform arrives through env (js/env.js).

/** No input for this long (ms) before an update is applied. */
export const QUIET_MS = 60000;

/** After a failed install, registration.update() runs at most this often (ms). */
export const FAILED_UPDATE_RETRY_MS = 3600000;

/**
 * @typedef {object} UpdateConditions
 * @property {() => string} tabletState app.js's tablet state ('UNREGISTERED', 'REGISTERED', 'WIPING', …)
 * @property {() => string} sessionState S2: always 'LOCKED' (S3: 'LOCKED', 'PICKER', …)
 * @property {() => Promise<boolean>} draftOpen whether the drafts store holds anything
 * @property {() => boolean} syncInFlight S2: always false (S5: a push is running)
 * @property {() => boolean} registrationPending device.registrationPending(): a sent registration body is kept in
 *   memory for a replay (X-3), so a reload now would lose its nonce and proof key
 */

/**
 * @typedef {object} Updater
 * @property {() => void} start listen for updatefound/statechange (the install-failure throttle) and controllerchange
 * @property {(reason: string) => Promise<void>} update registration.update(), skipped for an hour after a failed install
 * @property {() => boolean} waiting whether a worker waits
 * @property {() => void} inputSeen a person touched the tablet (resets the 60-s quiet time)
 * @property {(kind: string) => void} begin a request that must not be cut by a reload starts ('register', S3 'signin'/'pin', S5 'push')
 * @property {(kind: string) => void} end that request ended; a reload that waited for it runs now, unless a sent
 *   registration body is still kept (registrationPending())
 * @property {() => boolean} inFlight whether any such request runs
 * @property {() => Promise<boolean>} canActivate whether every condition of the quiet-time rule holds
 * @property {() => Promise<boolean>} check post SKIP_WAITING when canActivate(); true when posted. While a reload
 *   waits, it only runs that reload once nothing holds it (app.js's 15-s tick calls check())
 * @property {() => Promise<boolean>} updateNow About's Update now: ignores the quiet time, never drafts, flights or a wipe
 * @property {() => boolean} activateAtColdStart post SKIP_WAITING before any view when nothing is in flight
 * @property {() => void} onControllerChange reload only when the page had a controller at load and posted SKIP_WAITING
 * @property {() => boolean} posted whether this page posted SKIP_WAITING
 * @property {(registration: ServiceWorkerRegistration) => boolean} adopt a registration made after boot.js passed
 *   none (app.js registered sw.php again): from then on the rule runs as if boot.js had passed it; false (ignored)
 *   when the page already has one
 * @property {() => (ServiceWorkerRegistration|null)} registration the registration the rule runs on, or null
 */

/**
 * The update rule for one page. Nothing here reloads the page unless the page had a controller at load AND this page
 * itself posted SKIP_WAITING, and never while a request that must not be cut is in flight or a sent registration body
 * is kept for a replay (X-3).
 *
 * @param {object} options
 * @param {object} options.env the Env of js/env.js (now, mono, log, reload, serviceWorker)
 * @param {() => (object|null)} [options.getDb] the db.js adapter once app.js has opened it, else null (the cold-start
 *   activation runs before that)
 * @param {ServiceWorkerRegistration|null} options.registration boot.js's registration (null without a worker, or when
 *   boot.js's register() was refused; adopt() can hand one over later)
 * @param {boolean} options.controlledAtLoad whether the page had a controller when it loaded
 * @param {UpdateConditions} options.conditions
 * @param {() => void} [options.onReload] defaults to env.reload()
 * @returns {Updater}
 */
export function createUpdater({ env, getDb = () => null, registration: given, controlledAtLoad, conditions, onReload = () => env.reload() }) {
  const flights = new Set();
  let registration = given ?? null;
  let started = false;      // start() ran: the registration's listeners are attached once one exists
  let lastInput = env.mono();
  let postedSkip = false;
  let pendingReload = false;
  const waitingWorker = () => registration?.waiting ?? null;
  // getDb() is null until app.js has opened the database (the cold-start activation runs before that).
  const readFailedAt = async () => { try { return (await getDb()?.get('meta', 'sw'))?.update_failed_at ?? null; } catch { return null; } };
  const writeFailedAt = async (at) => { try { await getDb()?.put('meta', { update_failed_at: at }, 'sw'); } catch { /* erased */ } };
  const post = () => { const w = waitingWorker(); if (!w) return false; w.postMessage({ type: 'SKIP_WAITING' }); postedSkip = true; return true; };
  // A reload waits for every request that must not be cut AND while a sent registration body is kept for a replay
  // (X-3): its nonce and proof key exist only in memory. end() and every check() try the waiting reload again, so it
  // runs once the body is saved, dropped or out of its replay window.
  const mayReload = () => flights.size === 0 && conditions.registrationPending?.() !== true;
  const reloadOrWait = () => { if (!mayReload()) { pendingReload = true; return; } onReload(); };
  const retryReload = () => { if (pendingReload && mayReload()) { pendingReload = false; onReload(); } };
  /** The install-failure throttle and the controllerchange rule, once start() ran and a registration exists. */
  const wire = () => {
    if (!started || !registration) return;
    registration.addEventListener('updatefound', () => {
      const w = registration.installing;
      // A worker that reached 'installed' did not fail its install, even when it becomes redundant later (replaced
      // while waiting, or the old active worker after a newer one activated in a page that did not reload).
      let installed = false;
      w?.addEventListener('statechange', () => {
        if (w.state === 'installed') { installed = true; writeFailedAt(null); }
        if (w.state === 'redundant' && !installed && registration.waiting !== w && registration.active !== w) writeFailedAt(env.now());
      });
    });
    env.serviceWorker?.addEventListener('controllerchange', () => api.onControllerChange());
  };
  const api = {
    start() {
      started = true;
      wire();
    },
    adopt(next) {
      if (registration !== null || !next) return false;
      registration = next;
      wire();
      return true;
    },
    registration: () => registration,
    async update(reason) {
      if (!registration) return;
      const failedAt = await readFailedAt();
      // Within the hour after a failed install, skip. A failure "in the future" (the wall clock was set back since)
      // does not hold the check off: it runs at once.
      const elapsed = failedAt === null ? null : env.now() - failedAt;
      if (elapsed !== null && elapsed >= 0 && elapsed < FAILED_UPDATE_RETRY_MS) return;
      try { await registration.update(); } catch (e) { env.log(`update check (${reason}) failed: ${e?.name ?? e}`); }
    },
    waiting: () => waitingWorker() !== null,
    inputSeen() { lastInput = env.mono(); },
    begin(kind) { flights.add(kind); },
    end(kind) { flights.delete(kind); retryReload(); },
    inFlight: () => flights.size > 0,
    async canActivate() {
      return waitingWorker() !== null && ['UNREGISTERED', 'REGISTERED'].includes(conditions.tabletState())
        && ['LOCKED', 'PICKER'].includes(conditions.sessionState()) && !conditions.syncInFlight() && flights.size === 0
        && !conditions.registrationPending() && env.mono() - lastInput >= QUIET_MS && !(await conditions.draftOpen());
    },
    async check() {
      if (pendingReload) { retryReload(); return false; } // the new worker already took over: only the reload is left
      return (await api.canActivate()) ? post() : false;
    },
    async updateNow() {
      if (waitingWorker() === null || flights.size > 0 || conditions.syncInFlight() || conditions.registrationPending()
        || ['WIPING', 'ERASED'].includes(conditions.tabletState())) return false;
      return (await conditions.draftOpen()) ? false : post();
    },
    activateAtColdStart() { return flights.size === 0 ? post() : false; },
    /** Reload only when this page had a controller at load AND itself posted SKIP_WAITING; never mid-request, never
     *  while a registration body is kept (X-3). */
    onControllerChange() { if (controlledAtLoad && postedSkip) reloadOrWait(); },
    posted: () => postedSkip,
  };
  return api;
}
