// Test support (S3 spec §4.3): the server's S3 answers as builders, spies over vault.js and db.js, and stationHarness():
// a registered tablet over the REAL S2 modules (clock.js, api.js, device.js, db.js on the memory IndexedDB) around a real
// session.js, wired as app.js wires them. Not a suite (no .test.js suffix). Nothing here uses the network, and real time
// only as until()'s bound.
import { fakeEnv } from './fake-env.js';
import { fakeFetch } from './fake-fetch.js';
import { openMemoryDb } from './memory-db.js';
import { createClock } from '../../../public/station/js/clock.js';
import { createApi } from '../../../public/station/js/api.js';
import { createDevice } from '../../../public/station/js/device.js';
import { importProofKey } from '../../../public/station/js/proof.js';
import { formatDb } from '../../../public/station/js/canonical.js';
import { createSession } from '../../../public/station/js/session.js';
import { createUpdater } from '../../../public/station/js/update.js';
import * as vaultModule from '../../../public/station/js/vault.js';

/** The tablet's vault key: bytes 0..31. */
export const DVK = Uint8Array.from({ length: 32 }, (_, i) => i);
/** The grant's HMAC key: bytes 0x20..0x3f. */
export const GRANT_KEY = Uint8Array.from({ length: 32 }, (_, i) => 0x20 + i);
export const SESSION_REF = 'a'.repeat(64);
/** fake-env's DEFAULT_NOW as the server's datetime text. */
export const SERVER_TIME = '2026-10-01 12:00:00.000';
export const PASSWORD = 'Correct-Horse-Battery-9';
export const CREDENTIAL = 'pfd1_' + 'S'.repeat(43);
export const BUILD = 'test+0000000000';
export const DEVICE_ID = 7;
/** The raw proof key the harness imports (non-extractable) into meta.device. */
const PROOF_RAW = Uint8Array.from({ length: 32 }, (_, i) => 100 + i);
/** StationConfig::client() with its defaults. */
export const CONFIG = Object.freeze({ organisation_name: 'CHS Pet Pantry', session_idle_minutes: 30, session_absolute_hours: 12, pin_min_digits: 4,
  pin_max_digits: 6, pin_max_failed: 3, pin_shift_hours: 12, offline_grant_hours: 72, offline_max_failed_unlocks: 10, sync_clock_skew_minutes: 10,
  offline_mode_enabled: true });

/** A JSON Response. */
export const json = (status, body, headers = {}) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
/** The error envelope of 50 §5.2: {error, message, ...extra}. */
export const errorBody = (code, message = '', extra = {}) => ({ error: code, message, ...extra });

// ---- builders: (over = {}) => ({...defaults, ...over}) ----

export const userJson = (over = {}) => ({ user_id: 12, username: 'jdoe', email: 'Jo.Doe@Example.test', display_name: 'Jo Doe', role: 'Volunteer',
  capabilities: ['home.view', 'profile.self', 'auth.pin_switch'], offline_caps: ['offline.checkin', 'offline.distribute', 'offline.pet_edit', 'offline.register'],
  pin_switch: true, has_pin: false, ...over });
export const release = (over = {}) => ({ dvk: vaultModule.b64url(DVK), grant_id: 345, grant_hmac_key: vaultModule.b64url(GRANT_KEY), grant_issued_at: SERVER_TIME,
  grant_expires_at: '2026-10-04 12:00:00.000', pbkdf2_iterations: 1000, offline_allowed: true, ...over });
export const loginReply = (over = {}) => ({ ok: true, user: userJson(), session_ref: SESSION_REF, gate: null, release: release(), release_unavailable: null,
  revoked_grants: [], config: { ...CONFIG }, password_min_length: 12, server_time: SERVER_TIME, build: BUILD, csrf: 'csrf-2', ...over });
export const pinReply = (over = {}) => ({ ok: true, user: userJson({ has_pin: true }), session_ref: 'b'.repeat(64), gate: null, revoked_grants: [],
  server_time: SERVER_TIME, build: BUILD, csrf: 'csrf-3', ...over });
export const policyDoc = (over = {}) => ({ document_id: 5, version: '3', language: 'en', body: 'First paragraph.\n\nSecond paragraph.',
  fingerprint: 'f'.repeat(64), ...over });
/** GET api/auth/policy.php's answer. */
export const policyReply = (over = {}) => ({ required: true, document: policyDoc(), server_time: SERVER_TIME, ...over });
export const acceptReply = (over = {}) => ({ ok: true, gate: null, release: release(), release_unavailable: null, revoked_grants: [], server_time: SERVER_TIME,
  csrf: 'csrf-4', ...over });
export const passwordReply = (over = {}) => ({ ok: true, gate: null, release: release(), release_unavailable: null, revoked_grants: [],
  server_time: SERVER_TIME, csrf: 'csrf-5', ...over });
export const pinSetReply = (over = {}) => ({ ok: true, revoked_grants: [], server_time: SERVER_TIME, csrf: 'csrf-6', ...over });
export const logoutReply = (over = {}) => ({ ok: true, server_time: SERVER_TIME, csrf: 'csrf-7', ...over });
/** The heartbeat's 200 (50 §6.4), for the device hooks. */
export const heartbeatReply = (over = {}) => {
  const { organisation_name: _org, ...config } = CONFIG;
  return { status: 'ok', offline_enabled: true, label: 'E2E 1', site: { site_id: 3, name: 'Dev Site North' }, directive: null, revoked_grants: [],
    config, server_time: SERVER_TIME, build: BUILD, ...over };
};
/**
 * api/session.php's answer (50 §6.2) naming `user` (a userJson(), or null: nobody) and `session_ref` (null: no session).
 * @param {{user?: object|null, session_ref?: string|null}} [who]
 */
export function touchReply({ user = userJson(), session_ref = SESSION_REF } = {}) {
  return {
    csrf: 'csrf-t', organisation_name: CONFIG.organisation_name, server_time: SERVER_TIME, build: BUILD, dev_relax: false,
    user: user === null ? null : { user_id: user.user_id, username: user.username, display_name: user.display_name, role: user.role, capabilities: user.capabilities },
    session: session_ref === null ? null : { session_ref, device_id: DEVICE_ID, site_id: 3, auth_method: 'Password', idle_seconds_left: 1500, absolute_seconds_left: 40000 },
    gate: null,
  };
}

// ---- spies ----

/**
 * Every function of vault.js wrapped: pushes 'vault.<name>' onto log before the call; for deriveKek also
 * {rounds, saltLength} (never the password). The classes (VaultError) and constants pass through. The returned
 * module has a non-enumerable `seen`: [{name, args, result}] (deriveKek's password replaced by null).
 * @param {Array} log
 * @returns {object}
 */
export function spyVault(log) {
  const seen = [];
  const out = {};
  for (const [name, f] of Object.entries(vaultModule)) {
    if (typeof f !== 'function' || /^[A-Z]/.test(name)) { out[name] = f; continue; }
    out[name] = (...args) => {
      log.push('vault.' + name);
      if (name === 'deriveKek') log.push({ rounds: args[3], saltLength: args[2]?.length ?? null });
      const result = f(...args);
      seen.push({ name, args: name === 'deriveKek' ? [args[0], null, args[2], args[3]] : args, result });
      return result;
    };
  }
  Object.defineProperty(out, 'seen', { value: seen, enumerable: false });
  return out;
}

/**
 * db.js's adapter with put/delete/clear, and tx's t.put/t.delete/t.clear, logging 'db.<op> <store>' before the call.
 * @param {import('../../../public/station/js/db.js').Db} db
 * @param {Array} log
 */
export function spyDb(db, log) {
  const logged = (o) => {
    const w = { ...o };
    for (const op of ['put', 'delete', 'clear']) w[op] = (store, ...a) => { log.push(`db.${op} ${store}`); return o[op](store, ...a); };
    return w;
  };
  const out = logged(db);
  out.tx = (stores, mode, fn) => db.tx(stores, mode, (t) => fn(logged(t)));
  return out;
}

// ---- the harness ----

/**
 * A registered tablet (meta.device with a non-extractable proof key, site 3 Dev Site North, label E2E 1) over the real
 * S2 modules, and a real session. device.js's hooks reach the current session as app.js wires them.
 * @param {{iterations?: number, deviceId?: number, offline?: object, seed?: object, env?: object}} [options]
 *   seed: {meta: {key: value}, <store>: [rows]} written after meta.device and meta.seq; env: reuse a fakeEnv (and its
 *   IndexedDB: a new page life on the same tablet)
 */
export async function stationHarness({ iterations = 1000, deviceId = DEVICE_ID, offline, seed = {}, env: givenEnv } = {}) {
  const env = givenEnv ?? fakeEnv({ fetch: fakeFetch() });
  const f = env.fetch;
  const { idb, db } = await openMemoryDb({ idb: env.indexedDB });
  const proof = await importProofKey(globalThis.crypto, PROOF_RAW);
  await db.put('meta', { device_id: deviceId, credential: CREDENTIAL, proof, site_id: 3, site_name: 'Dev Site North', label: 'E2E 1', iterations,
    registered_at: SERVER_TIME }, 'device');
  if ((await db.get('meta', 'seq')) === undefined) await db.put('meta', { next: 1 }, 'seq');
  for (const [store, rows] of Object.entries(seed)) {
    if (store === 'meta') for (const [k, v] of Object.entries(rows)) await db.put('meta', v, k);
    else for (const row of rows) await db.put(store, row);
  }
  const clock = createClock({ env, db });
  await clock.load();
  const h = { env, f, db, idb, log: [], wipes: [], connectivity: [], banners: [] };
  const updater = { calls: [], begin(k) { updater.calls.push('begin ' + k); }, end(k) { updater.calls.push('end ' + k); } };
  let device = null;
  const api = createApi({ env, clock, device: () => device?.credentials() ?? null });
  api.setCsrf('csrf-1');
  // Every api.get/api.post call, recorded when it is made (a request reaches fake-fetch only after its proof's HMAC,
  // which takes a varying number of event-loop turns): a check that nothing was sent counts these, never a wait.
  const calls = [];
  for (const method of ['get', 'post']) {
    const real = api[method];
    api[method] = (path, ...rest) => {
      calls.push({ method: method.toUpperCase(), path, body: method === 'post' ? rest[0] : undefined });
      return real(path, ...rest);
    };
  }
  device = createDevice({
    env, db, api, clock,
    ui: { wipe: (m) => h.wipes.push(m.phase), erased: (m) => h.wipes.push('erased ' + m.final), banner: (k, on) => h.banners.push([k, on]) },
    inflight: updater,
    bootRepair: async () => 'repaired',
    hooks: {
      onKeysDropped: (reason) => h.session.lock('hard', reason),
      onRevokedGrants: (ids) => h.session.onRevokedGrants(ids),
      onOfflineDisabled: () => h.session.onOfflineDisabled(),
      onConfig: (config) => h.session.onConfig(config),
      onConnectivity: (state, code) => h.connectivity.push(['device', state, code ?? null]),
    },
  });
  await device.load();
  const vault = spyVault(h.log);
  const sdb = spyDb(db, h.log);
  const make = () => createSession({ env, db: sdb, api, clock, device, updater, vault, offline,
    onConnectivity: (state, code) => h.connectivity.push([state, code ?? null]) });
  Object.assign(h, {
    api, clock, device, updater, vault, sdb, session: make(),
    /** A new page life's session over the same database and modules (the old one is dropped). */
    newSession() { h.session = make(); return h.session; },
    /**
     * A JSON reply for `match` whose server_time is the tablet's time when it answers (so the clock learns offset 0).
     * @param {object|string} match fake-fetch's match ({url} or a URL ending)
     * @param {number} status
     * @param {object} body
     * @param {{times?: number, delayMs?: number, until?: Promise<unknown>}} [o] delayMs on env's timers; until: held
     */
    answer(match, status, body, { times = 1, delayMs = 0, until = null } = {}) {
      const m = typeof match === 'string' ? { url: match } : match;
      f.on(m, async () => {
        if (until) await until;
        if (delayMs > 0) await new Promise((resolve) => env.setTimeout(resolve, delayMs));
        const out = Object.hasOwn(body, 'server_time') || status >= 400 ? { ...body, server_time: formatDb(env.now()) } : body;
        return json(status, out);
      }, { times });
      return h;
    },
    /** An error envelope reply. */
    fail(match, status, code, message = '', extra = {}, o = {}) { return h.answer(match, status, errorBody(code, message, extra), o); },
    /** The requests sent to a URL ending (they arrive some turns after the call: wait with until(), never a fixed flush). */
    requests: (url) => f.requests.filter((r) => r.url.endsWith(url)),
    /** The api.get/api.post calls made for a path ({method, path, body}), recorded at once; every call without `path`. */
    calls: (path) => (path === undefined ? [...calls] : calls.filter((c) => c.path === path)),
    /** The committed rows of a store (values, key order). */
    rows: (store) => (idb._dump('pfpms')[store] ?? []).map(([, value]) => value),
    /** A committed meta value. */
    meta: (key) => (idb._dump('pfpms').meta ?? []).find(([k]) => k === key)?.[1],
    /** Sign in with a login reply (over merged into loginReply()); resolves the session's result. */
    async signIn(over = {}, { identifier = 'jdoe', password = PASSWORD } = {}) {
      h.answer('api/auth/login.php', 200, loginReply(over));
      return h.session.signIn(identifier, password);
    },
  });
  return h;
}

// ---- small helpers for the suites ----

/** A promise and its resolve(), to hold a reply. */
export function deferred() {
  let resolve;
  const promise = new Promise((r) => { resolve = r; });
  return { promise, resolve };
}

/**
 * Waits, turn by turn (setImmediate), until pred() holds; throws when it still does not after `ms` of real time (only a
 * bound: no virtual timer moves). Use it for every check on work that runs WebCrypto (a device proof's HMAC, a seal,
 * PBKDF2): that work ends on libuv's thread pool, so the number of turns it takes has no bound under load and a fixed
 * flush() fails at random.
 */
export async function until(pred, ms = 10000) {
  const end = Date.now() + ms;
  for (;;) {
    if (await pred()) return;
    if (Date.now() > end) throw new Error('until: the condition never held');
    await new Promise((resolve) => setImmediate(resolve));
  }
}

/** The vault keys {rec, pin} the DVK opens (a fresh copy of the bytes: openVault zeroes what it is given). */
export const vaultKeys = () => vaultModule.openVault(globalThis.crypto, Uint8Array.from(DVK));

/** A vault user record (50 §3.5 plus has_pin) for seeding, over userJson()'s person and release()'s grant. */
export const vaultUser = (over = {}) => {
  const u = userJson();
  const r = release();
  return { user_id: u.user_id, username: u.username, display_name: u.display_name, role: u.role, offline_caps: u.offline_caps, pin_switch: true,
    has_pin: false, grant_id: r.grant_id, grant_hmac_key: r.grant_hmac_key, grant_issued_at: r.grant_issued_at, grant_expires_at: r.grant_expires_at,
    last_password_at: SERVER_TIME, pin_verifier: null, pin_failed: 0, v: 1, ...over };
};

/** A vault user sealed as the session seals it ({k, iv, ct}). */
export async function sealVaultUser(vu) {
  const keys = await vaultKeys();
  return vaultModule.seal(globalThis.crypto, keys.rec, 'vault_users', 'user:' + vu.user_id, new TextEncoder().encode(JSON.stringify(vu)));
}

/** The stored vault user of uid, opened with the DVK; null when there is none. */
export async function openVaultUser(h, uid) {
  const rec = h.rows('vault_users').find((r) => r.k === 'user:' + uid);
  if (!rec) return null;
  const keys = await vaultKeys();
  return JSON.parse(new TextDecoder().decode(await vaultModule.open(globalThis.crypto, keys.rec, 'vault_users', rec.k, rec)));
}

/** The DVK the keyring entry of uid gives for `password` (rejects VaultError('wrong_password') otherwise). */
export async function openKeyring(h, uid, password, deviceId = DEVICE_ID) {
  const rec = h.rows('keyring').find((r) => r.user_id === uid);
  if (!rec) throw new Error('no keyring entry for ' + uid);
  const kek = await vaultModule.deriveKek(globalThis.crypto, password, vaultModule.b64urlDecode(rec.salt, 16), rec.iterations);
  return vaultModule.unwrapDvk(globalThis.crypto, kek, rec, deviceId, uid);
}

/**
 * update.js's REAL rule behind h.updater (which still records every begin and end): a worker waits and this page has
 * posted SKIP_WAITING (About's Update now, with nothing in flight). The rule's onControllerChange() then reloads once
 * no hold is left; onReload() runs at that reload (take a snapshot there). Returns the rule.
 * @param {object} h a stationHarness()
 * @param {() => void} onReload
 */
export async function waitingUpdate(h, onReload) {
  const registration = { waiting: { postMessage() {} }, addEventListener() {} };
  const rule = createUpdater({ env: h.env, registration, controlledAtLoad: true, onReload, conditions: {
    tabletState: () => 'REGISTERED', sessionState: () => h.session.state(), draftOpen: async () => false, syncInFlight: () => false,
    registrationPending: () => false } });
  const { begin, end } = h.updater;
  h.updater.begin = (k) => { begin(k); rule.begin(k); };
  h.updater.end = (k) => { end(k); rule.end(k); };
  if (!(await rule.updateNow())) throw new Error('waitingUpdate: SKIP_WAITING was not posted');
  return rule;
}

/** app.js's 15-s interval: clock.tick(), then session.tick(). Returns the interval id. */
export const appTicks = (h) => h.env.setInterval(() => { h.clock.tick(); void h.session.tick(); }, 15000);

/** Another person: userJson() with their id, username, email and name. */
export const personJson = (userId, username, displayName, over = {}) =>
  userJson({ user_id: userId, username, email: `${username}@example.test`, display_name: displayName, ...over });
