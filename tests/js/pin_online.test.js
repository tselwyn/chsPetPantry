// session.js, the PIN online (S3 spec §3.1, §4.4; 50 §6.6, §6.7, D-22, D-25): the picker, the PIN switch's request,
// the verifier stored only after the server's OK, wrong PINs, the way back to the password, the offline seam and the
// 3-second deadline, the absolute limit, and setPin's local rules, success and errors. Over the REAL S2 modules. A
// request reaches fake-fetch some turns after the call (its proof's HMAC): checks on it wait with until().
import test from 'node:test';
import assert from 'node:assert/strict';
import { flush } from './support/fake-env.js';
import {
  stationHarness, release, personJson, pinReply, pinSetReply, logoutReply, loginReply, touchReply, PASSWORD, CREDENTIAL, SERVER_TIME, deferred,
  openVaultUser, vaultKeys, until,
} from './support/fake-station.js';
import * as V from '../../public/station/js/vault.js';
import { formatDb, parseDb } from '../../public/station/js/canonical.js';
import { PIN_TIMEOUT_MS, pinProblem } from '../../public/station/js/session.js';

const PIN = 'api/auth/pin.php';
const PIN_SET = 'api/auth/pin_set.php';
const LOGOUT = 'api/auth/logout.php';
const LOGIN = 'api/auth/login.php';
const SESSION = 'api/session.php';
const settle = () => flush(40);
const stepped = async (h, ms, step = 250) => { for (let t = 0; t < ms; t += step) { await h.env.advance(step); await settle(); } };
const strs = (log) => log.filter((x) => typeof x === 'string');
const bodyOf = (r) => JSON.parse(r.body);
const NAMES = ['jdoe', 'Jo Doe', 'Jo.Doe', 'coord', 'Cam Coord'];
const B = personJson(13, 'coord', 'Cam Coord');
/** 4821 in full-width digits: not digits to PHP's \d without /u, nor to the tablet. */
const WIDE = String.fromCharCode(0xff14, 0xff18, 0xff12, 0xff11);

/** A in vault_users (grant 345), then B; then Switch user: the picker, with keys. */
async function picker({ second = true } = {}) {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  if (second) {
    h.session.switchUser();
    assert.deepEqual(await h.signIn({ user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }, { identifier: 'coord' }), { ok: true });
  }
  h.session.switchUser();
  assert.equal(h.session.state(), 'PICKER');
  return h;
}
const verifierFor = async (uid, pin) => V.pinVerifier(crypto, (await vaultKeys()).pin, uid, pin);
const ctOf = (h, uid) => h.rows('vault_users').find((r) => r.k === 'user:' + uid)?.ct;

test('the picker lists people with a live grant in vault_users, by name', async () => {
  const h = await stationHarness();
  const soon = formatDb(parseDb(SERVER_TIME) + 120000);
  assert.deepEqual(await h.signIn({ user: personJson(20, 'zed', 'Zed Zulu'), session_ref: 'd'.repeat(64), release: release({ grant_id: 400 }) }), { ok: true });
  h.session.switchUser();
  assert.deepEqual(await h.signIn({ user: personJson(21, 'amy', 'Amy Able'), session_ref: 'e'.repeat(64), release: release({ grant_id: 401, grant_expires_at: soon }) }), { ok: true });
  h.session.switchUser();
  assert.deepEqual(await h.signIn(), { ok: true });
  h.session.switchUser();
  assert.deepEqual(h.session.peopleForPicker(), [
    { user_id: 21, display_name: 'Amy Able', username: 'amy', has_pin: false },
    { user_id: 12, display_name: 'Jo Doe', username: 'jdoe', has_pin: false },
    { user_id: 20, display_name: 'Zed Zulu', username: 'zed', has_pin: false },
  ]);
  await h.env.advance(120000);
  assert.deepEqual(h.session.peopleForPicker().map((p) => p.display_name), ['Jo Doe', 'Zed Zulu'], 'an expired grant is not offered');
  await h.session.tick();
  await until(() => h.rows('vault_users').length === 2);
  assert.deepEqual(h.rows('vault_users').map((r) => r.k), ['user:12', 'user:20'], 'and its entries are deleted');
});

test('a PIN switch posts user_id and pin with the device headers and a 3-second timeout, inside updater begin/end \'pin\'', async () => {
  const h = await picker();
  const opts = [];
  const post = h.api.post;
  h.api.post = (path, body, o) => { opts.push([path, o]); return post(path, body, o); };
  const held = deferred();
  h.answer(PIN, 200, pinReply(), { until: held.promise });
  h.updater.calls.length = 0;
  const p = h.session.pinSwitch(12, '4821');
  assert.equal(h.session.busy(), true);
  assert.deepEqual(h.updater.calls, ['begin pin']);
  await until(() => h.requests(PIN).length === 1);
  const [req] = h.requests(PIN);
  assert.deepEqual(bodyOf(req), { user_id: 12, pin: '4821' });
  assert.equal(req.method, 'POST');
  assert.equal(req.headers.authorization, 'PFPMS-Device ' + CREDENTIAL);
  assert.match(req.headers['pfpms-proof'], /^v1 \d{13} [A-Za-z0-9_-]{43}$/);
  assert.equal(req.headers['x-csrf-token'], 'csrf-2');
  assert.equal(req.credentials, 'same-origin');
  assert.deepEqual(opts[0], [PIN, { device: true, session: true, timeoutMs: PIN_TIMEOUT_MS }]);
  assert.equal(PIN_TIMEOUT_MS, 3000);
  assert.deepEqual(await h.session.pinSwitch(12, '4821'), { ok: false, message: { key: 'signin_failed' } }, 'one at a time');
  held.resolve();
  assert.deepEqual(await p, { ok: true });
  assert.deepEqual(h.updater.calls, ['begin pin', 'end pin']);
});

test('the verifier is stored only after the server says OK, with pin_failed 0 and has_pin true', async () => {
  const h = await picker();
  const before = ctOf(h, 12);
  const held = deferred();
  h.answer(PIN, 200, pinReply(), { until: held.promise });
  h.log.length = 0;
  const p = h.session.pinSwitch(12, '4821');
  await until(() => h.requests(PIN).length === 1);
  assert.equal(ctOf(h, 12), before);
  assert.ok(!strs(h.log).includes('vault.pinVerifier'), 'nothing computed before the answer');
  held.resolve();
  assert.deepEqual(await p, { ok: true });
  assert.notEqual(ctOf(h, 12), before);
  const vu = await openVaultUser(h, 12);
  assert.deepEqual([vu.pin_verifier, vu.pin_failed, vu.has_pin, vu.grant_id], [await verifierFor(12, '4821'), 0, true, 345]);
  assert.equal(h.session.peopleForPicker().find((x) => x.user_id === 12).has_pin, true);
});

test('a wrong PIN gives the tries left and never changes the vault user', async () => {
  const h = await picker();
  const before = ctOf(h, 12);
  h.log.length = 0;
  const cases = [[{ tries_left: 2 }, { key: 'pin_wrong_many', params: { n: 2 } }], [{ tries_left: 1 }, { key: 'pin_wrong_one' }],
    [{}, { key: 'pin_wrong_plain' }]];
  for (const [extra, message] of cases) {
    h.fail(PIN, 422, 'pin_wrong', 'Wrong PIN.', extra);
    assert.deepEqual(await h.session.pinSwitch(12, '1111'), { ok: false, message });
    assert.equal(h.session.state(), 'PICKER');
  }
  assert.equal(ctOf(h, 12), before);
  assert.ok(!strs(h.log).includes('vault.pinVerifier') && !strs(h.log).includes('db.put vault_users'));
});

test('tries_left 0, pin_locked and pin_unavailable send the person to the password form with their username', async () => {
  const h = await picker();
  const cases = [['pin_wrong', { tries_left: 0 }, 'pin_locked'], ['pin_locked', {}, 'pin_locked'], ['pin_unavailable', {}, 'pin_unavailable']];
  for (const [code, extra, key] of cases) {
    h.fail(PIN, 422, code, 'No.', extra);
    assert.deepEqual(await h.session.pinSwitch(13, '1111'), { ok: false, message: { key }, next: 'password', identifier: 'coord' }, code);
  }
  h.fail(PIN, 422, 'pin_unavailable', 'No.');
  assert.deepEqual(await h.session.pinSwitch(99, '1111'), { ok: false, message: { key: 'pin_unavailable' }, next: 'password', identifier: '' },
    'someone not in the vault: an empty identifier');
});

test('the 3-second timeout or any offline answer goes to the offline seam with the same user and PIN', async () => {
  const calls = [];
  const offline = {
    async unlockPassword() { throw new Error('not here'); },
    async pinSwitch(r) { calls.push([r.userId, r.pin]); return { ok: false, message: { key: 'pin_no_answer' }, next: 'password' }; },
  };
  const h0 = await picker({ second: false });
  const h = await stationHarness({ offline, env: undefined });
  assert.ok(h0.session.state() === 'PICKER');
  assert.deepEqual(await h.signIn(), { ok: true });
  h.session.switchUser();
  h.f.offline({ url: PIN });
  const expected = { ok: false, message: { key: 'pin_no_answer' }, next: 'password', identifier: 'jdoe' };
  assert.deepEqual(await h.session.pinSwitch(12, '4821'), expected);
  h.fail(PIN, 503, 'maintenance', 'Back soon.');
  assert.deepEqual(await h.session.pinSwitch(12, '4822'), expected, '503 is offline');
  h.f.hang({ url: PIN });
  const t0 = h.env.mono();
  const p = h.session.pinSwitch(12, '4823');
  await h.env.advance(PIN_TIMEOUT_MS);
  assert.deepEqual(await p, expected);
  assert.equal(h.env.mono() - t0, 3000);
  assert.deepEqual(calls, [[12, '4821'], [12, '4822'], [12, '4823']]);
  assert.deepEqual(h.connectivity.slice(-3), [['offline', 'network'], ['offline', 'maintenance'], ['offline', 'timeout']]);
  h0.f.offline({ url: PIN });
  assert.deepEqual(await h0.session.pinSwitch(12, '4821'), expected, "S3's stand-in sends the person to the password");
  assert.equal(h.session.state(), 'PICKER');
});

test('csrf_failed, then a hanging refresh, reaches the offline seam at exactly 3 s, and a late 200 never sets ACTIVE', async () => {
  let at = null;
  const h = await stationHarness({ offline: { async pinSwitch() { at = h.env.mono(); return { ok: false, message: { key: 'pin_no_answer' }, next: 'password' }; } } });
  assert.deepEqual(await h.signIn(), { ok: true });
  h.session.switchUser();
  const before = ctOf(h, 12);
  h.fail(PIN, 400, 'csrf_failed', 'Expired.', {}, { delayMs: PIN_TIMEOUT_MS - 100 });
  h.answer(SESSION, 200, touchReply({ user: null, session_ref: null }), { delayMs: 4900 });
  h.answer(PIN, 200, pinReply());
  const t0 = h.env.mono();
  const p = h.session.pinSwitch(12, '4821');
  await until(() => h.requests(PIN).length === 1);
  await h.env.advance(PIN_TIMEOUT_MS);
  assert.deepEqual(await p, { ok: false, message: { key: 'pin_no_answer' }, next: 'password', identifier: 'jdoe' });
  assert.equal(at - t0, 3000);
  await stepped(h, 12000);
  await until(() => h.requests(PIN).length === 2 && h.f.pending() === 0);
  assert.equal(h.requests(PIN).length, 2, 'api.js retried after the refresh');
  assert.equal(h.f.pending(), 0, 'the late 200 arrived');
  assert.equal(h.session.state(), 'PICKER', 'and never set ACTIVE');
  assert.equal(h.session.user(), null);
  assert.equal(ctOf(h, 12), before, 'no verifier stored');
});

test('End shift during a PIN switch sent late: its deadline calls no offline seam, and the next sign-in is sent and kept', async () => {
  const seam = [];
  const offline = {
    async unlockPassword() { seam.push('unlockPassword'); return { ok: false, message: { key: 'signin_no_answer' } }; },
    async pinSwitch() { seam.push('pinSwitch'); return { ok: false, message: { key: 'pin_no_answer' }, next: 'password' }; },
  };
  const h = await stationHarness({ offline });
  assert.deepEqual(await h.signIn(), { ok: true });
  h.session.switchUser();
  h.api.setCsrf(null); // api.js refreshes the token first (1 s), so pin.php goes out late (its own timer has not run), then hangs
  h.answer(SESSION, 200, touchReply({ user: null, session_ref: null }), { delayMs: 1000 });
  h.f.hang({ url: PIN });
  h.answer(LOGOUT, 200, logoutReply());
  h.updater.calls.length = 0;
  const p = h.session.pinSwitch(12, '4821');
  await h.env.advance(1500);
  await until(() => h.requests(PIN).length === 1);
  await h.session.endShift();
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.session.busy(), false, 'the hard lock ends the PIN switch\'s flight: the next person can sign in');
  assert.deepEqual(h.updater.calls, ['begin pin', 'begin end_shift', 'end pin', 'end end_shift'],
    'and its hold, once (the End shift holds its own until it is answered)');
  const seen = h.connectivity.length;
  const held = deferred();
  h.answer(LOGIN, 200, loginReply({ user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }), { until: held.promise });
  const next = h.session.signIn('coord', PASSWORD);
  await until(() => h.requests(LOGIN).length === 2);
  await h.env.advance(2000); // past the PIN switch's 3-s deadline, while the sign-in waits for its answer
  assert.deepEqual(await p, { ok: false }, 'superseded by End shift: given up');
  assert.deepEqual(seam, [], 'never the offline path for an attempt End shift superseded');
  assert.ok(!h.connectivity.slice(seen).some(([s]) => s === 'offline'), 'and no offline chip');
  held.resolve();
  assert.deepEqual(await next, { ok: true }, 'the old deadline left the newer attempt alone');
  assert.equal(h.session.user().user_id, 13);
  assert.deepEqual(h.updater.calls, ['begin pin', 'begin end_shift', 'end pin', 'end end_shift', 'begin signin', 'end signin']);
});

test('a PIN success is ACTIVE with auth_method PIN, and the absolute limit still counts from the last password sign-in', async () => {
  const h = await picker({ second: false });
  h.session.onConfig({ session_idle_minutes: 100000 });
  await h.env.advance(6 * 3600000);
  h.answer(PIN, 200, pinReply());
  assert.deepEqual(await h.session.pinSwitch(12, '4821'), { ok: true });
  assert.equal(h.session.state(), 'ACTIVE');
  const u = h.session.user();
  assert.deepEqual([u.user_id, u.auth_method, u.session_ref, u.has_pin], [12, 'PIN', 'b'.repeat(64), true]);
  assert.equal(h.session.mode(), 'online');
  h.answer(LOGOUT, 200, logoutReply());
  await h.env.advance(6 * 3600000 - 1);
  await h.session.tick();
  assert.equal(h.session.state(), 'ACTIVE');
  await h.env.advance(1);
  await h.session.tick();
  assert.equal(h.session.state(), 'LOCKED', '12 hours after the password sign-in, not after the PIN');
  assert.deepEqual(h.session.takeNotice(), { key: 'notice_absolute' });
  assert.equal(h.session.hasVaultKey(), false);
});

test('a PIN success clears the previous person\'s releaseUnavailable', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  h.session.switchUser();
  assert.deepEqual(await h.signIn({ user: B, session_ref: 'c'.repeat(64), release: null, release_unavailable: 'no_grant_possible' }, { identifier: 'coord' }), { ok: true });
  assert.equal(h.session.releaseUnavailable(), 'no_grant_possible');
  h.session.switchUser();
  assert.equal(h.session.releaseUnavailable(), null, 'nobody works: nothing to warn about');
  h.answer(PIN, 200, pinReply());
  assert.deepEqual(await h.session.pinSwitch(12, '4821'), { ok: true });
  assert.equal(h.session.releaseUnavailable(), null);
  assert.equal(h.session.canRecord(), true);
});

test('setPin refuses on the tablet what the server would, before any request', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  const digits = { pin_new: { key: 'pin_rule_digits', params: { min: 4, max: 6 } } };
  const cases = [
    ['12a4', '12a4', PASSWORD, digits], ['123', '123', PASSWORD, digits], ['1234567', '1234567', PASSWORD, digits], ['', '', PASSWORD, digits],
    [WIDE, WIDE, PASSWORD, digits],
    ['1234', '1234', PASSWORD, { pin_new: { key: 'pin_rule_guessable' } }], ['0000', '0000', PASSWORD, { pin_new: { key: 'pin_rule_guessable' } }],
    ['4321', '4321', PASSWORD, { pin_new: { key: 'pin_rule_guessable' } }], ['654321', '654321', PASSWORD, { pin_new: { key: 'pin_rule_guessable' } }],
    ['8901', '8902', PASSWORD, { pin_repeat: { key: 'pin_rule_mismatch' } }], ['4821', '4821', '', { pin_password: { key: 'pin_set_password_missing' } }],
    ['12', '12', '', { ...digits, pin_password: { key: 'pin_set_password_missing' } }],
  ];
  for (const [pin, confirm, password, errors] of cases) {
    assert.deepEqual(await h.session.setPin(password, pin, confirm), { ok: false, errors }, `${pin}/${confirm}`);
  }
  h.session.onConfig({ pin_min_digits: 5, pin_max_digits: 5 });
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: false, errors: { pin_new: { key: 'pin_rule_digits_exact', params: { n: 5 } } } });
  h.session.onConfig({ pin_min_digits: 3, pin_max_digits: 8 });
  assert.deepEqual([h.session.config().pin_min_digits, h.session.config().pin_max_digits], [4, 6], 'clamped to 4..6');
  for (const ok of ['8901', '1243', '1123', '4821', '2468', '482193']) assert.equal(pinProblem(ok, ok, h.session.config()), null, ok);
  assert.equal(h.requests(PIN_SET).length, 0);
});

test('setPin stores the new verifier after a 200, marks has_pin and emits no event', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  const events = [];
  for (const e of ['changed', 'people', 'locked']) h.session.on(e, () => events.push(e));
  const held = deferred();
  h.answer(PIN_SET, 200, pinSetReply(), { until: held.promise });
  const before = ctOf(h, 12);
  const p = h.session.setPin(PASSWORD, '4821', '4821');
  await until(() => h.requests(PIN_SET).length === 1);
  assert.deepEqual(bodyOf(h.requests(PIN_SET)[0]), { password: PASSWORD, pin: '4821', pin_confirm: '4821' });
  assert.equal(h.requests(PIN_SET)[0].headers.authorization, 'PFPMS-Device ' + CREDENTIAL);
  assert.equal(ctOf(h, 12), before, 'nothing before the answer');
  held.resolve();
  assert.deepEqual(await p, { ok: true });
  const vu = await openVaultUser(h, 12);
  assert.deepEqual([vu.pin_verifier, vu.pin_failed, vu.has_pin], [await verifierFor(12, '4821'), 0, true]);
  assert.equal(h.session.user().has_pin, true);
  assert.equal(h.session.state(), 'ACTIVE');
  assert.deepEqual(events, [], 'no event: the success screen stays until Continue');
});

test('setPin\'s wrong password (pin_password), pin_rules text (pin_new), 429, offline, a device 403 and 401 give their messages and store nothing', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  const before = ctOf(h, 12);
  h.log.length = 0;
  h.fail(PIN_SET, 422, 'login_failed', 'That password is not correct.');
  assert.deepEqual(await h.session.setPin('wrong', '4821', '4821'), { ok: false, errors: { pin_password: { key: 'pin_set_wrong_password' } } });
  h.fail(PIN_SET, 422, 'pin_rules', 'Use 4 digits.', { errors: { pin: 'Use 4 digits.' } });
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: false, errors: { pin_new: { text: 'Use 4 digits.' } } });
  h.fail(PIN_SET, 429, 'rate_limited', '');
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: false, message: { key: 'rate_limited_wait' } });
  h.fail(PIN_SET, 403, 'forbidden', '');
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: false, message: { key: 'pin_not_allowed' } });
  h.f.offline({ url: PIN_SET });
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: false, message: { key: 'pin_set_offline' } });
  h.fail(PIN_SET, 500, 'server_error', 'Something went wrong.', { incident: 'AB12CD36' });
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: false, message: { key: 'server_error', params: { incident: 'AB12CD36' } } },
    'the problem number to quote, as at sign-in');
  assert.equal(h.session.state(), 'ACTIVE');
  assert.equal(ctOf(h, 12), before);
  assert.ok(!strs(h.log).includes('vault.pinVerifier'));
  h.fail(PIN_SET, 401, 'session_timeout', 'Timed out.');
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: false });
  assert.equal(h.session.state(), 'PICKER', 'a 401 drops the person, keys kept');
  assert.deepEqual(h.session.takeNotice(), { key: 'notice_timeout' });
  const d = await stationHarness();
  assert.deepEqual(await d.signIn(), { ok: true });
  d.fail(PIN_SET, 403, 'device_revoked', 'Out of service.', { directive: { wipe: 'Wipe Now' } });
  d.answer('api/device/heartbeat.php', 200, { status: 'wiped', server_time: SERVER_TIME, build: 'test+0000000000' }, { times: Infinity });
  assert.deepEqual(await d.session.setPin(PASSWORD, '4821', '4821'), { ok: false });
  assert.equal(d.session.state(), 'LOCKED', 'the directive locked the tablet for good');
  assert.equal(d.session.user(), null);
});

test('setPin answered 422 account_unusable ends the person\'s use of the tablet: dropped with account_unusable and signed out', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  const before = ctOf(h, 12);
  h.log.length = 0;
  h.fail(PIN_SET, 422, 'account_unusable', 'This account cannot be used at the moment. Please contact an Administrator.');
  h.answer(LOGOUT, 200, logoutReply());
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: false });
  assert.equal(h.session.state(), 'PICKER', 'as after a 401 account_blocked: dropped, the others\' keys kept');
  assert.equal(h.session.user(), null);
  assert.equal(h.session.canRecord(), false);
  assert.equal(h.session.hasVaultKey(), true);
  assert.deepEqual(h.session.takeNotice(), { key: 'account_unusable' });
  assert.deepEqual(h.calls(LOGOUT).map((c) => c.body), [{ scope: 'user' }], 'the session the server left open is signed out');
  assert.equal(ctOf(h, 12), before);
  assert.ok(!strs(h.log).includes('vault.pinVerifier'), 'no verifier stored');
  const n = await stationHarness(); // no vault keys: LOCKED
  assert.deepEqual(await n.signIn({ release: null, release_unavailable: 'no_vault_key' }), { ok: true });
  n.fail(PIN_SET, 422, 'account_unusable', 'No.');
  n.answer(LOGOUT, 200, logoutReply());
  assert.deepEqual(await n.session.setPin(PASSWORD, '4821', '4821'), { ok: false });
  assert.equal(n.session.state(), 'LOCKED');
  assert.deepEqual(n.session.takeNotice(), { key: 'account_unusable' });
  const s = await stationHarness(); // Switch user while it was in flight: nobody else is dropped or signed out
  assert.deepEqual(await s.signIn(), { ok: true });
  const held = deferred();
  s.fail(PIN_SET, 422, 'account_unusable', 'No.', {}, { until: held.promise });
  const p = s.session.setPin(PASSWORD, '4821', '4821');
  await until(() => s.requests(PIN_SET).length === 1);
  s.session.switchUser();
  held.resolve();
  assert.deepEqual(await p, { ok: false });
  assert.equal(s.session.state(), 'PICKER');
  assert.equal(s.session.takeNotice(), null);
  assert.equal(s.calls(LOGOUT).length, 0);
  const e = await stationHarness(); // End shift while it was in flight, then B signs in: A's late 422 leaves B alone
  assert.deepEqual(await e.signIn(), { ok: true });
  const late = deferred();
  e.fail(PIN_SET, 422, 'account_unusable', 'No.', {}, { until: late.promise });
  e.answer(LOGOUT, 200, logoutReply());
  const q = e.session.setPin(PASSWORD, '4821', '4821');
  await until(() => e.requests(PIN_SET).length === 1);
  await e.session.endShift();
  assert.deepEqual(await e.signIn({ user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }, { identifier: 'coord' }), { ok: true });
  e.session.takeNotice();
  late.resolve();
  assert.deepEqual(await q, { ok: false });
  assert.equal(e.session.state(), 'ACTIVE');
  assert.equal(e.session.user()?.user_id, 13, 'B is still the one at the tablet');
  assert.equal(e.session.takeNotice(), null);
  assert.deepEqual(e.calls(LOGOUT).map((c) => c.body), [{ scope: 'device' }], 'only the End shift signed anyone out');
});

test('End shift during a PIN set ends its flight; its late answer stores nothing in the next person\'s vault', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  const held = deferred();
  h.answer(PIN_SET, 200, pinSetReply(), { until: held.promise });
  h.answer('api/auth/logout.php', 200, logoutReply());
  const p = h.session.setPin(PASSWORD, '4821', '4821');
  await until(() => h.requests(PIN_SET).length === 1);
  await h.session.endShift();
  assert.equal(h.session.busy(), false, 'the next person can sign in at once');
  assert.deepEqual(await h.signIn({ user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }, { identifier: 'coord' }), { ok: true });
  const before = ctOf(h, 12);
  h.log.length = 0;
  held.resolve();
  assert.deepEqual(await p, { ok: false }, 'an answer after a hard lock changes nothing');
  assert.equal(ctOf(h, 12), before, 'no verifier written into the vault the next sign-in opened');
  assert.ok(!strs(h.log).includes('vault.pinVerifier'));
  assert.equal(h.session.user().user_id, 13);
});

test('the console line is pin <ms> and names nobody', async () => {
  const h = await picker();
  h.answer(PIN, 200, pinReply(), { delayMs: 120 });
  const p = h.session.pinSwitch(12, '4821');
  await until(() => h.requests(PIN).length === 1); // the reply then waits on the tablet's timers
  await h.env.advance(120);
  assert.deepEqual(await p, { ok: true });
  assert.ok(h.env.logs.includes('pin 120 ms'), h.env.logs.join(' | '));
  for (const line of h.env.logs) for (const n of [...NAMES, '4821', PASSWORD]) assert.ok(!line.includes(n), line);
});
