// session.js, End shift / Lock device and its replay (S3 spec §3.1, §4.4; 50 §6.8, §7.5, D-24), and the final locks:
// the keys go first, whatever the network does; an End shift that could not be sent is kept and replayed with its own
// time, deleted only for that same time (or for a refusal that cannot pass; a 5xx, a 429, a CSRF or a proof-time refusal
// is kept); a waiting update reload runs only once the End shift is stored and answered; a directive, an erase or a
// replaced window makes the session inert. A request reaches fake-fetch some turns after the call (its proof's HMAC):
// positive checks wait with until(), "nothing sent" checks count h.calls().
import test from 'node:test';
import assert from 'node:assert/strict';
import { stationHarness, logoutReply, loginReply, touchReply, waitingUpdate, CREDENTIAL, PASSWORD, deferred, until } from './support/fake-station.js';
import { formatDb } from '../../public/station/js/canonical.js';
import { TOUCH_EVERY_MS } from '../../public/station/js/session.js';

const LOGOUT = 'api/auth/logout.php';
const LOGIN = 'api/auth/login.php';
const SESSION = 'api/session.php';
const strs = (log) => log.filter((x) => typeof x === 'string');
const bodyOf = (r) => JSON.parse(r.body);
const OLD = '2026-10-01 11:00:00.000';

async function active() {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  return h;
}

test('End shift locks hard at once, posts logout scope device and writes meta.shift_ended_at', async () => {
  const h = await active();
  const held = deferred();
  h.answer(LOGOUT, 200, logoutReply(), { until: held.promise });
  const at = formatDb(h.env.now());
  const p = h.session.endShift();
  assert.equal(h.session.state(), 'LOCKED', 'before any await');
  assert.equal(h.session.hasVaultKey(), false);
  assert.deepEqual(h.session.peopleForPicker(), []);
  assert.deepEqual(h.session.takeNotice(), { key: 'shift_ended' });
  await until(() => h.requests(LOGOUT).length === 1);
  const [req] = h.requests(LOGOUT);
  assert.deepEqual(bodyOf(req), { scope: 'device' });
  assert.equal(req.headers.authorization, 'PFPMS-Device ' + CREDENTIAL);
  assert.equal(req.headers['x-csrf-token'], 'csrf-2');
  assert.equal(h.meta('shift_ended_at'), at);
  assert.deepEqual(h.meta('pending_shift_end'), { at });
  held.resolve();
  await p;
  assert.equal(h.meta('pending_shift_end'), undefined, 'sent: nothing to replay');
  assert.equal(h.meta('shift_ended_at'), at, 'kept for S4');
  assert.equal(h.rows('vault_users').length, 1, 'the vault users stay (sealed) for the next sign-in');
});

test('End shift offline still locks and keeps pending_shift_end {at}', async () => {
  const h = await active();
  h.f.offline({ url: LOGOUT });
  const at = formatDb(h.env.now());
  await h.session.endShift();
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.session.hasVaultKey(), false);
  assert.deepEqual(h.meta('pending_shift_end'), { at });
  assert.equal(h.meta('shift_ended_at'), at);
  assert.deepEqual(h.connectivity.at(-1), ['offline', 'network']);
  const r = await stationHarness();
  assert.deepEqual(await r.signIn(), { ok: true });
  r.fail(LOGOUT, 400, 'bad_request', 'Unknown scope.', { field: 'scope' });
  await r.session.endShift();
  assert.equal(r.meta('pending_shift_end'), undefined, 'refused: it cannot succeed later');
  assert.equal(r.session.state(), 'LOCKED');
});

test('replayShiftEnd sends ended_at once and deletes the record only after a 200 for that same time', async () => {
  const h = await stationHarness({ seed: { meta: { pending_shift_end: { at: OLD } } } });
  const held = deferred();
  h.answer(LOGOUT, 200, logoutReply(), { until: held.promise });
  const a = h.session.replayShiftEnd();
  const b = h.session.replayShiftEnd();
  assert.equal(a, b, 'single flight');
  await until(() => h.requests(LOGOUT).length === 1);
  assert.equal(h.calls(LOGOUT).length, 1, 'one call');
  assert.deepEqual(bodyOf(h.requests(LOGOUT)[0]), { scope: 'device', ended_at: OLD });
  assert.deepEqual(h.meta('pending_shift_end'), { at: OLD }, 'kept until the answer');
  held.resolve();
  await a;
  assert.equal(h.meta('pending_shift_end'), undefined);
  await h.session.replayShiftEnd();
  assert.equal(h.calls(LOGOUT).length, 1, 'nothing pending: nothing sent (no call made, so no request can follow)');
  assert.equal(h.requests(LOGOUT).length, 1);
  assert.equal(h.session.state(), 'LOCKED');
});

test('a newer End shift while a replay is in flight is not deleted by the older answer', async () => {
  const h = await stationHarness({ seed: { meta: { pending_shift_end: { at: OLD } } } });
  const held = deferred();
  h.answer(LOGOUT, 200, logoutReply(), { until: held.promise });
  h.f.offline({ url: LOGOUT });
  const replay = h.session.replayShiftEnd();
  await until(() => h.requests(LOGOUT).length === 1); // the replay takes the held reply, so End shift gets the offline one
  assert.deepEqual(await h.signIn(), { ok: true });
  const newer = formatDb(h.env.now());
  assert.notEqual(newer, OLD);
  await h.session.endShift();
  assert.deepEqual(h.meta('pending_shift_end'), { at: newer });
  held.resolve();
  await replay;
  assert.deepEqual(h.meta('pending_shift_end'), { at: newer }, 'the older answer leaves the newer End shift for its own replay');
  assert.deepEqual(h.requests(LOGOUT).map((r) => bodyOf(r)), [{ scope: 'device', ended_at: OLD }, { scope: 'device' }]);
  assert.equal(h.f.pending(), 0, 'the replay was answered 200');
});

test('a refused replay is dropped; an offline, 5xx, 429, CSRF-refused or proof-time-refused one is kept', async () => {
  const h = await stationHarness({ seed: { meta: { pending_shift_end: { at: OLD } } } });
  h.f.offline({ url: LOGOUT });
  await h.session.replayShiftEnd();
  assert.deepEqual(h.meta('pending_shift_end'), { at: OLD }, 'offline: kept');
  h.fail(LOGOUT, 503, 'maintenance', 'Back soon.');
  await h.session.replayShiftEnd();
  assert.deepEqual(h.meta('pending_shift_end'), { at: OLD }, '503: kept');
  h.fail(LOGOUT, 500, 'server_error', 'Something went wrong.', { incident: 'AB12CD34' });
  await h.session.replayShiftEnd();
  assert.deepEqual(h.meta('pending_shift_end'), { at: OLD }, '500: kept (a deadlock, a database restart)');
  h.fail(LOGOUT, 429, 'rate_limited', 'Too many requests.');
  await h.session.replayShiftEnd();
  assert.deepEqual(h.meta('pending_shift_end'), { at: OLD }, '429: kept');
  h.fail(LOGOUT, 400, 'csrf_failed', 'Expired.', {}, { times: 2 });
  h.answer(SESSION, 200, touchReply({ user: null, session_ref: null }));
  await h.session.replayShiftEnd();
  assert.deepEqual(h.meta('pending_shift_end'), { at: OLD }, 'csrf_failed after api.js\'s one retry: kept');
  h.fail(LOGOUT, 401, 'device_proof_stale', 'Check the clock.', {}, { times: 2 });
  await h.session.replayShiftEnd();
  assert.deepEqual(h.meta('pending_shift_end'), { at: OLD }, 'device_proof_stale after api.js\'s one retry: kept');
  h.fail(LOGOUT, 400, 'bad_request', 'Unknown scope.', { field: 'scope' });
  await h.session.replayShiftEnd();
  assert.equal(h.meta('pending_shift_end'), undefined, 'refused: dropped');
  assert.equal(h.requests(LOGOUT).length, 9);
  assert.ok(h.requests(LOGOUT).every((r) => bodyOf(r).ended_at === OLD), 'every replay carries its own time');
  const m = await stationHarness({ seed: { meta: { pending_shift_end: 'not a record' } } });
  await m.session.replayShiftEnd();
  assert.equal(m.meta('pending_shift_end'), undefined, 'an unreadable record is dropped unsent');
  assert.equal(m.requests(LOGOUT).length, 0);
});

test('an End shift answered 500, 429, a CSRF or a proof-time refusal is kept, and the next replay sends it with its own time', async () => {
  // csrf_failed and device_proof_stale twice: api.js retries each once, so the second refusal reaches the session.
  const cases = [[500, 'server_error', { incident: 'AB12CD34' }, 1], [429, 'rate_limited', {}, 1], [400, 'csrf_failed', {}, 2],
    [401, 'device_proof_stale', {}, 2]];
  for (const [status, code, extra, times] of cases) {
    const h = await active();
    const at = formatDb(h.env.now());
    h.fail(LOGOUT, status, code, 'Not now.', extra, { times });
    if (code === 'csrf_failed') h.answer(SESSION, 200, touchReply({ user: null, session_ref: null }));
    await h.session.endShift();
    assert.equal(h.session.state(), 'LOCKED', code);
    assert.deepEqual(h.meta('pending_shift_end'), { at }, `${code}: kept, so the server still ends the sessions and closes the PIN window`);
    assert.deepEqual(h.connectivity.at(-1), ['online', null], 'the server answered');
    await h.env.advance(60000);
    h.answer(LOGOUT, 200, logoutReply());
    await h.session.replayShiftEnd();
    assert.deepEqual(bodyOf(h.requests(LOGOUT).at(-1)), { scope: 'device', ended_at: at }, 'the replay carries the End shift\'s own time');
    assert.equal(h.meta('pending_shift_end'), undefined, 'answered 200: deleted');
  }
  const u = await active();
  u.fail(LOGOUT, 401, 'device_unknown', 'Unknown tablet.');
  await u.session.endShift();
  assert.equal(u.meta('pending_shift_end'), undefined, 'a 401 of the tablet itself cannot pass later: dropped');
});

test('End shift during a sign-in while an update waits: the reload comes only after the End shift is stored and answered', async () => {
  const h = await stationHarness();
  const seen = [];
  const rule = await waitingUpdate(h, () => seen.push({ state: h.session.state(), shiftEnded: h.meta('shift_ended_at') ?? null,
    pending: h.meta('pending_shift_end') ?? null, sent: h.requests(LOGOUT).length }));
  const login = deferred();
  h.answer(LOGIN, 200, loginReply(), { until: login.promise });
  const out = deferred();
  h.answer(LOGOUT, 200, logoutReply(), { until: out.promise });
  const p = h.session.signIn('jdoe', PASSWORD);
  rule.onControllerChange(); // the new worker took over while the sign-in was in flight: the reload waits for it
  await until(() => h.requests(LOGIN).length === 1);
  const at = formatDb(h.env.now());
  const ending = h.session.endShift();
  assert.equal(h.session.busy(), false, 'the hard lock still ends the sign-in\'s flight at once');
  assert.deepEqual(seen, [], 'the End shift holds the reload the sign-in\'s hold was keeping');
  await until(() => h.requests(LOGOUT).length === 1);
  assert.deepEqual([h.meta('shift_ended_at'), h.meta('pending_shift_end')], [at, { at }]);
  assert.deepEqual(seen, [], 'sent, not answered yet: still held');
  out.resolve();
  await ending;
  assert.deepEqual(seen, [{ state: 'LOCKED', shiftEnded: at, pending: null, sent: 1 }], 'once answered and the record deleted');
  assert.deepEqual(h.updater.calls, ['begin signin', 'begin end_shift', 'end signin', 'end end_shift']);
  login.resolve();
  assert.deepEqual(await p, { ok: false }, 'the late answer changes nothing');
  assert.equal(seen.length, 1);
  const o = await stationHarness(); // offline: the reload runs once the logout failed, with the record kept for the replay
  const later = [];
  const r2 = await waitingUpdate(o, () => later.push(o.meta('pending_shift_end') ?? null));
  o.f.hang({ url: LOGIN });
  o.f.offline({ url: LOGOUT });
  void o.session.signIn('jdoe', PASSWORD);
  r2.onControllerChange();
  await until(() => o.requests(LOGIN).length === 1);
  const at2 = formatDb(o.env.now());
  const ended = o.session.endShift();
  assert.deepEqual(later, []);
  await ended;
  assert.deepEqual(later, [{ at: at2 }]);
});

test('lock(\'hard\', \'directive\'|\'erased\'|\'replaced\') is final: no sign-in, touch or write afterwards', async () => {
  for (const reason of ['directive', 'erased', 'replaced']) {
    const h = await stationHarness({ seed: { meta: { pending_shift_end: { at: OLD } } } });
    assert.deepEqual(await h.signIn(), { ok: true });
    await h.session.lock('hard', reason);
    assert.equal(h.session.state(), 'LOCKED', reason);
    assert.equal(h.session.hasVaultKey(), false);
    assert.equal(h.session.takeNotice(), null, 'no notice: the wipe or the other window shows its own screen');
    h.log.length = 0;
    const sent = h.f.requests.length;
    const called = h.calls().length;
    h.answer(SESSION, 200, touchReply(), { times: Infinity });
    h.answer(LOGOUT, 200, logoutReply(), { times: Infinity });
    assert.deepEqual(await h.session.signIn('jdoe', 'Correct-Horse-Battery-9'), { ok: false, message: { key: 'signin_failed' } });
    assert.deepEqual(await h.session.pinSwitch(12, '4821'), { ok: false, message: { key: 'signin_failed' } });
    await h.env.advance(TOUCH_EVERY_MS);
    await h.session.touch();
    await h.session.tick();
    await h.session.endShift();
    await h.session.replayShiftEnd();
    await h.session.onRevokedGrants([345]);
    await h.session.onOfflineDisabled();
    await h.session.cancelGate();
    assert.equal(h.calls().length, called, `${reason}: nothing is sent (no call made, so no request can follow)`);
    assert.equal(h.f.requests.length, sent);
    assert.deepEqual(strs(h.log).filter((l) => l.startsWith('db.')), [], `${reason}: nothing is written`);
    assert.equal(h.rows('keyring').length, 1, 'the keyring is the wipe\'s to clear');
    assert.deepEqual(h.meta('pending_shift_end'), { at: OLD });
    assert.equal(h.requests(LOGIN).length, 1);
  }
});
