// session.js, online sign-in (S3 spec §3.1, §3.2, §4.4): the PBKDF2 started before the answer, the request, what is
// written to IndexedDB and in which order, revoked grants first, the gates (the KEK kept, never the password), the
// refusal messages, the offline seam and the deadline, final locks, the keep-alive, onOfflineDisabled, the vault's
// people, the never-rejecting hooks and MESSAGE_KEYS. Over the REAL api.js, db.js, clock.js, device.js and vault.js.
// A request reaches fake-fetch some turns after the call (a device proof's HMAC): positive checks wait with until(),
// "nothing sent" checks count h.calls().
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { flush } from './support/fake-env.js';
import {
  stationHarness, loginReply, release, userJson, personJson, policyDoc, policyReply, acceptReply, logoutReply, touchReply, heartbeatReply,
  pinReply, pinSetReply, PASSWORD, SESSION_REF, SERVER_TIME, DVK, GRANT_KEY, CREDENTIAL, CONFIG, deferred, until, vaultUser,
  sealVaultUser, openVaultUser, openKeyring, vaultKeys,
} from './support/fake-station.js';
import * as V from '../../public/station/js/vault.js';
import { formatDb, parseDb } from '../../public/station/js/canonical.js';
import {
  MESSAGE_KEYS, LOCK_NOTICES, GATE_401, ACTIVE_401, SIGNIN_TIMEOUT_MS, GATE_KEK_MS, TOUCH_EVERY_MS, LOGOUT_TIMEOUT_MS, CALL_TIMEOUT_MS,
} from '../../public/station/js/session.js';

const LOGIN = 'api/auth/login.php';
const POLICY = 'api/auth/policy.php';
const PASSWORD_URL = 'api/auth/password.php';
const LOGOUT = 'api/auth/logout.php';
const PIN = 'api/auth/pin.php';
const PIN_SET = 'api/auth/pin_set.php';
const SESSION = 'api/session.php';
const HEARTBEAT = 'api/device/heartbeat.php';
const settle = () => flush(40);
/** Advances in small steps, settling between them, so work that takes event-loop turns is not skipped over by a jump. */
const stepped = async (h, ms, step = 250) => { for (let t = 0; t < ms; t += step) { await h.env.advance(step); await settle(); } };
const NAMES = ['jdoe', 'JDoe', 'Jo Doe', 'Jo.Doe', 'jo.doe', 'coord', 'Cam Coord', 'Amy Able'];
const bodyOf = (r) => JSON.parse(r.body);
const strs = (log) => log.filter((x) => typeof x === 'string');
const B = personJson(13, 'coord', 'Cam Coord');
const C = personJson(14, 'amy', 'Amy Able');

/** True when v holds a CryptoKey anywhere (own enumerable values, arrays). */
function holdsCryptoKey(v, seen = new Set()) {
  if (v === null || typeof v !== 'object' || seen.has(v)) return false;
  seen.add(v);
  if (Object.prototype.toString.call(v) === '[object CryptoKey]') return true;
  return Object.values(v).some((x) => holdsCryptoKey(x, seen));
}
/** Every getter of the session, called (what app.js and the screens can ever read). */
const getters = (s) => [s.state(), s.gate(), s.gateUser(), s.user(), s.mode(), s.capabilities(), s.hasVaultKey(), s.canRecord(),
  s.releaseUnavailable(), s.config(), s.busy(), s.peopleForPicker()];
const signedIn = async (h, over = {}, o = {}) => {
  const r = await h.signIn(over, o);
  assert.deepEqual(r, { ok: true }, 'signed in');
  return r;
};
const gateSignIn = async (h, kind = 'policy_ack', over = {}) => {
  const r = await h.signIn({ gate: kind, release: null, ...over });
  assert.deepEqual(r, { ok: true, gate: kind });
};

test('PBKDF2 starts before the sign-in answer arrives, with the tablet\'s rounds and a fresh 16-byte salt', async () => {
  const h = await stationHarness({ iterations: 2000 });
  const held = deferred();
  h.answer(LOGIN, 200, loginReply(), { until: held.promise });
  const p = h.session.signIn('jdoe', PASSWORD);
  await until(() => h.requests(LOGIN).length === 1); // the request is out
  const at = h.log.indexOf('vault.deriveKek');
  assert.ok(at !== -1, 'deriveKek ran before the answer');
  assert.deepEqual(h.log[at + 1], { rounds: 2000, saltLength: 16 });
  assert.ok(!strs(h.log).includes('db.put keyring'), 'nothing is written yet');
  held.resolve();
  assert.deepEqual(await p, { ok: true });
  const first = h.vault.seen.find((x) => x.name === 'deriveKek').args[2];
  const ring = h.rows('keyring')[0];
  assert.equal(ring.iterations, 2000, 'the keyring keeps the rounds used, not release.pbkdf2_iterations');
  assert.deepEqual(V.b64urlDecode(ring.salt, 16), first);
  h.session.switchUser();
  await signedIn(h, { session_ref: 'c'.repeat(64) });
  const salts = h.vault.seen.filter((x) => x.name === 'deriveKek').map((x) => V.b64url(x.args[2]));
  assert.equal(salts.length, 2);
  assert.notEqual(salts[0], salts[1], 'a fresh salt per sign-in');
});

test('the request carries the identifier as typed, the password, Authorization, PFPMS-Proof, X-CSRF-Token and credentials same-origin, with a 6-second timeout', async () => {
  const h = await stationHarness();
  const opts = [];
  const post = h.api.post;
  h.api.post = (path, body, o) => { opts.push([path, o]); return post(path, body, o); };
  await signedIn(h, {}, { identifier: ' JDoe ' });
  const [req] = h.requests(LOGIN);
  assert.equal(req.method, 'POST');
  assert.deepEqual(bodyOf(req), { identifier: ' JDoe ', password: PASSWORD });
  assert.equal(req.headers.authorization, 'PFPMS-Device ' + CREDENTIAL);
  assert.match(req.headers['pfpms-proof'], /^v1 \d{13} [A-Za-z0-9_-]{43}$/);
  assert.equal(req.headers['x-csrf-token'], 'csrf-1');
  assert.equal(req.headers['content-type'], 'application/json');
  assert.equal(req.credentials, 'same-origin');
  assert.deepEqual(opts[0], [LOGIN, { device: true, session: true, timeoutMs: SIGNIN_TIMEOUT_MS }]);
  assert.equal(SIGNIN_TIMEOUT_MS, 6000);
});

test('with offline_allowed the keyring holds the username, email and typed-identifier lookups, de-duplicated, and the rounds used', async () => {
  const h = await stationHarness();
  await signedIn(h, {}, { identifier: ' JDoe ' });
  const [ring] = h.rows('keyring');
  const expected = [await V.lookup(crypto, 7, 'jdoe'), await V.lookup(crypto, 7, 'Jo.Doe@Example.test')];
  assert.deepEqual(ring.lookup, expected, 'the typed " JDoe " is the username\'s hash');
  assert.deepEqual(Object.keys(ring).sort(), ['grant_expires_at', 'grant_id', 'iterations', 'iv', 'lookup', 'salt', 'user_id', 'v', 'wrapped_dvk']);
  assert.deepEqual([ring.user_id, ring.grant_id, ring.grant_expires_at, ring.iterations, ring.v], [12, 345, '2026-10-04 12:00:00.000', 1000, 1]);
  assert.deepEqual(await openKeyring(h, 12, PASSWORD), DVK, 'the entry opens with the password');
  const byTyped = await h.db.byIndex('keyring', 'lookup', await V.lookup(crypto, 7, 'JO.DOE@example.test '));
  assert.equal(byTyped.length, 1, 'found by the email typed another way');
  h.session.switchUser();
  await signedIn(h, { session_ref: 'c'.repeat(64) }, { identifier: 'J.Doe' });
  assert.equal(h.rows('keyring')[0].lookup.length, 3, 'a third spelling is kept');
  assert.equal(h.meta('config').offline_allowed, true);
});

test('without offline_allowed no keyring is written and an older entry for that person is deleted', async () => {
  const old = { user_id: 12, lookup: ['old'], grant_id: 300, grant_expires_at: '2026-10-03 00:00:00.000', salt: 'AAAAAAAAAAAAAAAAAAAAAA', iterations: 1000,
    iv: 'AAAAAAAAAAAAAAAA', wrapped_dvk: 'x', v: 1 };
  const h = await stationHarness({ seed: { keyring: [old] } });
  await signedIn(h, { release: release({ offline_allowed: false }) });
  assert.deepEqual(h.rows('keyring'), []);
  assert.ok(strs(h.log).includes('db.delete keyring') && !strs(h.log).includes('db.put keyring'));
  assert.equal(h.rows('vault_users').length, 1, 'the vault user is written: the person can record online');
  assert.equal(h.session.canRecord(), true);
  assert.equal(h.meta('config').offline_allowed, false);
});

test('keyring.put runs before openVault() zeroes the raw DVK, and vault_users is written after it', async () => {
  const h = await stationHarness();
  await signedIn(h);
  const log = strs(h.log);
  const ring = log.indexOf('db.put keyring');
  const open = log.indexOf('vault.openVault');
  const vu = log.indexOf('db.put vault_users');
  assert.ok(ring !== -1 && ring < open && open < vu, log.join(' > '));
  const wrapped = h.vault.seen.find((x) => x.name === 'wrapDvk').args[2];
  const opened = h.vault.seen.find((x) => x.name === 'openVault').args[1];
  assert.equal(wrapped, opened, 'one raw copy: wrapped first, then opened');
  assert.ok(opened.every((b) => b === 0), 'openVault() zeroed it');
});

test('the vault user record holds the grant and the sealed record is all that is stored', async () => {
  const h = await stationHarness();
  await signedIn(h);
  const rows = h.rows('vault_users');
  assert.equal(rows.length, 1);
  assert.deepEqual(Object.keys(rows[0]).sort(), ['ct', 'iv', 'k']);
  assert.equal(rows[0].k, 'user:12');
  assert.deepEqual(await openVaultUser(h, 12), {
    user_id: 12, username: 'jdoe', display_name: 'Jo Doe', role: 'Volunteer',
    offline_caps: ['offline.checkin', 'offline.distribute', 'offline.pet_edit', 'offline.register'], pin_switch: true, has_pin: false,
    grant_id: 345, grant_hmac_key: V.b64url(GRANT_KEY), grant_issued_at: SERVER_TIME, grant_expires_at: '2026-10-04 12:00:00.000',
    last_password_at: formatDb(h.env.now()), pin_verifier: null, pin_failed: 0, v: 1,
  });
  const plain = JSON.stringify([h.rows('keyring'), h.idb._dump('pfpms').meta]);
  for (const name of NAMES) assert.ok(!plain.includes(name), `no ${name} outside the sealed vault_users`);
});

test('the raw DVK is zeroed and no KEK outlives the sign-in', async () => {
  const h = await stationHarness();
  await signedIn(h);
  const kek = await h.vault.seen.find((x) => x.name === 'deriveKek').result;
  assert.equal(h.vault.seen.find((x) => x.name === 'wrapDvk').args[1], kek, 'the KEK wrapped the DVK');
  assert.ok(h.vault.seen.find((x) => x.name === 'openVault').args[1].every((b) => b === 0));
  assert.ok(!getters(h.session).some((v) => holdsCryptoKey(v)), 'no getter reaches a key');
  assert.deepEqual(await h.session.acceptPolicy(policyDoc()), { ok: false }, 'no gate holds a KEK');
  assert.equal(h.vault.seen.filter((x) => x.name === 'wrapDvk').length, 1);
  assert.equal(h.requests(POLICY).length, 0);
});

test('revoked_grants run before the new entries: only entries whose current grant is listed are deleted', async () => {
  const h = await stationHarness();
  await signedIn(h);
  h.session.switchUser();
  await signedIn(h, { user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }, { identifier: 'coord' });
  h.session.switchUser();
  h.log.length = 0;
  await signedIn(h, { user: C, session_ref: 'd'.repeat(64), release: release({ grant_id: 347 }), revoked_grants: [345, 300] }, { identifier: 'amy' });
  const log = strs(h.log);
  assert.ok(log.indexOf('db.delete keyring') !== -1 && log.indexOf('db.delete keyring') < log.indexOf('db.put keyring'), log.join(' > '));
  assert.deepEqual(h.rows('keyring').map((r) => [r.user_id, r.grant_id]), [[13, 346], [14, 347]]);
  assert.deepEqual(h.rows('vault_users').map((r) => r.k), ['user:13', 'user:14']);
  assert.deepEqual(h.session.peopleForPicker().map((p) => p.user_id), [14, 13]);
});

test('at a gate the KEK is kept, never the password: accepting writes a keyring that opens with the password typed at sign-in, and deriveKek ran once', async () => {
  const h = await stationHarness();
  await gateSignIn(h, 'policy_ack');
  assert.equal(h.session.state(), 'GATE');
  assert.equal(h.session.gate(), 'policy_ack');
  assert.deepEqual(h.session.gateUser(), { display_name: 'Jo Doe' });
  assert.deepEqual([h.rows('keyring'), h.rows('vault_users')], [[], []], 'nothing is written at a gate');
  assert.ok(!JSON.stringify(getters(h.session)).includes(PASSWORD), 'the password is nowhere to be read');
  h.answer(POLICY, 200, acceptReply());
  assert.deepEqual(await h.session.acceptPolicy(policyDoc()), { ok: true });
  const [req] = h.requests(POLICY);
  assert.deepEqual(bodyOf(req), { document_id: 5, fingerprint: 'f'.repeat(64), decision: 'accept' });
  assert.match(req.headers['pfpms-proof'], /^v1 /);
  assert.equal(h.session.state(), 'ACTIVE');
  assert.deepEqual(await openKeyring(h, 12, PASSWORD), DVK);
  assert.equal(h.vault.seen.filter((x) => x.name === 'deriveKek').length, 1);
  assert.equal((await openVaultUser(h, 12)).grant_id, 345);
  assert.equal(h.session.user().auth_method, 'Password');
});

test('after 10 minutes at the gate the tablet locks and an acceptance writes nothing', async () => {
  const h = await stationHarness();
  await gateSignIn(h);
  await h.env.advance(GATE_KEK_MS - 1);
  await h.session.tick();
  assert.equal(h.session.state(), 'GATE');
  await h.env.advance(1);
  await h.session.tick();
  assert.equal(h.session.state(), 'LOCKED');
  assert.deepEqual(h.session.takeNotice(), { key: 'gate_expired' });
  assert.deepEqual(await h.session.acceptPolicy(policyDoc()), { ok: false });
  const h2 = await stationHarness();
  await gateSignIn(h2);
  await h2.env.advance(GATE_KEK_MS);
  assert.deepEqual(await h2.session.acceptPolicy(policyDoc()), { ok: false, message: { key: 'gate_expired' } }, 'no tick ran: the acceptance checks');
  for (const t of [h, h2]) {
    assert.equal(t.requests(POLICY).length, 0);
    assert.deepEqual([t.rows('keyring'), t.rows('vault_users')], [[], []]);
    assert.equal(t.session.hasVaultKey(), false);
  }
});

test('declining leaves no keyring and no vault user for that person and locks hard with ack_declined', async () => {
  const h = await stationHarness();
  await signedIn(h);
  h.session.switchUser();
  await h.signIn({ user: B, session_ref: 'c'.repeat(64), gate: 'policy_ack', release: null }, { identifier: 'coord' });
  assert.equal(h.session.state(), 'GATE');
  h.answer(POLICY, 200, { ok: true, signed_out: true, server_time: SERVER_TIME, csrf: 'csrf-9' });
  assert.deepEqual(await h.session.declinePolicy(policyDoc()), { ok: true });
  assert.deepEqual(bodyOf(h.requests(POLICY)[0]), { document_id: 5, fingerprint: 'f'.repeat(64), decision: 'decline' });
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.session.hasVaultKey(), false, 'a hard lock: the keys go too');
  assert.deepEqual(h.session.takeNotice(), { key: 'ack_declined' });
  assert.deepEqual(h.rows('keyring').map((r) => r.user_id), [12]);
  assert.deepEqual(h.rows('vault_users').map((r) => r.k), ['user:12']);
  const h2 = await stationHarness();
  await gateSignIn(h2);
  h2.f.offline({ url: POLICY });
  assert.deepEqual(await h2.session.declinePolicy(policyDoc()), { ok: true }, 'offline alike');
  assert.equal(h2.session.state(), 'LOCKED');
});

test('policy_changed returns changed and the next accept releases', async () => {
  const h = await stationHarness();
  await gateSignIn(h);
  h.fail(POLICY, 409, 'policy_changed', 'The agreement was updated while you were reading it. Please read the current version.');
  assert.deepEqual(await h.session.acceptPolicy(policyDoc()), { ok: false, message: { key: 'policy_changed' }, changed: true });
  assert.equal(h.session.state(), 'GATE');
  assert.deepEqual(h.rows('keyring'), []);
  h.answer(POLICY, 200, acceptReply());
  assert.deepEqual(await h.session.acceptPolicy(policyDoc({ document_id: 6, fingerprint: 'e'.repeat(64) })), { ok: true });
  assert.equal(h.session.state(), 'ACTIVE');
  assert.equal(h.rows('keyring').length, 1);
});

test('the CSRF token of the answer replaces the old one', async () => {
  const h = await stationHarness();
  await signedIn(h);
  assert.equal(h.api.csrf(), 'csrf-2');
  h.answer(PIN_SET, 200, pinSetReply());
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: true });
  assert.equal(h.requests(PIN_SET)[0].headers['x-csrf-token'], 'csrf-2');
  assert.equal(h.api.csrf(), 'csrf-6');
});

test('meta.config takes the answer\'s config and offline_allowed and keeps the organisation name; a heartbeat keeps offline_allowed until offline is disabled', async () => {
  const h = await stationHarness({ seed: { meta: { config: { organisation_name: 'Old Org Name', session_idle_minutes: 30 } } } });
  const { organisation_name: _org, ...config } = CONFIG;
  await signedIn(h, { config: { ...config, session_idle_minutes: 20 }, password_min_length: 14 });
  let saved = h.meta('config');
  assert.equal(saved.organisation_name, 'Old Org Name');
  assert.equal(saved.session_idle_minutes, 20);
  assert.equal(saved.offline_allowed, true);
  assert.equal(h.session.config().session_idle_minutes, 20);
  assert.equal(h.session.config().password_min_length, 14);
  h.answer(HEARTBEAT, 200, heartbeatReply({ offline_enabled: true }));
  await h.device.heartbeat();
  saved = h.meta('config');
  assert.equal(saved.offline_allowed, true, 'a heartbeat keeps it');
  assert.equal(saved.organisation_name, 'Old Org Name');
  assert.equal(saved.offline_enabled, true);
  h.answer(HEARTBEAT, 200, heartbeatReply({ offline_enabled: false }));
  await h.device.heartbeat();
  await until(() => h.rows('keyring').length === 0);
  saved = h.meta('config');
  assert.equal(saved.offline_allowed, undefined, 'offline disabled: no longer allowed');
  assert.equal(saved.offline_enabled, false);
  assert.deepEqual(h.rows('keyring'), []);
  assert.equal(h.session.state(), 'ACTIVE');
});

test('a seeded tablet signs in without a release: no keyring, no vault user, cannot record, no_vault_key', async () => {
  const h = await stationHarness();
  await signedIn(h, { release: null, release_unavailable: 'no_vault_key' });
  assert.equal(h.session.state(), 'ACTIVE');
  assert.equal(h.session.releaseUnavailable(), 'no_vault_key');
  assert.equal(h.session.canRecord(), false);
  assert.equal(h.session.hasVaultKey(), false);
  assert.deepEqual([h.rows('keyring'), h.rows('vault_users')], [[], []]);
  assert.ok(!strs(h.log).includes('vault.openVault'));
  assert.equal(h.session.user().display_name, 'Jo Doe');
});

test('each refusal code gives its message', async () => {
  const h = await stationHarness();
  const cases = [
    [422, 'login_failed', 'That username or password is not correct.', {}, { key: 'login_failed' }],
    [422, 'account_locked', 'This account is locked.', {}, { key: 'account_locked' }],
    [422, 'account_unusable', 'This account cannot be used at the moment.', {}, { key: 'account_unusable' }],
    [403, 'no_station_access', 'No.', { reason: 'site' }, { key: 'no_station_access_site', params: { site: 'Dev Site North' } }],
    [403, 'no_station_access', 'No.', { reason: 'role' }, { key: 'no_station_access_role' }],
    [429, 'rate_limited', 'Too many sign-in attempts.', {}, { key: 'rate_limited_wait' }],
    [401, 'device_proof_invalid', 'Register again.', {}, { key: 'device_proof_invalid' }],
    [401, 'device_proof_stale', 'Stale.', {}, { key: 'signin_clock' }, 2],
    [403, 'device_site_inactive', 'Inactive.', {}, { key: 'device_site_inactive' }],
    [403, 'device_not_registered', 'Not registered.', {}, { key: 'device_not_registered' }],
    [500, 'server_error', 'Something went wrong.', { incident: 'AB12CD34' }, { key: 'server_error', params: { incident: 'AB12CD34' } }],
    [400, 'csrf_failed', 'Expired.', {}, { key: 'signin_failed' }, 2],
    [418, 'teapot', 'The server says no.', {}, { text: 'The server says no.' }],
    [418, 'teapot', '', {}, { key: 'signin_failed' }],
  ];
  for (const [status, code, message, extra, expected, times = 1] of cases) {
    h.fail(LOGIN, status, code, message, extra, { times });
    if (code === 'csrf_failed') h.answer(SESSION, 200, touchReply({ user: null, session_ref: null }));
    const r = await h.session.signIn('jdoe', PASSWORD);
    assert.deepEqual(r, { ok: false, message: expected }, code);
    assert.equal(h.session.state(), 'LOCKED');
  }
  assert.equal(h.f.pending(), 0, 'every reply was used (the stale proof and the CSRF were retried once)');
  assert.deepEqual(h.rows('keyring'), []);
  assert.ok(!h.env.logs.some((l) => NAMES.some((n) => l.includes(n))));
});

test('a 403 with a directive starts the wipe and the session locks for good', async () => {
  const h = await stationHarness({ seed: { keyring: [{ user_id: 13, lookup: ['x'], grant_id: 300, v: 1 }] } });
  h.fail(LOGIN, 403, 'device_revoked', 'This tablet has been taken out of service.', { directive: { wipe: 'Wipe Now' } });
  h.answer(HEARTBEAT, 200, { status: 'wiped', server_time: SERVER_TIME, build: 'test+0000000000' }, { times: Infinity });
  assert.deepEqual(await h.session.signIn('jdoe', PASSWORD), { ok: false, message: { key: 'signin_failed' } });
  await until(() => h.wipes.some((w) => String(w).startsWith('erased')));
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.idb._exists('pfpms'), false, 'the tablet is erased');
  const sent = h.f.requests.length;
  assert.deepEqual(await h.session.signIn('jdoe', PASSWORD), { ok: false, message: { key: 'signin_failed' } });
  assert.equal(h.f.requests.length, sent, 'nothing is sent any more');
});

test('an offline answer or the 6-second timeout goes to the offline seam with the identifier and password', async () => {
  const calls = [];
  const offline = {
    async unlockPassword(r) { calls.push([r.identifier, r.password === PASSWORD]); return { ok: false, message: { key: 'signin_no_answer' } }; },
    async pinSwitch() { throw new Error('not here'); },
  };
  const h = await stationHarness({ offline });
  h.f.offline({ url: LOGIN });
  assert.deepEqual(await h.session.signIn(' JDoe ', PASSWORD), { ok: false, message: { key: 'signin_no_answer' } });
  assert.deepEqual(calls, [[' JDoe ', true]]);
  assert.deepEqual(h.connectivity, [['offline', 'network']]);
  h.f.hang({ url: LOGIN });
  const t0 = h.env.mono();
  const p = h.session.signIn('jdoe', PASSWORD);
  await h.env.advance(SIGNIN_TIMEOUT_MS - 1);
  assert.equal(calls.length, 1);
  await h.env.advance(1);
  assert.deepEqual(await p, { ok: false, message: { key: 'signin_no_answer' } });
  assert.equal(h.env.mono() - t0, 6000);
  assert.deepEqual(calls[1], ['jdoe', true]);
  assert.deepEqual(h.connectivity[1], ['offline', 'timeout']);
  const h2 = await stationHarness(); // S3's stand-in
  h2.f.offline({ url: LOGIN });
  assert.deepEqual(await h2.session.signIn('jdoe', PASSWORD), { ok: false, message: { key: 'signin_no_answer' } });
  assert.equal(h2.session.state(), 'LOCKED');
});

test('an answer that arrives after a directive or in a replaced window writes nothing', async () => {
  for (const reason of ['directive', 'replaced']) {
    const h = await stationHarness();
    const held = deferred();
    h.answer(LOGIN, 200, loginReply(), { until: held.promise });
    const p = h.session.signIn('jdoe', PASSWORD);
    await until(() => h.requests(LOGIN).length === 1);
    await h.session.lock('hard', reason);
    held.resolve();
    assert.deepEqual(await p, { ok: false }, reason);
    assert.equal(h.session.state(), 'LOCKED');
    assert.deepEqual(strs(h.log).filter((l) => l.startsWith('db.')), [], 'no write of any kind');
    assert.deepEqual([h.rows('keyring'), h.rows('vault_users')], [[], []]);
    assert.deepEqual(await h.session.signIn('jdoe', PASSWORD), { ok: false, message: { key: 'signin_failed' } });
    assert.equal(h.requests(LOGIN).length, 1);
  }
});

test('the touch is sent at most every 5 minutes, only after input and only while ACTIVE', async () => {
  const h = await stationHarness();
  await signedIn(h);
  h.answer(SESSION, 200, touchReply(), { times: Infinity });
  await h.env.advance(60000);
  await h.session.touch();
  assert.equal(h.requests(SESSION).length, 0, 'within 5 minutes of the sign-in');
  await h.env.advance(TOUCH_EVERY_MS - 60000);
  await h.session.touch();
  await until(() => h.requests(SESSION).length === 1);
  const sent = h.requests(SESSION);
  assert.equal(sent.length, 1);
  assert.equal(sent[0].method, 'POST');
  assert.deepEqual(bodyOf(sent[0]), { action: 'touch' });
  assert.equal(sent[0].credentials, 'same-origin');
  assert.equal(sent[0].headers['x-csrf-token'], 'csrf-2');
  assert.equal(sent[0].headers.authorization, undefined, 'a session call, not a device call');
  await h.env.advance(60000);
  await h.session.touch();
  assert.equal(h.calls(SESSION).length, 1, 'at most every 5 minutes');
  h.session.switchUser();
  await h.env.advance(TOUCH_EVERY_MS);
  await h.session.touch();
  await h.session.tick();
  assert.equal(h.calls(SESSION).length, 1, 'only while ACTIVE');
  assert.equal(LOGOUT_TIMEOUT_MS, 5000);
});

test('a touch answer naming nobody or another session drops the person with notice_signed_out', async () => {
  for (const reply of [touchReply({ user: null, session_ref: null }), touchReply({ session_ref: 'e'.repeat(64) }), touchReply({ user: B })]) {
    const h = await stationHarness();
    await signedIn(h);
    h.answer(SESSION, 200, reply);
    await h.env.advance(TOUCH_EVERY_MS);
    await h.session.touch();
    await until(() => h.session.state() === 'PICKER'); // keys kept
    assert.deepEqual(h.session.takeNotice(), { key: 'notice_signed_out' });
    assert.equal(h.session.user(), null);
  }
  const h = await stationHarness();
  await signedIn(h);
  h.answer(SESSION, 200, touchReply());
  await h.env.advance(TOUCH_EVERY_MS);
  await h.session.touch();
  await until(() => h.f.pending() === 0); // the answer arrived (no proof on a touch: the rest takes fixed turns)
  await settle();
  assert.equal(h.session.state(), 'ACTIVE', 'the same person and session: nothing changes');
});

test('a touch answer for the person who was ACTIVE when it was sent never drops the next person', async () => {
  const h = await stationHarness();
  await signedIn(h);
  const held = deferred();
  h.answer(SESSION, 200, touchReply({ user: null, session_ref: null }), { until: held.promise });
  await h.env.advance(TOUCH_EVERY_MS);
  await h.session.touch();
  await until(() => h.requests(SESSION).length === 1);
  h.session.switchUser();
  await signedIn(h, { user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }, { identifier: 'coord' });
  held.resolve();
  await until(() => h.f.pending() === 0);
  await settle();
  assert.equal(h.session.state(), 'ACTIVE');
  assert.equal(h.session.user().user_id, 13);
});

test('one sign-in at a time, between updater.begin(\'signin\') and end', async () => {
  const h = await stationHarness();
  const held = deferred();
  h.answer(LOGIN, 200, loginReply(), { until: held.promise });
  const p = h.session.signIn('jdoe', PASSWORD);
  assert.equal(h.session.busy(), true);
  assert.deepEqual(h.updater.calls, ['begin signin']);
  assert.deepEqual(await h.session.signIn('jdoe', PASSWORD), { ok: false, message: { key: 'signin_failed' } });
  assert.equal(h.calls(LOGIN).length, 1, 'the second sign-in sent nothing');
  await until(() => h.requests(LOGIN).length === 1);
  held.resolve();
  assert.deepEqual(await p, { ok: true });
  assert.deepEqual(h.updater.calls, ['begin signin', 'end signin']);
  assert.equal(h.session.busy(), false);
});

test('the console line is sign-in <ms> and names nobody', async () => {
  const h = await stationHarness();
  h.answer(LOGIN, 200, loginReply(), { delayMs: 250 });
  const p = h.session.signIn(' JDoe ', PASSWORD);
  await until(() => h.requests(LOGIN).length === 1); // the request is out (its reply waits on the tablet's timers)
  await h.env.advance(250);
  assert.deepEqual(await p, { ok: true });
  assert.ok(h.env.logs.includes('sign-in 250 ms'), h.env.logs.join(' | '));
  for (const line of h.env.logs) for (const n of [...NAMES, PASSWORD]) assert.ok(!line.includes(n), line);
});

test('onOfflineDisabled deletes the keyring and signs nobody out', async () => {
  const h = await stationHarness();
  await signedIn(h);
  h.answer(HEARTBEAT, 200, heartbeatReply({ offline_enabled: false }), { times: 2 });
  await h.device.heartbeat();
  await until(() => h.rows('keyring').length === 0);
  assert.equal(h.rows('vault_users').length, 1);
  assert.equal(h.session.state(), 'ACTIVE');
  assert.equal(h.session.canRecord(), true);
  const clears = strs(h.log).filter((l) => l === 'db.clear keyring').length;
  await h.device.heartbeat({ reason: 'timer' });
  await settle();
  assert.equal(strs(h.log).filter((l) => l === 'db.clear keyring').length, clears, 'an empty keyring is not cleared again');
  assert.equal(h.session.state(), 'ACTIVE');
});

test('after a hard lock and another person\'s sign-in, the earlier person is listed again from vault_users', async () => {
  const h = await stationHarness();
  await signedIn(h);
  h.answer(LOGOUT, 200, logoutReply());
  await h.session.endShift();
  assert.deepEqual(h.session.peopleForPicker(), []);
  await signedIn(h, { user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }, { identifier: 'coord' });
  assert.deepEqual(h.session.peopleForPicker().map((p) => [p.user_id, p.display_name]), [[13, 'Cam Coord'], [12, 'Jo Doe']]);
  const log = strs(h.log);
  assert.ok(log.lastIndexOf('vault.open') < log.lastIndexOf('db.put vault_users'), 'loaded before the own record is written');
});

test('a password sign-in in a new page life keeps the stored pin_verifier and pin_failed', async () => {
  const h = await stationHarness();
  await signedIn(h);
  h.answer(PIN_SET, 200, pinSetReply());
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: true });
  const keys = await vaultKeys();
  const verifier = await V.pinVerifier(crypto, keys.pin, 12, '4821');
  assert.equal((await openVaultUser(h, 12)).pin_verifier, verifier);
  h.newSession(); // a reload: nobody in memory
  await signedIn(h, { user: userJson({ has_pin: true }), session_ref: 'c'.repeat(64), release: release({ grant_id: 350 }) });
  const back = await openVaultUser(h, 12);
  assert.deepEqual([back.pin_verifier, back.pin_failed, back.has_pin, back.grant_id], [verifier, 0, true, 350]);
  const seeded = await stationHarness({ seed: { vault_users: [await sealVaultUser(vaultUser({ pin_verifier: 'V'.repeat(43), pin_failed: 2 }))] } });
  await signedIn(seeded, { release: release({ grant_id: 351 }) });
  const kept = await openVaultUser(seeded, 12);
  assert.deepEqual([kept.pin_verifier, kept.pin_failed, kept.grant_id], ['V'.repeat(43), 2, 351]);
  assert.equal(seeded.session.peopleForPicker()[0].has_pin, true, 'a stored verifier offers the PIN pad');
});

test('a damaged vault_users record is skipped and logged by key only', async () => {
  const damaged = { k: 'user:99', iv: 'AAAAAAAAAAAAAAAA', ct: 'A'.repeat(43) };
  const moved = { ...(await sealVaultUser(vaultUser({ user_id: 13, username: 'coord', display_name: 'Cam Coord' }))), k: 'user:98' };
  const h = await stationHarness({ seed: { vault_users: [damaged, moved] } });
  await signedIn(h);
  assert.deepEqual(h.session.peopleForPicker().map((p) => p.user_id), [12]);
  assert.ok(h.env.logs.includes('vault user user:99 skipped: VaultError'), h.env.logs.join(' | '));
  assert.ok(h.env.logs.includes('vault user user:98 skipped: VaultError'), 'a record moved to another key does not open');
  for (const line of h.env.logs) for (const n of NAMES) assert.ok(!line.includes(n), line);
});

test('a vault_users record whose grant is in the last revoked_grants, or expired, is deleted when the vault opens', async () => {
  const past = formatDb(parseDb(SERVER_TIME) - 3600000);
  const seed = { vault_users: [
    await sealVaultUser(vaultUser({ user_id: 13, username: 'coord', display_name: 'Cam Coord', grant_id: 300 })),
    await sealVaultUser(vaultUser({ user_id: 14, username: 'amy', display_name: 'Amy Able', grant_id: 301, grant_expires_at: past })),
    await sealVaultUser(vaultUser({ user_id: 15, username: 'bo', display_name: 'Bo Brown', grant_id: 302 })),
  ] };
  const h = await stationHarness({ seed });
  await signedIn(h, { revoked_grants: [300] });
  assert.deepEqual(h.rows('vault_users').map((r) => r.k), ['user:12', 'user:15']);
  assert.deepEqual(h.session.peopleForPicker().map((p) => p.user_id), [15, 12]);
});

test('csrf_failed, then a hanging refresh, reaches the offline seam at exactly 6 s, and the late 200 writes nothing', async () => {
  let at = null;
  const h = await stationHarness({ offline: { async unlockPassword() { at = h.env.mono(); return { ok: false, message: { key: 'signin_no_answer' } }; } } });
  h.fail(LOGIN, 400, 'csrf_failed', 'Expired.', {}, { delayMs: SIGNIN_TIMEOUT_MS - 100 });
  h.answer(SESSION, 200, touchReply({ user: null, session_ref: null }), { delayMs: 4900 });
  h.answer(LOGIN, 200, loginReply());
  const t0 = h.env.mono();
  const p = h.session.signIn('jdoe', PASSWORD);
  await until(() => h.requests(LOGIN).length === 1);
  await h.env.advance(SIGNIN_TIMEOUT_MS);
  assert.deepEqual(await p, { ok: false, message: { key: 'signin_no_answer' } });
  assert.equal(at - t0, 6000, 'the deadline counts from t0, over the refresh and the retry');
  await stepped(h, 20000);
  await until(() => h.requests(LOGIN).length === 2 && h.f.pending() === 0);
  assert.equal(h.requests(LOGIN).length, 2, 'api.js retried after the refresh');
  assert.equal(h.f.pending(), 0, 'the late 200 arrived');
  assert.equal(h.session.state(), 'LOCKED');
  assert.deepEqual(strs(h.log).filter((l) => l.startsWith('db.')), [], 'the late answer wrote nothing');
  assert.deepEqual([h.rows('keyring'), h.rows('vault_users')], [[], []]);
  assert.equal(h.api.csrf(), 'csrf-2', 'api.js still takes the late answer\'s token');
});

test('signin_missing sends no request', async () => {
  const h = await stationHarness();
  for (const [identifier, password] of [['', PASSWORD], [' \t\n', PASSWORD], [null, PASSWORD], ['jdoe', ''], ['jdoe', undefined]]) {
    assert.deepEqual(await h.session.signIn(identifier, password), { ok: false, message: { key: 'signin_missing' } });
  }
  assert.equal(h.f.requests.length, 0);
  assert.deepEqual(h.updater.calls, []);
  assert.ok(!h.log.includes('vault.deriveKek'));
});

test('a malformed answer gives signin_failed; a malformed release activates with no_grant_possible and writes nothing; password_needed reads as no_grant_possible', async () => {
  for (const over of [{ session_ref: 'xyz' }, { user: userJson({ user_id: '12' }) }, { gate: 'other' }, { user: null }]) {
    const h = await stationHarness();
    assert.deepEqual(await h.signIn(over), { ok: false, message: { key: 'signin_failed' } }, JSON.stringify(over));
    assert.equal(h.session.state(), 'LOCKED');
    assert.ok(h.env.logs.includes('sign-in answer malformed'));
  }
  for (const rel of [release({ dvk: 'short' }), release({ grant_id: 0 }), release({ grant_expires_at: 'soon' }), release({ offline_allowed: 'yes' })]) {
    const h = await stationHarness();
    await signedIn(h, { release: rel });
    assert.equal(h.session.state(), 'ACTIVE');
    assert.equal(h.session.releaseUnavailable(), 'no_grant_possible');
    assert.equal(h.session.canRecord(), false);
    assert.deepEqual([h.rows('keyring'), h.rows('vault_users')], [[], []]);
    assert.ok(h.env.logs.includes('the release was malformed'));
  }
  const h = await stationHarness();
  await signedIn(h, { release: null, release_unavailable: 'password_needed' });
  assert.equal(h.session.releaseUnavailable(), 'no_grant_possible');
  const h2 = await stationHarness();
  await signedIn(h2, { release: null, release_unavailable: 'no_grant_possible' });
  assert.equal(h2.session.releaseUnavailable(), 'no_grant_possible');
});

test('a 401 at a gate locks hard with notice_timeout, account_unusable or notice_signed_out by code', async () => {
  const cases = [['session_timeout', 'notice_timeout'], ['account_blocked', 'account_unusable'], ['session_ended', 'notice_signed_out'],
    ['not_signed_in', 'notice_signed_out']];
  for (const [code, key] of cases) {
    const h = await stationHarness();
    await signedIn(h);
    h.session.switchUser();
    await gateSignIn(h, 'policy_ack', { user: B, session_ref: 'c'.repeat(64) });
    h.fail(POLICY, 401, code, 'Signed out.');
    assert.deepEqual(await h.session.acceptPolicy(policyDoc()), { ok: false }, code);
    assert.equal(h.session.state(), 'LOCKED');
    assert.equal(h.session.hasVaultKey(), false);
    assert.deepEqual(h.session.takeNotice(), { key });
  }
  const h = await stationHarness();
  await gateSignIn(h, 'password_change');
  h.fail(PASSWORD_URL, 401, 'session_timeout', 'Timed out.');
  assert.deepEqual(await h.session.changePassword(PASSWORD, 'A brand new long password'), { ok: false });
  assert.deepEqual(h.session.takeNotice(), { key: 'notice_timeout' });
});

test('loadPolicy: 403 password_change_required switches the gate to password; 401 soft-locks; offline gives ack_failed; two calls at once send one GET', async () => {
  const h = await stationHarness();
  await gateSignIn(h);
  const held = deferred();
  h.answer(POLICY, 200, policyReply(), { until: held.promise });
  const a = h.session.loadPolicy();
  const b = h.session.loadPolicy();
  assert.equal(a, b, 'single flight');
  held.resolve();
  assert.deepEqual(await a, { ok: true, required: true, document: policyDoc() });
  assert.equal(h.requests(POLICY).length, 1);
  assert.equal(h.requests(POLICY)[0].method, 'GET');
  assert.match(h.requests(POLICY)[0].headers['pfpms-proof'], /^v1 /);
  h.f.offline({ url: POLICY });
  assert.deepEqual(await h.session.loadPolicy(), { ok: false, message: { key: 'ack_failed' } });
  h.fail(POLICY, 503, 'maintenance', 'Back soon.');
  assert.deepEqual(await h.session.loadPolicy(), { ok: false, message: { key: 'ack_failed' } });
  h.fail(POLICY, 403, 'password_change_required', 'You must choose a new password first.');
  const seen = [];
  h.session.on('changed', () => seen.push(h.session.gate()));
  assert.deepEqual(await h.session.loadPolicy(), { ok: false });
  assert.equal(h.session.gate(), 'password_change');
  assert.deepEqual(seen, ['password_change']);
  h.fail(POLICY, 401, 'session_timeout', 'Timed out.');
  assert.deepEqual(await h.session.loadPolicy(), { ok: false });
  assert.equal(h.session.state(), 'LOCKED');
  assert.deepEqual(h.session.takeNotice(), { key: 'notice_timeout' });
  assert.equal(CALL_TIMEOUT_MS, 15000);
});

test('no_station_access or account_unusable answering an acceptance or a password change locks hard with the sign-in message as the notice', async () => {
  const cases = [
    [POLICY, 'policy_ack', 403, 'no_station_access', { reason: 'site' }, { key: 'no_station_access_site', params: { site: 'Dev Site North' } }],
    [POLICY, 'policy_ack', 422, 'account_unusable', {}, { key: 'account_unusable' }],
    [PASSWORD_URL, 'password_change', 403, 'no_station_access', { reason: 'role' }, { key: 'no_station_access_role' }],
    [PASSWORD_URL, 'password_change', 422, 'account_unusable', {}, { key: 'account_unusable' }],
  ];
  for (const [url, kind, status, code, extra, notice] of cases) {
    const h = await stationHarness();
    await gateSignIn(h, kind);
    h.fail(url, status, code, 'Refused.', extra);
    const r = kind === 'policy_ack' ? await h.session.acceptPolicy(policyDoc()) : await h.session.changePassword(PASSWORD, 'A brand new long password');
    assert.deepEqual(r, { ok: false }, `${url} ${code}`);
    assert.equal(h.session.state(), 'LOCKED');
    assert.deepEqual(h.session.takeNotice(), notice);
    assert.deepEqual([h.rows('keyring'), h.rows('vault_users')], [[], []]);
  }
});

test('the ACTIVE person\'s grant revoked by a heartbeat drops them to PICKER with grant_revoked and sends logout scope user', async () => {
  const h = await stationHarness();
  await signedIn(h);
  h.session.switchUser();
  await signedIn(h, { user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }, { identifier: 'coord' });
  h.answer(HEARTBEAT, 200, heartbeatReply({ revoked_grants: [346] }));
  h.answer(LOGOUT, 200, logoutReply());
  await h.device.heartbeat();
  await until(() => h.requests(LOGOUT).length === 1 && h.session.peopleForPicker().length === 1); // memory forgets after the commit
  assert.equal(h.session.state(), 'PICKER');
  assert.deepEqual(h.session.takeNotice(), { key: 'grant_revoked' });
  assert.deepEqual(h.rows('keyring').map((r) => r.user_id), [12]);
  assert.deepEqual(h.rows('vault_users').map((r) => r.k), ['user:12']);
  assert.deepEqual(h.session.peopleForPicker().map((p) => p.user_id), [12]);
  const [out] = h.requests(LOGOUT);
  assert.deepEqual(bodyOf(out), { scope: 'user' });
  assert.equal(out.headers.authorization, 'PFPMS-Device ' + CREDENTIAL);
  assert.equal(out.headers['x-csrf-token'], 'csrf-2');
});

test('End shift during a sign-in sent late: its deadline calls no offline seam, and the next sign-in is sent and kept', async () => {
  const seam = [];
  const offline = {
    async unlockPassword(r) { seam.push(['unlockPassword', r.identifier]); return { ok: false, message: { key: 'signin_no_answer' } }; },
    async pinSwitch() { seam.push(['pinSwitch']); return { ok: false, message: { key: 'pin_no_answer' }, next: 'password' }; },
  };
  const h = await stationHarness({ offline });
  h.api.setCsrf(null); // api.js refreshes the token first (1 s), so login.php goes out late (its own timer has not run), then hangs
  h.answer(SESSION, 200, touchReply({ user: null, session_ref: null }), { delayMs: 1000 });
  h.f.hang({ url: LOGIN });
  h.answer(LOGOUT, 200, logoutReply());
  const p = h.session.signIn('jdoe', PASSWORD);
  await h.env.advance(1500);
  await until(() => h.requests(LOGIN).length === 1);
  await h.session.endShift();
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.session.busy(), false, 'the hard lock ends the sign-in\'s flight: the next person can sign in');
  assert.deepEqual(h.updater.calls, ['begin signin', 'begin end_shift', 'end signin', 'end end_shift'],
    'and its hold, once (the End shift holds its own until it is answered)');
  const seen = h.connectivity.length;
  const held = deferred();
  h.answer(LOGIN, 200, loginReply({ user: B, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) }), { until: held.promise });
  const next = h.session.signIn('coord', 'Another-Long-Password-1');
  await until(() => h.requests(LOGIN).length === 2);
  await h.env.advance(SIGNIN_TIMEOUT_MS - 1000); // past the first sign-in's 6-s deadline, while the next one waits
  assert.deepEqual(await p, { ok: false }, 'superseded by End shift: given up');
  assert.deepEqual(seam, [], 'never the offline path (S4: an offline unlock) for an attempt End shift superseded');
  assert.ok(!h.connectivity.slice(seen).some(([s]) => s === 'offline'), 'and no offline chip');
  held.resolve();
  assert.deepEqual(await next, { ok: true }, 'the old deadline left the newer attempt alone');
  assert.equal(h.session.user().user_id, 13);
  assert.deepEqual(h.updater.calls, ['begin signin', 'begin end_shift', 'end signin', 'end end_shift', 'begin signin', 'end signin']);
});

test('a returning volunteer whose stored grant expired keeps the keyring entry and record of the new grant, with no old verifier', async () => {
  const keyPast = formatDb(parseDb(SERVER_TIME) - 3 * 86400000);
  const h = await stationHarness({ seed: {
    vault_users: [await sealVaultUser(vaultUser({ grant_id: 300, grant_expires_at: keyPast, pin_verifier: 'V'.repeat(43), pin_failed: 1, has_pin: true }))],
    keyring: [{ user_id: 12, lookup: ['old'], grant_id: 300, grant_expires_at: keyPast, salt: 'AAAAAAAAAAAAAAAAAAAAAA', iterations: 1000,
      iv: 'AAAAAAAAAAAAAAAA', wrapped_dvk: 'x', v: 1 }],
  } });
  await signedIn(h, { release: release({ grant_id: 400 }) }); // the vault was closed: loadPeople() deletes the expired record
  assert.deepEqual(h.rows('keyring').map((r) => [r.user_id, r.grant_id]), [[12, 400]], 'the entry the sign-in just wrote stays (D-21: by grant)');
  assert.deepEqual(await openKeyring(h, 12, PASSWORD), DVK, 'so the offline unlock works on this tablet');
  const vu = await openVaultUser(h, 12);
  assert.deepEqual([vu.grant_id, vu.pin_verifier, vu.pin_failed], [400, null, 0], 'an expired grant\'s verifier is not carried over (D-22)');
  assert.equal(h.session.canRecord(), true);
  assert.deepEqual(h.session.peopleForPicker().map((p) => [p.user_id, p.has_pin]), [[12, false]]);
  // The same with the person's own record revoked (listed in this answer) and no keyring entry of theirs.
  const r = await stationHarness({ seed: { vault_users: [await sealVaultUser(vaultUser({ grant_id: 300, pin_verifier: 'V'.repeat(43) }))] } });
  await signedIn(r, { release: release({ grant_id: 401 }), revoked_grants: [300] });
  assert.deepEqual(r.rows('keyring').map((x) => [x.user_id, x.grant_id]), [[12, 401]]);
  assert.deepEqual(await openKeyring(r, 12, PASSWORD), DVK);
  assert.deepEqual([(await openVaultUser(r, 12)).grant_id, (await openVaultUser(r, 12)).pin_verifier], [401, null]);
});

test('a prune of an expired grant running while a sign-in from PICKER stores the new grant deletes nothing of the new grant', async () => {
  const h = await stationHarness();
  await signedIn(h, { release: release({ grant_id: 300, grant_expires_at: formatDb(parseDb(SERVER_TIME) + 60000) }) });
  h.answer(PIN_SET, 200, pinSetReply());
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: true });
  h.session.switchUser();
  await h.env.advance(60000); // grant 300 has expired; no tick has pruned it yet
  const put = h.sdb.put;
  let raced = false;
  h.sdb.put = (store, ...args) => {
    const p = put(store, ...args);
    if (store === 'vault_users' && !raced) { raced = true; void h.session.tick(); } // the 15-s tick, just after the write starts
    return p;
  };
  let events = 0;
  h.session.on('people', () => { events += 1; });
  await signedIn(h, { release: release({ grant_id: 400 }) });
  assert.ok(raced);
  await until(() => events === 2); // the sign-in's write, and the prune's (its transaction is over)
  assert.deepEqual(h.rows('keyring').map((r) => [r.user_id, r.grant_id]), [[12, 400]], 'the new keyring row stays');
  const vu = await openVaultUser(h, 12);
  assert.deepEqual([vu.grant_id, vu.pin_verifier], [400, null], 'the new record stays; the expired grant\'s verifier is not carried over');
  assert.equal(h.session.state(), 'ACTIVE');
  assert.equal(h.session.canRecord(), true);
});

test('a revocation still being deleted when the same person signs in again from PICKER: the revoked grant\'s verifier is not carried over', async () => {
  const h = await stationHarness();
  await signedIn(h);
  h.answer(PIN_SET, 200, pinSetReply());
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: true });
  assert.equal(typeof (await openVaultUser(h, 12)).pin_verifier, 'string');
  h.session.switchUser();
  const gate = deferred();
  const tx = h.sdb.tx;
  let held = false;
  h.sdb.tx = async (stores, mode, fn) => { // the revocation's deletion waits: memory still holds the revoked record
    if (!held && stores.includes('keyring') && stores.includes('vault_users')) { held = true; await gate.promise; }
    return tx(stores, mode, fn);
  };
  const revoking = h.session.onRevokedGrants([345]); // a PIN reset elsewhere revoked grant 345
  await until(() => held);
  await signedIn(h, { release: release({ grant_id: 400 }) }); // its revoked_grants is empty: lastRevoked still names 345
  gate.resolve();
  await revoking;
  const vu = await openVaultUser(h, 12);
  assert.deepEqual([vu.grant_id, vu.pin_verifier, vu.pin_failed], [400, null, 0], 'the revoked grant\'s verifier dies with it (D-22)');
  assert.deepEqual(h.rows('keyring').map((r) => [r.user_id, r.grant_id]), [[12, 400]], 'the new grant\'s entry stays (D-21: by grant)');
  assert.equal(h.session.state(), 'ACTIVE');
  assert.equal(h.session.canRecord(), true);
  assert.deepEqual(h.session.peopleForPicker().map((p) => [p.user_id, p.has_pin]), [[12, false]]);
});

test('a prune running while a PIN set rewrites the record of that same expired grant deletes the rewritten record', async () => {
  const h = await stationHarness();
  // Online-only (no keyring row to go by), with a grant that expires in a minute.
  await signedIn(h, { release: release({ offline_allowed: false, grant_expires_at: formatDb(parseDb(SERVER_TIME) + 60000) }) });
  await h.env.advance(60000); // expired; no tick has pruned it yet
  h.answer(PIN_SET, 200, pinSetReply());
  h.answer(LOGOUT, 200, logoutReply());
  const put = h.sdb.put;
  let raced = false;
  h.sdb.put = (store, ...args) => {
    const p = put(store, ...args);
    if (store === 'vault_users' && !raced) { raced = true; void h.session.tick(); } // the 15-s tick, just after the write starts
    return p;
  };
  let events = 0;
  h.session.on('people', () => { events += 1; });
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: true });
  assert.ok(raced);
  await until(() => events === 1); // the prune's transaction is over (the PIN set's write emits nothing)
  assert.deepEqual(h.rows('vault_users'), [], 'the record of the expired grant goes, though it was stored again meanwhile');
  assert.deepEqual(h.session.peopleForPicker(), []);
  assert.equal(h.session.state(), 'PICKER', 'dropped, the keys kept');
  assert.deepEqual(h.session.takeNotice(), { key: 'grant_expired' });
});

test('a revocation while a PIN set rewrites the record of that same grant still deletes it', async () => {
  const h = await stationHarness();
  await signedIn(h, { release: release({ offline_allowed: false }) });
  h.answer(PIN_SET, 200, pinSetReply());
  h.answer(LOGOUT, 200, logoutReply());
  const put = h.sdb.put;
  let revoking = null;
  h.sdb.put = (store, ...args) => {
    const p = put(store, ...args);
    if (store === 'vault_users' && revoking === null) revoking = h.session.onRevokedGrants([345]);
    return p;
  };
  assert.deepEqual(await h.session.setPin(PASSWORD, '4821', '4821'), { ok: true });
  await revoking;
  assert.deepEqual(h.rows('vault_users'), []);
  assert.deepEqual(h.session.peopleForPicker(), []);
  assert.deepEqual(h.session.takeNotice(), { key: 'grant_revoked' });
});

test('a listed grant deletes only the entries that hold it: a person whose record holds a newer grant keeps it and keeps working', async () => {
  const h = await stationHarness();
  await signedIn(h, { release: release({ grant_id: 345, offline_allowed: false }) });
  // A keyring row left from an older grant (as a sign-in that stopped between its two writes would leave it).
  await h.db.put('keyring', { user_id: 12, lookup: ['old'], grant_id: 300, grant_expires_at: '2026-10-03 00:00:00.000', salt: 'AAAAAAAAAAAAAAAAAAAAAA',
    iterations: 1000, iv: 'AAAAAAAAAAAAAAAA', wrapped_dvk: 'x', v: 1 });
  await h.session.onRevokedGrants([300]);
  assert.deepEqual(h.rows('keyring'), [], 'the row of grant 300 goes');
  assert.deepEqual(h.rows('vault_users').map((x) => x.k), ['user:12'], 'the record of grant 345 stays');
  assert.equal(h.session.state(), 'ACTIVE', 'their current grant is not listed');
  assert.equal(h.session.canRecord(), true);
  assert.equal(h.calls(LOGOUT).length, 0);
});

test('on an online-only tablet (no keyring) a revoked grant of the ACTIVE person drops them, deletes their record and signs them out', async () => {
  const h = await stationHarness();
  await signedIn(h, { release: release({ offline_allowed: false }) });
  assert.deepEqual(h.rows('keyring'), [], 'only vault_users holds the person');
  h.answer(LOGOUT, 200, logoutReply());
  await h.session.onRevokedGrants([345]);
  assert.equal(h.session.state(), 'PICKER');
  assert.deepEqual(h.session.takeNotice(), { key: 'grant_revoked' });
  assert.equal(h.session.canRecord(), false);
  assert.deepEqual(h.rows('vault_users'), []);
  assert.deepEqual(h.session.peopleForPicker(), []);
  assert.deepEqual(h.calls(LOGOUT).map((c) => c.body), [{ scope: 'user' }]);
  await until(() => h.requests(LOGOUT).length === 1);
});

test('a sign-in stopped by a hard lock while the keyring entry is made zeroes the raw DVK and opens no vault', async () => {
  const h = await stationHarness();
  const held = deferred();
  const wrap = h.vault.wrapDvk;
  h.vault.wrapDvk = async (...args) => { const r = await wrap(...args); await held.promise; return r; };
  h.answer(LOGIN, 200, loginReply());
  const p = h.session.signIn('jdoe', PASSWORD);
  await until(() => h.vault.seen.some((x) => x.name === 'wrapDvk'));
  const raw = h.vault.seen.find((x) => x.name === 'wrapDvk').args[2];
  assert.ok(raw.some((b) => b !== 0), 'the raw DVK, still whole');
  await h.session.lock('hard', 'end_shift');
  held.resolve();
  assert.deepEqual(await p, { ok: false });
  assert.ok(raw.every((b) => b === 0), 'zeroed although openVault() never ran');
  assert.ok(!h.log.includes('vault.openVault'));
  assert.deepEqual([h.rows('keyring'), h.rows('vault_users')], [[], []]);
  assert.equal(h.session.hasVaultKey(), false);
});

test('a vault user sealed before a hard lock is not stored after it', async () => {
  const h = await stationHarness();
  const held = deferred();
  const seal = h.vault.seal;
  h.vault.seal = async (...args) => { const r = await seal(...args); await held.promise; return r; };
  h.answer(LOGIN, 200, loginReply());
  const p = h.session.signIn('jdoe', PASSWORD);
  await until(() => h.vault.seen.some((x) => x.name === 'seal'));
  await h.session.lock('hard', 'end_shift');
  held.resolve();
  assert.deepEqual(await p, { ok: false });
  assert.ok(!strs(h.log).includes('db.put vault_users'), 'nothing written once the vault has closed');
  assert.deepEqual(h.rows('vault_users'), []);
  assert.deepEqual(h.session.peopleForPicker(), []);
  assert.equal(h.session.state(), 'LOCKED');
});

test('an acceptance that is not sent says the answer was not sent; a server error keeps its problem number', async () => {
  const h = await stationHarness();
  await gateSignIn(h);
  h.f.offline({ url: POLICY });
  assert.deepEqual(await h.session.acceptPolicy(policyDoc()), { ok: false, message: { key: 'ack_send_failed' } }, 'not "could not be loaded"');
  h.fail(POLICY, 503, 'maintenance', 'Back soon.');
  assert.deepEqual(await h.session.acceptPolicy(policyDoc()), { ok: false, message: { key: 'ack_send_failed' } }, '503 is offline (D-58)');
  h.f.offline({ url: POLICY });
  assert.deepEqual(await h.session.loadPolicy(), { ok: false, message: { key: 'ack_failed' } }, 'loading keeps ack_failed');
  h.fail(POLICY, 500, 'server_error', 'Something went wrong.', { incident: 'AB12CD37' });
  assert.deepEqual(await h.session.acceptPolicy(policyDoc()), { ok: false, message: { key: 'server_error', params: { incident: 'AB12CD37' } } });
  assert.equal(h.session.state(), 'GATE', 'the agreement stays for another try');
  assert.equal(h.session.busy(), false);
});

// ---- never rejects over a closed database (one test per method) ----

const closedAfterSignIn = async () => {
  const h = await stationHarness();
  await signedIn(h);
  h.db.close();
  return h;
};
const noUnexpectedLogs = (h) => assert.ok(!h.env.logs.some((l) => /DbClosed|InvalidStateError/.test(l)), h.env.logs.join(' | '));

test('onRevokedGrants never rejects over a closed database', async () => {
  const h = await closedAfterSignIn();
  await assert.doesNotReject(h.session.onRevokedGrants([345]));
  noUnexpectedLogs(h);
});

test('onOfflineDisabled never rejects over a closed database', async () => {
  const h = await closedAfterSignIn();
  await assert.doesNotReject(h.session.onOfflineDisabled());
  noUnexpectedLogs(h);
});

test('replayShiftEnd never rejects over a closed database', async () => {
  const h = await closedAfterSignIn();
  await assert.doesNotReject(h.session.replayShiftEnd());
  noUnexpectedLogs(h);
});

test('endShift never rejects over a closed database', async () => {
  const h = await closedAfterSignIn();
  h.answer(LOGOUT, 200, logoutReply());
  await assert.doesNotReject(h.session.endShift());
  assert.equal(h.session.state(), 'LOCKED');
  assert.equal(h.requests(LOGOUT).length, 1, 'the server still hears of it');
  noUnexpectedLogs(h);
});

test('cancelGate never rejects over a closed database', async () => {
  const h = await stationHarness();
  await gateSignIn(h);
  h.db.close();
  h.answer(LOGOUT, 200, logoutReply());
  await assert.doesNotReject(h.session.cancelGate());
  assert.equal(h.session.state(), 'LOCKED');
  noUnexpectedLogs(h);
});

test('touch never rejects over a closed database', async () => {
  const h = await closedAfterSignIn();
  h.f.offline({ url: SESSION });
  await h.env.advance(TOUCH_EVERY_MS);
  await assert.doesNotReject(h.session.touch());
  await until(() => h.requests(SESSION).length === 1);
  noUnexpectedLogs(h);
});

test('tick never rejects over a closed database', async () => {
  const h = await stationHarness();
  await signedIn(h, { release: release({ grant_expires_at: formatDb(parseDb(SERVER_TIME) + 60000) }) });
  h.db.close();
  h.answer(LOGOUT, 200, logoutReply());
  await h.env.advance(60000);
  await assert.doesNotReject(h.session.tick());
  await until(() => h.session.state() === 'PICKER'); // the expired grant still drops the person
  noUnexpectedLogs(h);
});

// ---- MESSAGE_KEYS ----

test('MESSAGE_KEYS lists every key session.js can return', async () => {
  // 1. Every key literal in session.js, and every value of the notice maps.
  const src = readFileSync(new URL('../../public/station/js/session.js', import.meta.url), 'utf8');
  const literals = new Set([...src.matchAll(/\bkey: '([a-z_]+)'/g), ...src.matchAll(/\b(?:fail|toPassword|drop)\('([a-z_]+)'/g)].map((m) => m[1]));
  assert.ok(literals.size >= 30, 'the scan finds the literals');
  for (const k of literals) assert.ok(MESSAGE_KEYS.includes(k), `literal ${k}`);
  for (const k of Object.values(LOCK_NOTICES)) assert.ok(MESSAGE_KEYS.includes(k), `LOCK_NOTICES ${k}`);
  for (const k of Object.values(ACTIVE_401)) assert.ok(MESSAGE_KEYS.includes(k), `ACTIVE_401 ${k}`);
  for (const reason of Object.values(GATE_401)) assert.ok(MESSAGE_KEYS.includes(LOCK_NOTICES[reason]), `GATE_401 ${reason}`);
  assert.equal(MESSAGE_KEYS.length, 43);
  assert.equal(new Set(MESSAGE_KEYS).size, 43);
  assert.ok(Object.isFrozen(MESSAGE_KEYS) && Object.isFrozen(LOCK_NOTICES) && Object.isFrozen(GATE_401) && Object.isFrozen(ACTIVE_401));

  // 2. A driven pass through the real session: every key that comes back is listed, and every listed key comes back.
  const seen = new Set();
  const note = (r) => {
    if (r?.message?.key) seen.add(r.message.key);
    for (const m of Object.values(r?.errors ?? {})) if (m?.key) seen.add(m.key);
    return r;
  };
  const noteNotice = (s) => { const n = s.takeNotice(); if (n?.key) seen.add(n.key); };

  // the sign-in table, missing fields, offline
  let h = await stationHarness();
  const signInCases = [[422, 'login_failed'], [422, 'account_locked'], [422, 'account_unusable'], [403, 'no_station_access', { reason: 'site' }],
    [403, 'no_station_access', { reason: 'role' }], [429, 'rate_limited'], [401, 'device_proof_invalid'], [401, 'device_proof_stale', {}, 2],
    [403, 'device_site_inactive'], [403, 'device_not_registered'], [500, 'server_error', { incident: 'X1' }], [418, 'other_code']];
  for (const [status, code, extra = {}, times = 1] of signInCases) {
    h.fail(LOGIN, status, code, '', extra, { times });
    note(await h.session.signIn('jdoe', PASSWORD));
  }
  note(await h.session.signIn('', PASSWORD));
  h.f.offline({ url: LOGIN });
  note(await h.session.signIn('jdoe', PASSWORD));

  // PIN switch codes (from PICKER), PIN set, idle, grant drops
  await signedIn(h);
  const pinCases = [[422, 'pin_wrong', { tries_left: 2 }], [422, 'pin_wrong', { tries_left: 1 }], [422, 'pin_wrong', { tries_left: 0 }],
    [422, 'pin_wrong', {}], [422, 'pin_locked'], [422, 'pin_unavailable'], [429, 'rate_limited'], [401, 'device_proof_invalid']];
  const setPinCases = [[422, 'login_failed'], [422, 'pin_rules', { errors: { pin: 'Use 4 to 6 digits.' } }], [403, 'forbidden'], [429, 'rate_limited']];
  for (const [status, code, extra = {}] of setPinCases) {
    h.fail(PIN_SET, status, code, '', extra);
    note(await h.session.setPin(PASSWORD, '4821', '4821'));
  }
  h.f.offline({ url: PIN_SET });
  note(await h.session.setPin(PASSWORD, '4821', '4821'));
  for (const [pin, confirm, password] of [['12', '12', 'x'], ['1234', '1234', 'x'], ['4821', '4822', 'x'], ['4821', '4821', '']]) {
    note(await h.session.setPin(password, pin, confirm));
  }
  h.session.onConfig({ pin_min_digits: 5, pin_max_digits: 5 });
  note(await h.session.setPin(PASSWORD, '1', '1'));
  h.session.onConfig({ pin_min_digits: 4, pin_max_digits: 6 });
  h.session.switchUser();
  for (const [status, code, extra = {}] of pinCases) {
    h.fail(PIN, status, code, '', extra);
    note(await h.session.pinSwitch(12, '1111'));
  }
  h.f.offline({ url: PIN });
  note(await h.session.pinSwitch(12, '1111'));
  h.answer(PIN, 200, pinReply({ session_ref: SESSION_REF }));
  note(await h.session.pinSwitch(12, '4821'));
  await h.env.advance(30 * 60000);
  await h.session.tick();
  noteNotice(h.session); // notice_idle
  h.answer(PIN, 200, pinReply({ session_ref: SESSION_REF }));
  note(await h.session.pinSwitch(12, '4821'));
  h.answer(LOGOUT, 200, logoutReply(), { times: 2 });
  await h.session.onRevokedGrants([345]);
  noteNotice(h.session); // grant_revoked
  await signedIn(h, { release: release({ grant_id: 360, grant_expires_at: formatDb(h.env.now() + 60000) }) });
  await h.env.advance(60000);
  await h.session.tick();
  await until(() => h.session.state() === 'PICKER');
  noteNotice(h.session); // grant_expired

  // 401s while ACTIVE (a PIN set) and in a gate (an acceptance), every code
  for (const code of ['session_timeout', 'account_blocked', 'session_ended', 'not_signed_in']) {
    h = await stationHarness();
    await signedIn(h);
    h.fail(PIN_SET, 401, code);
    note(await h.session.setPin(PASSWORD, '4821', '4821'));
    noteNotice(h.session);
    h = await stationHarness();
    await gateSignIn(h);
    h.fail(POLICY, 401, code);
    note(await h.session.acceptPolicy(policyDoc()));
    noteNotice(h.session);
  }
  for (const code of ['password_change_required', 'policy_ack_required']) {
    h = await stationHarness();
    await signedIn(h);
    h.fail(PIN_SET, 403, code);
    note(await h.session.setPin(PASSWORD, '4821', '4821'));
    noteNotice(h.session);
  }

  // the gates: policy and password errors, refusals, expiry, decline, cancel
  h = await stationHarness();
  await gateSignIn(h);
  h.f.offline({ url: POLICY });
  note(await h.session.loadPolicy());
  h.fail(POLICY, 409, 'policy_changed');
  note(await h.session.acceptPolicy(policyDoc()));
  h.f.offline({ url: POLICY });
  note(await h.session.acceptPolicy(policyDoc()));
  h.fail(POLICY, 418, 'other_code');
  note(await h.session.acceptPolicy(policyDoc()));
  h.fail(POLICY, 403, 'no_station_access', '', { reason: 'site' });
  note(await h.session.acceptPolicy(policyDoc()));
  noteNotice(h.session);
  h = await stationHarness();
  await gateSignIn(h, 'password_change');
  note(await h.session.changePassword(PASSWORD, 'short'));
  for (const [status, code, extra = {}] of [[422, 'login_failed'], [422, 'invalid', { errors: { new_password: 'Too common.' } }], [429, 'rate_limited']]) {
    h.fail(PASSWORD_URL, status, code, 'x', extra);
    note(await h.session.changePassword(PASSWORD, 'A brand new long password'));
  }
  h.f.offline({ url: PASSWORD_URL });
  note(await h.session.changePassword(PASSWORD, 'A brand new long password'));
  h.fail(PASSWORD_URL, 422, 'account_unusable');
  note(await h.session.changePassword(PASSWORD, 'A brand new long password'));
  noteNotice(h.session);
  h = await stationHarness();
  await gateSignIn(h);
  await h.env.advance(GATE_KEK_MS);
  note(await h.session.acceptPolicy(policyDoc()));
  noteNotice(h.session);
  h = await stationHarness();
  await gateSignIn(h);
  h.f.offline({ url: POLICY });
  note(await h.session.declinePolicy(policyDoc()));
  noteNotice(h.session); // ack_declined
  h = await stationHarness();
  await gateSignIn(h);
  h.f.offline({ url: LOGOUT });
  await h.session.cancelGate();
  noteNotice(h.session); // none

  // hard locks: End shift, the absolute limit, the final reasons
  h = await stationHarness();
  await signedIn(h);
  h.f.offline({ url: LOGOUT });
  await h.session.endShift();
  noteNotice(h.session);
  await signedIn(h, { session_ref: 'c'.repeat(64) });
  h.session.onConfig({ session_idle_minutes: 100000 });
  h.answer(LOGOUT, 200, logoutReply());
  await h.env.advance(12 * 3600000);
  await h.session.tick();
  noteNotice(h.session); // notice_absolute
  for (const reason of ['directive', 'erased', 'replaced']) {
    h = await stationHarness();
    await signedIn(h);
    await h.session.lock('hard', reason);
    noteNotice(h.session);
  }

  for (const k of seen) assert.ok(MESSAGE_KEYS.includes(k), `returned ${k}`);
  assert.deepEqual([...MESSAGE_KEYS].filter((k) => !seen.has(k)), [], 'every listed key was reached');
});
