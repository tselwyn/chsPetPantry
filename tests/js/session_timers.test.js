// session.js, the timers (S3 spec §3.1 tick()/touch(), §4.4; 50 §7.4, §7.5, D-05): idle on both clocks, the absolute
// limit from the last password sign-in, grant expiry, a clock set back, sleep, the gate's two deadlines, the pending
// keep-alive, and lock()'s kinds. app.js's 15-s interval (clock.tick(), then session.tick()) runs on the fake timers.
// A request reaches fake-fetch some turns after the call (a device proof's HMAC): positive checks wait with until(),
// "nothing sent" checks count h.calls().
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  stationHarness, release, personJson, pinReply, logoutReply, touchReply, passwordReply, acceptReply, policyDoc, SERVER_TIME, appTicks,
  deferred, until, waitingUpdate,
} from './support/fake-station.js';
import { formatDb, parseDb } from '../../public/station/js/canonical.js';
import { GATE_KEK_MS, TOUCH_EVERY_MS } from '../../public/station/js/session.js';

const MIN = 60000;
const HOUR = 3600000;
const PIN = 'api/auth/pin.php';
const LOGOUT = 'api/auth/logout.php';
const SESSION = 'api/session.php';
const POLICY = 'api/auth/policy.php';
const PASSWORD_URL = 'api/auth/password.php';
const HEARTBEAT = 'api/device/heartbeat.php';
const B = personJson(13, 'coord', 'Cam Coord');

async function active(over = {}) {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(over), { ok: true });
  appTicks(h);
  return h;
}
async function gated(kind = 'policy_ack') {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn({ gate: kind, release: null }), { ok: true, gate: kind });
  appTicks(h);
  return h;
}

test('no input for session_idle_minutes moves ACTIVE to IDLE and keeps the keys', async () => {
  const h = await active();
  await h.env.advance(30 * MIN - 15000);
  assert.equal(h.session.state(), 'ACTIVE');
  await h.env.advance(15000);
  assert.equal(h.session.state(), 'IDLE');
  assert.equal(h.session.hasVaultKey(), true);
  assert.equal(h.session.user(), null);
  assert.deepEqual(h.session.takeNotice(), { key: 'notice_idle' });
  assert.deepEqual(h.session.peopleForPicker().map((p) => p.user_id), [12]);
  assert.equal(h.requests(LOGOUT).length, 0, 'the server session times out by itself');
});

test('only touch() extends the idle timer: heartbeats, answers and ticks do not', async () => {
  const h = await active();
  h.answer(HEARTBEAT, 200, { status: 'ok', offline_enabled: true, revoked_grants: [], config: {}, server_time: SERVER_TIME, build: 'test+0000000000' });
  h.answer('api/auth/pin_set.php', 200, { ok: true, revoked_grants: [], server_time: SERVER_TIME });
  await h.env.advance(10 * MIN);
  await h.device.heartbeat();
  assert.deepEqual(await h.session.setPin('pw', '4821', '4821'), { ok: true });
  await h.env.advance(20 * MIN);
  assert.equal(h.session.state(), 'IDLE', '30 minutes after the sign-in, whatever the network did');
  h.answer(PIN, 200, pinReply());
  h.answer(SESSION, 200, touchReply({ session_ref: 'b'.repeat(64) }), { times: Infinity });
  assert.deepEqual(await h.session.pinSwitch(12, '4821'), { ok: true });
  await h.env.advance(20 * MIN);
  await h.session.touch();
  await h.env.advance(29 * MIN);
  assert.equal(h.session.state(), 'ACTIVE', 'a touch at 20 minutes moves the limit to 50');
  await h.env.advance(1 * MIN);
  assert.equal(h.session.state(), 'IDLE');
});

test('after a sleep (mono paused, wall +2 h) the next tick locks', async () => {
  const h = await active();
  const mono = h.env.mono();
  await h.env.sleep(2 * HOUR);
  assert.equal(h.env.mono(), mono, 'performance.now() stopped');
  assert.equal(h.session.state(), 'IDLE', 'the interval\'s first run after the wake');
  assert.equal(h.session.user(), null);
});

test('session_absolute_hours after the last password sign-in locks hard; PIN switches do not extend it', async () => {
  const h = await active();
  h.session.onConfig({ session_idle_minutes: 100000 });
  await h.env.advance(6 * HOUR);
  h.session.switchUser();
  h.answer(PIN, 200, pinReply());
  assert.deepEqual(await h.session.pinSwitch(12, '4821'), { ok: true });
  h.answer(LOGOUT, 200, logoutReply());
  await h.env.advance(6 * HOUR - 15000);
  assert.equal(h.session.state(), 'ACTIVE');
  await h.env.advance(15000);
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.session.hasVaultKey(), false);
  assert.deepEqual(h.session.takeNotice(), { key: 'notice_absolute' });
  await until(() => h.requests(LOGOUT).length === 1);
  assert.deepEqual(JSON.parse(h.requests(LOGOUT)[0].body), { scope: 'user' }, 'the ACTIVE person is signed out on the server');
});

test('the active person\'s grant expiring drops them to PICKER and deletes their entries', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn({ user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }, { identifier: 'coord' }), { ok: true });
  h.session.switchUser();
  assert.deepEqual(await h.signIn({ release: release({ grant_expires_at: formatDb(parseDb(SERVER_TIME) + 10 * MIN) }) }), { ok: true });
  appTicks(h);
  h.answer(LOGOUT, 200, logoutReply());
  await h.env.advance(10 * MIN - 15000);
  assert.equal(h.session.state(), 'ACTIVE');
  await h.env.advance(15000);
  await until(() => h.requests(LOGOUT).length === 1 && h.rows('vault_users').length === 1);
  assert.equal(h.session.state(), 'PICKER');
  assert.deepEqual(h.session.takeNotice(), { key: 'grant_expired' });
  assert.deepEqual(h.rows('keyring').map((r) => r.user_id), [13]);
  assert.deepEqual(h.rows('vault_users').map((r) => r.k), ['user:13']);
  assert.deepEqual(h.session.peopleForPicker().map((p) => p.user_id), [13]);
  assert.deepEqual(h.calls(LOGOUT).map((c) => c.body), [{ scope: 'user' }]);
});

test('another person\'s grant expiring never drops the ACTIVE person; only that grant\'s entries go', async () => {
  const h = await stationHarness();
  const soon = release({ grant_id: 300, grant_expires_at: formatDb(parseDb(SERVER_TIME) + MIN) });
  assert.deepEqual(await h.signIn({ user: B, session_ref: 'c'.repeat(64), release: soon }, { identifier: 'coord' }), { ok: true });
  h.session.switchUser();
  assert.deepEqual(await h.signIn(), { ok: true }); // 12, grant 345 (3 days)
  await h.env.advance(MIN + 1000);
  await h.session.tick();
  await until(() => h.rows('vault_users').length === 1);
  assert.equal(h.session.state(), 'ACTIVE');
  assert.equal(h.session.user().user_id, 12);
  assert.equal(h.session.canRecord(), true);
  assert.equal(h.session.takeNotice(), null);
  assert.equal(h.calls(LOGOUT).length, 0, 'nobody is signed out');
  assert.deepEqual(h.rows('vault_users').map((r) => r.k), ['user:12']);
  assert.deepEqual(h.rows('keyring').map((r) => r.user_id), [12]);
});

test('canRecord() is false once the ACTIVE person\'s grant has expired, before any tick drops them', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn({ release: release({ grant_expires_at: formatDb(parseDb(SERVER_TIME) + MIN) }) }), { ok: true });
  assert.equal(h.session.canRecord(), true);
  await h.env.advance(MIN - 1);
  assert.equal(h.session.canRecord(), true, 'one millisecond before the expiry');
  await h.env.advance(1);
  assert.equal(h.session.state(), 'ACTIVE', 'no tick ran: the person is still at work');
  assert.equal(h.session.canRecord(), false, 'nothing more can be recorded with an expired grant');
});

test('a clock set back never extends idle or the absolute limit', async () => {
  const h = await active();
  h.env.jumpWall(-HOUR);
  await h.env.advance(30 * MIN - 15000);
  assert.equal(h.session.state(), 'ACTIVE');
  await h.env.advance(15000);
  assert.equal(h.session.state(), 'IDLE', 'idle on the monotonic clock');
  const a = await active();
  a.session.onConfig({ session_idle_minutes: 100000 });
  a.answer(LOGOUT, 200, logoutReply());
  a.env.jumpWall(-2 * HOUR);
  await a.env.advance(12 * HOUR - 15000);
  assert.equal(a.session.state(), 'ACTIVE');
  await a.env.advance(15000);
  assert.equal(a.session.state(), 'LOCKED', 'clock.tick() re-based serverNow(): 12 hours, not 14');
  assert.deepEqual(a.session.takeNotice(), { key: 'notice_absolute' });
});

test('IDLE shows the same people as PICKER and a PIN brings the person back', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  h.session.switchUser();
  const inPicker = h.session.peopleForPicker();
  assert.deepEqual(await h.signIn({ user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }, { identifier: 'coord' }), { ok: true });
  h.session.switchUser();
  const both = h.session.peopleForPicker();
  assert.equal(both.length, 2);
  assert.deepEqual(inPicker.map((p) => p.user_id), [12]);
  h.answer(PIN, 200, pinReply({ user: B, session_ref: 'd'.repeat(64) }));
  assert.deepEqual(await h.session.pinSwitch(13, '4821'), { ok: true });
  appTicks(h);
  await h.env.advance(30 * MIN);
  assert.equal(h.session.state(), 'IDLE');
  assert.deepEqual(h.session.peopleForPicker().map((p) => [p.user_id, p.has_pin]), [[13, true], [12, false]]);
  h.answer(PIN, 200, pinReply({ user: B, session_ref: 'e'.repeat(64) }));
  assert.deepEqual(await h.session.pinSwitch(13, '4821'), { ok: true });
  assert.equal(h.session.state(), 'ACTIVE');
  assert.equal(h.session.user().user_id, 13);
});

test('the gate expires after 10 minutes on the monotonic clock', async () => {
  const h = await gated();
  h.env.jumpWall(-HOUR); // a wall clock set back never extends it
  await h.env.advance(GATE_KEK_MS - 15000);
  assert.equal(h.session.state(), 'GATE');
  await h.env.advance(15000);
  assert.equal(h.session.state(), 'LOCKED');
  assert.deepEqual(h.session.takeNotice(), { key: 'gate_expired' });
  assert.equal(h.session.gate(), null);
});

test('the gate expiring signs the gated person out on the server once: at the tick, and at an acceptance tried too late', async () => {
  for (const kind of ['policy_ack', 'password_change']) {
    const h = await gated(kind);
    h.answer(LOGOUT, 200, logoutReply());
    await h.env.advance(GATE_KEK_MS);
    assert.equal(h.session.state(), 'LOCKED', kind);
    assert.deepEqual(h.session.takeNotice(), { key: 'gate_expired' });
    assert.deepEqual(h.calls(LOGOUT).map((c) => c.body), [{ scope: 'user' }], 'Logout, as Cancel: no acceptance can release to it later');
    await until(() => h.requests(LOGOUT).length === 1);
    assert.equal(h.requests(LOGOUT)[0].headers['x-csrf-token'], 'csrf-2', 'with the gated person\'s cookie and token');
    await h.env.advance(15000);
    assert.equal(h.calls(LOGOUT).length, 1, 'once: the next tick finds no gate');
  }
  const a = await stationHarness(); // no interval: the acceptance itself finds the 10 minutes over
  assert.deepEqual(await a.signIn({ gate: 'policy_ack', release: null }), { ok: true, gate: 'policy_ack' });
  a.answer(LOGOUT, 200, logoutReply());
  await a.env.advance(GATE_KEK_MS);
  assert.deepEqual(await a.session.acceptPolicy(policyDoc()), { ok: false, message: { key: 'gate_expired' } });
  assert.deepEqual(a.calls(LOGOUT).map((c) => c.body), [{ scope: 'user' }]);
  assert.equal(a.calls(POLICY).length, 0, 'the acceptance is not sent');
});

test('a gate expiring during an acceptance while an update waits: the reload comes only after the gate\'s sign-out is answered', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn({ gate: 'policy_ack', release: null }), { ok: true, gate: 'policy_ack' });
  const seen = [];
  const rule = await waitingUpdate(h, () => seen.push({ state: h.session.state(), sent: h.requests(LOGOUT).length }));
  await h.env.advance(GATE_KEK_MS - 5000);
  const accept = deferred();
  h.answer(POLICY, 200, acceptReply(), { until: accept.promise });
  const out = deferred();
  h.answer(LOGOUT, 200, logoutReply(), { until: out.promise });
  h.updater.calls.length = 0;
  const p = h.session.acceptPolicy(policyDoc());
  rule.onControllerChange(); // the new worker took over during the acceptance: the reload waits for it
  await until(() => h.requests(POLICY).length === 1);
  await h.env.advance(5000);
  await h.session.tick(); // the 10 minutes are over: expireGate()
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.session.busy(), false, 'the acceptance\'s flight ended at once');
  assert.deepEqual(seen, [], 'the sign-out holds the reload the acceptance\'s hold was keeping');
  await until(() => h.requests(LOGOUT).length === 1);
  assert.deepEqual(seen, [], 'sent, not answered yet: still held');
  out.resolve();
  await until(() => seen.length === 1);
  assert.deepEqual(seen, [{ state: 'LOCKED', sent: 1 }]);
  assert.deepEqual(h.updater.calls, ['begin signin', 'begin logout', 'end signin', 'end logout']);
  accept.resolve();
  assert.deepEqual(await p, { ok: false }, 'the late acceptance changes nothing');
  assert.equal(seen.length, 1);
});

test('two sign-outs at once hold a waiting reload until both are answered', async () => {
  const h = await stationHarness();
  const seen = [];
  const rule = await waitingUpdate(h, () => seen.push(h.requests(LOGOUT).length));
  const posts = [];
  const post = h.api.post;
  h.api.post = (path, ...rest) => { const r = post(path, ...rest); if (path === LOGOUT) posts.push(r); return r; };
  const first = deferred();
  const second = deferred();
  h.answer(LOGOUT, 200, logoutReply(), { until: first.promise });
  h.answer(LOGOUT, 200, logoutReply(), { until: second.promise });
  assert.deepEqual(await h.signIn({ gate: 'policy_ack', release: null }), { ok: true, gate: 'policy_ack' });
  await h.session.cancelGate(); // the first sign-out
  rule.onControllerChange(); // the reload waits for it
  assert.deepEqual(await h.signIn({ user: B, session_ref: 'c'.repeat(64), gate: 'policy_ack', release: null }, { identifier: 'coord' }),
    { ok: true, gate: 'policy_ack' });
  await h.session.cancelGate(); // the second, while the first is still out
  await until(() => h.requests(LOGOUT).length === 2);
  first.resolve();
  await posts[0];
  assert.deepEqual(seen, [], 'the first answer alone does not free the reload: the second sign-out still holds it');
  second.resolve();
  await posts[1];
  assert.deepEqual(seen, [2]);
  assert.equal(h.updater.calls.filter((c) => c.endsWith(' logout')).join(), 'begin logout,end logout', 'one hold of the kind');
});

test('an acceptance answered after the 10 minutes (no tick between) locks with gate_expired, writes nothing and signs out', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn({ gate: 'policy_ack', release: null }), { ok: true, gate: 'policy_ack' });
  const held = deferred();
  h.answer(POLICY, 200, acceptReply(), { until: held.promise });
  h.answer(LOGOUT, 200, logoutReply());
  const p = h.session.acceptPolicy(policyDoc());
  await until(() => h.requests(POLICY).length === 1);
  h.env.jumpWall(GATE_KEK_MS); // serverNow() passes the gate's untilServer (as after a sleep); no timer runs, no tick
  held.resolve();
  assert.deepEqual(await p, { ok: false, message: { key: 'gate_expired' } }, 'the kept KEK is never used after its 10 minutes');
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.session.hasVaultKey(), false);
  assert.deepEqual([h.rows('keyring'), h.rows('vault_users')], [[], []]);
  assert.ok(!h.log.includes('vault.wrapDvk') && !h.log.includes('vault.openVault'));
  assert.deepEqual(h.calls(LOGOUT).map((c) => c.body), [{ scope: 'user' }], 'the server released: its session (and so the grant\'s use) ends');
});

test('at the gate, a sleep (mono paused, wall +11 min) makes the next tick lock hard with gate_expired, and an acceptance then writes nothing', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  h.session.switchUser();
  assert.deepEqual(await h.signIn({ user: B, session_ref: 'c'.repeat(64), gate: 'policy_ack', release: null }, { identifier: 'coord' }), { ok: true, gate: 'policy_ack' });
  appTicks(h);
  await h.env.sleep(11 * MIN);
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.session.hasVaultKey(), false);
  assert.deepEqual(h.session.takeNotice(), { key: 'gate_expired' });
  assert.deepEqual(await h.session.acceptPolicy(policyDoc()), { ok: false });
  assert.equal(h.requests(POLICY).length, 0);
  assert.deepEqual(h.rows('keyring').map((r) => r.user_id), [12], 'nothing for the gated person');
});

test('after a password change the gate\'s two deadlines restart for the agreement', async () => {
  const h = await gated('password_change');
  await h.env.advance(8 * MIN);
  h.answer(PASSWORD_URL, 200, passwordReply({ gate: 'policy_ack', release: null }));
  assert.deepEqual(await h.session.changePassword('Correct-Horse-Battery-9', 'A sentence I will remember 42'), { ok: true, gate: 'policy_ack' });
  await h.env.sleep(9 * MIN);
  assert.equal(h.session.state(), 'GATE', '17 minutes after the sign-in, 9 after the change');
  await h.env.advance(MIN + 15000);
  assert.equal(h.session.state(), 'LOCKED', 'serverNow() passed the restarted 10 minutes');
  assert.deepEqual(h.session.takeNotice(), { key: 'gate_expired' });
  const m = await gated('password_change');
  await m.env.advance(8 * MIN);
  m.answer(PASSWORD_URL, 200, passwordReply({ gate: 'policy_ack', release: null }));
  assert.deepEqual(await m.session.changePassword('Correct-Horse-Battery-9', 'A sentence I will remember 42'), { ok: true, gate: 'policy_ack' });
  await m.env.advance(10 * MIN - 15000);
  assert.equal(m.session.state(), 'GATE', 'the monotonic deadline restarted too');
  await m.env.advance(15000);
  assert.equal(m.session.state(), 'LOCKED');
});

test('idle on a tablet without keys goes to LOCKED, not IDLE', async () => {
  const h = await active({ release: null, release_unavailable: 'no_vault_key' });
  await h.env.advance(30 * MIN);
  assert.equal(h.session.state(), 'LOCKED');
  assert.deepEqual(h.session.takeNotice(), { key: 'notice_idle' });
  assert.deepEqual(h.session.peopleForPicker(), []);
});

test('switchUser without keys goes to LOCKED', async () => {
  const h = await active({ release: null, release_unavailable: 'no_grant_possible' });
  h.session.switchUser();
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.session.user(), null);
  const k = await active();
  k.session.switchUser();
  assert.equal(k.session.state(), 'PICKER');
});

test('input after a touch, then none: one keep-alive goes once the interval has passed; no input, no keep-alive', async () => {
  const h = await active();
  h.answer(SESSION, 200, touchReply(), { times: Infinity });
  await h.env.advance(MIN);
  await h.session.touch();
  assert.equal(h.calls(SESSION).length, 0);
  await h.env.advance(TOUCH_EVERY_MS - MIN - 15000);
  assert.equal(h.calls(SESSION).length, 0, 'not before the interval');
  await h.env.advance(15000);
  assert.equal(h.calls(SESSION).length, 1, 'the pending keep-alive goes with the tick');
  await h.env.advance(15 * MIN);
  assert.equal(h.calls(SESSION).length, 1, 'no input since: no keep-alive');
  const n = await active();
  n.answer(SESSION, 200, touchReply(), { times: Infinity });
  await n.env.advance(25 * MIN);
  assert.equal(n.calls(SESSION).length, 0, 'no input, no keep-alive');
});

test('after a sleep (mono paused, wall +6 min) the first input sends the touch', async () => {
  const h = await active();
  h.answer(SESSION, 200, touchReply());
  await h.env.sleep(6 * MIN);
  assert.equal(h.calls(SESSION).length, 0, 'the wake\'s tick sends nothing without input');
  await h.session.touch();
  assert.equal(h.calls(SESSION).length, 1);
  assert.equal(h.session.state(), 'ACTIVE');
});

test('lock() with any kind but hard is ignored', async () => {
  const h = await active();
  for (const kind of ['soft', 'idle', undefined, 'HARD']) await h.session.lock(kind, 'directive');
  assert.equal(h.session.state(), 'ACTIVE');
  assert.equal(h.session.hasVaultKey(), true);
  assert.ok(h.env.logs.includes('lock ignored: soft') && h.env.logs.includes('lock ignored: undefined'), h.env.logs.join(' | '));
  assert.deepEqual(await h.signIn({ session_ref: 'c'.repeat(64) }), { ok: false, message: { key: 'signin_failed' } }, 'ACTIVE: no sign-in over it');
});
