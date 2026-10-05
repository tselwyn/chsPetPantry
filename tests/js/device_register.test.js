// Registration (50-design §2.3, §6.3, X-3; S2 spec §3.16, §3.17): device.js's codeCheck, prepare and register over
// the real db.js, clock.js and api.js (memory IndexedDB, fake fetch, fake env), and app.js's device screen over
// fake-dom.js. The registration body is frozen at its first send and resent as it is while the server's replay
// window is open; only the discard codes make a new nonce and proof key.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeEnv, flush } from './support/fake-env.js';
import { fakeFetch } from './support/fake-fetch.js';
import { openMemoryDb } from './support/memory-db.js';
import { fakeDocument, text } from './support/fake-dom.js';
import { fakeLockManager } from './support/fake-locks.js';
import { createClock } from '../../public/station/js/clock.js';
import { createApi } from '../../public/station/js/api.js';
import { DbClosed } from '../../public/station/js/db.js';
import { fromBytes, format, normalise, qrPayload } from '../../public/station/js/registration_code.js';
import { b64url, b64urlDecode } from '../../public/station/js/vault.js';
import {
  REGISTER_RETRY_DELAYS_MS, REGISTER_TRIES, REPLAY_WINDOW_MS, RegistrationError, codeCheck, createDevice, registrationMessage,
} from '../../public/station/js/device.js';
import { createDeviceScreen, start } from '../../public/station/js/app.js';
import { mount, useDocument } from '../../public/station/js/dom.js';
import * as deviceView from '../../public/station/js/views/device.js';
import { COPY } from '../../public/station/js/copy.js';

const CODE = fromBytes(new Uint8Array([0x5a, 0x01, 0xc3, 0x77, 0x10, 0xfe, 0x42, 0x99, 0x0b, 0x6d]));
const CODE2 = fromBytes(new Uint8Array([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]));
const CRED = 'pfd1_' + 'Q'.repeat(43);
const SERVER_TIME = '2026-10-01 12:00:00.000';
const BODY_FIELDS = ['code', 'registration_nonce', 'proof_key', 'display_mode', 'storage_persisted', 'app_build', 'pbkdf2_iterations'];
const REGISTER = 'api/device/register.php';
const HEARTBEAT = 'api/device/heartbeat.php';

const registerReply = (over = {}) => ({ device_id: 7, site: { site_id: 3, name: 'Dev Site North' }, label: 'E2E 1', credential: CRED,
  pbkdf2_iterations: 600000, replayed: false, server_time: SERVER_TIME, build: 'test+0000000000', ...over });
const heartbeatReply = (over = {}) => ({ status: 'ok', offline_enabled: true, label: 'E2E 1', site: { site_id: 3, name: 'Dev Site North' },
  directive: null, revoked_grants: [], config: { session_idle_minutes: 15 }, server_time: SERVER_TIME, build: 'test+0000000000', ...over });

/** Waits (in real time, one task at a time) until pred() holds. */
async function until(pred, ms = 10000) {
  const end = Date.now() + ms;
  for (;;) {
    if (await pred()) return;
    if (Date.now() > end) throw new Error('until: the condition never held');
    await new Promise((resolve) => setImmediate(resolve));
  }
}
const settle = () => flush(60);

/** A WebCrypto whose exportKey results and deriveBits calls are recorded (Node's subtle needs its own this). */
function recordingCrypto() {
  const real = globalThis.crypto;
  const log = { exported: [], derived: [] };
  const subtle = new Proxy(real.subtle, {
    get(target, prop) {
      if (prop === 'exportKey') return async (...a) => { const r = await target.exportKey(...a); log.exported.push(r); return r; };
      if (prop === 'deriveBits') return async (alg, ...rest) => { log.derived.push(alg); return target.deriveBits(alg, ...rest); };
      const v = Reflect.get(target, prop, target);
      return typeof v === 'function' ? v.bind(target) : v;
    },
  });
  return { crypto: { subtle, getRandomValues: (a) => real.getRandomValues(a), randomUUID: () => real.randomUUID() }, log };
}

/** db.js's adapter, its calls recorded as [method, store, key] (and failNextWrite makes the next readwrite tx fail). */
function recordingDb(db) {
  const calls = [];
  const ctl = { calls, failNextWrite: false, hideSelftest: false };
  ctl.db = {
    ...db,
    get: async (s, k) => { calls.push(['get', s, k]); if (ctl.hideSelftest && k === 'selftest') return undefined; return db.get(s, k); },
    put: (s, v, k) => { calls.push(['put', s, k]); return db.put(s, v, k); },
    delete: (s, k) => { calls.push(['delete', s, k]); return db.delete(s, k); },
    tx: (stores, mode, fn) => {
      calls.push(['tx', stores.join(','), mode]);
      if (mode === 'readwrite' && ctl.failNextWrite) { ctl.failNextWrite = false; return Promise.reject(new DbClosed()); }
      return db.tx(stores, mode, fn);
    },
  };
  return ctl;
}

/**
 * A device over the real platform modules, with a heartbeat that answers 200 unless heartbeat is false. inflight(t)
 * may give update.js's begin/end itself (t: {f, env, idb}); by default they are recorded in `flights`.
 */
async function setup({ env: envOptions = {}, heartbeat = true, document = null, inflight = null } = {}) {
  const f = fakeFetch();
  const rc = recordingCrypto();
  const env = fakeEnv({ fetch: f, document, ...envOptions });
  env.crypto = rc.crypto;
  const persistedCalls = { n: 0 };
  const persisted = env.persisted;
  env.persisted = async () => { persistedCalls.n += 1; return persisted(); };
  const { idb, db } = await openMemoryDb({ idb: env.indexedDB });
  const rec = recordingDb(db);
  const clock = createClock({ env, db });
  await clock.load();
  let device = null;
  const api = createApi({ env, clock, device: () => device?.credentials() ?? null });
  api.setCsrf('csrf-token-1');
  const flights = [];
  const ui = { log: [], wipe: (m) => ui.log.push(['wipe', m]), erased: (m) => ui.log.push(['erased', m]), banner: (k, on) => ui.log.push(['banner', k, on]) };
  if (heartbeat) f.json({ method: 'POST', url: HEARTBEAT }, 200, heartbeatReply(), {}, { times: Infinity });
  const connectivity = [];
  device = createDevice({
    env, db: rec.db, api, clock, ui, bootRepair: async () => 'repaired', hooks: { onConnectivity: (state, code) => connectivity.push([state, code ?? null]) },
    inflight: inflight?.({ f, env, idb }) ?? { begin: (k) => flights.push(['begin', k, f.requests.length]), end: (k) => flights.push(['end', k, f.requests.length]) },
  });
  return { f, env, idb, db, rec, clock, api, device, ui, flights, crypto: rc.log, persistedCalls, connectivity };
}

const registerRequests = (f) => f.requests.filter((r) => r.url.endsWith(REGISTER));
const bodyOf = (r) => JSON.parse(r.body);
const rejectsWith = (p, key) => assert.rejects(p, (e) => e instanceof RegistrationError && e.key === key);

test('codeCheck: empty, partial, ok and mistyped, with and without the QR prefix', () => {
  const formatted = format(CODE);
  const wrongLast = CODE.slice(0, 16) + (CODE[16] === '0' ? '1' : '0');
  const cases = [
    ['', 'empty'], ['   ', 'empty'], ['---', 'empty'], ['PFPMS-DEVICE:1:', 'empty'], ['pfpms-device:1:', 'empty'],
    ['AB', 'partial'], [formatted.slice(0, 10), 'partial'], ['PFPMS-DEVICE:1:' + CODE.slice(0, 16), 'partial'],
    [CODE, 'ok'], [formatted, 'ok'], [formatted.toLowerCase(), 'ok'], [` ${formatted} `, 'ok'], [qrPayload(CODE), 'ok'],
    [wrongLast, 'mistyped'], [CODE + 'X', 'mistyped'], [qrPayload(wrongLast), 'mistyped'], ['PFPMS-DEVICE:1:' + wrongLast.toLowerCase(), 'mistyped'],
    [null, 'empty'], [undefined, 'empty'],
  ];
  for (const [input, want] of cases) assert.equal(codeCheck(input), want, JSON.stringify(input));
});

test('a mistyped code is refused before any request', async () => {
  const t = await setup();
  const wrongLast = CODE.slice(0, 16) + (CODE[16] === '0' ? '1' : '0');
  for (const typed of [wrongLast, 'ABC', '']) await rejectsWith(t.device.register(typed), 'reg_mistyped');
  assert.equal(t.f.requests.length, 0);
  assert.deepEqual(t.flights, []);
  assert.equal(t.crypto.derived.length, 0, 'not even a preparation');
  assert.equal(t.device.registrationPending(), false);
});

test('prepare runs the self-test, calibrates, and makes a 32-byte proof key and a 16-byte nonce, and does not ask for persistence', async () => {
  const t = await setup();
  assert.deepEqual(await t.device.prepare(), { ok: true });
  // The self-test: a key stored at meta.selftest, read back, deleted.
  const self = t.rec.calls.filter((c) => c[2] === 'selftest').map((c) => c[0]);
  assert.deepEqual(self, ['put', 'get', 'delete']);
  assert.equal(await t.db.get('meta', 'selftest'), undefined);
  // Calibration: 100 000 PBKDF2 rounds, timed with env.mono() (the fake's mono does not move: 2 000 000 rounds).
  assert.equal(t.crypto.derived.length, 1);
  assert.equal(t.crypto.derived[0].name, 'PBKDF2');
  assert.equal(t.crypto.derived[0].iterations, 100000);
  // The proof key: 32 raw bytes exported once.
  assert.equal(t.crypto.exported.length, 1);
  assert.equal(t.crypto.exported[0].byteLength, 32);
  // No persistence question at mount.
  assert.equal(t.env.persistCalls, 0);
  assert.equal(t.persistedCalls.n, 0);
  // Memoised: a second prepare does nothing new.
  assert.deepEqual(await t.device.prepare(), { ok: true });
  assert.equal(t.crypto.derived.length, 1);
  assert.equal(t.crypto.exported.length, 1);
  // What it made goes into the body.
  t.f.json({ url: REGISTER }, 200, registerReply());
  await t.device.register(CODE);
  const body = bodyOf(registerRequests(t.f)[0]);
  assert.equal(b64urlDecode(body.proof_key, 32).length, 32);
  assert.equal(body.proof_key.length, 43);
  assert.equal(b64urlDecode(body.registration_nonce, 16).length, 16);
  assert.equal(body.registration_nonce.length, 22);
  assert.equal(body.pbkdf2_iterations, 2000000);
  assert.equal(t.crypto.derived.length, 1, 'the press used the preparation');
  await settle();
});

test('storage_persisted and display_mode are taken at the press', async () => {
  const doc = fakeDocument();
  useDocument(doc);
  const t = await setup({ env: { persisted: false, persistResult: false, displayMode: 'browser' }, document: doc });
  const root = doc.getElementById('app');
  const screen = createDeviceScreen({ env: t.env, device: t.device, root, mountView: (m, a) => mount(root, deviceView.render(m, a)), onContinue: () => {} });
  await screen.show();
  assert.equal(screen.model().phase, 'ready');
  // The tab became the installed app (persisted) between the mount and the press.
  t.env.set('persisted', true);
  t.env.set('displayMode', 'standalone');
  const input = root.querySelector('#code');
  input.value = format(CODE);
  input.dispatch('input');
  t.f.json({ url: REGISTER }, 200, registerReply());
  root.querySelector('button[type="submit"]').dispatch('click');
  await until(() => screen.model().phase === 'registered');
  const body = bodyOf(registerRequests(t.f)[0]);
  assert.equal(body.storage_persisted, true);
  assert.equal(body.display_mode, 'standalone');
  assert.equal(t.env.persistCalls, 0, 'already persisted: nothing to ask');
  assert.equal(screen.model().storageRefused, false);
  assert.ok(!text(root).includes(COPY.reg_storage_refused), 'no storage warning');
  assert.ok(text(root).includes('Registered to Dev Site North as E2E 1.'));
  await settle();
});

test('persist() is asked at the press when persisted() is false', async () => {
  for (const granted of [true, false]) {
    const t = await setup({ env: { persisted: false, persistResult: granted } });
    await t.device.prepare();
    assert.equal(t.env.persistCalls, 0);
    t.f.json({ url: REGISTER }, 200, registerReply());
    const r = await t.device.register(CODE);
    assert.equal(t.env.persistCalls, 1, `granted ${granted}`);
    assert.equal(bodyOf(registerRequests(t.f)[0]).storage_persisted, granted);
    assert.equal(r.storagePersisted, granted);
    await settle();
  }
});

test('a failed self-test blocks registration', async () => {
  const doc = fakeDocument();
  useDocument(doc);
  const t = await setup({ document: doc });
  t.rec.hideSelftest = true; // the stored key does not come back
  assert.deepEqual(await t.device.prepare(), { ok: false, reason: 'selftest' });
  await rejectsWith(t.device.register(CODE), 'reg_selftest_failed');
  assert.equal(t.f.requests.length, 0);
  assert.equal(t.crypto.derived.length, 0, 'no calibration after a failed self-test');
  const root = doc.getElementById('app');
  const screen = createDeviceScreen({ env: t.env, device: t.device, root, mountView: (m, a) => mount(root, deviceView.render(m, a)), onContinue: () => {} });
  await screen.show();
  assert.equal(screen.model().phase, 'blocked');
  assert.equal(root.querySelector('form'), null);
  assert.ok(text(root).includes(COPY.reg_selftest_failed));
});

test('the body has exactly the §6.3 fields in order', async () => {
  const t = await setup({ env: { displayMode: 'standalone', shellBuild: '0.1.0-dev+abcdef0123' } });
  t.f.json({ url: REGISTER }, 200, registerReply());
  await t.device.register(' ' + format(CODE).toLowerCase() + ' ');
  const body = bodyOf(registerRequests(t.f)[0]);
  assert.deepEqual(Object.keys(body), BODY_FIELDS);
  assert.equal(body.code, CODE, 'the canonical 17 symbols');
  assert.equal(body.display_mode, 'standalone');
  assert.equal(body.storage_persisted, true);
  assert.equal(body.app_build, '0.1.0-dev+abcdef0123');
  assert.ok(Number.isInteger(body.pbkdf2_iterations) && body.pbkdf2_iterations % 10000 === 0);
  assert.ok(body.pbkdf2_iterations >= 100000 && body.pbkdf2_iterations <= 2000000);
  await settle();
});

test('the POST sends Content-Type, X-CSRF-Token and the session credentials, and no Authorization', async () => {
  const t = await setup();
  t.f.json({ url: REGISTER }, 200, registerReply());
  await t.device.register(CODE);
  const r = registerRequests(t.f)[0];
  assert.equal(r.method, 'POST');
  assert.equal(r.url, '../' + REGISTER);
  assert.equal(r.headers['content-type'], 'application/json');
  assert.equal(r.headers['x-csrf-token'], 'csrf-token-1');
  assert.equal(r.headers['x-pfpms-client'], 'station');
  assert.equal(r.credentials, 'same-origin');
  assert.equal('authorization' in r.headers, false);
  assert.equal('pfpms-proof' in r.headers, false);
  await settle();
});

test('a lost answer is resent with the same body 3 times, then reg_no_answer', async () => {
  assert.equal(REGISTER_TRIES, 3);
  assert.deepEqual(REGISTER_RETRY_DELAYS_MS, [1000, 3000]);
  const t = await setup();
  t.f.offline({ url: REGISTER }, { times: 3 });
  let outcome = null;
  const p = t.device.register(CODE).then(() => { outcome = 'ok'; }, (e) => { outcome = e; });
  await until(() => registerRequests(t.f).length === 1);
  await settle();
  await t.env.advance(999);
  await settle();
  assert.equal(registerRequests(t.f).length, 1, 'the second try waits 1 s');
  await t.env.advance(1);
  await until(() => registerRequests(t.f).length === 2);
  await settle();
  await t.env.advance(2999);
  await settle();
  assert.equal(registerRequests(t.f).length, 2, 'the third waits 3 s');
  await t.env.advance(1);
  await until(() => outcome !== null);
  await p;
  assert.ok(outcome instanceof RegistrationError && outcome.key === 'reg_no_answer');
  const bodies = registerRequests(t.f).map((r) => r.body);
  assert.equal(bodies.length, 3);
  assert.equal(new Set(bodies).size, 1, 'the same body string every time');
  assert.equal(t.device.registrationPending(), true, 'kept for the next press');
  assert.equal(t.device.registered(), false);
});

test('the next press within 14 minutes resends the same body', async () => {
  const t = await setup();
  t.f.offline({ url: REGISTER }, { times: 3 });
  const p = t.device.register(CODE).catch((e) => e);
  await until(() => registerRequests(t.f).length === 1);
  await settle();
  await t.env.advance(1000);
  await until(() => registerRequests(t.f).length === 2);
  await settle();
  await t.env.advance(3000);
  assert.equal((await p).key, 'reg_no_answer');
  await t.env.advance(REPLAY_WINDOW_MS - 5000);
  assert.equal(t.device.registrationPending(), true);
  t.f.json({ url: REGISTER }, 200, registerReply({ replayed: true }));
  const r = await t.device.register(format(CODE)); // the same code, typed differently
  assert.equal(r.replayed, true);
  const bodies = registerRequests(t.f).map((x) => x.body);
  assert.equal(bodies.length, 4);
  assert.equal(new Set(bodies).size, 1, 'the frozen body, persistence answer included');
  assert.equal(t.crypto.derived.length, 1, 'no new preparation');
  await settle();
});

test('another code or a press after 14 minutes makes a new nonce and proof key', async () => {
  for (const variant of ['another code', 'after 14 minutes']) {
    const t = await setup();
    t.f.offline({ url: REGISTER }, { times: 3 });
    const p = t.device.register(CODE).catch((e) => e);
    await until(() => registerRequests(t.f).length === 1);
    await settle();
    await t.env.advance(1000);
    await until(() => registerRequests(t.f).length === 2);
    await settle();
    await t.env.advance(3000);
    assert.equal((await p).key, 'reg_no_answer');
    const first = bodyOf(registerRequests(t.f)[0]);
    if (variant === 'after 14 minutes') {
      await t.env.advance(REPLAY_WINDOW_MS + 1);
      assert.equal(t.device.registrationPending(), false);
    }
    t.f.json({ url: REGISTER }, 200, registerReply());
    await t.device.register(variant === 'another code' ? CODE2 : CODE);
    const second = bodyOf(registerRequests(t.f).at(-1));
    assert.notEqual(second.registration_nonce, first.registration_nonce, variant);
    assert.notEqual(second.proof_key, first.proof_key, variant);
    assert.equal(second.code, variant === 'another code' ? CODE2 : CODE);
    assert.equal(t.crypto.derived.length, 2, `${variant}: a new preparation`);
    await settle();
  }
});

test('replayed: true is a success', async () => {
  const t = await setup();
  t.f.json({ url: REGISTER }, 200, registerReply({ replayed: true }));
  const r = await t.device.register(CODE);
  assert.deepEqual(r, { site: 'Dev Site North', label: 'E2E 1', replayed: true, storagePersisted: true });
  assert.equal(t.device.registered(), true);
  assert.equal(t.device.registrationPending(), false);
  assert.equal((await t.db.get('meta', 'device')).credential, CRED);
  await settle();
});

test('on 200 meta.device holds the credential and a non-extractable proof key, meta.seq is {next: 1}, and the raw proof key is zeroed', async () => {
  const t = await setup();
  t.f.json({ url: REGISTER }, 200, registerReply());
  const r = await t.device.register(CODE);
  assert.deepEqual(r, { site: 'Dev Site North', label: 'E2E 1', replayed: false, storagePersisted: true });
  const d = await t.db.get('meta', 'device');
  assert.deepEqual(Object.keys(d).sort(), ['credential', 'device_id', 'iterations', 'label', 'proof', 'registered_at', 'site_id', 'site_name']);
  assert.equal(d.device_id, 7);
  assert.equal(d.credential, CRED);
  assert.equal(d.site_id, 3);
  assert.equal(d.site_name, 'Dev Site North');
  assert.equal(d.label, 'E2E 1');
  assert.equal(d.iterations, 600000);
  assert.equal(d.registered_at, SERVER_TIME);
  assert.equal(d.proof.type, 'secret');
  assert.equal(d.proof.extractable, false);
  assert.deepEqual(d.proof.usages, ['sign']);
  assert.equal(d.proof.algorithm.name, 'HMAC');
  assert.equal(d.proof.algorithm.length, 256);
  await assert.rejects(globalThis.crypto.subtle.exportKey('raw', d.proof), { name: 'InvalidAccessError' });
  assert.deepEqual(await t.db.get('meta', 'seq'), { next: 1 });
  const raw = new Uint8Array(t.crypto.exported[0]);
  assert.equal(raw.length, 32);
  assert.ok(raw.every((b) => b === 0), 'the raw proof key bytes are zeroed');
  // An existing meta.seq is left alone (a replayed registration after a failed local write).
  const u = await setup();
  await u.db.put('meta', { next: 42 }, 'seq');
  u.f.json({ url: REGISTER }, 200, registerReply());
  await u.device.register(CODE);
  assert.deepEqual(await u.db.get('meta', 'seq'), { next: 42 });
  // The device and its public part.
  assert.deepEqual(t.device.info(), { device_id: 7, site_id: 3, site_name: 'Dev Site North', label: 'E2E 1', iterations: 600000, registered_at: SERVER_TIME });
  assert.equal(t.device.credentials().credential, CRED);
  await settle();
});

test('the key the server received signs exactly like the stored key', async () => {
  const t = await setup();
  t.f.json({ url: REGISTER }, 200, registerReply());
  await t.device.register(CODE);
  const sent = b64urlDecode(bodyOf(registerRequests(t.f)[0]).proof_key, 32);
  const serverKey = await globalThis.crypto.subtle.importKey('raw', sent, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const stored = (await t.db.get('meta', 'device')).proof;
  const msg = new TextEncoder().encode('pfpms/v1/proof\nPOST\napi/device/heartbeat.php\n1790856000000\n' + '0'.repeat(64));
  const a = b64url(await globalThis.crypto.subtle.sign('HMAC', serverKey, msg));
  const b = b64url(await globalThis.crypto.subtle.sign('HMAC', stored, msg));
  assert.equal(a, b);
  await settle();
});

test('the credential is stored only in meta.device', async () => {
  const t = await setup();
  t.f.json({ url: REGISTER }, 200, registerReply());
  await t.device.register(CODE);
  await until(() => t.f.requests.some((r) => r.url.endsWith(HEARTBEAT)));
  await settle();
  const dump = t.idb._dump('pfpms');
  const hits = [];
  for (const [store, rows] of Object.entries(dump)) {
    for (const [key, value] of rows) if (JSON.stringify(value).includes('pfd1_')) hits.push(`${store}:${key}`);
  }
  assert.deepEqual(hits, ['meta:device']);
  assert.equal(JSON.stringify(dump).match(/pfd1_/g).length, 1);
});

test('each error code gives its message, and only the discard codes drop the prepared body', async () => {
  const cases = [
    ['code_mistyped', 422, 'reg_mistyped', true], ['not_installed', 409, 'reg_not_installed', true], ['code_invalid', 422, 'reg_invalid', true],
    ['busy', 409, 'reg_busy', false], ['rate_limited', 429, 'reg_rate_limited', false], ['bad_request', 400, 'reg_bad_request', true],
    ['bad_json', 400, 'reg_bad_request', true], ['unsupported_media_type', 415, 'reg_bad_request', true], ['server_error', 500, 'server_error', false],
    ['something_new', 400, 'reg_failed', false], ['maintenance', 503, 'reg_maintenance', false],
  ];
  for (const [code, status, key, discard] of cases) {
    const t = await setup();
    t.f.json({ url: REGISTER }, status, { error: code, message: 'Server text.', incident: 'INC-1' });
    let err = null;
    try { await t.device.register(CODE); } catch (e) { err = e; }
    assert.ok(err instanceof RegistrationError, code);
    assert.equal(err.key, key, code);
    assert.equal(err.incident, code === 'server_error' ? 'INC-1' : null, `${code}: incident`);
    assert.equal(registerRequests(t.f).length, 1, `${code}: no automatic retry`);
    assert.equal(t.device.registrationPending(), !discard, `${code}: pending`);
    t.f.json({ url: REGISTER }, 200, registerReply());
    await t.device.register(CODE);
    const [a, b] = registerRequests(t.f).map(bodyOf);
    assert.equal(a.registration_nonce !== b.registration_nonce, discard, `${code}: a new nonce only when discarded`);
    assert.equal(a.proof_key !== b.proof_key, discard, `${code}: a new proof key only when discarded`);
    await settle();
  }
  // csrf_failed after api.js's own refresh and retry: reg_failed, kept.
  const t = await setup();
  t.f.json({ url: REGISTER }, 403, { error: 'csrf_failed', message: 'x' }, {}, { times: 2 });
  t.f.json({ url: 'api/session.php' }, 200, { csrf: 'csrf-token-2', server_time: SERVER_TIME });
  await rejectsWith(t.device.register(CODE), 'reg_failed');
  assert.equal(registerRequests(t.f).length, 2);
  assert.equal(registerRequests(t.f)[1].headers['x-csrf-token'], 'csrf-token-2');
  assert.equal(t.device.registrationPending(), true);
  // The table itself.
  assert.deepEqual(registrationMessage(new TypeError('x')), { key: 'reg_failed', params: {}, discard: false, incident: null });
});

test('a failed meta write keeps the attempt', async () => {
  const t = await setup();
  t.f.json({ url: REGISTER }, 200, registerReply());
  t.rec.failNextWrite = true;
  await rejectsWith(t.device.register(CODE), 'reg_failed');
  assert.equal(t.device.registered(), false);
  assert.equal(t.device.registrationPending(), true);
  assert.equal(await t.db.get('meta', 'device'), undefined);
  t.f.json({ url: REGISTER }, 200, registerReply({ replayed: true }));
  const r = await t.device.register(CODE);
  assert.equal(r.replayed, true);
  const [a, b] = registerRequests(t.f).map((x) => x.body);
  assert.equal(a, b, 'the replay resends the same body');
  assert.equal((await t.db.get('meta', 'device')).credential, CRED);
  assert.equal(t.device.registrationPending(), false);
  await settle();
});

test('registrationPending is true from the first send until success, a discard or 14 minutes', async () => {
  const t = await setup();
  assert.equal(t.device.registrationPending(), false);
  const seen = [];
  t.f.on({ url: REGISTER }, () => { seen.push(t.device.registrationPending()); return 'offline'; }, { times: 3 });
  const p = t.device.register(CODE).catch((e) => e);
  await until(() => registerRequests(t.f).length === 1);
  await settle();
  await t.env.advance(1000);
  await until(() => registerRequests(t.f).length === 2);
  await settle();
  await t.env.advance(3000);
  await p;
  assert.deepEqual(seen, [true, true, true], 'true while the request is out');
  assert.equal(t.device.registrationPending(), true);
  await t.env.advance(REPLAY_WINDOW_MS - 4000);
  assert.equal(t.device.registrationPending(), true, 'exactly 14 minutes after the first send');
  await t.env.advance(1);
  assert.equal(t.device.registrationPending(), false, 'past 14 minutes');
  // Until success.
  const s = await setup();
  s.f.offline({ url: REGISTER }, { times: 3 });
  const q = s.device.register(CODE).catch((e) => e);
  await until(() => registerRequests(s.f).length === 1);
  await settle();
  await s.env.advance(1000);
  await until(() => registerRequests(s.f).length === 2);
  await settle();
  await s.env.advance(3000);
  await q;
  assert.equal(s.device.registrationPending(), true);
  s.f.json({ url: REGISTER }, 200, registerReply({ replayed: true }));
  await s.device.register(CODE);
  assert.equal(s.device.registrationPending(), false, 'after success');
  // Until a discard.
  const d = await setup();
  d.f.json({ url: REGISTER }, 422, { error: 'code_invalid', message: 'x' });
  await rejectsWith(d.device.register(CODE), 'reg_invalid');
  assert.equal(d.device.registrationPending(), false, 'after a discard');
  await settle();
});

test('the first heartbeat follows the registration', async () => {
  const t = await setup();
  t.f.json({ url: REGISTER }, 200, registerReply());
  await t.device.register(CODE);
  await until(() => t.f.requests.some((r) => r.url.endsWith(HEARTBEAT)));
  const order = t.f.requests.map((r) => r.url.replace('../', ''));
  assert.deepEqual(order, [REGISTER, HEARTBEAT]);
  const h = t.f.requests[1];
  assert.equal(h.method, 'POST');
  assert.equal(h.headers.authorization, 'PFPMS-Device ' + CRED);
  assert.match(h.headers['pfpms-proof'], /^v1 \d{13} [A-Za-z0-9_-]{43}$/);
  assert.equal(h.credentials, 'omit');
  await until(async () => (await t.db.get('meta', 'last_heartbeat_at')) !== undefined);
});

test('the POST runs between inflight begin and end', async () => {
  const t = await setup();
  t.f.json({ url: REGISTER }, 200, registerReply());
  await t.device.register(CODE);
  assert.deepEqual(t.flights, [['begin', 'register', 0], ['end', 'register', 1]]);
  // The retries run inside one flight; an error ends it too.
  const u = await setup();
  u.f.offline({ url: REGISTER });
  u.f.json({ url: REGISTER }, 409, { error: 'busy', message: 'x' });
  const p = u.device.register(CODE).catch((e) => e);
  await until(() => registerRequests(u.f).length === 1);
  await settle();
  assert.deepEqual(u.flights, [['begin', 'register', 0]], 'still in flight during the wait');
  await u.env.advance(1000);
  assert.equal((await p).key, 'reg_busy');
  assert.deepEqual(u.flights, [['begin', 'register', 0], ['end', 'register', 2]]);
  await settle();
});

test('the register flight ends only once meta.device is written, or when the press fails', async () => {
  // A reload that waits for the flight (update.js) must never run between a 200 and the write of meta.device.
  const ends = [];
  const t = await setup({ inflight: ({ idb }) => ({ begin() {}, end: () => ends.push(Object.fromEntries(idb._dump('pfpms').meta).device?.credential ?? null) }) });
  t.f.json({ url: REGISTER }, 200, registerReply());
  await t.device.register(CODE);
  assert.deepEqual(ends, [CRED], 'the flight ended after the credential was stored');
  // A failed write ends it too (the body is kept for the replay).
  const u = await setup();
  u.f.json({ url: REGISTER }, 200, registerReply());
  u.rec.failNextWrite = true;
  await rejectsWith(u.device.register(CODE), 'reg_failed');
  assert.deepEqual(u.flights.map((x) => x[0]), ['begin', 'end']);
  assert.equal(u.device.registrationPending(), true);
  await settle();
});

test('the replay window is measured on the monotonic clock: a wall-clock sync after a lost answer keeps the body', async () => {
  const lostAnswer = async (t) => {
    t.f.offline({ url: REGISTER }, { times: 3 });
    const p = t.device.register(CODE).catch((e) => e);
    await until(() => registerRequests(t.f).length === 1);
    await settle();
    await t.env.advance(1000);
    await until(() => registerRequests(t.f).length === 2);
    await settle();
    await t.env.advance(3000);
    assert.equal((await p).key, 'reg_no_answer');
  };
  // A fresh tablet's clock syncs an hour forward just after it joins the Wi-Fi.
  const t = await setup();
  await lostAnswer(t);
  t.env.jumpWall(60 * 60 * 1000);
  assert.equal(t.device.registrationPending(), true, 'seconds passed, not an hour');
  t.f.json({ url: REGISTER }, 200, registerReply({ replayed: true }));
  const r = await t.device.register(CODE);
  assert.equal(r.replayed, true);
  assert.equal(new Set(registerRequests(t.f).map((x) => x.body)).size, 1, 'the same body: the sheet is not burned');
  assert.equal(t.crypto.derived.length, 1, 'no new preparation');
  // A clock set back does not stretch the window either.
  const u = await setup();
  await lostAnswer(u);
  u.env.jumpWall(-60 * 60 * 1000);
  await u.env.advance(REPLAY_WINDOW_MS + 1);
  assert.equal(u.device.registrationPending(), false);
  await settle();
});

test('the press tells the chip: no answer is offline, any answer is online', async () => {
  const t = await setup();
  t.f.offline({ url: REGISTER }, { times: 3 });
  const p = t.device.register(CODE).catch((e) => e);
  await until(() => registerRequests(t.f).length === 1);
  await settle();
  await t.env.advance(1000);
  await until(() => registerRequests(t.f).length === 2);
  await settle();
  await t.env.advance(3000);
  assert.equal((await p).key, 'reg_no_answer');
  assert.deepEqual(t.connectivity, [['offline', 'network'], ['offline', 'network'], ['offline', 'network']]);
  t.f.json({ url: REGISTER }, 409, { error: 'busy', message: 'x' });
  await rejectsWith(t.device.register(CODE), 'reg_busy');
  assert.deepEqual(t.connectivity.at(-1), ['online', null]);
  t.f.json({ url: REGISTER }, 503, { error: 'maintenance', message: 'x' });
  await rejectsWith(t.device.register(CODE), 'reg_maintenance');
  assert.equal(t.connectivity.at(-1)[0], 'offline');
  t.f.json({ url: REGISTER }, 200, registerReply({ replayed: true }));
  await t.device.register(CODE);
  assert.ok(t.connectivity.some((c, i) => i > 3 && c[0] === 'online'));
  await settle();
});

// ---- the device screen (app.js createDeviceScreen) over fake-dom ----

/** The device screen over a real device, mounted into a fake document's #app. */
async function screenSetup(envOptions = {}) {
  const doc = fakeDocument();
  useDocument(doc);
  const t = await setup({ env: envOptions, document: doc });
  const root = doc.getElementById('app');
  let continued = 0;
  const screen = createDeviceScreen({ env: t.env, device: t.device, root, mountView: (m, a) => mount(root, deviceView.render(m, a)),
    onContinue: () => { continued += 1; }, scanMs: 250 });
  return { ...t, doc, root, screen, continued: () => continued };
}
const typeInto = (input, value) => { input.value = value; input.dispatch('input'); };
const allTracksStopped = (env) => env.streams.length > 0 && env.streams.every((s) => s.getTracks().every((track) => track.stopped));

test('mounting runs prepare and shows Getting this tablet ready… then the form', async () => {
  const s = await screenSetup();
  const shown = s.screen.show();
  assert.ok(text(s.root).includes(COPY.getting_ready), 'drawn at once');
  assert.equal(s.root.querySelector('form'), null);
  assert.equal(s.screen.model().phase, 'preparing');
  await shown;
  assert.equal(s.screen.model().phase, 'ready');
  assert.ok(s.root.querySelector('form'));
  assert.ok(!text(s.root).includes(COPY.getting_ready));
  assert.equal(s.crypto.derived.length, 1, 'prepare calibrated');
  assert.equal(s.root.querySelector('[data-scan]'), null, 'no BarcodeDetector: typing only');
  assert.equal(s.doc.activeElement, s.root.querySelector('#code'), 'the input has the focus');
  assert.equal(s.f.requests.length, 0);
});

test('typing updates the check line and the button and keeps the same input node', async () => {
  const s = await screenSetup();
  await s.screen.show();
  const input = s.root.querySelector('#code');
  const line = () => s.root.querySelector('#code-check');
  const button = () => s.root.querySelector('button[type="submit"]');
  typeInto(input, 'ABCD-');
  assert.equal(text(line()), COPY.code_hint);
  assert.equal(button().disabled, true);
  typeInto(input, format(CODE));
  assert.equal(text(line()), COPY.code_ok);
  assert.equal(line().getAttribute('class'), 'message message-ok');
  assert.equal(button().disabled, false);
  typeInto(input, CODE.slice(0, 16) + (CODE[16] === '0' ? '1' : '0'));
  assert.equal(text(line()), COPY.reg_mistyped);
  assert.equal(button().disabled, true);
  typeInto(input, '');
  assert.equal(text(line()), COPY.code_hint);
  assert.equal(s.root.querySelector('#code'), input, 'the input was never re-mounted');
  assert.equal(s.doc.activeElement, input);
  assert.equal(s.screen.model().code, '');
  assert.equal(s.f.requests.length, 0, 'the check makes no request');
});

test('a scanned QR fills the formatted code, stops every track and focuses Register', async () => {
  const s = await screenSetup({ barcodeDetector: { results: [[], [qrPayload(CODE)]] } });
  await s.screen.show();
  const scan = s.root.querySelector('[data-scan]');
  assert.equal(text(scan), COPY.scan_button);
  scan.dispatch('click');
  await until(() => s.screen.model().phase === 'scanning');
  const video = s.root.querySelector('video.scanner');
  assert.equal(video.srcObject, s.env.streams[0]);
  assert.equal(text(s.root.querySelector('[data-scan]')), COPY.scan_stop);
  const detector = await s.env.barcodeDetector();
  await s.env.advance(250);
  assert.equal(detector.detectCalls, 1);
  assert.equal(detector.sources[0], video, 'detect() looks at the video');
  assert.equal(s.screen.model().phase, 'scanning', 'nothing found yet');
  await s.env.advance(250);
  await until(() => s.screen.model().phase === 'ready');
  assert.equal(detector.detectCalls, 2);
  assert.equal(s.root.querySelector('#code').value, format(CODE));
  assert.equal(s.screen.model().check, 'ok');
  assert.ok(allTracksStopped(s.env), 'every track stopped');
  assert.equal(s.root.querySelector('video'), null);
  const register = s.root.querySelector('button[type="submit"]');
  assert.equal(register.disabled, false);
  assert.equal(s.doc.activeElement, register, 'Register has the focus');
  await s.env.advance(1000);
  assert.equal(detector.detectCalls, 2, 'the scan loop stopped');
  assert.equal(s.env.pendingTimers(), 0);
});

test('a raw value that is not a valid code is ignored and scanning goes on', async () => {
  const wrong = qrPayload(CODE.slice(0, 16) + (CODE[16] === '0' ? '1' : '0'));
  const s = await screenSetup({ barcodeDetector: { results: [['hello', wrong, 'https://example.org/'], [qrPayload(CODE2)]] } });
  await s.screen.show();
  s.root.querySelector('[data-scan]').dispatch('click');
  await until(() => s.screen.model().phase === 'scanning');
  await s.env.advance(250);
  assert.equal(s.screen.model().phase, 'scanning');
  assert.equal(s.root.querySelector('#code').value, '');
  assert.ok(!allTracksStopped(s.env));
  await s.env.advance(250);
  await until(() => s.screen.model().phase === 'ready');
  assert.equal(s.root.querySelector('#code').value, format(CODE2));
  assert.ok(allTracksStopped(s.env));
});

test('a refused camera shows camera_refused and typing still works', async () => {
  const s = await screenSetup({ barcodeDetector: { results: [] }, camera: 'refused' });
  await s.screen.show();
  s.root.querySelector('[data-scan]').dispatch('click');
  await until(() => text(s.root).includes(COPY.camera_refused));
  assert.equal(s.screen.model().phase, 'ready');
  assert.equal(s.root.querySelector('video'), null);
  const input = s.root.querySelector('#code');
  typeInto(input, format(CODE));
  assert.equal(text(s.root.querySelector('#code-check')), COPY.code_ok);
  assert.equal(s.root.querySelector('button[type="submit"]').disabled, false);
  s.f.json({ url: REGISTER }, 200, registerReply());
  s.root.querySelector('button[type="submit"]').dispatch('click');
  await until(() => s.screen.model().phase === 'registered');
  await settle();
});

test('Stop scanning, leaving the view and hidden stop every track', async () => {
  // Stop scanning.
  let s = await screenSetup({ barcodeDetector: { results: [] } });
  await s.screen.show();
  s.root.querySelector('[data-scan]').dispatch('click');
  await until(() => s.screen.model().phase === 'scanning');
  s.root.querySelector('[data-scan]').dispatch('click'); // Stop scanning
  assert.ok(allTracksStopped(s.env));
  assert.equal(s.screen.model().phase, 'ready');
  assert.equal(s.screen.model().stream, null);
  assert.equal(s.root.querySelector('video'), null);
  assert.equal(s.env.pendingTimers(), 0, 'the scan loop is cleared');
  // Leaving the view.
  s = await screenSetup({ barcodeDetector: { results: [] } });
  await s.screen.show();
  s.root.querySelector('[data-scan]').dispatch('click');
  await until(() => s.screen.model().phase === 'scanning');
  s.screen.stop();
  assert.ok(allTracksStopped(s.env));
  assert.equal(s.env.pendingTimers(), 0);
  // Hidden, through app.js's listener: the camera stops and the view goes back to ready.
  const doc = fakeDocument();
  const f = fakeFetch();
  f.json({ url: 'api/session.php' }, 200, { csrf: 'c', organisation_name: 'CHS Pet Pantry', server_time: SERVER_TIME, build: 'test+0000000000', dev_relax: false });
  const env = fakeEnv({ document: doc, fetch: f, locks: fakeLockManager(), barcodeDetector: { results: [] } });
  await start({ registration: null, controlledAtLoad: false, started: () => true, repair: async () => 'repaired' }, { env });
  const root = doc.getElementById('app');
  await until(() => root.querySelector('[data-scan]') !== null);
  root.querySelector('[data-scan]').dispatch('click');
  await until(() => root.querySelector('video.scanner') !== null);
  env.fire('hidden');
  assert.ok(allTracksStopped(env), 'hidden stops the camera');
  assert.equal(root.querySelector('video'), null);
  assert.equal(text(root.querySelector('[data-scan]')), COPY.scan_button);
});

test('a RegistrationError shows its message and its incident number', async () => {
  const s = await screenSetup();
  await s.screen.show();
  typeInto(s.root.querySelector('#code'), format(CODE));
  s.f.json({ url: REGISTER }, 500, { error: 'server_error', message: 'x', incident: 'A1B2C3' });
  s.root.querySelector('button[type="submit"]').dispatch('click');
  assert.equal(s.screen.model().phase, 'sending');
  assert.equal(text(s.root.querySelector('button[type="submit"]')), COPY.registering);
  await until(() => s.screen.model().phase === 'ready');
  assert.equal(text(s.root.querySelector('p.message-error[role="alert"]')), COPY.server_error);
  assert.ok(text(s.root).includes('Problem number: A1B2C3'));
  assert.equal(s.root.querySelector('#code').value, format(CODE), 'the code stays');
  // A message without an incident number.
  s.f.json({ url: REGISTER }, 409, { error: 'busy', message: 'x' });
  s.root.querySelector('button[type="submit"]').dispatch('click');
  await until(() => s.screen.model().phase === 'ready' && text(s.root).includes(COPY.reg_busy));
  assert.ok(!text(s.root).includes('Problem number'));
  // Then a success, and Continue.
  s.f.json({ url: REGISTER }, 200, registerReply());
  s.root.querySelector('button[type="submit"]').dispatch('click');
  await until(() => s.screen.model().phase === 'registered');
  assert.ok(text(s.root).includes('Registered to Dev Site North as E2E 1.'));
  s.root.querySelector('button').dispatch('click');
  assert.equal(s.continued(), 1);
  assert.equal(normalise(s.screen.model().code), CODE);
  await settle();
});
