// Who is at the tablet, online part (docs/design/50-design-station.md §2.3, §7.5, D-05, D-16, D-21, D-22, D-24, D-42,
// D-54; S3 spec §3.1, §3.2): the password sign-in and its two gates (the agreement, the forced password change), the
// PIN switch and the PIN set, Switch user, End shift (and its replay), the idle, absolute and grant timers, the server
// keep-alive, and revoked grants. The offline paths are S4's (deps.offline).
//
// Rules that shape this file:
// - Closure-based: createSession() keeps its state in local variables and returns a plain object of closures (app.js
//   spreads it over its stand-in, so nothing here may use `this`).
// - Hooks and fire-and-forget methods never reject (device.js calls the hooks without await or catch).
// - Every crypto call and fetch runs before or after a db.tx(), never inside one (db.js's auto-commit trap).
// - The password is never kept; at a gate only the non-extractable KEK, its salt and the typed identifier are, for at
//   most GATE_KEK_MS on both clocks. The raw DVK is zeroed by openVault() (or here, when the write stops early).
// - Nothing here holds copy: results carry copy keys (MESSAGE_KEYS); views/screens.js shows them. Log lines never
//   name a person.
// - One call at a time (the flight; busy()). A hard lock ends it at once: its late answer changes nothing (no write, no
//   offline path), and the next person can sign in.
// - A waiting update reload never cuts what a hard lock starts: End shift (its record and its logout) and every sign-out
//   take their own update.js hold (holdReload) before the lock ends the flight's.
// - Entries are deleted by grant, never by person (D-21): only a keyring row or vault user that still holds the grant.
import { ApiError } from './api.js';
import { formatDb, parseDb } from './canonical.js';
import * as vaultModule from './vault.js';

export const SIGNIN_TIMEOUT_MS = 6000;     // D-42: then the offline path (S4) is offered
export const PIN_TIMEOUT_MS = 3000;        // D-42
export const CALL_TIMEOUT_MS = 15000;      // policy, password, pin_set
export const LOGOUT_TIMEOUT_MS = 5000;     // logout, End shift, the touch
export const GATE_KEK_MS = 600000;         // the KEK is kept at most 10 minutes at a gate (50 §2.3 step 5)
export const TOUCH_EVERY_MS = 300000;      // D-05: the keep-alive at most every 5 minutes
export const DEFAULT_ROUNDS = 600000;      // offline_pbkdf2_iterations' default, when meta.device has none
export const STATES = Object.freeze(['LOCKED', 'GATE', 'ACTIVE', 'PICKER', 'IDLE']);

/** Every copy key a result, a field error or a notice of this file can carry (copy_keys.test.js checks COPY has them). */
export const MESSAGE_KEYS = Object.freeze([
  'signin_missing', 'login_failed', 'account_locked', 'account_unusable', 'no_station_access_site', 'no_station_access_role',
  'rate_limited_wait', 'signin_no_answer', 'signin_clock', 'device_proof_invalid', 'device_site_inactive', 'device_not_registered',
  'signin_failed', 'server_error', 'pin_wrong_plain', 'pin_wrong_one', 'pin_wrong_many', 'pin_locked', 'pin_unavailable',
  'pin_no_answer', 'pin_rule_digits', 'pin_rule_digits_exact', 'pin_rule_guessable', 'pin_rule_mismatch', 'pin_set_wrong_password',
  'pin_set_password_missing', 'pin_set_offline', 'pin_not_allowed', 'policy_changed', 'ack_failed', 'ack_send_failed', 'ack_declined',
  'gate_expired', 'password_rule', 'current_password_wrong', 'password_offline', 'notice_idle', 'notice_absolute', 'notice_signed_out',
  'notice_timeout', 'shift_ended', 'grant_expired', 'grant_revoked',
]);

/** The notice of a hard lock, by reason. directive, erased, replaced and cancelled have none; refused brings its own. */
export const LOCK_NOTICES = Object.freeze({
  end_shift: 'shift_ended', absolute: 'notice_absolute', gate_expired: 'gate_expired', declined: 'ack_declined',
  timeout: 'notice_timeout', signed_out: 'notice_signed_out', account: 'account_unusable',
});
/** A 401 at a gate: the hard-lock reason by code ('other' for every other code). */
export const GATE_401 = Object.freeze({ session_timeout: 'timeout', account_blocked: 'account', other: 'signed_out' });
/** A 401 while someone works: the drop's notice by code ('other' for every other code). */
export const ACTIVE_401 = Object.freeze({ session_timeout: 'notice_timeout', account_blocked: 'account_unusable', other: 'notice_signed_out' });
/** Hard-lock reasons after which the session is inert for good (a wipe, an erase, a replaced window). */
const FINAL_REASONS = Object.freeze(['directive', 'erased', 'replaced']);

/** The S4 seam; S3's stand-in answers as "no connection" (sign-in) or "use the password" (PIN). */
export const OFFLINE_NONE = Object.freeze({
  /** @param {{identifier: string, password: string}} _request */
  async unlockPassword(_request) { return { ok: false, message: { key: 'signin_no_answer' } }; },
  /** @param {{userId: number, pin: string}} _request */
  async pinSwitch(_request) { return { ok: false, message: { key: 'pin_no_answer' }, next: 'password' }; },
});

const LOGIN = 'api/auth/login.php';
const PIN = 'api/auth/pin.php';
const PIN_SET = 'api/auth/pin_set.php';
const LOGOUT = 'api/auth/logout.php';
const POLICY = 'api/auth/policy.php';
const PASSWORD = 'api/auth/password.php';
const SESSION = 'api/session.php';

/** The settings config() reports, with their defaults (S3 spec §3.1). */
const CONFIG_DEFAULTS = Object.freeze({ session_idle_minutes: 30, session_absolute_hours: 12, pin_min_digits: 4, pin_max_digits: 6,
  pin_max_failed: 3, pin_shift_hours: 12, offline_grant_hours: 72, password_min_length: 12 });
const SIGN_IN_STATES = Object.freeze(['LOCKED', 'PICKER', 'IDLE']);
const GATES = Object.freeze(['policy_ack', 'password_change']);
const HEX64 = /^[0-9a-f]{64}$/;
const PHP_TRIM = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g; // PHP trim()'s set, as the server trims the identifier
const DEADLINE = Symbol('deadline');

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const isPositiveInt = (v) => Number.isInteger(v) && v > 0;
const text = (v) => (typeof v === 'string' ? v : '');
const strings = (v) => (Array.isArray(v) ? v.filter((x) => typeof x === 'string') : []);
const nameOf = (e) => (typeof e?.name === 'string' && /^[A-Za-z]{1,40}$/.test(e.name) ? e.name : 'error');
const isClosed = (e) => e?.name === 'DbClosed' || e?.name === 'InvalidStateError';
const pick = (map, code) => (typeof code === 'string' && Object.hasOwn(map, code) ? map[code] : map.other);
const clampDigits = (n) => Math.max(4, Math.min(6, n));
const utf8 = (s) => new TextEncoder().encode(s);
const fromUtf8 = (b) => new TextDecoder().decode(b);
/** A failed result with a copy key. */
const fail = (key, params) => ({ ok: false, message: params === undefined ? { key } : { key, params } });

/** All one digit, or every step +1, or every step −1 (no wrap-around: 8901 is allowed); Pin::guessable()'s twin. */
function guessable(pin) {
  if (new Set(pin).size === 1) return true;
  let up = true;
  let down = true;
  for (let i = 1; i < pin.length; i++) {
    const step = pin.charCodeAt(i) - pin.charCodeAt(i - 1);
    up = up && step === 1;
    down = down && step === -1;
  }
  return up || down;
}

/**
 * The tablet's own copy of Pin::problems(), with the PIN-set view's field ids: digits and length, then guessable (both
 * on pin_new), then the repeat (pin_repeat).
 * @param {string} pin
 * @param {string} confirm
 * @param {{pin_min_digits: number, pin_max_digits: number}} config session.config() (already clamped to 4..6)
 * @returns {Object<string, {key: string, params?: object}>|null}
 */
export function pinProblem(pin, confirm, config) {
  const min = config.pin_min_digits;
  const max = config.pin_max_digits;
  const p = typeof pin === 'string' ? pin : '';
  if (!/^[0-9]+$/.test(p) || p.length < min || p.length > max) {
    return { pin_new: min === max ? { key: 'pin_rule_digits_exact', params: { n: min } } : { key: 'pin_rule_digits', params: { min, max } } };
  }
  if (guessable(p)) return { pin_new: { key: 'pin_rule_guessable' } };
  if (p !== confirm) return { pin_repeat: { key: 'pin_rule_mismatch' } };
  return null;
}

/**
 * @typedef {{key: string, params?: object}|{text: string}} Message
 * @typedef {{ok: true, gate?: string}|{ok: false, message?: Message, errors?: Object<string, Message>, next?: 'password',
 *   identifier?: string, changed?: true}} Result
 * @typedef {{user_id: number, username: string, email: string, display_name: string, role: string, capabilities: string[],
 *   offline_caps: string[], pin_switch: boolean, has_pin: boolean, session_ref: string, auth_method: 'Password'|'PIN'}} Person
 * @typedef {object} Session every member of S3 spec §3.1 (app.js merges it over its stand-in)
 */

/**
 * @param {object} deps
 * @param {import('./env.js').Env} deps.env
 * @param {import('./db.js').Db} deps.db
 * @param {import('./api.js').Api} deps.api
 * @param {import('./clock.js').Clock} deps.clock
 * @param {import('./device.js').Device} deps.device       info() (device_id, site_name, iterations), handleError(e)
 * @param {{begin(k: string): void, end(k: string): void}} deps.updater   update.js (a reload never cuts a sign-in, a PIN
 *   switch, an End shift or a sign-out: kinds 'signin', 'pin', 'end_shift', 'logout')
 * @param {object} [deps.chrome]                          unused (the S2 seam passes it)
 * @param {(state: 'online'|'offline', code?: string) => void} [deps.onConnectivity]   app.js hooks.onConnectivity
 * @param {(config: object) => void} [deps.onConfig]      app.js: the header after a sign-in's config
 * @param {object} [deps.vault]                            defaults to the vault.js module (tests inject a spy)
 * @param {{unlockPassword: Function, pinSwitch: Function}} [deps.offline]   defaults to OFFLINE_NONE (S4 replaces it)
 * @returns {Session}
 */
export function createSession(deps) {
  const { env, db, api, clock, device } = deps;
  const updater = deps.updater ?? { begin() {}, end() {} };
  const vault = deps.vault ?? vaultModule;
  const offline = deps.offline ?? OFFLINE_NONE;
  const onConnectivity = (state, code) => { try { deps.onConnectivity?.(state, code); } catch (e) { env.log('connectivity hook failed: ' + nameOf(e)); } };
  const configHook = (config) => { try { deps.onConfig?.(config); } catch (e) { env.log('config hook failed: ' + nameOf(e)); } };

  // ---- memory (never in IndexedDB) ----
  let state = 'LOCKED';
  let keys = null;              // {rec, pin}: the vault keys (K_rec, K_pin), non-extractable
  const people = new Map();     // user_id → the decrypted vault user
  let peopleLoaded = false;     // false whenever keys is null
  let person = null;            // the ACTIVE Person
  let gate = null;              // {kind, user, sessionRef, kek, salt, rounds, typed, until, untilServer}
  let notice = null;
  let lastRevoked = [];
  let unavailable = null;
  let lastInputMono = 0;
  let lastInputServer = 0;
  let lastPasswordServer = null;
  let lastTouchMono = 0;
  let lastTouchServer = 0;
  let inputSinceTouch = false;
  let touching = false;
  let attempt = 0;              // each sign-in or PIN attempt takes the next value; an answer for an older one is ignored
  let policyLoad = null;
  let replaying = null;
  let stopped = false;          // final: a directive, an erase or a replaced window
  let flight = null;            // the sign-in, PIN, gate or PIN-set call in flight (single flight; busy()): {release}
  const holds = new Map();      // update.js hold kind → how many calls of this session hold it (update.js keeps one per kind)
  const sealedGrant = new Map(); // the iv of a sealed vault user this page life opened or stored → its grant_id (not secret)
  const cfg = { ...CONFIG_DEFAULTS };
  let offlineAllowed = false;
  const listeners = { changed: new Set(), people: new Set(), locked: new Set() };

  const logUnlessClosed = (e, what) => { if (!isClosed(e)) env.log(`${what}: ${nameOf(e)}`); };

  function emit(event, arg) {
    for (const entry of [...listeners[event]]) {
      try { entry.cb(arg); } catch (e) { env.log(`session ${event} listener failed: ${nameOf(e)}`); }
    }
  }

  function config() {
    const min = clampDigits(cfg.pin_min_digits);
    return { ...cfg, pin_min_digits: min, pin_max_digits: Math.max(min, clampDigits(cfg.pin_max_digits)) };
  }

  function onConfig(c) {
    if (!isObject(c)) return;
    for (const k of Object.keys(CONFIG_DEFAULTS)) if (isPositiveInt(c[k])) cfg[k] = c[k];
    if (typeof c.offline_allowed === 'boolean') offlineAllowed = c.offline_allowed;
  }

  // ---- state changes ----

  /** Every way into LOCKED forgets the absolute limit's start and any gate. */
  function enterLocked() {
    state = 'LOCKED';
    gate = null;
    lastPasswordServer = null;
  }

  /**
   * Takes update.js's hold of `kind` (a reload that waits runs only once no hold is left) and returns its release, which
   * acts once. update.js keeps one hold per kind, so two calls of one kind (two sign-outs at once) are counted here: the
   * kind is released when the last of them ends.
   */
  function holdReload(kind) {
    const n = holds.get(kind) ?? 0;
    holds.set(kind, n + 1);
    if (n === 0) updater.begin(kind);
    let held = true;
    return () => {
      if (!held) return;
      held = false;
      const left = holds.get(kind) - 1;
      if (left > 0) { holds.set(kind, left); return; }
      holds.delete(kind);
      try { updater.end(kind); } catch (e) { env.log('update hold not ended: ' + nameOf(e)); } // never into logoutUser's chain
    };
  }

  /** Starts the single flight, and update.js's hold when one is named; null when a call is already in flight. */
  function beginFlight(hold = null) {
    if (flight !== null) return null;
    const f = { release: null };
    flight = f;
    if (hold !== null) f.release = holdReload(hold);
    return f;
  }

  /**
   * Ends f once: in its own finally, or earlier at a hard lock (its late answer changes nothing, so a reload may cut it;
   * what the lock itself starts, End shift or a sign-out, holds the reload on its own).
   */
  function endFlight(f) {
    if (f === null || flight !== f) return;
    flight = null;
    f.release?.();
  }

  function hardLock(reason, given = null) {
    attempt += 1; // an answer still in flight (a sign-in, a PIN) changes nothing after a hard lock
    endFlight(flight); // and the next person can sign in at once
    keys = null;
    people.clear();
    peopleLoaded = false;
    person = null;
    unavailable = null;
    enterLocked();
    notice = given ?? (Object.hasOwn(LOCK_NOTICES, reason) ? { key: LOCK_NOTICES[reason] } : null);
    if (FINAL_REASONS.includes(reason)) stopped = true;
    emit('changed');
    emit('locked', reason);
  }

  function drop(key) {
    person = null;
    unavailable = null;
    if (keys) state = 'PICKER'; else enterLocked();
    notice = { key };
    emit('changed');
    emit('locked', key);
  }

  function softLock(code) {
    if (state === 'GATE') hardLock(pick(GATE_401, code));
    else if (state === 'ACTIVE') drop(pick(ACTIVE_401, code));
  }

  const gateExpired = () => gate !== null && (env.mono() >= gate.until || clock.serverNow() >= gate.untilServer);

  /**
   * The gate's 10 minutes are over: its server session ends too ('Logout', as Cancel does), so no acceptance sent later
   * from this tablet can release that person's keys; then the hard lock.
   */
  function expireGate() {
    logoutUser();
    hardLock('gate_expired');
    return fail('gate_expired');
  }

  function resetActivity() {
    lastInputMono = env.mono();
    lastTouchMono = lastInputMono;
    lastInputServer = clock.serverNow();
    lastTouchServer = lastInputServer;
    inputSinceTouch = false;
  }

  /**
   * Best effort: the server session of whoever the cookie carries ends ('Logout'). Its 'logout' hold keeps a waiting
   * update reload until the answer (or the timeout), so the reload the next hard lock frees never cuts the sign-out (a
   * gate's, security-2): every caller sends it before its lock or drop.
   */
  function logoutUser() {
    const release = holdReload('logout');
    try {
      api.post(LOGOUT, { scope: 'user' }, { device: true, session: true, timeoutMs: LOGOUT_TIMEOUT_MS }).then(release, release);
    } catch { release(); /* never thrown: api.post is async */ }
  }

  /**
   * p, or an offline ApiError 'timeout' once ms have passed since t0 (the deadline covers api.js's refresh and retry).
   * Only while attempt `my` is still the current one is the error marked DEADLINE (the offline path may follow) and
   * attempt moved on (the late answer is for an older attempt); an attempt a hard lock or a newer attempt superseded is
   * just given up, and the newer attempt is left alone.
   */
  function withDeadline(p, t0, ms, my) {
    p.catch(() => {});
    return new Promise((resolve, reject) => {
      const timer = env.setTimeout(() => {
        const e = new ApiError({ kind: 'offline', code: 'timeout' });
        if (attempt === my) {
          attempt += 1;
          e[DEADLINE] = true;
        }
        reject(e);
      }, Math.max(0, t0 + ms - env.mono()));
      p.then((v) => { env.clearTimeout(timer); resolve(v); }, (e) => { env.clearTimeout(timer); reject(e); });
    });
  }

  async function viaOffline(call, fallback) {
    try {
      const r = await call();
      return isObject(r) ? r : fallback;
    } catch (e) {
      env.log('offline path failed: ' + nameOf(e));
      return fallback;
    }
  }

  function signInMessage(e) {
    switch (e?.code) {
      case 'login_failed': return { key: 'login_failed' };
      case 'account_locked': return { key: 'account_locked' };
      case 'account_unusable': return { key: 'account_unusable' };
      case 'no_station_access':
        return e.extra?.reason === 'role' ? { key: 'no_station_access_role' }
          : { key: 'no_station_access_site', params: { site: text(device.info()?.site_name) } };
      case 'rate_limited': return { key: 'rate_limited_wait' };
      case 'device_proof_invalid': return { key: 'device_proof_invalid' };
      case 'device_proof_stale': return { key: 'signin_clock' };
      case 'device_site_inactive': return { key: 'device_site_inactive' };
      case 'device_not_registered': return { key: 'device_not_registered' };
      case 'server_error': {
        const incident = e.extra?.incident;
        return { key: 'server_error', params: { incident: typeof incident === 'string' || typeof incident === 'number' ? String(incident) : null } };
      }
      case 'csrf_failed': return { key: 'signin_failed' };
      default: return typeof e?.message === 'string' && e.message !== '' ? { text: e.message } : { key: 'signin_failed' };
    }
  }

  const publicFields = (u) => ({
    user_id: u.user_id, username: text(u.username), email: text(u.email), display_name: text(u.display_name), role: text(u.role),
    capabilities: strings(u.capabilities), offline_caps: strings(u.offline_caps), pin_switch: u.pin_switch === true, has_pin: u.has_pin === true,
  });

  const validUser = (u) => isObject(u) && isPositiveInt(u.user_id);
  const validAnswer = (res) => isObject(res) && validUser(res.user) && typeof res.session_ref === 'string' && HEX64.test(res.session_ref);

  function validRelease(r) {
    return isObject(r) && vault.b64urlDecode(r.dvk, 32) !== null && vault.b64urlDecode(r.grant_hmac_key, 32) !== null
      && isPositiveInt(r.grant_id) && parseDb(r.grant_issued_at) !== null && parseDb(r.grant_expires_at) !== null
      && typeof r.offline_allowed === 'boolean' && Number.isInteger(r.pbkdf2_iterations);
  }

  // ---- IndexedDB: config, entries, the vault ----

  async function saveConfig(res) {
    const given = isObject(res.config) ? res.config : {};
    let merged = null;
    try {
      await db.tx(['meta'], 'readwrite', async (t) => {
        const raw = await t.get('meta', 'config');
        const old = isObject(raw) ? raw : {};
        merged = { ...old, ...given, organisation_name: given.organisation_name ?? old.organisation_name, offline_enabled: old.offline_enabled,
          offline_allowed: res.release ? res.release.offline_allowed === true : (old.offline_allowed ?? false) };
        await t.put('meta', merged, 'config');
      });
    } catch (e) {
      logUnlessClosed(e, 'the configuration was not saved');
      merged = { ...given, offline_allowed: res.release ? res.release.offline_allowed === true : offlineAllowed };
    }
    onConfig(Number.isInteger(res.password_min_length) ? { ...merged, password_min_length: res.password_min_length } : merged);
    configHook(merged);
  }

  /**
   * D-21, by grant: one transaction deleting, for each stale {user_id, grant_id} (a vault user or a keyring row), that
   * person's keyring row only while it still holds grant_id, and their stored vault user only while it holds grant_id
   * (sealedGrant; a record this page life never opened, as with the vault closed or a damaged one, goes with its keyring
   * row). So a newer grant stored meanwhile (the sign-in under way, or one from PICKER) is never deleted. Memory forgets
   * a person only while it still holds that grant, whatever happens.
   */
  async function deleteEntries(stale) {
    if (stale.length === 0) return;
    try {
      await db.tx(['keyring', 'vault_users'], 'readwrite', async (t) => {
        for (const { user_id: uid, grant_id: grantId } of stale) {
          const ring = await t.get('keyring', uid);
          const ringGone = isObject(ring) && ring.grant_id === grantId;
          if (ringGone) await t.delete('keyring', uid);
          const row = await t.get('vault_users', 'user:' + uid);
          if (!isObject(row)) continue;
          const holds = sealedGrant.get(row.iv);
          if (holds !== undefined ? holds === grantId : ringGone) await t.delete('vault_users', 'user:' + uid);
        }
      });
    } finally {
      for (const { user_id: uid, grant_id: grantId } of stale) if (people.get(uid)?.grant_id === grantId) people.delete(uid);
      emit('people');
    }
  }

  /** Seals the vault user with k.rec and stores it; false when the vault closed meanwhile (nothing is stored then). */
  async function writeVaultUser(vu, k, { quiet = false } = {}) {
    const sealed = await vault.seal(env.crypto, k.rec, 'vault_users', 'user:' + vu.user_id, utf8(JSON.stringify(vu)));
    if (keys !== k || stopped) return false;
    sealedGrant.set(sealed.iv, vu.grant_id); // known before the row exists, whatever runs between the write and its end
    await db.put('vault_users', sealed);
    people.set(vu.user_id, vu);
    if (!quiet) emit('people');
    return true;
  }

  /** Every vault user this vault opens; then those revoked or expired are deleted (50 §3.4, D-21). */
  async function loadPeople(k) {
    const rows = await db.all('vault_users');
    for (const rec of rows) {
      const where = typeof rec?.k === 'string' && /^user:\d{1,10}$/.test(rec.k) ? rec.k : 'a record';
      try {
        const vu = JSON.parse(fromUtf8(await vault.open(env.crypto, k.rec, 'vault_users', rec?.k, rec)));
        if (!isObject(vu) || !isPositiveInt(vu.user_id) || rec.k !== 'user:' + vu.user_id) throw new TypeError('not a vault user');
        if (keys !== k) return false;
        sealedGrant.set(rec.iv, vu.grant_id);
        people.set(vu.user_id, vu);
      } catch (e) {
        env.log(`vault user ${where} skipped: ${nameOf(e)}`); // by key only: never a name
      }
    }
    if (keys !== k) return false;
    const now = clock.serverNow();
    // By grant (deleteEntries): the keyring row the sign-in under way has just written for a new grant stays.
    await deleteEntries([...people.values()].filter((vu) => lastRevoked.includes(vu.grant_id) || !(parseDb(vu.grant_expires_at) > now)));
    peopleLoaded = true;
    emit('people');
    return true;
  }

  /**
   * §3.2: the keyring entry (offline_allowed and a KEK), then the vault opens (zeroing the raw DVK), then, from a closed
   * vault, every stored vault user, then this person's own record. False when live() stopped it (nothing more is written).
   */
  async function writeRelease(user, release, kekInfo, live) {
    const deviceId = device.info()?.device_id;
    const uid = user.user_id;
    const dvk = vault.b64urlDecode(release.dvk, 32);
    try {
      if (release.offline_allowed === true && kekInfo?.kek && deviceId !== undefined && deviceId !== null) {
        const wrap = await vault.wrapDvk(env.crypto, kekInfo.kek, dvk, deviceId, uid);
        const lookup = [...new Set(await Promise.all([user.username, user.email, kekInfo.typed].map((x) => vault.lookup(env.crypto, deviceId, x))))];
        if (!live()) return false;
        await db.put('keyring', { user_id: uid, lookup, grant_id: release.grant_id, grant_expires_at: release.grant_expires_at,
          salt: vault.b64url(kekInfo.salt), iterations: kekInfo.rounds, iv: wrap.iv, wrapped_dvk: wrap.wrapped_dvk, v: 1 });
      } else {
        if (!live()) return false;
        await db.delete('keyring', uid);
      }
      if (!live()) return false;
      // (S4 writes the shift key here, with the raw bytes.)
      const k = await vault.openVault(env.crypto, dvk); // zeroes dvk
      if (!live()) return false;
      keys = k;
      if (!peopleLoaded && !(await loadPeople(k))) return false;
      if (!live()) return false;
      // This person's stored record, while its grant is live: an expired or revoked one (not pruned yet, from PICKER)
      // gives nothing, so its verifier dies with its grant (D-22).
      const prev = people.get(uid);
      const old = prev !== undefined && parseDb(prev.grant_expires_at) > clock.serverNow() && !lastRevoked.includes(prev.grant_id) ? prev : undefined;
      const vu = { user_id: uid, username: text(user.username), display_name: text(user.display_name), role: text(user.role),
        offline_caps: strings(user.offline_caps), pin_switch: user.pin_switch === true, has_pin: user.has_pin === true,
        grant_id: release.grant_id, grant_hmac_key: release.grant_hmac_key, grant_issued_at: release.grant_issued_at,
        grant_expires_at: release.grant_expires_at, last_password_at: formatDb(Math.round(clock.serverNow())),
        pin_verifier: old?.pin_verifier ?? null, pin_failed: old?.pin_failed ?? 0, v: 1 };
      return (await writeVaultUser(vu, k)) && live();
    } finally {
      dvk?.fill(0);
    }
  }

  /**
   * The person works now: the release written (or why not), then ACTIVE. live() false at any step: nothing more is
   * written and the state does not change (returns false).
   */
  async function activate(user, sessionRef, release, reason, kekInfo, method, live) {
    let why = null;
    if (validRelease(release)) {
      try {
        if (!(await writeRelease(user, release, kekInfo, live))) return false;
      } catch (e) {
        if (!live()) return false;
        logUnlessClosed(e, 'the release was not stored');
        why = 'no_grant_possible';
      }
    } else {
      if (release !== null && release !== undefined) env.log('the release was malformed');
      else if (reason !== 'no_vault_key' && reason !== 'no_grant_possible') env.log('no release: ' + (typeof reason === 'string' && /^[a-z_]{1,40}$/.test(reason) ? reason : 'no reason'));
      why = reason === 'no_vault_key' ? 'no_vault_key' : 'no_grant_possible';
    }
    if (!live()) return false;
    unavailable = why;
    person = { ...publicFields(user), session_ref: sessionRef, auth_method: method };
    if (method === 'Password') lastPasswordServer = clock.serverNow();
    resetActivity();
    gate = null;
    notice = null;
    state = 'ACTIVE';
    emit('changed');
    return true;
  }

  // ---- revoked grants, offline disabled ----

  async function applyRevoked(ids) {
    try {
      if (stopped || !Array.isArray(ids) || ids.length === 0) return;
      const list = ids.map((x) => (typeof x === 'string' && /^\d{1,10}$/.test(x) ? Number(x) : x)).filter((x) => Number.isInteger(x));
      if (list.length === 0) return;
      lastRevoked = list;
      const ring = await db.all('keyring');
      // Only an entry whose own grant is listed (D-21): a keyring row by its grant_id, a vault user by its grant_id.
      const stale = [...ring.filter((r) => isObject(r) && list.includes(r.grant_id)), ...[...people.values()].filter((vu) => list.includes(vu.grant_id))];
      if (stale.length === 0 || stopped) return;
      // The ACTIVE person by their current grant (their vault user), else by any listed entry of theirs.
      const own = person === null ? undefined : people.get(person.user_id);
      const activeHit = state === 'ACTIVE' && person !== null
        && (own !== undefined ? list.includes(own.grant_id) : stale.some((s) => s.user_id === person.user_id));
      if (activeHit) {
        logoutUser();
        drop('grant_revoked');
      }
      await deleteEntries(stale);
    } catch (e) {
      logUnlessClosed(e, 'revoked grants not applied');
    }
  }

  async function onOfflineDisabled() {
    try {
      offlineAllowed = false;
      if (stopped) return;
      if ((await db.count('keyring')) > 0 && !stopped) await db.clear('keyring');
    } catch (e) {
      logUnlessClosed(e, 'the keyring was not cleared');
    }
  }

  // ---- sign-in ----

  async function signIn(identifier, password) {
    if (stopped || flight !== null || !SIGN_IN_STATES.includes(state)) return fail('signin_failed');
    if (String(identifier ?? '').replace(PHP_TRIM, '') === '' || typeof password !== 'string' || password === '') return fail('signin_missing');
    const f = beginFlight('signin');
    const t0 = env.mono();
    const my = ++attempt;
    const live = () => !stopped && my === attempt;
    try {
      const it = device.info()?.iterations;
      const rounds = Number.isInteger(it) && it >= 1000 ? it : DEFAULT_ROUNDS;
      const salt = env.random(16);
      // PBKDF2 runs while the request travels (50 §2.3 step 1); never inside a db.tx().
      const kekP = (async () => vault.deriveKek(env.crypto, password, salt, rounds))().catch(() => null);
      let res;
      try {
        res = await withDeadline(api.post(LOGIN, { identifier, password }, { device: true, session: true, timeoutMs: SIGNIN_TIMEOUT_MS }),
          t0, SIGNIN_TIMEOUT_MS, my);
      } catch (e) {
        if (stopped) return { ok: false };
        if (e instanceof ApiError && e.offline) {
          if (my !== attempt && e[DEADLINE] !== true) return { ok: false }; // superseded: never the offline path
          onConnectivity('offline', e.code ?? 'timeout');
          return await viaOffline(() => offline.unlockPassword({ identifier, password }), fail('signin_no_answer'));
        }
        if (e instanceof ApiError) {
          onConnectivity('online');
          if (await device.handleError(e)) return fail('signin_failed'); // a directive or a 410 took the tablet over
          if (!live()) return { ok: false };
          return { ok: false, message: signInMessage(e) };
        }
        env.log('sign-in failed: ' + nameOf(e));
        return fail('signin_failed');
      }
      if (!live()) return { ok: false };
      onConnectivity('online');
      const gateKind = res?.gate ?? null;
      if (!validAnswer(res) || (gateKind !== null && !GATES.includes(gateKind))) {
        env.log('sign-in answer malformed');
        return fail('signin_failed');
      }
      await applyRevoked(res.revoked_grants);
      if (!live()) return { ok: false };
      await saveConfig(res);
      if (!live()) return { ok: false };
      const kek = await kekP;
      if (!live()) return { ok: false };
      if (gateKind !== null) {
        gate = { kind: gateKind, user: res.user, sessionRef: res.session_ref, kek, salt, rounds, typed: identifier,
          until: env.mono() + GATE_KEK_MS, untilServer: clock.serverNow() + GATE_KEK_MS };
        person = null;
        unavailable = null;
        notice = null;
        state = 'GATE';
        emit('changed');
      } else if (!(await activate(res.user, res.session_ref, res.release, res.release_unavailable, { kek, salt, rounds, typed: identifier }, 'Password', live))) {
        return { ok: false };
      }
      env.log('sign-in ' + Math.round(env.mono() - t0) + ' ms');
      return gateKind !== null ? { ok: true, gate: gateKind } : { ok: true };
    } catch (e) {
      logUnlessClosed(e, 'sign-in failed');
      return live() ? fail('signin_failed') : { ok: false };
    } finally {
      endFlight(f);
    }
  }

  // ---- gates ----

  /** The errors every gate call shares; null when the caller decides (its own codes, then 'other'). */
  async function gateError(e, offlineKey) {
    if (e instanceof ApiError && e.offline) { onConnectivity('offline', e.code); return fail(offlineKey); }
    if (!(e instanceof ApiError)) { env.log('gate call failed: ' + nameOf(e)); return fail('signin_failed'); }
    onConnectivity('online');
    if (e.status === 401) { softLock(e.code); return { ok: false }; }
    if (await device.handleError(e)) return { ok: false };
    if ((e.status === 403 && e.code === 'no_station_access') || (e.status === 422 && e.code === 'account_unusable')) {
      hardLock('refused', signInMessage(e)); // release()'s re-check refused: the lock screen shows why
      return { ok: false };
    }
    if (e.code === 'server_error') return { ok: false, message: signInMessage(e) }; // with its problem number, as at sign-in
    return null;
  }

  function loadPolicy() {
    policyLoad ??= fetchPolicy().finally(() => { policyLoad = null; });
    return policyLoad;
  }

  async function fetchPolicy() {
    try {
      if (stopped) return { ok: false };
      const g = gate;
      let res;
      try {
        res = await api.get(POLICY, { device: true, session: true, timeoutMs: CALL_TIMEOUT_MS });
      } catch (e) {
        if (stopped) return { ok: false };
        if (e instanceof ApiError && e.offline) { onConnectivity('offline', e.code); return fail('ack_failed'); }
        if (!(e instanceof ApiError)) { env.log('the agreement was not loaded: ' + nameOf(e)); return fail('ack_failed'); }
        onConnectivity('online');
        if (e.status === 401) { if (gate === g) softLock(e.code); return { ok: false }; }
        if (e.code === 'password_change_required') {
          if (gate !== null && gate === g && state === 'GATE') { gate.kind = 'password_change'; emit('changed'); }
          return { ok: false };
        }
        if (await device.handleError(e)) return { ok: false };
        return fail('ack_failed');
      }
      if (stopped) return { ok: false };
      onConnectivity('online');
      if (!isObject(res)) return fail('ack_failed');
      return { ok: true, required: res.required === true, document: isObject(res.document) ? res.document : null };
    } catch (e) {
      logUnlessClosed(e, 'the agreement was not loaded');
      return fail('ack_failed');
    }
  }

  async function acceptPolicy(doc) {
    if (stopped || state !== 'GATE' || gate?.kind !== 'policy_ack') return { ok: false };
    if (gateExpired()) return expireGate();
    const f = beginFlight('signin');
    if (f === null) return { ok: false };
    const g = gate;
    const live = () => !stopped && gate === g;
    try {
      let res;
      try {
        res = await api.post(POLICY, { document_id: doc?.document_id, fingerprint: doc?.fingerprint, decision: 'accept' },
          { device: true, session: true, timeoutMs: CALL_TIMEOUT_MS });
      } catch (e) {
        if (!live()) return { ok: false };
        if (e instanceof ApiError && e.status === 409 && e.code === 'policy_changed') {
          onConnectivity('online');
          return { ok: false, message: { key: 'policy_changed' }, changed: true };
        }
        return (await gateError(e, 'ack_send_failed')) ?? fail('signin_failed'); // the answer was not sent (ack_failed: not loaded)
      }
      if (!live()) return { ok: false };
      onConnectivity('online');
      await applyRevoked(res?.revoked_grants);
      if (!live()) return { ok: false };
      if (gateExpired()) return expireGate(); // the server released meanwhile: its session (and so the release) is ended
      if (res?.gate === 'password_change') {
        g.kind = 'password_change';
        emit('changed');
        return { ok: true, gate: 'password_change' };
      }
      if (res?.gate === 'policy_ack') return { ok: false, message: { key: 'policy_changed' }, changed: true }; // still due: read it again
      if (validRelease(res?.release)) await saveConfig({ release: res.release });
      if (!live()) return { ok: false };
      // The KEK kept from the sign-in: one deriveKek per sign-in.
      return (await activate(g.user, g.sessionRef, res?.release, res?.release_unavailable, g, 'Password', live)) ? { ok: true } : { ok: false };
    } catch (e) {
      logUnlessClosed(e, 'the acceptance failed');
      return live() ? fail('signin_failed') : { ok: false };
    } finally {
      endFlight(f);
    }
  }

  async function declinePolicy(doc) {
    if (stopped || state !== 'GATE' || flight !== null) return { ok: false };
    const g = gate;
    const f = beginFlight();
    try {
      try {
        await api.post(POLICY, { document_id: doc?.document_id, fingerprint: doc?.fingerprint, decision: 'decline' },
          { device: true, session: true, timeoutMs: CALL_TIMEOUT_MS });
      } catch (e) {
        if (e instanceof ApiError && e.kind === 'http' && !stopped) await device.handleError(e); // an answer or no answer alike
      }
      if (stopped) return { ok: false };
      if (gate === g) hardLock('declined');
      return { ok: true };
    } catch (e) {
      logUnlessClosed(e, 'the decline failed');
      if (!stopped && gate === g) hardLock('declined');
      return { ok: true };
    } finally {
      endFlight(f);
    }
  }

  async function changePassword(current, next) {
    if (stopped || state !== 'GATE' || gate?.kind !== 'password_change') return { ok: false };
    if (gateExpired()) return expireGate();
    if (flight !== null) return { ok: false };
    const n = config().password_min_length;
    if (typeof next !== 'string' || [...next].length < n) return { ok: false, errors: { new_password: { key: 'password_rule', params: { n } } } };
    const f = beginFlight('signin');
    const g = gate;
    const live = () => !stopped && gate === g;
    try {
      const newSalt = env.random(16);
      // The KEK of the new password, derived while the request travels.
      const newKekP = (async () => vault.deriveKek(env.crypto, next, newSalt, g.rounds))().catch(() => null);
      let res;
      try {
        res = await api.post(PASSWORD, { current_password: current, new_password: next }, { device: true, session: true, timeoutMs: CALL_TIMEOUT_MS });
      } catch (e) {
        if (!live()) return { ok: false };
        if (e instanceof ApiError && e.kind === 'http') {
          if (e.code === 'login_failed') { onConnectivity('online'); return { ok: false, errors: { current_password: { key: 'current_password_wrong' } } }; }
          if (e.code === 'invalid') {
            onConnectivity('online');
            const why = e.extra?.errors?.new_password;
            return { ok: false, errors: { new_password: { text: typeof why === 'string' && why !== '' ? why : e.message } } };
          }
          if (e.code === 'rate_limited') { onConnectivity('online'); return fail('rate_limited_wait'); }
        }
        return (await gateError(e, 'password_offline')) ?? fail('signin_failed');
      }
      if (!live()) return { ok: false };
      onConnectivity('online');
      await applyRevoked(res?.revoked_grants); // the old grant is listed: its entries go, the new ones are written after
      if (!live()) return { ok: false };
      const kek = await newKekP;
      if (!live()) return { ok: false };
      g.kek = kek;
      g.salt = newSalt;
      if (res?.gate === 'policy_ack') {
        g.kind = 'policy_ack';
        g.until = env.mono() + GATE_KEK_MS;
        g.untilServer = clock.serverNow() + GATE_KEK_MS;
        emit('changed');
        return { ok: true, gate: 'policy_ack' };
      }
      if (validRelease(res?.release)) await saveConfig({ release: res.release });
      if (!live()) return { ok: false };
      return (await activate(g.user, g.sessionRef, res?.release, res?.release_unavailable, g, 'Password', live)) ? { ok: true } : { ok: false };
    } catch (e) {
      logUnlessClosed(e, 'the password change failed');
      return live() ? fail('signin_failed') : { ok: false };
    } finally {
      endFlight(f);
    }
  }

  async function cancelGate() {
    try {
      if (stopped || state !== 'GATE') return;
      logoutUser();
      hardLock('cancelled');
    } catch (e) {
      logUnlessClosed(e, 'cancel failed');
    }
  }

  // ---- PIN ----

  async function pinSwitch(userId, pin) {
    if (stopped || flight !== null || (state !== 'PICKER' && state !== 'IDLE')) return fail('signin_failed');
    const f = beginFlight('pin');
    const t0 = env.mono();
    const my = ++attempt;
    const live = () => !stopped && my === attempt;
    const identifier = text(people.get(userId)?.username);
    const toPassword = (key) => ({ ok: false, message: { key }, next: 'password', identifier });
    try {
      let res;
      try {
        res = await withDeadline(api.post(PIN, { user_id: userId, pin }, { device: true, session: true, timeoutMs: PIN_TIMEOUT_MS }), t0, PIN_TIMEOUT_MS, my);
      } catch (e) {
        if (stopped) return { ok: false };
        if (e instanceof ApiError && e.offline) {
          if (my !== attempt && e[DEADLINE] !== true) return { ok: false }; // superseded: never the offline path
          onConnectivity('offline', e.code ?? 'timeout');
          const fallback = { ok: false, message: { key: 'pin_no_answer' }, next: 'password' };
          const r = await viaOffline(() => offline.pinSwitch({ userId, pin }), fallback);
          return r.ok === true ? r : { ...r, identifier };
        }
        if (!(e instanceof ApiError)) { env.log('pin failed: ' + nameOf(e)); return fail('signin_failed'); }
        onConnectivity('online');
        if (await device.handleError(e)) return { ok: false };
        if (!live()) return { ok: false };
        // The verifier is never touched on a failure.
        switch (e.code) {
          case 'pin_wrong': {
            const n = e.extra?.tries_left;
            if (!Number.isInteger(n)) return fail('pin_wrong_plain');
            if (n <= 0) return toPassword('pin_locked');
            return n === 1 ? fail('pin_wrong_one') : fail('pin_wrong_many', { n });
          }
          case 'pin_locked': return toPassword('pin_locked');
          case 'pin_unavailable': return toPassword('pin_unavailable');
          case 'rate_limited': return fail('rate_limited_wait');
          default: return { ok: false, message: signInMessage(e) };
        }
      }
      if (!live()) return { ok: false }; // a late 200 never sets ACTIVE
      onConnectivity('online');
      await applyRevoked(res?.revoked_grants);
      if (!live()) return { ok: false };
      if (!validAnswer(res)) {
        env.log('pin answer malformed');
        return fail('signin_failed');
      }
      const uid = res.user.user_id;
      const k = keys;
      if (k !== null && people.has(uid)) {
        try {
          const v = await vault.pinVerifier(env.crypto, k.pin, uid, pin);
          if (live() && people.has(uid)) await writeVaultUser({ ...people.get(uid), pin_verifier: v, pin_failed: 0, has_pin: true }, k);
        } catch (e) {
          logUnlessClosed(e, 'the PIN verifier was not stored');
        }
      }
      if (!live()) return { ok: false };
      person = { ...publicFields(res.user), session_ref: res.session_ref, auth_method: 'PIN' };
      unavailable = null; // this person's grant is in vault_users; never the previous person's warning
      resetActivity();    // not lastPasswordServer: a PIN never extends the absolute limit
      gate = null;
      notice = null;
      state = 'ACTIVE';
      emit('changed');
      env.log('pin ' + Math.round(env.mono() - t0) + ' ms');
      return { ok: true };
    } catch (e) {
      logUnlessClosed(e, 'pin failed');
      return live() ? fail('signin_failed') : { ok: false };
    } finally {
      endFlight(f);
    }
  }

  async function setPin(password, pin, confirm) {
    if (stopped || flight !== null || state !== 'ACTIVE' || person === null) return fail('signin_failed');
    const errors = { ...(pinProblem(pin, confirm, config()) ?? {}) };
    if (typeof password !== 'string' || password === '') errors.pin_password = { key: 'pin_set_password_missing' };
    if (Object.keys(errors).length > 0) return { ok: false, errors };
    const f = beginFlight();
    const live = () => !stopped && flight === f; // a hard lock ends the flight: its answer then changes nothing
    const me = person;
    try {
      let res;
      try {
        res = await api.post(PIN_SET, { password, pin, pin_confirm: confirm }, { device: true, session: true, timeoutMs: CALL_TIMEOUT_MS });
      } catch (e) {
        if (stopped) return { ok: false };
        if (e instanceof ApiError && e.offline) { onConnectivity('offline', e.code); return fail('pin_set_offline'); }
        if (!(e instanceof ApiError)) { env.log('PIN not set: ' + nameOf(e)); return fail('signin_failed'); }
        onConnectivity('online');
        if (e.status === 401) { if (person === me) softLock(e.code); return { ok: false }; }
        if (await device.handleError(e)) return { ok: false }; // the transaction's lockDevice(): the tablet was taken over
        switch (e.code) {
          case 'login_failed': return { ok: false, errors: { pin_password: { key: 'pin_set_wrong_password' } } };
          case 'pin_rules': {
            const why = e.extra?.errors?.pin;
            return { ok: false, errors: { pin_new: { text: typeof why === 'string' && why !== '' ? why : e.message } } };
          }
          case 'forbidden': return fail('pin_not_allowed');
          case 'rate_limited': return fail('rate_limited_wait');
          case 'password_change_required':
          case 'policy_ack_required':
            if (person === me) softLock('session_ended');
            return { ok: false };
          case 'account_unusable':
            // Pin::set's re-check (Auth::lockVerified): the account can no longer hold a session (deactivated, expired or
            // not started yet). The person stops working here as after a 401 account_blocked (ACTIVE_401: dropped, the
            // others' keys kept, not a hard lock); their session is still open on the server, so it is signed out.
            if (person === me && state === 'ACTIVE') {
              logoutUser();
              drop('account_unusable');
            }
            return { ok: false };
          default: return { ok: false, message: signInMessage(e) };
        }
      }
      if (!live()) return { ok: false }; // after a hard lock (End shift): nothing is written into the next vault
      onConnectivity('online');
      await applyRevoked(res?.revoked_grants);
      const uid = me.user_id;
      const k = keys;
      if (k !== null && people.has(uid) && live()) {
        try {
          const v = await vault.pinVerifier(env.crypto, k.pin, uid, pin);
          if (live() && people.has(uid)) await writeVaultUser({ ...people.get(uid), pin_verifier: v, pin_failed: 0, has_pin: true }, k, { quiet: true });
        } catch (e) {
          logUnlessClosed(e, 'the PIN verifier was not stored');
        }
      }
      if (person === me) person.has_pin = true; // no event: the PIN-set screen keeps its success until Continue
      return { ok: true };
    } catch (e) {
      logUnlessClosed(e, 'PIN not set');
      return fail('signin_failed');
    } finally {
      endFlight(f);
    }
  }

  // ---- Switch user, End shift ----

  function switchUser() {
    if (stopped || state !== 'ACTIVE') return;
    person = null; // `unavailable` reads as null outside ACTIVE; the next sign-in or PIN switch sets it
    notice = null;
    if (keys) state = 'PICKER'; else enterLocked();
    emit('changed');
  }

  /**
   * An End shift's HTTP error that can pass, so the record is kept (D-24: the server must hear of it): a 5xx (a deadlock
   * after the retries, a database restart), a 429, a CSRF token or a proof's time still refused after api.js's one
   * retry. Every other refusal (400 bad_request, a 401 or 403 of the tablet) cannot succeed later.
   */
  const passing = (e) => e.status >= 500 || e.status === 429 || e.code === 'csrf_failed' || e.code === 'device_proof_stale';

  /** Deletes meta.pending_shift_end only while it still holds `at` (a newer End shift is never deleted by an older answer). */
  async function forgetShiftEnd(at) {
    if (stopped) return;
    await db.tx(['meta'], 'readwrite', async (t) => {
      const current = await t.get('meta', 'pending_shift_end');
      if (isObject(current) && current.at === at) await t.delete('meta', 'pending_shift_end');
    });
  }

  async function endShift() {
    let release = null;
    try {
      if (stopped) return;
      const at = formatDb(Math.round(clock.serverNow()));
      // Its own update.js hold before the lock ends the flight's: a reload waiting on that one runs only once this End
      // shift is stored and answered (D-24). The flight still ends at once (busy() is false).
      release = holdReload('end_shift');
      hardLock('end_shift'); // first: whatever the network does, the keys are gone
      try {
        await db.tx(['meta'], 'readwrite', async (t) => {
          await t.put('meta', at, 'shift_ended_at');
          await t.put('meta', { at }, 'pending_shift_end');
        });
      } catch (e) {
        logUnlessClosed(e, 'the End shift was not stored');
      }
      if (stopped) return;
      try {
        await api.post(LOGOUT, { scope: 'device' }, { device: true, session: true, timeoutMs: LOGOUT_TIMEOUT_MS });
      } catch (e) {
        if (stopped) return;
        if (e instanceof ApiError && e.offline) { onConnectivity('offline', e.code); return; } // kept: replayed later
        if (!(e instanceof ApiError)) { env.log('End shift not sent: ' + nameOf(e)); return; }
        onConnectivity('online');
        if (!(await device.handleError(e)) && passing(e)) return; // kept: the replay sends it with its own time
        await forgetShiftEnd(at); // refused: it cannot succeed later
        return;
      }
      onConnectivity('online');
      await forgetShiftEnd(at);
    } catch (e) {
      logUnlessClosed(e, 'End shift');
    } finally {
      release?.();
    }
  }

  function replayShiftEnd() {
    replaying ??= runReplay().finally(() => { replaying = null; });
    return replaying;
  }

  async function runReplay() {
    try {
      if (stopped) return;
      const p = await db.get('meta', 'pending_shift_end');
      if (p === undefined || p === null || stopped) return;
      if (!isObject(p) || typeof p.at !== 'string') {
        await db.delete('meta', 'pending_shift_end'); // unreadable: it can never be sent
        return;
      }
      try {
        await api.post(LOGOUT, { scope: 'device', ended_at: p.at }, { device: true, session: true, timeoutMs: LOGOUT_TIMEOUT_MS });
      } catch (e) {
        if (stopped) return;
        if (e instanceof ApiError && e.offline) return; // kept
        if (!(e instanceof ApiError)) { env.log('End shift replay not sent: ' + nameOf(e)); return; }
        if (!(await device.handleError(e)) && passing(e)) return; // kept for the next replay
        await forgetShiftEnd(p.at); // refused: it cannot succeed later
        return;
      }
      await forgetShiftEnd(p.at);
    } catch (e) {
      logUnlessClosed(e, 'End shift replay');
    }
  }

  async function lock(kind, reason) {
    try {
      if (kind !== 'hard') {
        env.log('lock ignored: ' + (typeof kind === 'string' && /^[a-z]{1,20}$/.test(kind) ? kind : typeof kind));
        return;
      }
      hardLock(typeof reason === 'string' ? reason : 'locked');
    } catch (e) {
      logUnlessClosed(e, 'lock');
    }
  }

  // ---- timers and the keep-alive ----

  function touchDue() {
    return !stopped && state === 'ACTIVE' && person !== null && inputSinceTouch && !touching
      && Math.max(env.mono() - lastTouchMono, clock.serverNow() - lastTouchServer) >= TOUCH_EVERY_MS;
  }

  function sendTouch() {
    const sent = { user_id: person.user_id, session_ref: person.session_ref };
    lastTouchMono = env.mono();
    lastTouchServer = clock.serverNow();
    inputSinceTouch = false;
    touching = true;
    api.post(SESSION, { action: 'touch' }, { session: true, timeoutMs: LOGOUT_TIMEOUT_MS })
      .then((info) => {
        try {
          // Only while the same person works: A's late answer never drops B.
          if (stopped || state !== 'ACTIVE' || person?.user_id !== sent.user_id || person.session_ref !== sent.session_ref) return;
          if (info?.user?.user_id !== sent.user_id || info?.session?.session_ref !== sent.session_ref) softLock('session_ended');
        } catch (e) {
          env.log('touch answer not read: ' + nameOf(e));
        }
      }, () => { /* the next keep-alive tries again */ })
      .finally(() => { touching = false; });
  }

  async function touch() {
    try {
      if (stopped) return;
      lastInputMono = env.mono();
      lastInputServer = clock.serverNow();
      inputSinceTouch = true;
      if (touchDue()) sendTouch();
    } catch (e) {
      logUnlessClosed(e, 'touch');
    }
  }

  async function pruneExpired(now) {
    try {
      const gone = [...people.values()].filter((vu) => !(parseDb(vu.grant_expires_at) > now));
      if (gone.length === 0) return;
      if (state === 'ACTIVE' && person !== null && gone.some((vu) => vu.user_id === person.user_id)) {
        logoutUser();
        drop('grant_expired');
      }
      await deleteEntries(gone);
    } catch (e) {
      logUnlessClosed(e, 'expired entries not deleted');
    }
  }

  async function tick() {
    try {
      if (stopped) return;
      const now = clock.serverNow();
      const mono = env.mono();
      if (state === 'GATE' && gateExpired()) { expireGate(); return; }
      if (lastPasswordServer !== null && now - lastPasswordServer >= cfg.session_absolute_hours * 3600000) {
        if (state === 'ACTIVE') logoutUser();
        hardLock('absolute');
        return;
      }
      void pruneExpired(now);
      if (state === 'ACTIVE' && Math.max(mono - lastInputMono, now - lastInputServer) >= cfg.session_idle_minutes * 60000) {
        person = null;
        unavailable = null;
        if (keys) state = 'IDLE'; else enterLocked(); // a tablet without keys has no picker
        notice = { key: 'notice_idle' };
        emit('changed');
        emit('locked', 'idle');
        return;
      }
      if (touchDue()) sendTouch(); // input after the last touch is reported once the interval has passed
    } catch (e) {
      logUnlessClosed(e, 'tick');
    }
  }

  // ---- the picker ----

  function peopleForPicker() {
    const now = clock.serverNow();
    return [...people.values()].filter((vu) => parseDb(vu.grant_expires_at) > now)
      .map((vu) => ({ user_id: vu.user_id, display_name: text(vu.display_name), username: text(vu.username),
        has_pin: vu.has_pin === true || typeof vu.pin_verifier === 'string' }))
      .sort((a, b) => a.display_name.localeCompare(b.display_name));
  }

  function canRecord() {
    if (state !== 'ACTIVE' || keys === null || person === null) return false;
    const vu = people.get(person.user_id);
    return vu !== undefined && parseDb(vu.grant_expires_at) > clock.serverNow();
  }

  try {
    void db.get('meta', 'config').then(onConfig, () => {});
  } catch { /* a closed database: the defaults stay */ }

  return {
    state: () => state,
    gate: () => (state === 'GATE' && gate !== null ? gate.kind : null),
    gateUser: () => (state === 'GATE' && gate !== null ? { display_name: text(gate.user?.display_name) } : null),
    user: () => (state === 'ACTIVE' && person !== null ? { ...person, capabilities: [...person.capabilities], offline_caps: [...person.offline_caps] } : null),
    mode: () => (state === 'ACTIVE' ? 'online' : null),
    capabilities: () => (state === 'ACTIVE' && person !== null ? [...person.capabilities] : []),
    hasVaultKey: () => keys !== null,
    canRecord,
    releaseUnavailable: () => (state === 'ACTIVE' ? unavailable : null),
    config,
    busy: () => flight !== null,
    takeNotice() { const n = notice; notice = null; return n; },
    peopleForPicker,
    signIn,
    loadPolicy,
    acceptPolicy,
    declinePolicy,
    changePassword,
    cancelGate,
    pinSwitch,
    setPin,
    switchUser,
    endShift,
    replayShiftEnd,
    lock,
    touch,
    tick,
    onRevokedGrants: (ids) => applyRevoked(ids),
    onOfflineDisabled,
    onConfig,
    unlockDelaySeconds: () => 0,
    on(event, cb) {
      const set = listeners[event];
      if (!set || typeof cb !== 'function') return () => {};
      const entry = { cb };
      set.add(entry);
      return () => { set.delete(entry); };
    },
  };
}
