// app.js with the session (S3 spec §3.7, §4.4 WP-F): the real app.js, session.js, screens and views over fake-env.js,
// fake-fetch.js, the memory IndexedDB, fake-dom.js and fake-locks.js, answered with fake-station.js's replies. It checks
// what app.js wires: the routes that follow the session's state and gate, the person bar, End shift and its replay,
// the timers and the input listener, the lock screen redrawn in place, the createSession seam, and the update rule.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeEnv, flush } from './support/fake-env.js';
import { fakeFetch } from './support/fake-fetch.js';
import { openMemoryDb } from './support/memory-db.js';
import { fakeDocument, text } from './support/fake-dom.js';
import { fakeLockManager } from './support/fake-locks.js';
import { fakeContainer, fakeRegistration, fakeWorker } from './support/fake-sw.js';
import {
  BUILD, CONFIG, CREDENTIAL, DEVICE_ID, PASSWORD, SERVER_TIME, acceptReply, heartbeatReply, json, loginReply, logoutReply, passwordReply,
  personJson, pinReply, pinSetReply, policyReply, release, userJson,
} from './support/fake-station.js';
import { importProofKey } from '../../public/station/js/proof.js';
import { formatDb } from '../../public/station/js/canonical.js';
import { HEARTBEAT_MS, TICK_MS, start } from '../../public/station/js/app.js';
import { createSession } from '../../public/station/js/session.js';
import { TRIGGER_GAP_MS } from '../../public/station/js/device.js';
import { QUIET_MS } from '../../public/station/js/update.js';
import { COPY, t } from '../../public/station/js/copy.js';

const SESSION = 'api/session.php';
const HEARTBEAT = 'api/device/heartbeat.php';
const PING = 'api/ping.php';
const LOGIN = 'api/auth/login.php';
const PIN = 'api/auth/pin.php';
const PIN_SET = 'api/auth/pin_set.php';
const LOGOUT = 'api/auth/logout.php';
const POLICY = 'api/auth/policy.php';
const PASSWORD_URL = 'api/auth/password.php';

/** api/session.php's GET answer for an anonymous Station (50 §6.2). */
const sessionInfo = (over = {}) => ({ csrf: 'csrf-s', organisation_name: 'CHS Pet Pantry', build: BUILD, dev_relax: false, user: null, session: null,
  gate: null, ...over });
/** The heartbeat's config with a shorter idle limit (minutes). */
const idleConfig = (minutes) => ({ ...heartbeatReply().config, session_idle_minutes: minutes });

/** Waits (real time, at most 10 s) until pred() holds, letting IndexedDB, WebCrypto and fetch replies settle. */
async function until(pred, ms = 10000) {
  const end = Date.now() + ms;
  for (;;) {
    if (await pred()) return;
    if (Date.now() > end) throw new Error('until: the condition never held');
    await new Promise((resolve) => setImmediate(resolve));
  }
}
const clearHooks = () => { delete globalThis.__pfpms; };

/**
 * A registered tablet (device 7, Dev Site North, E2E 1, 1000 PBKDF2 rounds) over the real app.js and session.js.
 * `server` maps an endpoint to its answer, (request, body) => [status, body] | 'offline' | 'hang' (or a promise of
 * one); every JSON answer gets server_time = the tablet's time when it answers, so the clock learns offset 0. An
 * endpoint with no answer set up is offline (and listed in `sent`, so a test sees it was asked).
 */
async function tablet({ iterations = 1000, dev = false, meta = {}, deps = {}, env: envOver = {}, boot: bootOver = {}, server: over = {} } = {}) {
  const doc = fakeDocument();
  const f = fakeFetch();
  const env = fakeEnv({ document: doc, fetch: f, locks: fakeLockManager(), ...envOver });
  const sent = [];
  const server = {
    [SESSION]: (req) => [200, req.method === 'GET' ? sessionInfo({ dev_relax: dev }) : sessionInfo()],
    [HEARTBEAT]: () => [200, heartbeatReply()],
    ...over,
  };
  f.on(() => true, async (req) => {
    const name = Object.keys(server).find((u) => req.url === '../' + u) ?? null;
    const body = typeof req.body === 'string' ? JSON.parse(req.body) : null;
    sent.push({ url: name ?? req.url, method: req.method, body, csrf: req.headers['x-csrf-token'] ?? null });
    if (name === null) return 'offline';
    const out = await server[name](req, body);
    if (out === 'offline' || out === 'hang') return out;
    const [status, payload] = out;
    return json(status, { ...payload, server_time: formatDb(env.now()) });
  }, { times: Infinity });
  const { db } = await openMemoryDb({ idb: env.indexedDB });
  await db.put('meta', { device_id: DEVICE_ID, credential: CREDENTIAL, proof: await importProofKey(globalThis.crypto, new Uint8Array(32).fill(5)),
    site_id: 3, site_name: 'Dev Site North', label: 'E2E 1', iterations, registered_at: SERVER_TIME }, 'device');
  await db.put('meta', { next: 1 }, 'seq');
  for (const [k, v] of Object.entries(meta)) await db.put('meta', v, k);
  db.close();
  const root = doc.getElementById('app');
  const boot = { registration: null, controlledAtLoad: false, started: () => true, repair: async () => 'repaired', ...bootOver };
  const p = {
    doc, f, env, root, sent, server,
    run: () => start(boot, { env, ...deps }),
    view: () => root.querySelector('section')?.getAttribute('class') ?? null,
    $: (selector) => root.querySelector(selector),
    $$: (selector) => root.querySelectorAll(selector),
    button: (label) => root.querySelectorAll('button').find((b) => text(b) === label) ?? null,
    click(label) {
      const b = p.button(label);
      assert.ok(b, `a button "${label}" shows`);
      b.dispatch('click');
    },
    bar: () => p.$$('.session-bar button').map(text),
    calls: (url) => sent.filter((r) => r.url === url),
    meta: (key) => Object.fromEntries(env.indexedDB._dump('pfpms').meta ?? [])[key],
    /** Types into the lock screen's form and presses Sign in. */
    signIn(identifier, password = PASSWORD) {
      const id = root.querySelector('#identifier');
      const pw = root.querySelector('#password');
      assert.ok(id && pw, 'the sign-in form shows');
      id.value = identifier;
      pw.value = password;
      const submit = root.querySelector('section button[type="submit"]');
      assert.equal(submit.disabled, false, 'the form is enabled');
      submit.dispatch('click');
    },
    /** Presses PIN pad keys: digits, 'back', 'ok'. */
    keys(...list) { for (const k of list) { const b = p.$(`button[data-key="${k}"]`); assert.ok(b, `key ${k}`); b.dispatch('click'); } },
  };
  return p;
}

const HOME = 'view view-home';
const LOGIN_FORM = 'view view-login';
const PICKER = 'view view-login view-picker';
const PIN_PAD = 'view view-login view-pin';

test('signing in from the lock screen shows home with the person and both controls in the header', async () => {
  clearHooks();
  try {
    const p = await tablet({ dev: true, server: { [LOGIN]: () => [200, loginReply()] } });
    const app = await p.run();
    assert.equal(app.state(), 'REGISTERED');
    assert.equal(p.view(), LOGIN_FORM);
    assert.equal(p.$('.session-bar'), null, 'nobody at the tablet: no person bar');
    assert.deepEqual(globalThis.__pfpms.debug.session(), { state: 'LOCKED', gate: null, user_id: null });

    p.signIn(' JDoe ');
    assert.equal(text(p.$('section button[type="submit"]')), COPY.signing_in, 'the form says Signing in… while the answer is out');
    await until(() => p.view() === HOME);
    assert.equal(p.env.hash(), '#/home');
    assert.equal(text(p.$('h1')), t('home_signed_in', { name: 'Jo Doe' }));
    assert.equal(text(p.$('section p.tablet')), 'E2E 1 at Dev Site North');
    assert.equal(text(p.$('section p.status')), COPY.home_online);
    assert.ok(p.button(COPY.set_pin_button), 'a person who can switch with a PIN and has none is offered one');
    // The header: the person's name, Switch user and End shift / Lock device, between the top bar and the view.
    assert.equal(text(p.$('.session-bar .person')), 'Jo Doe');
    assert.deepEqual(p.bar(), [COPY.switch_user, COPY.end_shift]);
    const chrome = p.root.children[0];
    assert.deepEqual(chrome.children.map((n) => n.getAttribute('class')), ['topbar', 'session-bar', 'banner banner-danger'], 'the bar before the banners');
    assert.deepEqual(globalThis.__pfpms.debug.session(), { state: 'ACTIVE', gate: null, user_id: 12 });
    const login = p.calls(LOGIN);
    assert.equal(login.length, 1);
    assert.equal(login[0].body.identifier, ' JDoe ', 'the identifier as typed');
    assert.equal(login[0].csrf, 'csrf-s', 'with the token api/session.php gave');
  } finally {
    clearHooks();
  }
});

test('End shift from the header returns to the lock screen with shift_ended', async () => {
  const logouts = [];
  let answer;
  const held = new Promise((resolve) => { answer = resolve; });
  const p = await tablet({ server: {
    [LOGIN]: () => [200, loginReply()],
    [LOGOUT]: async (req, body) => { logouts.push(body); await held; return [200, logoutReply()]; },
  } });
  await p.run();
  p.signIn('jdoe');
  await until(() => p.view() === HOME);
  p.click(COPY.end_shift);
  // At once, before the server answers: the keys are gone and the lock screen shows.
  assert.equal(p.view(), LOGIN_FORM, 'the form (a hard lock: no picker)');
  assert.equal(p.$('ul.tiles'), null);
  assert.equal(p.env.hash(), '#/login');
  assert.equal(text(p.$('p.notice')), COPY.shift_ended);
  assert.equal(p.$('.session-bar'), null, 'nobody and no keys: no person bar');
  assert.equal(p.$('#identifier').value, '', 'the form is empty');
  await until(() => logouts.length === 1 && p.meta('pending_shift_end') !== undefined);
  assert.deepEqual(logouts, [{ scope: 'device' }]);
  assert.equal(typeof p.meta('shift_ended_at'), 'string');
  answer();
  await until(() => p.meta('pending_shift_end') === undefined);
  assert.equal(p.view(), LOGIN_FORM);
  assert.equal(text(p.$('p.notice')), COPY.shift_ended, 'the notice stays');
});

test('a gate routes to ack or password and to home after the release', async () => {
  const logins = [loginReply({ gate: 'policy_ack', release: null }), loginReply({ gate: 'password_change', release: null })];
  const decisions = [];
  const changes = [];
  const p = await tablet({ server: {
    [LOGIN]: () => [200, logins.shift()],
    [POLICY]: (req, body) => {
      if (req.method === 'GET') return [200, policyReply()];
      decisions.push(body.decision);
      return [200, acceptReply()];
    },
    [PASSWORD_URL]: (req, body) => { changes.push(Object.keys(body).sort()); return [200, passwordReply({ gate: 'policy_ack', release: null })]; },
    [LOGOUT]: () => [200, logoutReply()],
  } });
  await p.run();

  // The agreement, then home.
  p.signIn('jdoe');
  await until(() => p.view() === 'view view-ack' && p.button(COPY.ack_accept) !== null);
  assert.equal(p.env.hash(), '#/ack');
  assert.deepEqual(p.bar(), [COPY.end_shift], 'at a gate: End shift only');
  assert.equal(p.$('.session-bar .person'), null, 'nobody works yet');
  for (const refused of ['#/home', '#/login', '#/pin', '#/password']) {
    p.env.setHash(refused);
    await flush();
    assert.equal(p.view(), 'view view-ack', `${refused} is refused at the agreement`);
  }
  await until(() => p.button(COPY.ack_accept) !== null); // the agreement view drawn again loads the text again
  p.click(COPY.ack_accept);
  await until(() => p.view() === HOME);
  assert.equal(p.env.hash(), '#/home');
  assert.deepEqual(p.bar(), [COPY.switch_user, COPY.end_shift]);
  assert.deepEqual(decisions, ['accept']);

  // The forced change, then the agreement, then home.
  p.click(COPY.end_shift);
  assert.equal(p.view(), LOGIN_FORM);
  p.signIn('jdoe');
  await until(() => p.view() === 'view view-password');
  assert.equal(p.env.hash(), '#/password');
  assert.deepEqual(p.bar(), [COPY.end_shift]);
  p.env.setHash('#/ack');
  await flush();
  assert.equal(p.view(), 'view view-password', 'the agreement waits for the password change');
  p.$('#current_password').value = 'Temporary-Pass-1';
  p.$('#new_password').value = 'A-new-long-password-2';
  p.$('#repeat_password').value = 'A-new-long-password-2';
  p.$('section button[type="submit"]').dispatch('click');
  await until(() => p.view() === 'view view-ack' && p.button(COPY.ack_accept) !== null);
  assert.equal(p.env.hash(), '#/ack');
  assert.deepEqual(changes, [['current_password', 'new_password']]);
  p.click(COPY.ack_accept);
  await until(() => p.view() === HOME);
  assert.equal(p.env.hash(), '#/home');
  assert.deepEqual(decisions, ['accept', 'accept']);
  assert.equal(text(p.$('.session-bar .person')), 'Jo Doe');
});

test('Switch user shows the picker; a PIN switch shows home as that person', async () => {
  const jo = userJson({ has_pin: true });
  const ann = personJson(13, 'ann', 'Ann Lee');
  const logins = [loginReply({ user: jo }), loginReply({ user: ann, session_ref: 'c'.repeat(64), release: release({ grant_id: 346 }) })];
  const pins = [];
  const p = await tablet({ server: {
    [LOGIN]: () => [200, logins.shift()],
    [PIN]: (req, body) => { pins.push(body); return [200, pinReply({ user: jo })]; },
  } });
  await p.run();
  p.signIn('jdoe');
  await until(() => p.view() === HOME);

  p.click(COPY.switch_user);
  assert.equal(p.view(), PICKER);
  assert.equal(p.env.hash(), '#/login');
  assert.equal(text(p.$('h1')), COPY.picker_title);
  assert.deepEqual(p.$$('ul.tiles button').map(text), [COPY.someone_else, 'Jo Doe']);
  assert.deepEqual(p.bar(), [COPY.end_shift], 'keys but nobody: End shift only');
  assert.equal(p.$('.session-bar .person'), null);

  // Someone else signs in with a password from the picker.
  p.click(COPY.someone_else);
  assert.equal(p.view(), LOGIN_FORM);
  assert.ok(p.button(COPY.back), 'Back returns to the picker');
  p.signIn('ann');
  await until(() => p.view() === HOME && text(p.$('h1')) === t('home_signed_in', { name: 'Ann Lee' }));
  assert.equal(text(p.$('.session-bar .person')), 'Ann Lee');

  // Back to Jo by PIN.
  p.click(COPY.switch_user);
  assert.deepEqual(p.$$('ul.tiles button').map(text), [COPY.someone_else, 'Ann Lee', 'Jo Doe'], 'Someone else first, then both people by name');
  p.$('ul.tiles button[data-user-id="12"]').dispatch('click');
  assert.equal(p.view(), PIN_PAD);
  assert.equal(text(p.$('h1')), 'Jo Doe');
  p.keys('4', '8', '2', '1', 'ok');
  await until(() => p.view() === HOME);
  assert.equal(text(p.$('h1')), t('home_signed_in', { name: 'Jo Doe' }));
  assert.equal(text(p.$('.session-bar .person')), 'Jo Doe');
  assert.deepEqual(p.bar(), [COPY.switch_user, COPY.end_shift]);
  assert.deepEqual(pins, [{ user_id: 12, pin: '4821' }]);
  assert.equal(p.calls(LOGIN).length, 2, 'no password for the PIN switch');
});

test('the session ticks every 15 s and on visible before the heartbeat; input calls updater.inputSeen and session.touch', async () => {
  const log = [];
  let p = null;
  let viewAtHeartbeat = null;
  // The real session, with app.js's clock, updater and device watched through the deps it is given.
  const watched = (d) => {
    const s = createSession(d);
    const tickClock = d.clock.tick;
    d.clock.tick = () => { log.push('clock.tick'); return tickClock(); };
    const inputSeen = d.updater.inputSeen;
    d.updater.inputSeen = () => { log.push('updater.inputSeen'); return inputSeen(); };
    const heartbeat = d.device.heartbeat;
    d.device.heartbeat = (o) => { log.push('heartbeat ' + o?.reason); viewAtHeartbeat = p?.view() ?? null; return heartbeat(o); };
    return { ...s, tick: () => { log.push('session.tick'); return s.tick(); }, touch: () => { log.push('session.touch'); return s.touch(); } };
  };
  p = await tablet({ deps: { createSession: watched }, server: { [LOGIN]: () => [200, loginReply()] } });
  await p.run();
  assert.deepEqual(log, ['heartbeat startup']);
  log.length = 0;

  await p.env.advance(TICK_MS - 1);
  assert.deepEqual(log, []);
  await p.env.advance(1);
  assert.deepEqual(log, ['clock.tick', 'session.tick'], 'every 15 s: the clock, then the session');
  await p.env.advance(TICK_MS);
  assert.deepEqual(log, ['clock.tick', 'session.tick', 'clock.tick', 'session.tick']);

  // Input: the update rule's quiet time and the session's idle timer.
  log.length = 0;
  p.env.fire('input');
  assert.deepEqual(log, ['updater.inputSeen', 'session.touch']);

  // Someone works; the tablet sleeps past the idle limit (the wall clock moves, no timer runs) and wakes.
  p.signIn('jdoe');
  await until(() => p.view() === HOME);
  await p.env.advance(TRIGGER_GAP_MS);
  log.length = 0;
  p.env.jumpWall(31 * 60000);
  p.env.fire('visible');
  assert.deepEqual(log, ['clock.tick', 'session.tick', 'heartbeat visible'], 'the session ticks before the heartbeat');
  assert.equal(viewAtHeartbeat, PICKER, 'the woken tablet locked before the heartbeat was even asked for');
  assert.equal(text(p.$('p.notice')), COPY.notice_idle);
  assert.equal(p.$('.session-bar .person'), null, 'the last person is gone from the header');
});

test('a heartbeat 200 and an api/session.php answer replay a pending End shift', async () => {
  const at = '2026-10-01 11:00:00.000';
  const pending = { shift_ended_at: at, pending_shift_end: { at } };

  // api/session.php answers; the heartbeat never does.
  const first = [];
  const a = await tablet({ meta: pending, server: {
    [HEARTBEAT]: () => 'offline',
    [LOGOUT]: (req, body) => { first.push(body); return [200, logoutReply()]; },
  } });
  await a.run();
  await until(() => a.meta('pending_shift_end') === undefined);
  assert.deepEqual(first, [{ scope: 'device', ended_at: at }], 'sent with its own time');
  assert.equal(a.calls(LOGOUT)[0].csrf, 'csrf-s', 'with the token that answer brought');
  assert.equal(a.calls(HEARTBEAT).length, 1, 'the heartbeat got no answer');

  // The heartbeat answers; api/session.php did not at the start.
  const second = [];
  let sessions = 0;
  const b = await tablet({ meta: pending, server: {
    [SESSION]: () => (++sessions === 1 ? 'offline' : [200, sessionInfo()]),
    [LOGOUT]: (req, body) => { second.push(body); return [200, logoutReply()]; },
  } });
  await b.run();
  await until(() => b.meta('pending_shift_end') === undefined);
  assert.deepEqual(second, [{ scope: 'device', ended_at: at }]);
  const urls = b.sent.map((r) => r.url);
  assert.ok(urls.indexOf(HEARTBEAT) < urls.indexOf(LOGOUT), 'after the heartbeat\'s 200');
  assert.equal(b.meta('shift_ended_at'), at, 'the tablet\'s own record of the End shift stays');
  // Nothing is left to send: later heartbeats send no logout.
  await b.env.advance(HEARTBEAT_MS);
  await until(() => b.calls(HEARTBEAT).length === 2);
  await flush(20);
  assert.equal(second.length, 1);
});

test('About stays across a session change; other views follow the session\'s home', async () => {
  const p = await tablet({ server: {
    [LOGIN]: () => [200, loginReply()],
    [LOGOUT]: () => [200, logoutReply()],
    [PING]: () => [200, { ok: true, build: BUILD, authorization_received: true, script_path: PING }],
  } });
  await p.run();
  p.signIn('jdoe');
  await until(() => p.view() === HOME);

  // About while someone works: Back returns to home, not to the lock screen.
  p.env.setHash('#/about');
  await until(() => p.view() === 'view view-about');
  assert.deepEqual(p.bar(), [COPY.switch_user, COPY.end_shift], 'the person bar stays on About');
  p.click(COPY.back);
  assert.equal(p.view(), HOME);
  assert.equal(p.env.hash(), '#/home');

  // A session change while About shows: About stays; Back then goes to the session's home.
  p.env.setHash('#/about');
  await until(() => p.view() === 'view view-about');
  p.click(COPY.end_shift);
  assert.equal(p.view(), 'view view-about', 'About stays');
  assert.equal(p.$('.session-bar'), null);
  p.click(COPY.back);
  assert.equal(p.view(), LOGIN_FORM);
  assert.equal(p.env.hash(), '#/login');
  assert.equal(text(p.$('p.notice')), COPY.shift_ended, 'the notice waited for the lock screen');

  // Other views follow: the PIN-set view gives way to the picker at Switch user.
  p.signIn('jdoe');
  await until(() => p.view() === HOME);
  p.env.setHash('#/pin');
  await until(() => p.view() === 'view view-pin-set');
  p.click(COPY.switch_user);
  assert.equal(p.view(), PICKER);
  assert.equal(p.env.hash(), '#/login');
  p.env.setHash('#/home');
  await flush();
  assert.equal(p.view(), PICKER, 'home needs someone at the tablet');
});

test('the PIN-set success screen stays until Continue', async () => {
  const listeners = { changed: [], people: [] };
  // The real session; app.js's listeners are kept so the test can raise an event that moves nothing.
  const watched = (d) => {
    const s = createSession(d);
    return { ...s, on(event, cb) { listeners[event]?.push(cb); return s.on(event, cb); } };
  };
  const sets = [];
  const p = await tablet({ deps: { createSession: watched }, server: {
    [LOGIN]: () => [200, loginReply()],
    [PIN_SET]: (req, body) => { sets.push(Object.keys(body).sort()); return [200, pinSetReply()]; },
  } });
  await p.run();
  assert.equal(listeners.changed.length, 1, 'app.js listens to changed');
  assert.equal(listeners.people.length, 1, 'and to people');
  p.signIn('jdoe');
  await until(() => p.view() === HOME);
  p.click(COPY.set_pin_button);
  assert.equal(p.view(), 'view view-pin-set');
  assert.equal(p.env.hash(), '#/pin');
  p.$('#pin_password').value = PASSWORD;
  p.$('#pin_new').value = '4821';
  p.$('#pin_repeat').value = '4821';
  p.$('section button[type="submit"]').dispatch('click');
  await until(() => text(p.root).includes(COPY.pin_set_ok));
  assert.deepEqual(sets, [['password', 'pin', 'pin_confirm']]);

  // Events that move neither the state nor the gate leave the success where it is.
  for (const cb of listeners.changed) cb();
  for (const cb of listeners.people) cb();
  await flush(20);
  assert.equal(p.view(), 'view view-pin-set');
  assert.equal(p.env.hash(), '#/pin');
  assert.equal(text(p.$('p.message-ok')), COPY.pin_set_ok);
  assert.deepEqual(p.bar(), [COPY.switch_user, COPY.end_shift]);

  p.click(COPY.continue);
  assert.equal(p.view(), HOME);
  assert.equal(p.env.hash(), '#/home');
  assert.equal(p.button(COPY.set_pin_button), null, 'the PIN is set: home offers it no more');
});

test('a heartbeat that changes the label while the PIN pad is open keeps the digits and the notice', async () => {
  let label = 'E2E 1';
  const p = await tablet({ server: {
    [LOGIN]: () => [200, loginReply({ user: userJson({ has_pin: true }), config: { ...CONFIG, session_idle_minutes: 1 } })],
    [HEARTBEAT]: () => [200, heartbeatReply({ label, config: idleConfig(1) })],
  } });
  await p.run();
  p.signIn('jdoe');
  await until(() => p.view() === HOME);
  // Nobody touches the tablet for a minute: the picker, with why.
  const idleFor = 60000 + TICK_MS;
  await p.env.advance(idleFor);
  await until(() => p.view() === PICKER);
  assert.equal(text(p.$('p.notice')), COPY.notice_idle);
  p.$('ul.tiles button[data-user-id="12"]').dispatch('click');
  assert.equal(p.view(), PIN_PAD);
  p.keys('4', '8');
  assert.equal(text(p.$('p.pin-dots')), '●●○○○○');

  // An Administrator renames the tablet; the next heartbeat brings it.
  label = 'E2E 2';
  const beats = p.calls(HEARTBEAT).length;
  // Up to the 5-minute heartbeat and no further: its answer is awaited in real time, so the fake clock must not pass
  // its 15-s timeout meanwhile.
  await p.env.advance(HEARTBEAT_MS - idleFor);
  await until(() => p.calls(HEARTBEAT).length > beats && text(p.$('span.tablet')) === 'E2E 2 at Dev Site North');
  await flush(20);
  assert.equal(p.view(), PIN_PAD, 'still the PIN pad');
  assert.equal(text(p.$('section p.tablet')), 'E2E 2 at Dev Site North', 'the lock screen shows the new name');
  assert.equal(text(p.$('p.pin-dots')), '●●○○○○', 'the digits typed so far stay');
  assert.equal(text(p.$('p.notice')), COPY.notice_idle, 'and the notice');
  p.keys('2', '1');
  assert.equal(text(p.$('p.pin-dots')), '●●●●○○', 'typing goes on where it was');
});

test('createSession null keeps the stand-in, and a stub with only S2\'s members (on() returning undefined) works', async () => {
  clearHooks();
  try {
    // null: nobody ever signs in, and nothing is asked of the server for it.
    const p = await tablet({ dev: true, deps: { createSession: null } });
    const app = await p.run();
    assert.equal(app.state(), 'REGISTERED');
    assert.equal(p.view(), LOGIN_FORM);
    p.signIn('jdoe');
    await flush(20);
    assert.deepEqual(p.sent.filter((r) => r.url.includes('api/auth/')), [], 'no sign-in request');
    assert.equal(p.view(), LOGIN_FORM);
    assert.equal(p.$('section button[type="submit"]').disabled, false, 'the form is usable again');
    assert.equal(p.$('p.message'), null);
    assert.equal(p.$('.session-bar'), null);
    assert.deepEqual(globalThis.__pfpms.debug.session(), { state: 'LOCKED', gate: null, user_id: null });
    p.env.setHash('#/home');
    await flush();
    assert.equal(p.view(), LOGIN_FORM, 'the stand-in allows only the lock screen');
    await p.env.advance(TICK_MS * 2);
    p.env.fire('input');
    await p.env.advance(TRIGGER_GAP_MS);
    p.env.fire('visible');
    await flush(20);
    assert.equal(app.state(), 'REGISTERED');
  } finally {
    clearHooks();
  }

  // S2's stub: no gate(), user(), config(), replayShiftEnd()…, and on() returns undefined.
  const locks = [];
  const stub = () => ({ state: () => 'LOCKED', lock: (...a) => locks.push(a), onRevokedGrants() {}, onOfflineDisabled() {}, tick() {}, touch() {},
    hasVaultKey: () => false, on() {} });
  const q = await tablet({ deps: { createSession: stub }, server: {
    [HEARTBEAT]: () => [200, heartbeatReply({ revoked_grants: [345], offline_enabled: false })],
    [PING]: () => [200, { ok: true, build: BUILD, authorization_received: true, script_path: PING }],
  } });
  const appQ = await q.run();
  assert.equal(appQ.state(), 'REGISTERED');
  assert.equal(q.view(), LOGIN_FORM);
  q.signIn('jdoe');
  await flush(20);
  assert.equal(q.view(), LOGIN_FORM);
  await q.env.advance(HEARTBEAT_MS); // ticks, and a heartbeat whose answer calls every hook
  await until(() => q.calls(HEARTBEAT).length === 2); // answered before the clock moves past its timeout
  await flush(20);
  q.env.fire('input');
  await q.env.advance(TRIGGER_GAP_MS);
  q.env.fire('visible');
  q.env.setHash('#/about');
  await until(() => q.view() === 'view view-about');
  q.click(COPY.back);
  assert.equal(q.view(), LOGIN_FORM);
  await flush(20);
  assert.equal(appQ.state(), 'REGISTERED');
  assert.deepEqual(locks, []);
  assert.deepEqual(q.env.logs.filter((l) => /failed|Error/.test(l)), []);
});

test('no update is applied while GATE, ACTIVE or IDLE', async () => {
  const registration = fakeRegistration({ active: fakeWorker('activated'), waiting: null });
  const container = fakeContainer({ controller: fakeWorker('activated'), registration });
  const p = await tablet({ env: { serviceWorker: container }, boot: { registration, controlledAtLoad: true }, server: {
    [LOGIN]: () => [200, loginReply({ gate: 'policy_ack', release: null, config: { ...CONFIG, session_idle_minutes: 5 } })],
    [POLICY]: (req) => [200, req.method === 'GET' ? policyReply() : acceptReply()],
    [LOGOUT]: () => [200, logoutReply()],
    [HEARTBEAT]: () => [200, heartbeatReply({ config: idleConfig(5) })],
  } });
  await p.run();
  const waiting = fakeWorker('installed');
  const wait = QUIET_MS + TICK_MS * 2; // past the quiet time, with two update checks in it

  // GATE
  p.signIn('jdoe');
  await until(() => p.view() === 'view view-ack' && p.button(COPY.ack_accept) !== null);
  registration.waiting = waiting;
  await p.env.advance(wait);
  assert.equal(p.view(), 'view view-ack');
  assert.deepEqual(waiting.messages, [], 'not at the agreement');

  // ACTIVE
  p.click(COPY.ack_accept);
  await until(() => p.view() === HOME);
  await p.env.advance(wait);
  assert.equal(p.view(), HOME);
  assert.deepEqual(waiting.messages, [], 'not while someone works');

  // IDLE (the keys are kept)
  await p.env.advance(5 * 60000);
  await until(() => p.view() === PICKER);
  assert.equal(text(p.$('p.notice')), COPY.notice_idle);
  await p.env.advance(wait);
  assert.equal(p.view(), PICKER);
  assert.deepEqual(waiting.messages, [], 'not while the tablet is only idle');

  // LOCKED: End shift; the update goes at the next tick's check once the End shift is answered (its own update.js
  // hold, D-24, ends then: a tick while the logout is still out would find the hold and wait for the tick after).
  p.click(COPY.end_shift);
  assert.equal(p.view(), LOGIN_FORM);
  await until(() => p.calls(LOGOUT).length === 1 && p.meta('pending_shift_end') === undefined);
  await flush(20);
  assert.deepEqual(waiting.messages, [], 'the picker and the form are one view: no check of its own');
  await p.env.advance(TICK_MS);
  await until(() => waiting.messages.length === 1);
  assert.deepEqual(waiting.messages, [{ type: 'SKIP_WAITING' }]);
  assert.equal(p.env.reloads, 0, 'the reload waits for the new worker');
});

/** The node, named shortly for a failure message. */
const nodeName = (n) => (n === null || n === undefined ? String(n)
  : `<${String(n.tagName ?? '#text').toLowerCase()}${['id', 'data-key', 'data-user-id'].map((a) => (n.getAttribute?.(a) ? ` ${a}="${n.getAttribute(a)}"` : '')).join('')}> "${String(n.textContent ?? '').slice(0, 40)}"`);
/**
 * actual === expected for fake-DOM nodes (or null). assert.equal would print and diff both nodes' whole object graphs
 * on a failure, which takes minutes; this fails at once with the two nodes named.
 */
function same(actual, expected, message = 'the same node') {
  if (actual !== expected) assert.fail(`${message}: got ${nodeName(actual)}, wanted ${nodeName(expected)}`);
}

test('the app keeps the focus: Set a PIN across a connection change, End shift across a header redraw, a PIN key on the pad', async () => {
  const p = await tablet({ server: { [LOGIN]: () => [200, loginReply()] } });
  await p.run();
  p.signIn('jdoe');
  await until(() => p.view() === HOME);
  // The chip goes offline: home is drawn again (its "Working online." goes), and Set a PIN keeps the focus.
  p.$('#set-pin').focus();
  p.env.fire('offline');
  assert.equal(text(p.$('span.chip')), COPY.offline);
  same(p.$('section p.status'), null, 'home follows the chip');
  same(p.doc.activeElement, p.$('#set-pin'), 'Set a PIN keeps the focus');
  // Back online: the header (and home) are drawn again, and End shift keeps the focus.
  p.$('#end-shift').focus();
  await p.env.advance(TRIGGER_GAP_MS); // past the start-up heartbeat, so online asks the server at once
  p.env.fire('online');
  await until(() => text(p.$('span.chip')) === COPY.online);
  assert.equal(text(p.$('section p.status')), COPY.home_online);
  same(p.doc.activeElement, p.$('#end-shift'), 'End shift keeps the focus');

  // The PIN pad, through app.js's drawing: the pressed key keeps the focus and nothing is re-mounted.
  const jo = userJson({ has_pin: true });
  const q = await tablet({ server: { [LOGIN]: () => [200, loginReply({ user: jo })], [PIN]: () => 'hang' } });
  await q.run();
  q.signIn('jdoe');
  await until(() => q.view() === HOME);
  q.click(COPY.switch_user);
  q.$('ul.tiles button[data-user-id="12"]').dispatch('click');
  assert.equal(q.view(), PIN_PAD);
  const section = q.$('section');
  for (const k of ['4', '8', '2']) {
    const key = q.$(`button[data-key="${k}"]`);
    key.focus();
    key.dispatch('click');
    same(q.doc.activeElement, key, `key ${k} keeps the focus`);
  }
  same(q.$('section'), section, 'the pad is changed in place');
  assert.equal(text(q.$('p.pin-count')), '3 digits entered');
  const one = q.$('button[data-key="1"]');
  one.focus();
  one.dispatch('click');
  const ok = q.$('button[data-key="ok"]');
  ok.focus();
  ok.dispatch('click'); // the answer never comes: the wait
  await flush(5);
  same(q.doc.activeElement, ok, 'OK keeps the focus while the PIN is checked');
  assert.equal(text(q.$('section p.status')), COPY.signing_in);
});

test('a flapping connection on home moves no focus: the About link, the title and the page keep it; the lock screen drawn again keeps the About link', async () => {
  const listeners = { people: [] };
  const watched = (d) => {
    const s = createSession(d);
    return { ...s, on(event, cb) { listeners[event]?.push(cb); return s.on(event, cb); } };
  };
  const p = await tablet({ deps: { createSession: watched }, server: { [LOGIN]: () => [200, loginReply()] } });
  await p.run();
  // The lock screen drawn again (the people read again): the focused About link in the footer keeps the focus.
  assert.equal(p.view(), LOGIN_FORM);
  p.$('footer a.link').focus();
  same(p.doc.activeElement, p.$('#about-link'), 'the footer link can take the focus');
  const lockSection = p.$('section');
  for (const cb of listeners.people) cb();
  assert.ok(p.$('section') !== lockSection, 'the lock screen was drawn again');
  same(p.doc.activeElement, p.$('#about-link'), 'About keeps the focus across the lock screen drawn again');

  p.signIn('jdoe');
  await until(() => p.view() === HOME);
  const section = p.$('section');
  const title = p.$('h1');
  same(p.doc.activeElement, title, 'home: the focus on its title');
  // Offline: the title is the same node and keeps the focus (it is not focused, so not read out, again).
  p.env.fire('offline');
  assert.equal(text(p.$('span.chip')), COPY.offline);
  same(p.$('section p.status'), null, 'home follows the chip');
  same(p.$('section'), section, 'home is changed in place');
  same(p.$('h1'), title, 'the same title');
  same(p.doc.activeElement, title, 'the title keeps the focus');
  // The page has the focus: online again leaves it there.
  p.doc.activeElement = p.doc.body;
  await p.env.advance(TRIGGER_GAP_MS); // past the start-up heartbeat, so online asks the server at once
  p.env.fire('online');
  await until(() => text(p.$('span.chip')) === COPY.online);
  assert.equal(text(p.$('section p.status')), COPY.home_online);
  same(p.doc.activeElement, p.doc.body, 'the page keeps the focus');
  // The footer's About link: offline, then online again; it keeps the focus (the footer is not drawn again).
  const about = p.$('#about-link');
  about.focus();
  p.env.fire('offline');
  assert.equal(text(p.$('span.chip')), COPY.offline);
  same(p.doc.activeElement, about, 'About keeps the focus when the chip goes offline');
  await p.env.advance(TRIGGER_GAP_MS);
  p.env.fire('online');
  await until(() => text(p.$('span.chip')) === COPY.online);
  same(p.doc.activeElement, about, 'and when it comes back');
  same(p.$('section'), section, 'still the home section mounted at sign-in');
});
