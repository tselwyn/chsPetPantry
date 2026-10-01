// public/station/js/api.js (50-design §5.2, §5.5, §7.2, D-38): headers, credentials and fetch options on every kind
// of call, the device proof over the exact body string sent, the error envelope as ApiError, offline answers,
// timeouts, the clock and CSRF learned from any answer, and the two one-time retries. fakeFetch records every request.
import test from 'node:test';
import assert from 'node:assert/strict';
import { ApiError, DEFAULT_TIMEOUT_MS, OFFLINE_STATUSES, createApi } from '../../public/station/js/api.js';
import { importProofKey } from '../../public/station/js/proof.js';
import { createClock } from '../../public/station/js/clock.js';
import { formatDb } from '../../public/station/js/canonical.js';
import { fakeEnv } from './support/fake-env.js';
import { openMemoryDb } from './support/memory-db.js';
import { fixture, fromHex } from './support/fixtures.js';

const CREDENTIAL = 'pfd1_' + 'A'.repeat(43);
const RAW_KEY = new Uint8Array(32).map((_, i) => i * 7 + 3);
const HEARTBEAT = 'api/device/heartbeat.php';
const enc = new TextEncoder();
const hex = (bytes) => Buffer.from(bytes).toString('hex');
const b64url = (bytes) => Buffer.from(bytes).toString('base64url');

/** An Api over fakeEnv and fakeFetch, with the real clock (memory database) and, optionally, a registered tablet. */
async function setup({ registered = true, proofKey = true, base = undefined } = {}) {
  const env = fakeEnv();
  const { db } = await openMemoryDb();
  const clock = createClock({ env, db });
  await clock.load();
  const learned = [];
  const learn = clock.learn;
  clock.learn = (...a) => { learned.push(a); learn(...a); };
  const key = proofKey ? await importProofKey(crypto, RAW_KEY) : null;
  const tablet = registered ? { credential: CREDENTIAL, proofKey: key } : null;
  const api = createApi({ env, clock, device: () => tablet, ...(base === undefined ? {} : { base }) });
  return { env, fetch: env.fetch, clock, api, learned, tablet };
}

/** What DeviceProof::message() signs, recomputed from the raw key and the recorded body, as base64url. */
async function expectedMac(method, endpoint, ts, bodyText) {
  const key = await crypto.subtle.importKey('raw', RAW_KEY, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const bodyHash = hex(await crypto.subtle.digest('SHA-256', enc.encode(bodyText)));
  return b64url(await crypto.subtle.sign('HMAC', key, enc.encode(`pfpms/v1/proof\n${method}\n${endpoint}\n${ts}\n${bodyHash}`)));
}

test('every call sends X-PFPMS-Client and Accept', async () => {
  const t = await setup();
  t.fetch.json({}, 200, { ok: true }, {}, { times: Infinity });
  t.api.setCsrf('tok');
  await t.api.get('api/ping.php');
  await t.api.post('api/x.php', { a: 1 });
  await t.api.get('api/session.php', { session: true });
  await t.api.post('api/auth/login.php', { a: 1 }, { session: true });
  await t.api.get('api/ping.php', { device: true });
  await t.api.post(HEARTBEAT, { a: 1 }, { device: true });
  await t.api.post(HEARTBEAT, { a: 1 }, { device: true, clearSite: true });
  assert.equal(t.fetch.requests.length, 7);
  for (const r of t.fetch.requests) {
    assert.equal(r.headers['x-pfpms-client'], 'station', r.url);
    assert.equal(r.headers.accept, 'application/json', r.url);
  }
});

test('every call with a body sends Content-Type application/json, device-only POSTs included', async () => {
  const t = await setup();
  t.fetch.json({ method: 'POST' }, 200, { ok: true }, {}, { times: Infinity });
  t.api.setCsrf('tok');
  await t.api.post('api/x.php', { a: 1 });
  await t.api.post('api/auth/login.php', { a: 1 }, { session: true });
  await t.api.post(HEARTBEAT, { a: 1 }, { device: true });
  await t.api.post(HEARTBEAT, { a: 1 }, { device: true, clearSite: true });
  await t.api.post('api/x.php', {});
  for (const r of t.fetch.requests) {
    assert.equal(r.headers['content-type'], 'application/json', r.url);
    assert.equal(typeof r.body, 'string');
  }
  assert.equal(t.fetch.requests.at(-1).body, '{}');
  await assert.rejects(t.api.post('api/x.php', undefined), TypeError, 'a body is required');
});

test('a GET sends neither a body nor a Content-Type', async () => {
  const t = await setup();
  t.fetch.json({ method: 'GET' }, 200, { ok: true }, {}, { times: Infinity });
  await t.api.get('api/ping.php');
  await t.api.get('api/session.php', { session: true });
  await t.api.get('api/ping.php', { device: true });
  for (const r of t.fetch.requests) {
    assert.equal(r.method, 'GET');
    assert.equal(r.body, undefined);
    assert.equal('body' in r.init, false);
    assert.equal(r.headers['content-type'], undefined);
    assert.equal(r.headers['x-csrf-token'], undefined);
  }
});

test('device calls send Authorization and a PFPMS-Proof over the exact body string', async () => {
  const t = await setup();
  t.fetch.json({ method: 'POST', url: HEARTBEAT }, 200, { status: 'ok' });
  // Key order and spacing that canonical JSON would change: the proof must cover the string actually sent.
  const body = { z_last: 1, a_first: 'é', nested: { y: true, b: null }, list: [3, 1, 2] };
  const ts = Math.round(t.clock.serverNow());
  assert.deepEqual(await t.api.post(HEARTBEAT, body, { device: true }), { status: 'ok' });
  const r = t.fetch.requests[0];
  assert.equal(r.body, JSON.stringify(body));
  assert.equal(r.body, '{"z_last":1,"a_first":"é","nested":{"y":true,"b":null},"list":[3,1,2]}');
  assert.equal(r.headers.authorization, 'PFPMS-Device ' + CREDENTIAL);
  const parts = r.headers['pfpms-proof'].split(' ');
  assert.equal(parts.length, 3);
  assert.equal(parts[0], 'v1');
  assert.equal(parts[1], String(ts), 'the clock\'s serverNow(), 13 digits');
  assert.match(parts[2], /^[A-Za-z0-9_-]{43}$/);
  assert.equal(parts[2], await expectedMac('POST', HEARTBEAT, ts, r.body));
  // A device GET signs the empty body.
  t.fetch.json({ method: 'GET', url: 'api/ping.php' }, 200, { ok: true, authorization_received: true });
  await t.api.get('api/ping.php', { device: true, timeoutMs: 5000 });
  const g = t.fetch.requests[1];
  const [, gts, gmac] = g.headers['pfpms-proof'].split(' ');
  assert.equal(gmac, await expectedMac('GET', 'api/ping.php', gts, ''));
  // A tablet without a proof key sends the credential alone.
  const bare = await setup({ proofKey: false });
  bare.fetch.json({}, 200, { ok: true });
  await bare.api.post(HEARTBEAT, { a: 1 }, { device: true });
  assert.equal(bare.fetch.requests[0].headers.authorization, 'PFPMS-Device ' + CREDENTIAL);
  assert.equal(bare.fetch.requests[0].headers['pfpms-proof'], undefined);
});

test('other calls never send Authorization or PFPMS-Proof', async () => {
  const t = await setup();
  t.fetch.json({}, 200, { ok: true }, {}, { times: Infinity });
  t.api.setCsrf('tok');
  await t.api.get('api/ping.php');
  await t.api.post('api/device/register.php', { code: 'x' }, { session: true });
  await t.api.get('api/session.php', { session: true });
  await t.api.post('api/x.php', { a: 1 }, { clearSite: true });
  for (const r of t.fetch.requests) {
    assert.equal(r.headers.authorization, undefined, r.url);
    assert.equal(r.headers['pfpms-proof'], undefined, r.url);
  }
});

test('session POSTs send X-CSRF-Token with credentials same-origin; session GETs send no token', async () => {
  const t = await setup();
  assert.equal(t.api.csrf(), null);
  // The token is unknown: api/session.php is asked first.
  t.fetch.json({ method: 'GET', url: 'api/session.php' }, 200, { csrf: 'token-1', organisation_name: 'Pantry', dev_relax: false });
  t.fetch.json({ method: 'POST', url: 'api/device/register.php' }, 200, { device_id: 9 });
  assert.deepEqual(await t.api.post('api/device/register.php', { code: 'x' }, { session: true }), { device_id: 9 });
  assert.deepEqual(t.fetch.requests.map((r) => [r.method, r.url]), [['GET', '../api/session.php'], ['POST', '../api/device/register.php']]);
  const [get, post] = t.fetch.requests;
  assert.equal(get.credentials, 'same-origin');
  assert.equal(get.headers['x-csrf-token'], undefined, 'a session GET sends no token');
  assert.equal(post.credentials, 'same-origin');
  assert.equal(post.headers['x-csrf-token'], 'token-1');
  assert.equal(t.api.csrf(), 'token-1');
  // Known token: no second refresh.
  t.fetch.json({ method: 'POST' }, 200, { ok: true });
  await t.api.post('api/auth/logout.php', {}, { session: true });
  assert.equal(t.fetch.requests.length, 3);
  assert.equal(t.fetch.requests[2].headers['x-csrf-token'], 'token-1');
  // refreshCsrf() is api/session.php with the session, 5 s, and returns its body.
  t.fetch.json({ url: 'api/session.php' }, 200, { csrf: 'token-2', build: 'b' });
  assert.deepEqual(await t.api.refreshCsrf(), { csrf: 'token-2', build: 'b' });
  assert.equal(t.api.csrf(), 'token-2');
  assert.equal(t.fetch.requests[3].credentials, 'same-origin');
  // Two POSTs at once share one refresh.
  const s = await setup();
  s.fetch.json({ url: 'api/session.php' }, 200, { csrf: 'shared' });
  s.fetch.json({ method: 'POST' }, 200, { ok: true }, {}, { times: 2 });
  await Promise.all([s.api.post('api/a.php', {}, { session: true }), s.api.post('api/b.php', {}, { session: true })]);
  assert.equal(s.fetch.requests.filter((r) => r.url.endsWith('session.php')).length, 1);
  assert.ok(s.fetch.requests.filter((r) => r.method === 'POST').every((r) => r.headers['x-csrf-token'] === 'shared'));
  s.api.setCsrf(null);
  assert.equal(s.api.csrf(), null);
});

test('device-only calls use credentials omit, and same-origin with clearSite', async () => {
  const t = await setup();
  t.fetch.json({}, 200, { ok: true }, {}, { times: Infinity });
  await t.api.post(HEARTBEAT, { a: 1 }, { device: true });
  await t.api.get('api/ping.php', { device: true });
  await t.api.get('api/ping.php');
  await t.api.post(HEARTBEAT, { a: 1 }, { device: true, clearSite: true });
  assert.deepEqual(t.fetch.requests.map((r) => r.credentials), ['omit', 'omit', 'omit', 'same-origin']);
});

test('every call uses mode same-origin, cache no-store and redirect error', async () => {
  const t = await setup();
  t.fetch.json({}, 200, { ok: true }, {}, { times: Infinity });
  t.api.setCsrf('tok');
  await t.api.get('api/ping.php');
  await t.api.post('api/a.php', { a: 1 }, { session: true });
  await t.api.get('api/session.php', { session: true });
  await t.api.post(HEARTBEAT, { a: 1 }, { device: true, clearSite: true });
  for (const r of t.fetch.requests) {
    assert.deepEqual([r.mode, r.cache, r.redirect], ['same-origin', 'no-store', 'error'], r.url);
    assert.ok(r.signal instanceof AbortSignal, 'every call can be aborted');
  }
});

test('a status in okStatuses returns the body', async () => {
  const t = await setup();
  t.fetch.json({}, 409, { results: [{ status: 'conflict' }] });
  assert.deepEqual(await t.api.post('api/sync/push.php', { items: [] }, { device: true, okStatuses: [409] }), { results: [{ status: 'conflict' }] });
  t.fetch.json({}, 409, { error: 'conflict', message: 'Already there.' });
  await assert.rejects(t.api.post('api/sync/push.php', { items: [] }, { device: true }), { name: 'ApiError', kind: 'http', status: 409, code: 'conflict' });
  t.fetch.json({}, 201, { created: true });
  assert.deepEqual(await t.api.post('api/x.php', {}), { created: true }, 'any 2xx is ok');
});

test('the error envelope becomes ApiError http with code, message and extra', async () => {
  const t = await setup();
  const directive = { wipe: 'Wipe Now', issued_at: '2026-10-01 11:59:00.000' };
  t.fetch.json({}, 403, { error: 'device_revoked', message: 'This tablet has been retired.', directive });
  const e = await t.api.post(HEARTBEAT, { a: 1 }, { device: true }).catch((x) => x);
  assert.ok(e instanceof ApiError);
  assert.ok(e instanceof Error);
  assert.equal(e.name, 'ApiError');
  assert.equal(e.kind, 'http');
  assert.equal(e.offline, false);
  assert.equal(e.status, 403);
  assert.equal(e.code, 'device_revoked');
  assert.equal(e.message, 'This tablet has been retired.');
  assert.deepEqual(e.extra, { directive });
  assert.equal(e.retryAfter, null);
  // A 500 carries its incident number in extra; an answer without an envelope gets http_<status>.
  t.fetch.json({}, 500, { error: 'server_error', message: 'Something went wrong.', incident: 'A1B2C3' });
  await assert.rejects(t.api.get('api/ping.php'), { kind: 'http', status: 500, code: 'server_error', extra: { incident: 'A1B2C3' } });
  t.fetch.json({}, 418, { teapot: true });
  await assert.rejects(t.api.get('api/ping.php'), { kind: 'http', status: 418, code: 'http_418', message: '', extra: { teapot: true } });
  t.fetch.json({}, 410, { error: 'wiped', message: '' });
  await assert.rejects(t.api.post(HEARTBEAT, {}, { device: true }), { kind: 'http', status: 410, code: 'wiped' });
  // The constructor's defaults.
  const d = new ApiError({ kind: 'offline', code: 'network' });
  assert.deepEqual([d.status, d.message, d.extra, d.retryAfter, d.offline], [0, '', {}, null, true]);
});

test('Retry-After becomes retryAfter', async () => {
  const t = await setup();
  t.fetch.json({}, 429, { error: 'rate_limited', message: 'Too many tries.' }, { 'Retry-After': '30' });
  await assert.rejects(t.api.get('api/ping.php'), { kind: 'http', code: 'rate_limited', retryAfter: 30 });
  t.fetch.json({}, 429, { error: 'rate_limited' });
  await assert.rejects(t.api.get('api/ping.php'), { retryAfter: null });
  t.fetch.json({}, 429, { error: 'rate_limited' }, { 'Retry-After': 'Wed, 21 Oct 2026 07:28:00 GMT' });
  await assert.rejects(t.api.get('api/ping.php'), { retryAfter: null });
  t.fetch.json({}, 503, { error: 'maintenance', message: 'Updating.' }, { 'Retry-After': '120' });
  await assert.rejects(t.api.get('api/ping.php'), { kind: 'offline', code: 'maintenance', retryAfter: 120 });
});

test('a network error, a timeout, 502, 503 (maintenance) and 504 are offline', async () => {
  assert.deepEqual(OFFLINE_STATUSES, [502, 503, 504]);
  assert.equal(DEFAULT_TIMEOUT_MS, 15000);
  const t = await setup();
  t.fetch.offline({});
  await assert.rejects(t.api.get('api/ping.php'), { name: 'ApiError', kind: 'offline', offline: true, status: 0, code: 'network' });
  // A timeout: the default 15 s, aborting the fetch.
  t.fetch.hang({});
  let settled = null;
  const hanging = t.api.post(HEARTBEAT, { a: 1 }, { device: true }).catch((e) => { settled = e; });
  while (t.fetch.requests.length < 2) await new Promise((r) => setImmediate(r));
  await t.env.advance(DEFAULT_TIMEOUT_MS - 1);
  assert.equal(settled, null);
  await t.env.advance(1);
  await hanging;
  assert.ok(settled instanceof ApiError);
  assert.deepEqual([settled.kind, settled.code, settled.status], ['offline', 'timeout', 0]);
  assert.equal(t.fetch.requests[1].signal.aborted, true);
  // timeoutMs, and a body that never finishes arriving: the timeout covers the body read too.
  t.fetch.on({}, () => new Response(new ReadableStream({ start(c) { c.enqueue(enc.encode('{"ok":')); } }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
  let slow = null;
  const reading = t.api.get('api/ping.php', { timeoutMs: 5000 }).catch((e) => { slow = e; });
  await t.env.advance(5000);
  await reading;
  assert.deepEqual([slow?.kind, slow?.code, slow?.status], ['offline', 'timeout', 200]);
  // 502 from a proxy (HTML), 503 maintenance (the envelope), 504.
  t.fetch.on({}, () => new Response('<html>Bad gateway</html>', { status: 502, headers: { 'Content-Type': 'text/html' } }));
  await assert.rejects(t.api.get('api/ping.php'), { kind: 'offline', status: 502, code: 'unavailable', message: '' });
  t.fetch.json({}, 503, { error: 'maintenance', message: 'The system is being updated. Please try again in a few minutes.' });
  await assert.rejects(t.api.get('api/ping.php'), { kind: 'offline', status: 503, code: 'maintenance', message: 'The system is being updated. Please try again in a few minutes.' });
  t.fetch.json({}, 504, {});
  await assert.rejects(t.api.get('api/ping.php'), { kind: 'offline', status: 504, code: 'unavailable' });
  assert.equal(t.env.pendingTimers(), 0, 'every timeout timer was cleared');
});

test('an HTML 200 is offline not_json', async () => {
  const t = await setup();
  t.fetch.html({});
  await assert.rejects(t.api.get('api/ping.php'), { kind: 'offline', status: 200, code: 'not_json' });
  t.fetch.on({}, () => new Response('{"ok": tru', { status: 200, headers: { 'Content-Type': 'application/json' } }));
  await assert.rejects(t.api.get('api/ping.php'), { kind: 'offline', code: 'not_json' }, 'JSON that does not parse');
  t.fetch.on({}, () => new Response('[1, 2]', { status: 200, headers: { 'Content-Type': 'application/json' } }));
  await assert.rejects(t.api.get('api/ping.php'), { kind: 'offline', code: 'not_json' }, 'not an object');
  t.fetch.html({}, 403);
  await assert.rejects(t.api.post(HEARTBEAT, {}, { device: true }), { kind: 'offline', status: 403, code: 'not_json' });
  t.fetch.on({}, () => new Response('{"ok":true}', { status: 200, headers: { 'Content-Type': 'Application/JSON; charset=UTF-8' } }));
  assert.deepEqual(await t.api.get('api/ping.php'), { ok: true }, 'the media type is case-insensitive');
});

test('server_time in any JSON answer teaches the clock', async () => {
  const t = await setup();
  const server = (ms) => formatDb(ms);
  // The answer takes 300 ms: learn() gets the times around the fetch.
  const slowly = (status, body) => async () => {
    await t.env.advance(300);
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  };
  const t0 = t.env.now();
  t.fetch.on({}, slowly(200, { ok: true, server_time: server(t0 + 150 + 60000) }));
  await t.api.get('api/ping.php');
  assert.deepEqual(t.learned, [[server(t0 + 150 + 60000), t0, t0 + 300]]);
  assert.equal(t.clock.offsetMs(), 60000);
  // An error envelope and an offline status teach it too.
  t.fetch.on({}, slowly(401, { error: 'device_proof_stale', message: 'Clock.', server_time: server(t0 + 1000) }), { times: 2 });
  await assert.rejects(t.api.post(HEARTBEAT, {}, { device: true }), { code: 'device_proof_stale' });
  t.fetch.json({}, 503, { error: 'maintenance', server_time: server(t0 + 2000) });
  await assert.rejects(t.api.get('api/ping.php'), { code: 'maintenance' });
  assert.equal(t.learned.length, 4);
  // No server_time, or one that is not a string: nothing learned.
  t.fetch.json({}, 200, { ok: true });
  t.fetch.json({}, 200, { ok: true, server_time: 1790856000000 });
  t.fetch.html({});
  await t.api.get('api/ping.php');
  await t.api.get('api/ping.php');
  await assert.rejects(t.api.get('api/ping.php'), { code: 'not_json' });
  assert.equal(t.learned.length, 4);
});

test('a server_time that rolls out of years 0000-9999 teaches nothing and never breaks the call', async () => {
  // A proxy or captive portal may answer JSON with any server_time; the call still settles as usual.
  const t = await setup();
  t.fetch.json({}, 200, { ok: true, server_time: '0000-00-00 00:00:00.000' });
  t.fetch.json({}, 401, { error: 'device_unknown', message: 'Unknown.', server_time: '9999-12-31 24:00:00.000' });
  assert.deepEqual(await t.api.get('api/ping.php'), { ok: true, server_time: '0000-00-00 00:00:00.000' });
  await assert.rejects(t.api.post(HEARTBEAT, {}, { device: true }), (e) => e instanceof ApiError && e.code === 'device_unknown');
  assert.equal(t.learned.length, 2, 'learn() was asked');
  assert.equal(t.clock.offsetMs(), null, 'and learned nothing');
});

test('a server_time no proof can carry teaches nothing, so the next device call still goes out', async () => {
  // A host clock reset to 2000, or a proxy's first or last instant: both engines parse them, but no 13-digit proof
  // timestamp can carry them. Learned (and saved), every device call would fail before it is sent, across reloads.
  const t = await setup();
  for (const text of ['2000-01-01 00:00:00.000', '0000-01-01 00:00:00.000', '9999-12-31 23:59:59.999']) {
    t.fetch.json({ url: 'api/ping.php' }, 200, { ok: true, server_time: text });
    assert.deepEqual(await t.api.get('api/ping.php'), { ok: true, server_time: text });
  }
  assert.equal(t.learned.length, 3, 'learn() was asked');
  assert.equal(t.clock.offsetMs(), null, 'and learned nothing');
  t.fetch.json({ url: HEARTBEAT }, 200, { status: 'ok' });
  assert.deepEqual(await t.api.post(HEARTBEAT, { a: 1 }, { device: true }), { status: 'ok' });
  assert.equal(t.fetch.requests.length, 4);
  assert.equal(t.fetch.requests[3].headers['pfpms-proof'].split(' ')[1], String(t.env.now()), 'signed with the tablet clock');
});

test('a clock no proof can carry makes a device call an offline ApiError, code clock, and sends nothing', async () => {
  // Every failure of a call on the network is an ApiError: proof.js's RangeError never reaches the caller.
  const env = fakeEnv();
  let now = 0;
  const clock = { serverNow: () => now, learn() {} };
  const tablet = { credential: CREDENTIAL, proofKey: await importProofKey(crypto, RAW_KEY) };
  const api = createApi({ env, clock, device: () => tablet });
  for (const at of [1e12 - 1, 1e13, 0, -1, Number.NaN, 2 ** 53]) {
    now = at;
    const e = await api.post(HEARTBEAT, { a: 1 }, { device: true }).catch((x) => x);
    assert.ok(e instanceof ApiError, `${at}: ${e}`);
    assert.deepEqual([e.kind, e.offline, e.status, e.code, e.message], ['offline', true, 0, 'clock', ''], String(at));
    await assert.rejects(api.get('api/ping.php', { device: true }), { name: 'ApiError', kind: 'offline', code: 'clock' });
  }
  assert.equal(env.fetch.requests.length, 0, 'nothing was sent');
  assert.equal(env.pendingTimers(), 0, 'every timeout timer was cleared');
  // A call that carries no proof is unaffected, and the edges of the range are signed.
  env.fetch.json({}, 200, { ok: true }, {}, { times: Infinity });
  assert.deepEqual(await api.get('api/ping.php'), { ok: true });
  now = 1e12;
  await api.post(HEARTBEAT, { a: 1 }, { device: true });
  now = 1e13 - 1;
  await api.post(HEARTBEAT, { a: 1 }, { device: true });
  assert.deepEqual(env.fetch.requests.slice(1).map((r) => r.headers['pfpms-proof'].split(' ')[1]), ['1000000000000', '9999999999999']);
  // Only that RangeError. Any other proof failure reaches the caller as it was thrown (so beatOnce logs it, instead of
  // a chip that says Offline and hides a broken key), and nothing is sent either: here a key that can only verify.
  now = 1790856000000;
  const sent = env.fetch.requests.length;
  const verifyOnly = await crypto.subtle.importKey('raw', RAW_KEY, { name: 'HMAC', hash: 'SHA-256' }, false, ['verify']);
  const broken = createApi({ env, clock, device: () => ({ credential: CREDENTIAL, proofKey: verifyOnly }) });
  for (const call of [() => broken.post(HEARTBEAT, { a: 1 }, { device: true }), () => broken.get('api/ping.php', { device: true })]) {
    const e = await call().catch((x) => x);
    assert.ok(!(e instanceof ApiError), String(e));
    assert.equal(e?.name, 'InvalidAccessError', String(e));
  }
  // The very error object, unchanged.
  const boom = new DOMException('The operation failed for an operation-specific reason.', 'OperationError');
  const real = env.crypto;
  env.crypto = { getRandomValues: (a) => real.getRandomValues(a), subtle: { digest: (...a) => real.subtle.digest(...a), sign: async () => { throw boom; } } };
  try {
    assert.equal(await api.post(HEARTBEAT, { a: 1 }, { device: true }).catch((x) => x), boom);
  } finally {
    env.crypto = real;
  }
  assert.equal(env.fetch.requests.length, sent, 'nothing was sent');
  assert.equal(env.pendingTimers(), 0, 'every timeout timer was cleared');
});

test('a csrf field in any answer replaces the token', async () => {
  const t = await setup();
  t.fetch.json({}, 200, { ok: true, csrf: 'from-ping' });
  await t.api.get('api/ping.php');
  assert.equal(t.api.csrf(), 'from-ping');
  t.fetch.json({}, 200, { user: { id: 1 }, csrf: 'after-sign-in' });
  await t.api.post('api/auth/login.php', { identifier: 'x' }, { session: true });
  assert.equal(t.fetch.requests[1].headers['x-csrf-token'], 'from-ping');
  assert.equal(t.api.csrf(), 'after-sign-in');
  t.fetch.json({}, 403, { error: 'ack_required', message: 'Accept the policy.', csrf: 'from-an-error' });
  await assert.rejects(t.api.post('api/auth/login.php', {}, { session: true }), { code: 'ack_required' });
  assert.equal(t.api.csrf(), 'from-an-error');
  t.fetch.json({}, 200, { csrf: 42 });
  await t.api.get('api/ping.php');
  assert.equal(t.api.csrf(), 'from-an-error', 'only a string replaces it');
});

test('csrf_failed refreshes the token once and retries once', async () => {
  const t = await setup();
  t.api.setCsrf('stale');
  t.fetch.json({ method: 'POST' }, 403, { error: 'csrf_failed', message: 'Refresh the page.' });
  t.fetch.json({ method: 'GET', url: 'api/session.php' }, 200, { csrf: 'fresh' });
  t.fetch.json({ method: 'POST' }, 200, { ok: true });
  const body = { identifier: 'jdoe', password: 'secret value' };
  assert.deepEqual(await t.api.post('api/auth/login.php', body, { session: true }), { ok: true });
  const [first, refresh, second] = t.fetch.requests;
  assert.deepEqual(t.fetch.requests.map((r) => r.method), ['POST', 'GET', 'POST']);
  assert.equal(first.headers['x-csrf-token'], 'stale');
  assert.ok(refresh.url.endsWith('api/session.php'));
  assert.equal(second.headers['x-csrf-token'], 'fresh');
  assert.equal(second.body, first.body, 'the same body string is resent');
  // A second csrf_failed is thrown, after exactly one refresh.
  const u = await setup();
  u.api.setCsrf('stale');
  u.fetch.json({ method: 'POST' }, 403, { error: 'csrf_failed' }, {}, { times: 2 });
  u.fetch.json({ method: 'GET' }, 200, { csrf: 'still-bad' });
  await assert.rejects(u.api.post('api/auth/login.php', {}, { session: true }), { code: 'csrf_failed' });
  assert.deepEqual(u.fetch.requests.map((r) => r.method), ['POST', 'GET', 'POST']);
  // A call without the session never retries.
  const v = await setup();
  v.fetch.json({}, 403, { error: 'csrf_failed' });
  await assert.rejects(v.api.post('api/x.php', {}), { code: 'csrf_failed' });
  assert.equal(v.fetch.requests.length, 1);
});

test('device_proof_stale retries once with a new timestamp', async () => {
  const t = await setup();
  const t0 = t.env.now();
  // The server is 10 minutes ahead: it refuses the proof and says what time it is.
  t.fetch.json({}, 401, { error: 'device_proof_stale', message: "The tablet's clock is too far from the server's.", server_time: formatDb(t0 + 600000) });
  t.fetch.json({}, 200, { status: 'ok' });
  assert.deepEqual(await t.api.post(HEARTBEAT, { n: 1 }, { device: true }), { status: 'ok' });
  const [first, second] = t.fetch.requests;
  const ts1 = Number(first.headers['pfpms-proof'].split(' ')[1]);
  const ts2 = Number(second.headers['pfpms-proof'].split(' ')[1]);
  assert.equal(ts1, t0);
  assert.equal(ts2, t0 + 600000, 'signed with the re-based clock');
  assert.equal(second.body, first.body);
  assert.equal(second.headers['pfpms-proof'].split(' ')[2], await expectedMac('POST', HEARTBEAT, ts2, second.body));
  // A second device_proof_stale is thrown.
  t.fetch.json({}, 401, { error: 'device_proof_stale', server_time: formatDb(t0) }, {}, { times: 2 });
  await assert.rejects(t.api.post(HEARTBEAT, {}, { device: true }), { kind: 'http', code: 'device_proof_stale' });
  assert.equal(t.fetch.requests.length, 4);
  // Without device: true it is an ordinary error.
  t.fetch.json({}, 401, { error: 'device_proof_stale' });
  await assert.rejects(t.api.get('api/ping.php'), { code: 'device_proof_stale' });
  assert.equal(t.fetch.requests.length, 5);
});

test('the URL is base + path and the proof signs path', async () => {
  const t = await setup();
  t.fetch.json({}, 200, { ok: true });
  await t.api.get('api/ping.php');
  assert.equal(t.fetch.requests[0].url, '../api/ping.php', 'from station/, the app root is ../');
  const sub = await setup({ base: 'https://pantry.example.org/sub/' });
  sub.fetch.json({}, 200, { ok: true });
  await sub.api.post(HEARTBEAT, { a: 1 }, { device: true });
  const r = sub.fetch.requests[0];
  assert.equal(r.url, 'https://pantry.example.org/sub/api/device/heartbeat.php');
  const [, ts, mac] = r.headers['pfpms-proof'].split(' ');
  assert.equal(mac, await expectedMac('POST', HEARTBEAT, ts, r.body), 'the endpoint signed is the path, as Request::scriptPath()');
});

test('a device GET with a query sends the query and signs the path without it (the fixture proof.get vector)', async () => {
  // Request::scriptPath() has no query, so DeviceProof::check() verifies 'api/sync/status.php' (50 §6.12, S5's status poll).
  const v = fixture('station_crypto.json').proof;
  const env = fakeEnv();
  const clock = { serverNow: () => v.ts_ms, learn() {} };
  const tablet = { credential: CREDENTIAL, proofKey: await importProofKey(crypto, fromHex(v.key_hex)) };
  const api = createApi({ env, clock, device: () => tablet });
  const query = '?uuids=123e4567-e89b-42d3-a456-426614174000,123e4567-e89b-42d3-a456-426614174001';
  env.fetch.json({}, 200, { ok: true }, {}, { times: 2 });
  await api.get(v.get.endpoint + query, { device: true });
  const r = env.fetch.requests[0];
  assert.equal(r.url, '../' + v.get.endpoint + query, 'the query is sent');
  assert.equal(r.headers['pfpms-proof'], v.get.header, 'the proof is the vector for the bare endpoint');
  await api.get(v.get.endpoint + '#frag', { device: true });
  assert.equal(env.fetch.requests[1].headers['pfpms-proof'], v.get.header, 'a fragment is never signed either');
});

test('a device call without a registered tablet throws', async () => {
  const t = await setup({ registered: false });
  await assert.rejects(t.api.post(HEARTBEAT, {}, { device: true }), (e) => e instanceof Error && !(e instanceof ApiError)
    && e.message === 'api: device call without a registered tablet');
  await assert.rejects(t.api.get('api/ping.php', { device: true }), /device call without a registered tablet/);
  assert.equal(t.fetch.requests.length, 0, 'nothing was sent');
  assert.equal(t.env.pendingTimers(), 0);
});
