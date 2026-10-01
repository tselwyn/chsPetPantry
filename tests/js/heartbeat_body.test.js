// The heartbeat (50-design §6.4; S2 spec §3.16): the exact body, its bounds and raw tablet times, the request's
// headers and proof, and what a 200 clears: only the flags that were sent and the failure groups that did not change
// while the request was in flight, in one readwrite transaction. Real db.js, clock.js and api.js over the fakes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeEnv, flush, DEFAULT_NOW } from './support/fake-env.js';
import { fakeFetch } from './support/fake-fetch.js';
import { openMemoryDb } from './support/memory-db.js';
import { createClock } from '../../public/station/js/clock.js';
import { createApi } from '../../public/station/js/api.js';
import { formatDb } from '../../public/station/js/canonical.js';
import { importProofKey, proofMessage } from '../../public/station/js/proof.js';
import { b64url, sha256Hex } from '../../public/station/js/vault.js';
import {
  MAX_COUNT, MAX_INT, STARTUP_HEARTBEAT_MS, TRIGGER_GAP_MS, createDevice, heartbeatBody, outboxStats,
} from '../../public/station/js/device.js';

const CRED = 'pfd1_' + 'H'.repeat(43);
const RAW = Uint8Array.from({ length: 32 }, (_, i) => i + 1);
const HEARTBEAT = 'api/device/heartbeat.php';
const KEYS = ['app_build', 'client_now', 'storage_persisted', 'display_mode', 'pending_count', 'attention_count', 'max_seq', 'oldest_pending_at',
  'storage_estimate_kb', 'locked_out_since', 'failed_unlock_wipe', 'clock_rollback', 'auth_failures', 'wiped', 'items_pushed'];
const SERVER_TIME = '2026-10-01 13:00:00.000'; // the server is an hour ahead of the tablet
const reply = (over = {}) => ({ status: 'ok', offline_enabled: true, label: 'E2E 1', site: { site_id: 3, name: 'Dev Site North' }, directive: null,
  revoked_grants: [], config: { session_idle_minutes: 15 }, server_time: SERVER_TIME, build: 'test+0000000000', ...over });
const group = (userId, count, firstAt, lastAt, factor = 'password') => ({ user_id: userId, factor, count, first_at: firstAt, last_at: lastAt });

async function until(pred, ms = 10000) {
  const end = Date.now() + ms;
  for (;;) {
    if (await pred()) return;
    if (Date.now() > end) throw new Error('until: the condition never held');
    await new Promise((resolve) => setImmediate(resolve));
  }
}

/** db.js's adapter with its calls recorded ([method, store or stores, key or mode]). */
function recordingDb(db) {
  const calls = [];
  const wrapped = { ...db };
  for (const m of ['get', 'put', 'delete', 'all', 'keys', 'byIndex', 'count', 'clear']) wrapped[m] = (s, ...a) => { calls.push([m, s, a.at(-1)]); return db[m](s, ...a); };
  wrapped.tx = (stores, mode, fn) => { calls.push(['tx', stores.join(','), mode]); return db.tx(stores, mode, fn); };
  return { db: wrapped, calls };
}

/** A registered tablet (meta.device with its proof key), its outbox and meta, and a device over the real modules. */
async function setup({ meta = {}, outbox = [], env: envOptions = {}, registered = true, hooks: moreHooks = {} } = {}) {
  const f = fakeFetch();
  const env = fakeEnv({ fetch: f, ...envOptions });
  const { idb, db } = await openMemoryDb({ idb: env.indexedDB });
  if (registered) {
    const proof = await importProofKey(globalThis.crypto, RAW);
    await db.put('meta', { device_id: 7, credential: CRED, proof, site_id: 3, site_name: 'Dev Site North', label: 'E2E 1', iterations: 600000,
      registered_at: '2026-09-30 10:00:00.000' }, 'device');
    await db.put('meta', { next: 1 }, 'seq');
  }
  for (const [k, v] of Object.entries(meta)) {
    if (v === undefined) await db.delete('meta', k); else await db.put('meta', v, k);
  }
  for (const r of outbox) await db.put('outbox', r);
  const rec = recordingDb(db);
  const clock = createClock({ env, db });
  await clock.load();
  let device = null;
  const api = createApi({ env, clock, device: () => device?.credentials() ?? null });
  const log = [];
  const ui = { wipe: (m) => log.push(['wipe', m.phase]), erased: (m) => log.push(['erased', m.final]), banner: (k, on) => log.push(['banner', k, on]) };
  const hooks = {
    onKeysDropped: (r) => log.push(['keys', r]), onRevokedGrants: (ids) => log.push(['revoked', ids]), onOfflineDisabled: () => log.push(['offline_disabled']),
    onUpdateNeeded: (b) => log.push(['update', b]), onConfig: (c) => log.push(['config', c]), onConnectivity: (s) => log.push(['connectivity', s]),
    ...moreHooks,
  };
  device = createDevice({ env, db: rec.db, api, clock, ui, inflight: { begin() {}, end() {} }, bootRepair: async () => 'repaired', hooks });
  await device.load();
  return { f, env, idb, db, rec, clock, api, device, log };
}
const beats = (f) => f.requests.filter((r) => r.url.endsWith(HEARTBEAT));
const lastBody = (f) => JSON.parse(beats(f).at(-1).body);
const fakeEnvFor = (over = {}) => fakeEnv(over);
const zeroStats = { queued: 0, conflict: 0, invalid: 0, pending: 0, attention: 0, oldestPendingMs: null };

test('the body has exactly the §6.4 keys in order', async () => {
  const env = fakeEnvFor();
  assert.deepEqual(Object.keys(heartbeatBody({ env, meta: {}, stats: zeroStats, persisted: true, estimateKb: 812 })), KEYS);
  const confirmation = heartbeatBody({ env, meta: {}, stats: zeroStats, persisted: true, estimateKb: 812, extra: { wiped: true, items_pushed: 3 } });
  assert.deepEqual(Object.keys(confirmation), KEYS, 'the confirmation keeps the order');
  assert.equal(confirmation.wiped, true);
  assert.equal(confirmation.items_pushed, 3);
  const t = await setup();
  t.f.json({ url: HEARTBEAT }, 200, reply());
  await t.device.heartbeat();
  const body = lastBody(t.f);
  assert.deepEqual(Object.keys(body), KEYS);
  assert.deepEqual(body, {
    app_build: 'test+0000000000', client_now: '2026-10-01 12:00:00.000', storage_persisted: true, display_mode: 'standalone', pending_count: 0,
    attention_count: 0, max_seq: 0, oldest_pending_at: null, storage_estimate_kb: 812, locked_out_since: null, failed_unlock_wipe: false,
    clock_rollback: false, auth_failures: [], wiped: false, items_pushed: null,
  });
});

test('times are the tablet\'s raw clock', async () => {
  const t = await setup({ outbox: [{ client_uuid: 'a', seq: 1, state: 'queued', kind: 'x', created_at: DEFAULT_NOW - 90000 },
    { client_uuid: 'b', seq: 2, state: 'conflict', kind: 'x', created_at: DEFAULT_NOW - 30000 }],
  meta: { unlock: { locked_out_since: '2026-10-01 11:58:00.000' } } });
  t.f.json({ url: HEARTBEAT }, 200, reply(), {}, { times: 2 });
  await t.device.heartbeat();
  assert.equal(t.clock.offsetMs(), 3600000, 'the clock learned the server is an hour ahead');
  await t.env.advance(30000);
  await t.device.heartbeat({ reason: 'timer' });
  const body = lastBody(t.f);
  assert.equal(body.client_now, formatDb(t.env.now()), 'the raw tablet clock, not serverNow()');
  assert.equal(body.client_now, '2026-10-01 12:00:30.000');
  assert.equal(body.oldest_pending_at, '2026-10-01 11:58:30.000', 'the smallest created_at, raw');
  assert.equal(body.locked_out_since, '2026-10-01 11:58:00.000', 'as S4 wrote it');
});

test('max_seq is meta.seq.next − 1 and 0 before any record', async () => {
  const env = fakeEnvFor();
  const body = (seq) => heartbeatBody({ env, meta: seq === undefined ? {} : { seq }, stats: zeroStats, persisted: true, estimateKb: null });
  assert.equal(body(undefined).max_seq, 0);
  assert.equal(body({ next: 1 }).max_seq, 0);
  assert.equal(body({ next: 43 }).max_seq, 42);
  assert.equal(body({ next: 0 }).max_seq, 0);
  const t = await setup({ meta: { seq: { next: 8 } } });
  t.f.json({ url: HEARTBEAT }, 200, reply());
  await t.device.heartbeat();
  assert.equal(lastBody(t.f).max_seq, 7);
});

test('counts come from the outbox state index', async () => {
  const at = DEFAULT_NOW - 600000;
  const outbox = [
    { client_uuid: 'q1', seq: 1, state: 'queued', kind: 'intake', created_at: at + 5 },
    { client_uuid: 'q2', seq: 2, state: 'queued', kind: 'intake', created_at: at + 7 },
    { client_uuid: 'c1', seq: 3, state: 'conflict', kind: 'intake', created_at: at + 3 },
    { client_uuid: 'i1', seq: 4, state: 'invalid', kind: 'intake', created_at: at + 9 },
    { client_uuid: 'i2', seq: 5, state: 'invalid', kind: 'intake', created_at: at + 8 },
    { client_uuid: 'i3', seq: 6, state: 'invalid', kind: 'intake', created_at: 'not a number' },
    { client_uuid: 'h1', seq: 7, state: 'held', kind: 'intake', created_at: at - 1000 }, // not pending
    { client_uuid: 'r1', seq: 8, state: 'refused', kind: 'intake', created_at: at - 2000 },
  ];
  const t = await setup({ outbox });
  assert.deepEqual(await outboxStats(t.db), { queued: 2, conflict: 1, invalid: 3, pending: 6, attention: 4, oldestPendingMs: at + 3 });
  t.f.json({ url: HEARTBEAT }, 200, reply());
  await t.device.heartbeat();
  const body = lastBody(t.f);
  assert.equal(body.pending_count, 6);
  assert.equal(body.attention_count, 4);
  assert.equal(body.oldest_pending_at, formatDb(at + 3));
  const empty = await setup();
  assert.deepEqual(await outboxStats(empty.db), zeroStats);
});

test('values are bounded', () => {
  const env = fakeEnvFor();
  const failures = Array.from({ length: 30 }, (_, i) => group(i + 1, 1, '2026-10-01 11:00:00.000', '2026-10-01 11:00:00.000'));
  const body = heartbeatBody({ env, meta: { seq: { next: 1e12 }, auth_failures: failures }, stats: { pending: 2e9, attention: 3e9, oldestPendingMs: null },
    persisted: 'yes', estimateKb: 1e12 });
  assert.equal(body.pending_count, MAX_COUNT);
  assert.equal(body.attention_count, MAX_COUNT);
  assert.equal(body.max_seq, MAX_INT);
  assert.equal(body.storage_estimate_kb, MAX_INT);
  assert.equal(body.auth_failures.length, 20);
  assert.deepEqual(body.auth_failures, failures.slice(0, 20));
  assert.equal(body.storage_persisted, false, 'only true is true');
  assert.equal(heartbeatBody({ env, meta: { auth_failures: 'junk', failed_unlock_wipe_pending: 'yes', clock_rollback_pending: 1 }, stats: zeroStats,
    persisted: true, estimateKb: null }).auth_failures.length, 0);
  const junk = heartbeatBody({ env, meta: { failed_unlock_wipe_pending: 'yes', clock_rollback_pending: 1 }, stats: zeroStats, persisted: true, estimateKb: null });
  assert.equal(junk.failed_unlock_wipe, false);
  assert.equal(junk.clock_rollback, false);
  assert.equal(junk.storage_estimate_kb, null);
  assert.equal(MAX_COUNT, 10000000);
  assert.equal(MAX_INT, 2147483647);
});

test('the request sends Content-Type, Authorization, PFPMS-Proof and credentials omit', async () => {
  const t = await setup();
  t.f.json({ url: HEARTBEAT }, 200, reply());
  await t.device.heartbeat();
  const r = beats(t.f)[0];
  assert.equal(r.url, '../' + HEARTBEAT);
  assert.equal(r.method, 'POST');
  assert.equal(r.headers['content-type'], 'application/json');
  assert.equal(r.headers['x-pfpms-client'], 'station');
  assert.equal(r.headers.authorization, 'PFPMS-Device ' + CRED);
  assert.equal(r.credentials, 'omit');
  assert.equal(r.mode, 'same-origin');
  assert.equal(r.cache, 'no-store');
  assert.equal(r.redirect, 'error');
  const m = /^v1 (\d{13}) ([A-Za-z0-9_-]{43})$/.exec(r.headers['pfpms-proof']);
  assert.ok(m, r.headers['pfpms-proof']);
  const key = await globalThis.crypto.subtle.importKey('raw', RAW, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const msg = proofMessage('POST', HEARTBEAT, Number(m[1]), await sha256Hex(globalThis.crypto, r.body));
  assert.equal(b64url(await globalThis.crypto.subtle.sign('HMAC', key, new TextEncoder().encode(msg))), m[2], 'signed over the exact body sent');
  assert.equal('x-csrf-token' in r.headers, false);
});

test('after a 200 only the sent flags and unchanged failure groups are cleared', async () => {
  const sent = [group(5, 2, '2026-10-01 11:00:00.000', '2026-10-01 11:05:00.000'), group(6, 1, '2026-10-01 11:10:00.000', '2026-10-01 11:10:00.000', 'pin')];
  const extra = Array.from({ length: 20 }, (_, i) => group(100 + i, 1, '2026-10-01 09:00:00.000', '2026-10-01 09:00:00.000'));
  const t = await setup({ meta: { clock_rollback_pending: true, failed_unlock_wipe_pending: false, auth_failures: [...sent, ...extra] } });
  const added = group(9, 1, '2026-10-01 11:59:00.000', '2026-10-01 11:59:00.000');
  t.f.on({ url: HEARTBEAT }, async () => {
    // While the request is out: S4 records a failed-unlock wipe and a new failure group.
    await t.db.put('meta', true, 'failed_unlock_wipe_pending');
    await t.db.put('meta', [...(await t.db.get('meta', 'auth_failures')), added], 'auth_failures');
    return new Response(JSON.stringify(reply()), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });
  await t.device.heartbeat();
  const body = lastBody(t.f);
  assert.equal(body.clock_rollback, true);
  assert.equal(body.failed_unlock_wipe, false);
  assert.equal(body.auth_failures.length, 20, 'twenty groups at most');
  assert.equal(await t.db.get('meta', 'clock_rollback_pending'), false, 'sent true: cleared');
  assert.equal(await t.db.get('meta', 'failed_unlock_wipe_pending'), true, 'set while in flight, not sent: kept');
  const left = await t.db.get('meta', 'auth_failures');
  assert.deepEqual(left, [...extra.slice(18), added], 'the 20 sent groups are gone; the unsent ones stay');
});

test('a failure added to a group while the heartbeat is in flight is kept', async () => {
  const g = group(5, 2, '2026-10-01 11:00:00.000', '2026-10-01 11:05:00.000');
  const other = group(6, 1, '2026-10-01 11:10:00.000', '2026-10-01 11:10:00.000', 'pin');
  const t = await setup({ meta: { auth_failures: [g, other] } });
  t.f.on({ url: HEARTBEAT }, async () => {
    await t.db.put('meta', [{ ...g, count: 5, last_at: '2026-10-01 11:58:00.000' }, other], 'auth_failures');
    return new Response(JSON.stringify(reply()), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });
  await t.device.heartbeat();
  assert.deepEqual(lastBody(t.f).auth_failures, [g, other]);
  assert.deepEqual(await t.db.get('meta', 'auth_failures'), [
    { user_id: 5, factor: 'password', count: 3, first_at: '2026-10-01 11:05:00.000', last_at: '2026-10-01 11:58:00.000' },
  ], 'the grown group keeps its unreported part; the unchanged one is removed');
});

test('the 200 writes in one readwrite transaction', async () => {
  const t = await setup({ meta: { clock_rollback_pending: true, auth_failures: [group(5, 1, 'a', 'a')], config: { organisation_name: 'CHS Pet Pantry' } } });
  t.f.json({ url: HEARTBEAT }, 200, reply({ label: 'Front desk 2' }));
  t.rec.calls.length = 0;
  await t.device.heartbeat();
  const writes = t.rec.calls.filter((c) => ['put', 'delete', 'clear'].includes(c[0]) || (c[0] === 'tx' && c[2] === 'readwrite'));
  assert.deepEqual(writes, [['tx', 'meta', 'readwrite']], 'one readwrite transaction, no other write');
  assert.deepEqual(t.rec.calls.filter((c) => c[0] === 'tx').map((c) => c.slice(1)), [['meta', 'readonly'], ['outbox', 'readonly'], ['meta', 'readwrite']]);
  assert.equal(await t.db.get('meta', 'clock_rollback_pending'), false);
  assert.equal((await t.db.get('meta', 'device')).label, 'Front desk 2');
});

test('nothing is cleared without a 200', async () => {
  const failures = [group(5, 2, '2026-10-01 11:00:00.000', '2026-10-01 11:05:00.000')];
  const answers = [
    ['offline', (f) => f.offline({ url: HEARTBEAT })],
    ['500', (f) => f.json({ url: HEARTBEAT }, 500, { error: 'server_error', message: 'x', incident: 'I1' })],
    ['503', (f) => f.json({ url: HEARTBEAT }, 503, { error: 'maintenance', message: 'x' })],
    ['401', (f) => f.json({ url: HEARTBEAT }, 401, { error: 'device_unknown', message: 'x' })],
    ['429', (f) => f.json({ url: HEARTBEAT }, 429, { error: 'rate_limited', message: 'x' }, { 'Retry-After': '900' })],
    ['html', (f) => f.html({ url: HEARTBEAT })],
  ];
  for (const [name, answer] of answers) {
    const t = await setup({ meta: { clock_rollback_pending: true, failed_unlock_wipe_pending: true, auth_failures: failures } });
    answer(t.f);
    assert.equal(await t.device.heartbeat(), null, name);
    assert.equal(await t.db.get('meta', 'clock_rollback_pending'), true, name);
    assert.equal(await t.db.get('meta', 'failed_unlock_wipe_pending'), true, name);
    assert.deepEqual(await t.db.get('meta', 'auth_failures'), failures, name);
    assert.equal(await t.db.get('meta', 'last_heartbeat_at'), undefined, name);
  }
});

test('meta.config, the label, the site and last_heartbeat_at follow the answer', async () => {
  const t = await setup({ meta: { config: { organisation_name: 'CHS Pet Pantry', session_idle_minutes: 30 } } });
  t.f.json({ url: HEARTBEAT }, 200, reply({ label: 'Front desk 2', site: { site_id: 4, name: 'Dev Site South' }, offline_enabled: false,
    config: { session_idle_minutes: 15, pin_max_failed: 5 } }));
  const res = await t.device.heartbeat();
  assert.equal(res.label, 'Front desk 2');
  assert.deepEqual(await t.db.get('meta', 'config'), { session_idle_minutes: 15, pin_max_failed: 5, offline_enabled: false, organisation_name: 'CHS Pet Pantry' });
  const d = await t.db.get('meta', 'device');
  assert.equal(d.label, 'Front desk 2');
  assert.equal(d.site_id, 4);
  assert.equal(d.site_name, 'Dev Site South');
  assert.equal(d.credential, CRED, 'the rest stays');
  assert.equal(d.proof.extractable, false);
  assert.equal(d.registered_at, '2026-09-30 10:00:00.000');
  assert.deepEqual(t.device.info(), { device_id: 7, site_id: 4, site_name: 'Dev Site South', label: 'Front desk 2', iterations: 600000, registered_at: '2026-09-30 10:00:00.000' });
  assert.equal(await t.db.get('meta', 'last_heartbeat_at'), SERVER_TIME, 'serverNow() at the 200: the server\'s frame');
  const config = t.log.find((x) => x[0] === 'config');
  assert.deepEqual(config[1], await t.db.get('meta', 'config'), 'onConfig gets meta.config');
  assert.deepEqual(t.log.filter((x) => x[0] === 'connectivity'), [['connectivity', 'online']]);
  // The same label and site write nothing new to meta.device.
  t.f.json({ url: HEARTBEAT }, 200, reply({ label: 'Front desk 2', site: { site_id: 4, name: 'Dev Site South' } }));
  await t.env.advance(1000);
  await t.device.heartbeat();
  assert.equal((await t.db.get('meta', 'config')).offline_enabled, true);
});

test('a different build asks for an update', async () => {
  const t = await setup();
  t.f.json({ url: HEARTBEAT }, 200, reply({ build: 'test+0000000000' }));
  await t.device.heartbeat();
  assert.deepEqual(t.log.filter((x) => x[0] === 'update'), []);
  t.f.json({ url: HEARTBEAT }, 200, reply({ build: '0.1.0-dev+1234567890' }));
  await t.device.heartbeat();
  assert.deepEqual(t.log.filter((x) => x[0] === 'update'), [['update', '0.1.0-dev+1234567890']]);
});

test('revoked_grants and offline_enabled false reach their hooks', async () => {
  const t = await setup();
  t.f.json({ url: HEARTBEAT }, 200, reply({ revoked_grants: [], offline_enabled: true }));
  await t.device.heartbeat();
  assert.deepEqual(t.log.filter((x) => ['revoked', 'offline_disabled'].includes(x[0])), []);
  t.f.json({ url: HEARTBEAT }, 200, reply({ revoked_grants: [11, 12], offline_enabled: false }));
  await t.device.heartbeat();
  assert.deepEqual(t.log.filter((x) => ['revoked', 'offline_disabled'].includes(x[0])), [['revoked', [11, 12]], ['offline_disabled']]);
});

test('one heartbeat at a time, online/visible at most every 20 s, start-up bounded to 5 s', async () => {
  assert.equal(TRIGGER_GAP_MS, 20000);
  assert.equal(STARTUP_HEARTBEAT_MS, 5000);
  const t = await setup();
  t.f.json({ url: HEARTBEAT }, 200, reply(), {}, { times: Infinity });
  const p1 = t.device.heartbeat({ reason: 'timer' });
  const p2 = t.device.heartbeat({ reason: 'online' });
  const p3 = t.device.heartbeat({ reason: 'timer' });
  assert.equal(p1, p2);
  assert.equal(p1, p3);
  await p1;
  assert.equal(beats(t.f).length, 1, 'one request');
  assert.equal(await t.device.heartbeat({ reason: 'online' }), null);
  assert.equal(await t.device.heartbeat({ reason: 'visible' }), null);
  assert.equal(beats(t.f).length, 1, 'online and visible wait 20 s');
  await t.device.heartbeat({ reason: 'timer' });
  assert.equal(beats(t.f).length, 2, 'the timer does not wait');
  await t.env.advance(TRIGGER_GAP_MS - 1);
  assert.equal(await t.device.heartbeat({ reason: 'visible' }), null);
  await t.env.advance(1);
  assert.notEqual(await t.device.heartbeat({ reason: 'visible' }), null);
  assert.equal(beats(t.f).length, 3);

  // Start-up: bounded by its timeout.
  const s = await setup();
  s.f.hang({ url: HEARTBEAT });
  let done = false;
  const p = s.device.heartbeat({ reason: 'startup', timeoutMs: STARTUP_HEARTBEAT_MS }).then((v) => { done = true; return v; });
  await until(() => beats(s.f).length === 1);
  await s.env.advance(STARTUP_HEARTBEAT_MS - 1);
  await flush(20);
  assert.equal(done, false);
  await s.env.advance(1);
  assert.equal(await p, null);
  assert.equal(beats(s.f)[0].signal.aborted, true, 'the request was aborted');
  assert.deepEqual(s.log.filter((x) => x[0] === 'connectivity'), [['connectivity', 'offline']]);

  // Not registered: no request at all.
  const u = await setup({ registered: false });
  assert.equal(await u.device.heartbeat({ reason: 'startup' }), null);
  assert.equal(u.f.requests.length, 0);
});

test('a 429 with a directive still starts the wipe', async () => {
  const t = await setup();
  t.f.json({ url: HEARTBEAT }, 429, { error: 'rate_limited', message: 'x', status: 'revoked', directive: { wipe: 'Push Then Wipe' } }, { 'Retry-After': '900' });
  t.f.json({ url: HEARTBEAT }, 200, { status: 'wiped', server_time: SERVER_TIME, build: 'test+0000000000' });
  assert.equal(await t.device.heartbeat(), null);
  assert.deepEqual(t.log.find((x) => x[0] === 'keys'), ['keys', 'directive']);
  assert.equal(t.device.wiping(), true);
  await until(() => t.log.some((x) => x[0] === 'erased'));
  assert.deepEqual(t.log.find((x) => x[0] === 'erased'), ['erased', 'uploaded']);
  assert.equal(JSON.parse(beats(t.f)[1].body).wiped, true, 'the confirmation followed');
});

test('device_credential_missing shows the header banner and the next 200 clears it', async () => {
  const t = await setup();
  t.f.json({ url: HEARTBEAT }, 401, { error: 'device_credential_missing', message: 'x' });
  assert.equal(await t.device.heartbeat(), null);
  const missing = () => t.log.filter((x) => x[0] === 'banner' && x[1] === 'header_missing');
  assert.deepEqual(t.log.filter((x) => x[0] === 'banner'), [['banner', 'header_missing', true]]);
  t.f.json({ url: HEARTBEAT }, 200, reply());
  await t.device.heartbeat();
  assert.deepEqual(missing(), [['banner', 'header_missing', true], ['banner', 'header_missing', false]]);
});

test('device_unknown shows the unknown-tablet banner, keeps the tablet registered, and the next 200 clears it', async () => {
  // The server has no row for this credential (a restored backup, a reset): it says so, and it never erases itself.
  const t = await setup();
  t.f.json({ url: HEARTBEAT }, 401, { error: 'device_unknown', message: 'The server does not recognise this tablet.' }, {}, { times: 2 });
  assert.equal(await t.device.heartbeat(), null);
  const unknown = () => t.log.filter((x) => x[0] === 'banner' && x[1] === 'unknown_tablet');
  assert.deepEqual(unknown(), [['banner', 'unknown_tablet', true]]);
  assert.equal(t.device.registered(), true, 'nothing is erased');
  assert.equal(t.device.wiping(), false);
  assert.equal(t.log.some((x) => x[0] === 'erased' || x[0] === 'wipe' || x[0] === 'keys'), false);
  assert.equal((await t.db.get('meta', 'device')).credential, CRED);
  await t.env.advance(TRIGGER_GAP_MS);
  assert.equal(await t.device.heartbeat(), null);
  assert.deepEqual(unknown(), [['banner', 'unknown_tablet', true], ['banner', 'unknown_tablet', true]], 'still shown on the next try');
  t.f.json({ url: HEARTBEAT }, 200, reply());
  await t.device.heartbeat();
  assert.deepEqual(unknown().at(-1), ['banner', 'unknown_tablet', false], 'a 200 clears it');
});

test('onHeartbeatOk follows each handled 200, and only a 200', async () => {
  const seen = [];
  const t = await setup({ hooks: { onHeartbeatOk: () => seen.push(t.log.filter((x) => x[0] === 'config').length) } });
  t.f.json({ url: HEARTBEAT }, 200, reply());
  await t.device.heartbeat();
  assert.deepEqual(seen, [1], 'once, after the answer was handled (its config already applied)');
  for (const fail of [(f) => f.offline({ url: HEARTBEAT }), (f) => f.json({ url: HEARTBEAT }, 503, { error: 'maintenance', message: 'x' }),
    (f) => f.json({ url: HEARTBEAT }, 401, { error: 'device_unknown', message: 'x' })]) {
    fail(t.f);
    await t.device.heartbeat();
  }
  assert.deepEqual(seen, [1], 'no answer, maintenance or an error is not one');
  // The window is replaced while the answer is handled: nothing more from it.
  const late = [];
  const u = await setup({ hooks: { onConfig: () => u.device.stop(), onHeartbeatOk: () => late.push('ok') } });
  u.f.json({ url: HEARTBEAT }, 200, reply());
  await u.device.heartbeat();
  assert.deepEqual(late, []);
});
