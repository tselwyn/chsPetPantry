// The tablet itself (docs/design/50-design-station.md §2.3, §6.3, §6.4, §7.6, X-3, X-4): registration, the
// heartbeat body and its answer, directives, the wipe state machine, the local erase after a 410, and Repair.
//
// Two rules shape this file:
// - The registration body is frozen once it is first sent, and kept in memory for the next press within the server's
//   replay window (X-3): its nonce and its raw proof key exist nowhere else, so the page must not reload meanwhile
//   (update.js asks registrationPending()).
// - A wipe writes meta.wipe before it deletes anything, re-reads it before every stage, and keeps the credential and
//   the stage in memory, because the confirmation's Clear-Site-Data may erase the database under it.
// - stop() is final (the window lost the primary lock, X-2): from then on nothing here sends, writes, deletes or
//   changes the screen, and a closed database is never read as "Clear-Site-Data erased everything".
import { formatDb } from './canonical.js';
import { normalise } from './registration_code.js';
import { calibrate, keySelfTest, b64url } from './vault.js';
import { createProofKey, importProofKey } from './proof.js';
import { DbClosed } from './db.js';
import { ApiError } from './api.js';

/** Reuse the same body while the server's 15-minute replay window is surely open (ms). */
export const REPLAY_WINDOW_MS = 14 * 60 * 1000;
/** Automatic tries per press on a lost answer. */
export const REGISTER_TRIES = 3;
/** The waits (ms) before the second and the third try. */
export const REGISTER_RETRY_DELAYS_MS = [1000, 3000];
/** The start-up heartbeat's timeout (ms): the lock screen stays disabled until it answers or this passes. */
export const STARTUP_HEARTBEAT_MS = 5000;
/** 'online'/'visible' heartbeats at most this often (ms). */
export const TRIGGER_GAP_MS = 20000;
/** The wipe confirmation's waits (ms) after each failed try; the last one repeats. */
export const WIPE_BACKOFF_MS = [5000, 15000, 30000, 60000, 300000];
/** Records that block a Retire wipe are retried this often (ms). */
export const STUCK_RETRY_MS = 3600000;
/** The heartbeat's counts are capped here (DeviceHeartbeat::MAX_COUNT). */
export const MAX_COUNT = 10000000;
/** The heartbeat's other integers are capped here (DeviceHeartbeat::MAX_INT). */
export const MAX_INT = 2147483647;

const HEARTBEAT = 'api/device/heartbeat.php';
const REGISTER = 'api/device/register.php';
const QR_PREFIX_LOOSE = /^[ \t\r\n]*PFPMS-DEVICE:1:/i;
const ZERO_STATS = Object.freeze({ queued: 0, conflict: 0, invalid: 0, pending: 0, attention: 0, oldestPendingMs: null });
const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

/** device.stop() ended the work in progress (a wait, a stage, a late answer): never logged, never shown. */
class Stopped extends Error {
  constructor() { super('the device was stopped'); this.name = 'Stopped'; }
}

/** A registration press that failed: `key` is the copy key to show (params and the server's incident number with it). */
export class RegistrationError extends Error {
  /**
   * @param {string} key a copy.js key
   * @param {object} [params]
   * @param {string|null} [incident] the server's problem number (server_error)
   */
  constructor(key, params = {}, incident = null) { super(key); this.name = 'RegistrationError'; this.key = key; this.params = params; this.incident = incident; }
}

/**
 * The live check of a typed or scanned code: 'ok' when it normalises; otherwise the count of letters and digits
 * (after a leading, case-insensitive PFPMS-DEVICE:1:) says 'empty' (none), 'partial' (fewer than 17) or 'mistyped'.
 * @param {string} text
 * @returns {'empty'|'partial'|'ok'|'mistyped'}
 */
export function codeCheck(text) {
  if (normalise(text) !== null) return 'ok';
  const rest = (typeof text === 'string' ? text : '').replace(QR_PREFIX_LOOSE, '');
  const n = (rest.match(/[0-9A-Za-z]/g) ?? []).length;
  if (n === 0) return 'empty';
  return n < 17 ? 'partial' : 'mistyped';
}

/**
 * What a failed registration request means for the person, and whether the prepared body must be discarded (a new
 * nonce and proof key for the next press) or kept for a replay.
 * @param {unknown} e
 * @returns {{key: string, params: object, discard: boolean, incident: string|null}}
 */
export function registrationMessage(e) {
  const m = (key, discard = false, incident = null) => ({ key, params: {}, discard, incident });
  if (!(e instanceof ApiError)) return m('reg_failed');
  if (e.offline) return m(e.code === 'maintenance' ? 'reg_maintenance' : 'reg_no_answer');
  switch (e.code) {
    case 'code_mistyped': return m('reg_mistyped', true);
    case 'not_installed': return m('reg_not_installed', true);
    case 'code_invalid': return m('reg_invalid', true);
    case 'busy': return m('reg_busy');
    case 'rate_limited': return m('reg_rate_limited');
    case 'bad_request':
    case 'bad_json':
    case 'unsupported_media_type': return m('reg_bad_request', true);
    case 'server_error': {
      const incident = e.extra?.incident;
      return m('server_error', false, typeof incident === 'string' || typeof incident === 'number' ? String(incident) : null);
    }
    default: return m('reg_failed');
  }
}

/**
 * The wipe confirmation's wait after failed try number `attempt` (0-based).
 * @param {number} attempt
 * @returns {number} ms
 */
export function backoffMs(attempt) {
  return WIPE_BACKOFF_MS[Math.min(Math.max(0, Math.trunc(attempt) || 0), WIPE_BACKOFF_MS.length - 1)];
}

/**
 * The outbox counts the heartbeat reports, read through the `state` index in one read-only transaction.
 * oldestPendingMs is the smallest numeric created_at among those records (the tablet's raw ms; S4/S5 contract).
 * @param {import('./db.js').Db} db
 * @returns {Promise<{queued: number, conflict: number, invalid: number, pending: number, attention: number, oldestPendingMs: number|null}>}
 */
export async function outboxStats(db) {
  const [queued, conflict, invalid] = await db.tx(['outbox'], 'readonly', async (t) => [
    await t.byIndex('outbox', 'state', 'queued'),
    await t.byIndex('outbox', 'state', 'conflict'),
    await t.byIndex('outbox', 'state', 'invalid'),
  ]);
  let oldest = null;
  for (const r of [...queued, ...conflict, ...invalid]) {
    const at = r?.created_at;
    if (typeof at === 'number' && Number.isFinite(at) && (oldest === null || at < oldest)) oldest = at;
  }
  return {
    queued: queued.length, conflict: conflict.length, invalid: invalid.length,
    pending: queued.length + conflict.length + invalid.length, attention: conflict.length + invalid.length,
    oldestPendingMs: oldest === null ? null : Math.round(oldest),
  };
}

/**
 * The heartbeat body (50 §6.4: these keys, in this order; every value bounded). Pure.
 * @param {object} input
 * @param {import('./env.js').Env} input.env
 * @param {object} input.meta the meta values read for it (seq, unlock, auth_failures, failed_unlock_wipe_pending,
 *   clock_rollback_pending)
 * @param {{pending: number, attention: number, oldestPendingMs: number|null}} input.stats outboxStats()
 * @param {boolean} input.persisted env.persisted()
 * @param {number|null} input.estimateKb env.estimateKb()
 * @param {object} [input.extra] the wipe confirmation: {wiped: true, items_pushed: n}
 * @returns {object}
 */
export function heartbeatBody({ env, meta, stats, persisted, estimateKb, extra = {} }) {
  return {
    app_build: env.shellBuild(),
    client_now: formatDb(env.now()),                       // the tablet's raw clock
    storage_persisted: persisted === true,
    display_mode: env.displayMode(),
    pending_count: Math.min(stats.pending, MAX_COUNT),     // queued + conflict + invalid
    attention_count: Math.min(stats.attention, MAX_COUNT), // conflict + invalid
    max_seq: Math.min(Math.max(0, (meta.seq?.next ?? 1) - 1), MAX_INT),
    oldest_pending_at: stats.oldestPendingMs === null ? null : formatDb(stats.oldestPendingMs), // raw clock
    storage_estimate_kb: estimateKb === null ? null : Math.min(estimateKb, MAX_INT),
    locked_out_since: meta.unlock?.locked_out_since ?? null,                    // S4 writes it (raw clock text)
    failed_unlock_wipe: meta.failed_unlock_wipe_pending === true,               // S4
    clock_rollback: meta.clock_rollback_pending === true,                        // S2 (clock.load)
    auth_failures: Array.isArray(meta.auth_failures) ? meta.auth_failures.slice(0, 20) : [], // S4: [{user_id, factor, count, first_at, last_at}]
    wiped: false,
    items_pushed: null,
    ...extra,                                              // the confirmation: {wiped: true, items_pushed: n}
  };
}

/**
 * The failure groups left after a 200 that reported `sent`: a sent group still as it was sent is removed; one that
 * grew while the heartbeat was in flight keeps its unreported part (count reduced by the sent count, first_at the
 * sent last_at); groups that were not sent stay as they are.
 */
function unreportedFailures(current, sent) {
  const out = [];
  for (const g of current) {
    const s = sent.find((x) => x?.user_id === g?.user_id && x?.factor === g?.factor && x?.first_at === g?.first_at);
    if (!s) { out.push(g); continue; }
    if (g.count === s.count && g.last_at === s.last_at) continue;
    if (typeof g.count === 'number' && typeof s.count === 'number' && g.count > s.count) out.push({ ...g, count: g.count - s.count, first_at: s.last_at });
    else out.push(g);
  }
  return out;
}

const publicPart = (d) => ({ device_id: d.device_id, site_id: d.site_id, site_name: d.site_name, label: d.label, iterations: d.iterations, registered_at: d.registered_at });

/**
 * @typedef {object} DeviceHooks
 * @property {(reason: 'directive'|'erased'|'replaced') => void} [onKeysDropped] S3/S4: session.lock('hard', reason)
 * @property {(ids: Array<number|string>) => void} [onRevokedGrants] S3
 * @property {() => void} [onOfflineDisabled] S4
 * @property {(build: string) => void} [onUpdateNeeded] app.js: updater.update('build')
 * @property {(config: object) => void} [onConfig] app.js: re-render the chrome
 * @property {(state: 'online'|'offline', code?: string) => void} [onConnectivity] app.js: the chip (code: the
 *   offline ApiError's code, so About can tell a captive portal, not_json)
 * @property {() => void} [onHeartbeatOk] app.js: a heartbeat was answered 200 and its answer handled (the page
 *   registers its worker again when boot.js could not)
 * @property {() => void} [onStorageLost] app.js: env.reload(). The storage closed or was cleared under a wipe that
 *   only the server can end (a Retire wipe's pushing stage, no 410): nothing here can go on, so a fresh start decides
 *   (it resumes meta.wipe, or shows Register this tablet when the storage is gone). Only then: a run that fails on
 *   anything else is tried again here after a backoff
 * @property {null|(() => Promise<{queued: number, attention: number, pushed: number}>)} [drainForWipe] S5
 */
/**
 * @typedef {object} Device
 * @property {() => Promise<object|null>} load reads meta.device; returns its public part or null
 * @property {() => ({credential: string, proofKey: CryptoKey|null}|null)} credentials api.js's device getter (memory)
 * @property {() => object|null} info the public part {device_id, site_id, site_name, label, iterations, registered_at}
 * @property {() => boolean} registered
 * @property {() => Promise<{ok: boolean, reason?: string}>} prepare self-test, calibration, proof key and nonce (once)
 * @property {(typed: string) => Promise<{site: string, label: string, replayed: boolean, storagePersisted: boolean}>} register
 *   throws RegistrationError
 * @property {() => boolean} registrationPending a sent registration body is kept for the next press
 * @property {(options?: {reason?: string, timeoutMs?: number}) => Promise<object|null>} heartbeat
 * @property {(body: object) => Promise<void>} handle a heartbeat (or S3/S5) answer
 * @property {(e: unknown) => Promise<boolean>} handleError a 410, a directive, the header-missing and unknown-tablet banners
 * @property {(directive: {wipe: string}) => Promise<void>} wipe
 * @property {() => Promise<boolean>} resumeWipe
 * @property {() => boolean} wiping
 * @property {(final?: 'uploaded'|'not_uploaded'|'unknown') => Promise<void>} eraseLocally
 * @property {() => Promise<'offline'|'repaired'|'pending'>} repair 'pending': refused while a registration body is kept
 * @property {() => void} forget
 * @property {() => void} stop the window lost the primary lock (app.js onLost): forget(), end every wait, and from then
 *   on send, write, delete and show nothing (a wipe in progress goes no further here; the new primary window resumes it)
 */

/**
 * @param {object} deps
 * @param {import('./env.js').Env} deps.env
 * @param {import('./db.js').Db} deps.db
 * @param {import('./api.js').Api} deps.api
 * @param {import('./clock.js').Clock} deps.clock
 * @param {{wipe: (model: object) => void, erased: (model: object) => void, banner: (kind: string, on: boolean) => void}} deps.ui
 *   app.js renders views/wipe.js (ui.wipe sets the tablet state to WIPING, ui.erased to ERASED) and the chrome banners
 * @param {{begin: (kind: string) => void, end: (kind: string) => void}} deps.inflight update.js's begin/end
 * @param {() => Promise<'offline'|'repaired'>} deps.bootRepair boot's repair (start(bootInfo).repair)
 * @param {DeviceHooks} [deps.hooks]
 * @returns {Device}
 */
export function createDevice({ env, db, api, clock, ui, inflight, bootRepair, hooks: given = {} }) {
  const hooks = {
    onKeysDropped() {}, onRevokedGrants() {}, onOfflineDisabled() {}, onUpdateNeeded() {}, onConfig() {}, onConnectivity() {},
    onHeartbeatOk() {}, onStorageLost() {}, drainForWipe: null, ...given,
  };
  let creds = null;          // {credential, proofKey}: memory only, so it outlives Clear-Site-Data during a wipe
  let pub = null;            // the public part of meta.device
  let preparing = null;      // Promise of {ok, reason?, rounds, proof, nonce}
  let attempt = null;        // {code, firstSentMono, prep, body}: a registration body kept for a replay (X-3)
  let pressing = null;       // the register() running
  let beat = null;           // the heartbeat in flight
  let lastBeatAt = -Infinity;
  let runner = null;         // the wipe's run() while it runs
  let wipeMem = null;        // the last meta.wipe read or written
  let erasing = null;        // eraseLocally() while it runs
  let saved = null;          // {body, config}: the heartbeat answer saveAnswer() stored last, and its meta.config
  let stopped = false;       // stop() ran: this window is no longer the tablet's primary window (final)
  let rerun = false;         // meta.wipe changed under a running stage (an escalation, a 410): run() re-reads it now
  let wakeStuck = null;      // ends the stuck wait early: wakeStuck('nudge' | 'changed')
  const waits = new Set();   // the timers of delay()/wait() still pending (stop() ends them)

  /** A wait on env's timers: resolves 'timer', or early through end(reason); stop() rejects it with Stopped. */
  const wait = (ms) => {
    let entry = null;
    const promise = new Promise((resolve, reject) => {
      if (stopped) { reject(new Stopped()); return; }
      entry = {
        end(value) { if (!waits.delete(entry)) return; env.clearTimeout(entry.timer); resolve(value); },
        fail(e) { if (!waits.delete(entry)) return; env.clearTimeout(entry.timer); reject(e); },
      };
      waits.add(entry);
      entry.timer = env.setTimeout(() => entry.end('timer'), ms);
    });
    return { promise, end: (value) => entry?.end(value) };
  };
  const delay = (ms) => wait(ms).promise;
  /** Throws Stopped once stop() ran: called after every await that a replaced window must not act on. */
  const halt = () => { if (stopped) throw new Stopped(); };
  /** A heartbeat finished less than TRIGGER_GAP_MS ago. A wall clock set back since then never counts as recent, so
   *  an OS time sync cannot hold the 'online'/'visible' heartbeats (or a stuck wipe's question) off for that long. */
  const recentBeat = () => { const gap = env.now() - lastBeatAt; return gap >= 0 && gap < TRIGGER_GAP_MS; };
  const describe = (e) => e?.name ?? String(e);
  const isClosed = (e) => e instanceof DbClosed || e?.name === 'DbClosed' || e?.name === 'InvalidStateError';

  const dropPreparation = () => {
    const p = attempt?.prep;
    attempt = null;
    preparing = null;
    p?.proof?.raw?.fill(0);
  };

  /** The whole preparation (memoised until consumed by a success or discarded). Never rejects. */
  const prepared = () => {
    if (preparing === null) {
      preparing = (async () => {
        if (!(await keySelfTest(env.crypto, db))) return { ok: false, reason: 'selftest' };
        try {
          const rounds = await calibrate(env);
          const proof = await createProofKey(env.crypto);
          const nonce = b64url(env.random(16));
          return { ok: true, rounds, proof, nonce };
        } catch (e) {
          env.log(`registration preparation failed: ${describe(e)}`);
          return { ok: false, reason: 'crypto' };
        }
      })();
    }
    return preparing;
  };

  async function readMeta(keys) {
    return db.tx(['meta'], 'readonly', async (t) => {
      const out = {};
      for (const k of keys) out[k] = await t.get('meta', k);
      return out;
    });
  }

  // ---- registration (50 §6.3, X-3) --------------------------------------------------------------------------------

  /** The replay window is measured on env.mono(), so a wall-clock change (an OS time sync) cannot end it early. */
  const replayOpen = (a) => a.firstSentMono !== null && env.mono() - a.firstSentMono <= REPLAY_WINDOW_MS;

  async function press(typed) {
    const code = normalise(typed);
    if (code === null) throw new RegistrationError('reg_mistyped');
    if (attempt !== null && (attempt.code !== code || !replayOpen(attempt))) dropPreparation();
    if (attempt === null) {
      const p = await prepared();
      if (!p.ok) throw new RegistrationError('reg_selftest_failed');
      // Asked at the press, not when the view mounted: the tab may have become the installed app since.
      const storagePersisted = (await env.persisted()) || (await env.persist());
      const displayMode = env.displayMode();
      attempt = {
        code, firstSentMono: null, prep: p,
        body: Object.freeze({ code, registration_nonce: p.nonce, proof_key: b64url(p.proof.raw), display_mode: displayMode,
          storage_persisted: storagePersisted, app_build: env.shellBuild(), pbkdf2_iterations: p.rounds }),
      };
    }
    halt(); // replaced while it prepared: nothing is sent from this window
    const a = attempt;
    let result;
    // One flight from the first send until the answer is saved or the press fails: a reload that waits for it never
    // runs between a 200 and the write of meta.device (and update.js also waits while registrationPending(), X-3).
    inflight.begin('register');
    try {
      let body;
      for (let i = 0; ; i++) {
        a.firstSentMono ??= env.mono();
        try {
          body = await api.post(REGISTER, a.body, { session: true, timeoutMs: 15000 });
          halt();
          hooks.onConnectivity('online');
          break;
        } catch (e) {
          halt();
          if (e instanceof ApiError) {
            if (e.offline) hooks.onConnectivity('offline', e.code); else hooks.onConnectivity('online');
          }
          if (e instanceof ApiError && e.offline && e.code !== 'maintenance') {
            if (i + 1 < REGISTER_TRIES) { await delay(REGISTER_RETRY_DELAYS_MS[i]); continue; }
            throw new RegistrationError('reg_no_answer'); // the attempt is kept: the next press resends the same body
          }
          const m = registrationMessage(e);
          if (m.discard && attempt === a) dropPreparation();
          throw new RegistrationError(m.key, m.params, m.incident);
        }
      }
      let proofKey;
      let record;
      try {
        if (typeof body?.credential !== 'string' || !isObject(body.site)) throw new TypeError('the registration answer has no credential or site');
        proofKey = await importProofKey(env.crypto, a.prep.proof.raw); // non-extractable; before the transaction
        halt();
        record = { device_id: body.device_id, credential: body.credential, proof: proofKey, site_id: body.site.site_id, site_name: body.site.name,
          label: body.label, iterations: body.pbkdf2_iterations, registered_at: body.server_time };
        await db.tx(['meta'], 'readwrite', async (t) => {
          await t.put('meta', record, 'device');
          if ((await t.get('meta', 'seq')) === undefined) await t.put('meta', { next: 1 }, 'seq');
        });
      } catch (e) {
        halt();
        env.log(`registration not saved: ${describe(e)}`);
        throw new RegistrationError('reg_failed'); // the attempt is kept: the replay makes the next press safe
      }
      halt();
      result = { site: body.site.name, label: body.label, replayed: body.replayed === true, storagePersisted: a.body.storage_persisted };
      a.prep.proof.raw.fill(0);
      if (attempt === a) { attempt = null; preparing = null; }
      creds = { credential: record.credential, proofKey };
      pub = publicPart(record);
    } finally {
      inflight.end('register');
    }
    void device.heartbeat({ reason: 'registered' });
    return result;
  }

  // ---- heartbeat (50 §6.4) ----------------------------------------------------------------------------------------

  async function beatOnce(reason, timeoutMs) {
    let meta;
    let stats;
    try {
      meta = await readMeta(['seq', 'unlock', 'auth_failures', 'failed_unlock_wipe_pending', 'clock_rollback_pending']);
      stats = await outboxStats(db);
    } catch (e) {
      if (!stopped) env.log(`heartbeat (${reason}) skipped: ${describe(e)}`);
      return null;
    }
    const body = heartbeatBody({ env, meta, stats, persisted: await env.persisted(), estimateKb: await env.estimateKb() });
    if (stopped) return null;
    let res;
    try {
      res = await api.post(HEARTBEAT, body, { device: true, clearSite: device.wiping(), timeoutMs });
    } catch (e) {
      if (stopped) return null; // a late answer in a replaced window: no banner, no directive, no erase here
      if (!(e instanceof ApiError)) { env.log(`heartbeat (${reason}) failed: ${describe(e)}`); return null; }
      if (e.offline) hooks.onConnectivity('offline', e.code);
      else hooks.onConnectivity('online');
      const handled = await device.handleError(e);
      if (!handled && e.status === 429 && e.extra?.directive) await device.handle({ directive: e.extra.directive });
      return null;
    }
    if (stopped) return null;
    try {
      await saveAnswer(res, body);
    } catch (e) {
      if (stopped) return null;
      env.log(`heartbeat answer not saved: ${describe(e)}`);
    }
    ui.banner('header_missing', false);
    ui.banner('unknown_tablet', false);
    hooks.onConnectivity('online');
    await device.handle(res);
    if (!stopped) hooks.onHeartbeatOk(); // after handle(): a directive in this answer already shows in wiping()
    return res;
  }

  /** After a 200, in one readwrite transaction: the contact time, what was reported, the config, the label and site. */
  async function saveAnswer(res, sent) {
    if (!isObject(res)) return;
    const config = isObject(res.config) ? { ...res.config, offline_enabled: res.offline_enabled === true } : null;
    const site = isObject(res.site) ? res.site : null;
    let changed = null;
    await db.tx(['meta'], 'readwrite', async (t) => {
      await t.put('meta', formatDb(Math.round(clock.serverNow())), 'last_heartbeat_at');
      if (sent.clock_rollback === true) await t.put('meta', false, 'clock_rollback_pending');
      if (sent.failed_unlock_wipe === true) await t.put('meta', false, 'failed_unlock_wipe_pending');
      if (sent.auth_failures.length > 0) {
        const current = await t.get('meta', 'auth_failures');
        if (Array.isArray(current)) await t.put('meta', unreportedFailures(current, sent.auth_failures), 'auth_failures');
      }
      if (config !== null) {
        const old = await t.get('meta', 'config');
        // The organisation name comes from api/session.php; a heartbeat's config does not carry it.
        if (isObject(old) && old.organisation_name !== undefined && config.organisation_name === undefined) config.organisation_name = old.organisation_name;
        if (isObject(old) && old.offline_allowed === true && config.offline_enabled === true) config.offline_allowed = true; // S3: the sign-in's, until offline is disabled
        await t.put('meta', config, 'config');
      }
      const d = await t.get('meta', 'device');
      if (isObject(d)) {
        const next = { ...d };
        if (typeof res.label === 'string' && res.label !== d.label) next.label = res.label;
        if (site !== null && site.site_id !== undefined && site.site_id !== d.site_id) next.site_id = site.site_id;
        if (site !== null && typeof site.name === 'string' && site.name !== d.site_name) next.site_name = site.name;
        if (next.label !== d.label || next.site_id !== d.site_id || next.site_name !== d.site_name) {
          await t.put('meta', next, 'device');
          changed = next;
        }
      }
    });
    if (config !== null) saved = { body: res, config };
    if (changed !== null) pub = publicPart(changed);
  }

  // ---- wipes (X-4) ------------------------------------------------------------------------------------------------

  /** meta.wipe now; from memory when the storage is already gone. */
  async function readWipe() {
    try {
      const w = await db.get('meta', 'wipe');
      return isObject(w) ? w : null;
    } catch (e) {
      if (isClosed(e)) return wipeMem;
      throw e;
    }
  }

  /** Writes the next meta.wipe (or carries on in memory when the storage is gone). */
  async function putWipe(next) {
    try { await db.put('meta', next, 'wipe'); } catch (e) { if (!isClosed(e)) throw e; }
    wipeMem = next;
  }

  const finalOf = (mode) => (mode === 'Wipe Now' ? 'not_uploaded' : 'uploaded');

  async function stageStart(w) {
    halt();
    ui.wipe({ phase: w.mode === 'Wipe Now' ? 'erasing' : 'retiring', mode: w.mode });
    const now = w.mode === 'Wipe Now';
    const next = { ...w, stage: now ? 'confirming' : 'pushing' };
    try {
      await db.tx(['pack', 'keyring', 'vault_users', 'sessions', 'drafts', 'meta', ...(now ? ['outbox'] : [])], 'readwrite', async (t) => {
        for (const s of ['pack', 'keyring', 'vault_users', 'sessions', 'drafts']) await t.clear(s);
        if (now) await t.clear('outbox');
        await t.delete('meta', 'shift');
        await t.delete('meta', 'pending_shift_end');
        await t.put('meta', next, 'wipe');
      });
    } catch (e) {
      halt();
      if (!isClosed(e)) throw e;
    }
    halt();
    wipeMem = next;
  }

  async function stagePushing(w) {
    if (w.mode === 'Wipe Now') {
      const next = { ...w, stage: 'confirming' };
      try {
        await db.tx(['outbox', 'meta'], 'readwrite', async (t) => {
          await t.clear('outbox');
          await t.put('meta', next, 'wipe');
        });
      } catch (e) {
        halt();
        if (!isClosed(e)) throw e;
      }
      halt();
      wipeMem = next;
      return;
    }
    ui.wipe({ phase: 'retiring', mode: w.mode });
    let r;
    if (hooks.drainForWipe) {
      r = await hooks.drainForWipe();
    } else {
      // A closed database is never "nothing left to upload": that would confirm (and delete) with records still
      // stored. Only the server says the tablet is erased (a 410 writes the deleting stage); otherwise the run stops
      // and the next start decides.
      let s;
      try { s = await outboxStats(db); } catch (e) { halt(); throw e; }
      r = { queued: s.queued, attention: s.attention, pushed: 0 };
    }
    halt();
    if (rerun) return; // meta.wipe changed meanwhile (an escalation, a 410): run() re-reads it
    const left = (r?.queued ?? 0) + (r?.attention ?? 0);
    if (left > 0) {
      ui.wipe({ phase: 'stuck', n: left, mode: w.mode });
      // Only a heartbeat brings an Administrator's Erase now (or a 410). Ask at once unless one just ran (the
      // directive's own, or the last hourly retry), so a restart or a resumed wipe never waits an hour to ask.
      let why = 'now';
      if (creds === null || recentBeat()) {
        const stuckWait = wait(STUCK_RETRY_MS);
        wakeStuck = stuckWait.end; // an 'online'/'visible' nudge, or meta.wipe changing, ends the wait early
        try { why = await stuckWait.promise; } finally { wakeStuck = null; }
      }
      halt();
      if (why !== 'changed' && !rerun) await device.heartbeat({ reason: 'wipe' }); // an escalation arrives through handle()
      halt();
      return;                                      // run() re-reads meta.wipe and comes back here
    }
    await putWipe({ ...w, stage: 'confirming', items_pushed: (w.items_pushed ?? 0) + (r?.pushed ?? 0) });
    halt();
  }

  async function stageConfirming(w) {
    halt();
    ui.wipe({ phase: 'confirming', mode: w.mode });
    if (creds === null) { await putWipe({ ...w, stage: 'deleting' }); return; } // no credential stored: nothing to confirm with
    let meta = {};
    try { meta = await readMeta(['seq', 'unlock']); } catch (e) { halt(); if (!isClosed(e)) throw e; }
    try { await db.clear('outbox'); } catch (e) { halt(); if (!isClosed(e)) throw e; }
    for (let n = 0; ; n++) {
      const body = heartbeatBody({ env, meta, stats: ZERO_STATS, persisted: await env.persisted(), estimateKb: await env.estimateKb(),
        extra: { wiped: true, items_pushed: w.items_pushed ?? 0 } });
      halt();
      try {
        const res = await api.post(HEARTBEAT, body, { device: true, clearSite: true });
        halt();
        if (res?.status === 'wiped') break;
      } catch (e) {
        halt();
        if (e instanceof ApiError && e.status === 410) break;
        if (!(e instanceof ApiError)) env.log(`wipe confirmation failed: ${describe(e)}`);
      }
      await delay(backoffMs(n)); // offline, 429, 5xx, a second device_proof_stale, or a 200 that ignored the claim
    }
    // The answer's Clear-Site-Data may already have erased the storage (DbClosed): carry on from memory.
    await putWipe({ ...w, stage: 'deleting' });
    halt();
  }

  /** Close and delete the database, the pfpms- caches and this scope's workers; then the erased screen. */
  async function deleteEverything(final) {
    halt();
    try {
      await db.destroy({ onBlocked: () => { if (!stopped) ui.wipe({ phase: 'blocked' }); }, retryMs: 2000, timers: env });
    } catch (e) {
      env.log(`database not deleted: ${describe(e)}`);
    }
    halt();
    try {
      const store = env.caches;
      for (const name of (await store?.keys()) ?? []) if (name.startsWith('pfpms-')) await store.delete(name);
    } catch (e) {
      env.log(`the cache storage was not cleared: ${describe(e)}`);
    }
    halt();
    try {
      const scope = env.scope();
      for (const r of (await env.serviceWorker?.getRegistrations?.()) ?? []) if (String(r.scope).startsWith(scope)) await r.unregister();
    } catch (e) {
      env.log(`service worker not removed: ${describe(e)}`);
    }
    halt();
    device.forget();
    ui.erased({ final });
  }

  async function run() {
    for (;;) {
      halt();
      rerun = false;
      const w = await readWipe();
      halt();
      if (w === null) return;
      wipeMem = w;
      switch (w.stage) {
        case 'pushing': await stagePushing(w); break;
        case 'confirming': await stageConfirming(w); break;
        case 'deleting': await deleteEverything(w.final ?? finalOf(w.mode)); return; // final: a 410 while pushing
        default: await stageStart(w); // 'start'
      }
    }
  }

  /**
   * run() until it ends. A run that fails on anything but a closed storage (a QuotaExceededError, Chromium's
   * UnknownError, a bug) is tried again after backoffMs(failures in a row), through wait() so that stop() ends it:
   * never stopped for good (wiping() stays true, so nothing else would ever send), never a tight loop, never a reload
   * (the next start would meet the same failure and reload again). The count starts again with every runner.
   */
  async function runUntilDone() {
    for (let failures = 0; ; failures++) {
      try {
        await run();
        return;
      } catch (e) {
        if (e instanceof Stopped || stopped) return; // replaced: the new primary window resumes the wipe
        env.log(`wipe stopped: ${describe(e)}`);
        // The storage closed or was cleared under a wipe that only the server can end (no 410 came): nothing is left
        // here to send the next question, so the screen would say "uploading" for ever. A fresh start decides.
        if (isClosed(e)) { hooks.onStorageLost(); return; }
      }
      try { await delay(Math.min(backoffMs(failures), STUCK_RETRY_MS)); } catch { return; } // stop(): Stopped
    }
  }

  /** Starts the wipe's runner unless one runs (single flight); never awaited by a caller that run() may be waiting on. */
  function ensureRun() {
    if (stopped) return Promise.resolve();
    if (runner === null) {
      // Only this runner clears the slot it took, so a runner that ends late can never clear a newer one's.
      const mine = runUntilDone().finally(() => { if (runner === mine) runner = null; });
      runner = mine;
    }
    return runner;
  }

  /** meta.wipe changed under the running stage: the stuck wait ends and run() re-reads it. */
  function wipeChanged() {
    rerun = true;
    wakeStuck?.('changed');
  }

  // ---- the Device -------------------------------------------------------------------------------------------------

  const device = {
    async load() {
      const d = await db.get('meta', 'device');
      if (stopped) return null; // replaced while it read: the credentials stay forgotten
      if (!isObject(d) || typeof d.credential !== 'string') { creds = null; pub = null; return null; }
      creds = { credential: d.credential, proofKey: d.proof ?? null };
      pub = publicPart(d);
      return { ...pub };
    },
    credentials: () => creds,
    info: () => (pub === null ? null : { ...pub }),
    registered: () => creds !== null,

    async prepare() {
      const p = await prepared();
      return p.ok ? { ok: true } : { ok: false, reason: p.reason };
    },

    register(typed) {
      if (stopped) return Promise.reject(new RegistrationError('reg_failed'));
      if (pressing === null) pressing = press(typed).finally(() => { pressing = null; });
      return pressing;
    },

    registrationPending: () => attempt !== null && replayOpen(attempt),

    heartbeat({ reason = 'timer', timeoutMs = 15000 } = {}) {
      if (stopped || creds === null) return Promise.resolve(null);
      if (device.wiping() && reason !== 'wipe') {
        // Only the wipe sends while it runs. While records keep a Retire wipe waiting, the tablet waking or coming
        // online makes it ask the server at once (at most every TRIGGER_GAP_MS): Erase now arrives only that way.
        if ((reason === 'online' || reason === 'visible') && wakeStuck !== null && !recentBeat()) wakeStuck('nudge');
        return Promise.resolve(null);
      }
      if (beat !== null) return beat;
      if ((reason === 'online' || reason === 'visible') && recentBeat()) return Promise.resolve(null);
      beat = beatOnce(reason, timeoutMs).finally(() => { beat = null; lastBeatAt = env.now(); });
      return beat;
    },

    async handle(body) {
      if (stopped || !isObject(body)) return;
      if (body.directive) { await device.wipe(body.directive); return; }
      if (Array.isArray(body.revoked_grants) && body.revoked_grants.length > 0) hooks.onRevokedGrants(body.revoked_grants);
      if (body.offline_enabled === false) hooks.onOfflineDisabled();
      if (typeof body.build === 'string' && body.build !== env.shellBuild()) hooks.onUpdateNeeded(body.build);
      if (isObject(body.config)) hooks.onConfig(saved?.body === body ? saved.config : { ...body.config, offline_enabled: body.offline_enabled === true });
    },

    async handleError(e) {
      if (stopped || !(e instanceof ApiError)) return false;
      if (e.status === 410) {
        await device.eraseLocally('unknown'); // a running wipe goes to deleting (its confirming loop ends there itself)
        return true;
      }
      if (isObject(e.extra?.directive)) { await device.wipe(e.extra.directive); return true; }
      if (e.code === 'device_credential_missing') { ui.banner('header_missing', true); return false; }
      // The server has no row for this credential (a restored backup, a reset). Never erased automatically: a
      // misconfigured server must not wipe tablets that hold unsent records. The banner stays until a 200.
      if (e.code === 'device_unknown') { ui.banner('unknown_tablet', true); return false; }
      return false;
    },

    async wipe(directive) {
      if (stopped) return;
      const mode = directive?.wipe === 'Wipe Now' ? 'Wipe Now' : 'Push Then Wipe';
      if (erasing !== null) return; // the server already holds the tablet as erased: the local erase is running
      const current = (await readWipe()) ?? wipeMem;
      if (stopped) return;
      if (current !== null) {
        // Only an escalation, and only before the outbox is dealt with (an Administrator's Erase now).
        if (current.mode === 'Push Then Wipe' && mode === 'Wipe Now' && (current.stage === 'start' || current.stage === 'pushing')) {
          await putWipe({ ...current, mode: 'Wipe Now' });
          wipeChanged(); // a stuck wait ends now: the outbox is cleared and the confirmation sent without the hour
        }
        ensureRun();
        return;
      }
      // Before any deletion.
      await putWipe({ mode, stage: 'start', started_at: formatDb(Math.round(clock.serverNow())), items_pushed: 0 });
      if (stopped) return;
      hooks.onKeysDropped('directive');
      ensureRun();
    },

    async resumeWipe() {
      if (stopped) return false;
      const w = await readWipe();
      if (stopped || w === null) return false;
      wipeMem = w;
      ensureRun();
      return true;
    },

    wiping: () => runner !== null || wipeMem !== null || erasing !== null,

    async eraseLocally(final = 'unknown') {
      if (stopped) return;
      if (runner !== null) {
        // A wipe runs. While it confirms, its own loop ends in deleting. Before that (a 410 to the stuck retry: the
        // server already holds the tablet as erased) it would never confirm: it goes to deleting now, by itself.
        const w = wipeMem;
        if (w !== null && w.stage !== 'confirming' && w.stage !== 'deleting') {
          await putWipe({ ...w, stage: 'deleting', final });
          wipeChanged();
        }
        return;
      }
      if (erasing !== null) return erasing;
      hooks.onKeysDropped('erased');
      ui.wipe({ phase: 'erasing' });
      erasing = deleteEverything(final).catch((e) => { if (!(e instanceof Stopped)) throw e; });
      return erasing;
    },

    async repair() {
      // Repair reloads the page: never while a sent registration body is kept (its nonce and proof key exist only
      // in memory, X-3; the next press would burn the sheet).
      if (device.registrationPending()) return 'pending';
      try {
        await api.get('api/ping.php', { timeoutMs: 5000 });
      } catch (e) {
        if (!(e instanceof ApiError) || e.offline) return 'offline'; // nothing removed
      }
      if (stopped) return 'offline'; // replaced meanwhile: this window removes nothing and never reloads
      if (device.registrationPending()) return 'pending';
      return bootRepair();
    },

    forget() {
      creds = null;
      pub = null;
      dropPreparation();
    },

    stop() {
      stopped = true;
      for (const w of [...waits]) w.fail(new Stopped());
      wakeStuck = null;
      device.forget();
    },
  };
  return device;
}
