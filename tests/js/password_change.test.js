// session.js, the forced password change at the gate (S3 spec §3.1, §4.4; 50 §6.9, D-54): the new KEK derived while the
// request travels, the keyring of the new password, a pending agreement afterwards, field errors, revoked grants, and
// Cancel. Over the REAL S2 modules. A request reaches fake-fetch some turns after the call (its proof's HMAC): checks on
// it wait with until(), never a fixed flush.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  stationHarness, release, passwordReply, acceptReply, logoutReply, policyDoc, PASSWORD, DVK, deferred, openKeyring, openVaultUser, until,
} from './support/fake-station.js';
import { VaultError, b64url } from '../../public/station/js/vault.js';
import { GATE_KEK_MS } from '../../public/station/js/session.js';

const PASSWORD_URL = 'api/auth/password.php';
const POLICY = 'api/auth/policy.php';
const LOGOUT = 'api/auth/logout.php';
const NEW = 'A sentence I will remember 42';
const strs = (log) => log.filter((x) => typeof x === 'string');
const bodyOf = (r) => JSON.parse(r.body);

async function atGate(h = null, over = {}) {
  const t = h ?? await stationHarness();
  assert.deepEqual(await t.signIn({ gate: 'password_change', release: null, ...over }), { ok: true, gate: 'password_change' });
  assert.equal(t.session.gate(), 'password_change');
  return t;
}

test('the KEK is derived from the new password in parallel with the request, and the keyring opens with the new password, not the old', async () => {
  const h = await atGate();
  const held = deferred();
  h.answer(PASSWORD_URL, 200, passwordReply(), { until: held.promise });
  h.log.length = 0;
  const p = h.session.changePassword(PASSWORD, NEW);
  await until(() => h.requests(PASSWORD_URL).length === 1);
  const at = h.log.indexOf('vault.deriveKek');
  assert.ok(at !== -1, 'the new KEK is under way before the answer');
  assert.deepEqual(h.log[at + 1], { rounds: 1000, saltLength: 16 }, 'the rounds of the sign-in');
  held.resolve();
  assert.deepEqual(await p, { ok: true });
  const req = h.requests(PASSWORD_URL)[0];
  assert.deepEqual(bodyOf(req), { current_password: PASSWORD, new_password: NEW });
  assert.match(req.headers['pfpms-proof'], /^v1 /);
  assert.equal(h.session.state(), 'ACTIVE');
  assert.deepEqual(await openKeyring(h, 12, NEW), DVK);
  await assert.rejects(openKeyring(h, 12, PASSWORD), (e) => e instanceof VaultError && e.code === 'wrong_password');
  const salts = h.vault.seen.filter((x) => x.name === 'deriveKek').map((x) => x.args[2]);
  assert.equal(salts.length, 2, 'one KEK per password');
  assert.notDeepEqual(salts[0], salts[1], 'a new salt for the new password');
  assert.equal(h.rows('keyring')[0].salt, b64url(salts[1]));
  assert.equal(h.session.user().auth_method, 'Password');
});

test('a pending agreement after the change keeps the new KEK for the acknowledgement', async () => {
  const h = await atGate();
  h.answer(PASSWORD_URL, 200, passwordReply({ gate: 'policy_ack', release: null }));
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: true, gate: 'policy_ack' });
  assert.equal(h.session.state(), 'GATE');
  assert.equal(h.session.gate(), 'policy_ack');
  assert.deepEqual(h.rows('keyring'), [], 'nothing until the agreement');
  h.answer(POLICY, 200, acceptReply());
  assert.deepEqual(await h.session.acceptPolicy(policyDoc()), { ok: true });
  assert.deepEqual(await openKeyring(h, 12, NEW), DVK);
  assert.equal(h.vault.seen.filter((x) => x.name === 'deriveKek').length, 2, 'no third derivation');
});

test('server errors land on their fields; a short password never reaches the server', async () => {
  const h = await atGate();
  assert.deepEqual(await h.session.changePassword(PASSWORD, 'short'), { ok: false, errors: { new_password: { key: 'password_rule', params: { n: 12 } } } });
  assert.equal(h.requests(PASSWORD_URL).length, 0);
  h.fail(PASSWORD_URL, 422, 'login_failed', 'The current password is not correct.');
  assert.deepEqual(await h.session.changePassword('wrong', NEW), { ok: false, errors: { current_password: { key: 'current_password_wrong' } } });
  h.fail(PASSWORD_URL, 422, 'invalid', 'Too common. Too short.', { errors: { new_password: 'Too common. Too short.' } });
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: false, errors: { new_password: { text: 'Too common. Too short.' } } });
  h.fail(PASSWORD_URL, 422, 'invalid', 'Choose a password different from your current one.');
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: false, errors: { new_password: { text: 'Choose a password different from your current one.' } } });
  h.fail(PASSWORD_URL, 429, 'rate_limited', '');
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: false, message: { key: 'rate_limited_wait' } });
  h.f.offline({ url: PASSWORD_URL });
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: false, message: { key: 'password_offline' } });
  h.fail(PASSWORD_URL, 418, 'teapot', 'No.');
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: false, message: { key: 'signin_failed' } });
  h.fail(PASSWORD_URL, 500, 'server_error', 'Something went wrong.', { incident: 'AB12CD35' });
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: false, message: { key: 'server_error', params: { incident: 'AB12CD35' } } },
    'the problem number to quote, as at sign-in');
  assert.equal(h.session.state(), 'GATE', 'the gate stays for another try');
  assert.equal(h.session.gate(), 'password_change');
  h.session.onConfig({ password_min_length: 30 });
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: false, errors: { new_password: { key: 'password_rule', params: { n: 30 } } } });
  assert.equal(h.requests(PASSWORD_URL).length, 7);
  assert.deepEqual([h.rows('keyring'), h.rows('vault_users')], [[], []]);
});

test('revoked_grants listing the old grant never delete the new entries', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  assert.equal(h.rows('keyring')[0].grant_id, 345);
  h.session.switchUser();
  await atGate(h, { session_ref: 'c'.repeat(64) });
  h.answer(PASSWORD_URL, 200, passwordReply({ release: release({ grant_id: 347 }), revoked_grants: [345] }));
  h.log.length = 0;
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: true });
  const log = strs(h.log);
  const deleted = log.indexOf('db.delete keyring');
  assert.ok(deleted !== -1 && deleted < log.indexOf('db.put keyring'), 'the old entries go first: ' + log.join(' > '));
  assert.ok(log.indexOf('db.delete vault_users') < log.indexOf('db.put vault_users'));
  assert.deepEqual(h.rows('keyring').map((r) => [r.user_id, r.grant_id]), [[12, 347]]);
  assert.equal((await openVaultUser(h, 12)).grant_id, 347);
  assert.equal(h.session.canRecord(), true);
  assert.deepEqual(await openKeyring(h, 12, NEW), DVK);
});

test('cancelGate signs out and locks hard', async () => {
  const h = await stationHarness();
  assert.deepEqual(await h.signIn(), { ok: true });
  h.session.switchUser();
  await atGate(h, { session_ref: 'c'.repeat(64) });
  h.answer(LOGOUT, 200, logoutReply());
  await h.session.cancelGate();
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.session.hasVaultKey(), false, 'a hard lock: the vault closes too');
  assert.deepEqual(h.session.peopleForPicker(), []);
  assert.equal(h.session.takeNotice(), null);
  await until(() => h.requests(LOGOUT).length === 1);
  const [out] = h.requests(LOGOUT);
  assert.deepEqual(bodyOf(out), { scope: 'user' });
  assert.equal(out.headers['x-csrf-token'], 'csrf-2');
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: false }, 'the gate is gone');
  assert.equal(h.requests(PASSWORD_URL).length, 0);
});

test('a password change tried after the gate\'s 10 minutes locks with gate_expired and signs the gated person out', async () => {
  const h = await atGate();
  h.answer(LOGOUT, 200, logoutReply());
  await h.env.advance(GATE_KEK_MS);
  assert.deepEqual(await h.session.changePassword(PASSWORD, NEW), { ok: false, message: { key: 'gate_expired' } });
  assert.equal(h.session.state(), 'LOCKED');
  assert.deepEqual(h.session.takeNotice(), { key: 'gate_expired' });
  assert.deepEqual(h.calls(LOGOUT).map((c) => c.body), [{ scope: 'user' }], 'Logout, as Cancel does');
  assert.equal(h.calls(PASSWORD_URL).length, 0, 'the change is not sent');
  await until(() => h.requests(LOGOUT).length === 1);
});
