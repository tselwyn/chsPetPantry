// public/station/js/router.js (S2 spec §3.12, S3 spec §3.8): hash routes gated by the tablet state and the session's
// state and gate, the ALLOWED, SESSION_VIEWS and GATE_VIEWS tables, and the router's own hashchange handling, over
// fake-env.js.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeEnv, flush } from './support/fake-env.js';
import { ALLOWED, GATE_VIEWS, ROUTES, SESSION_VIEWS, allowedIn, createRouter, homeOf, parseHash, sessionAllows, sessionHome }
  from '../../public/station/js/router.js';
import { STATES } from '../../public/station/js/session.js';

/** A router over fakeEnv in a given state; `rendered` lists every render(name). */
function setup(state = 'UNREGISTERED', hash = '') {
  const env = fakeEnv({ hash });
  const rendered = [];
  const s = { state };
  const router = createRouter({ env, render: (n) => rendered.push(n), allowed: (n) => allowedIn(s.state, n), fallback: () => homeOf(s.state) });
  return { env, rendered, s, router };
}

test('parseHash takes only #/<known route>', () => {
  for (const r of ROUTES) assert.equal(parseHash('#/' + r), r);
  for (const h of ['', '#', '#/', '#about', '/about', '#/About', '#/about/', '#/about?x=1', '#/nowhere', '#/../about', ' #/about', null, undefined]) {
    assert.equal(parseHash(h), null, JSON.stringify(h));
  }
});

test('ALLOWED has the §3.12 views per state, home first', () => {
  assert.deepEqual(ALLOWED, {
    STARTING: ['starting'], UNREGISTERED: ['device', 'about'], REGISTERED: ['login', 'home', 'ack', 'password', 'pin', 'about'], WIPING: ['wipe'],
    ERASED: ['wipe'], ELSEWHERE: ['elsewhere'], REPLACED: ['elsewhere'], NO_STORAGE: ['starting'],
  });
  assert.ok(Object.isFrozen(ALLOWED));
  for (const [state, views] of Object.entries(ALLOWED)) {
    assert.equal(homeOf(state), views[0]);
    for (const v of views) assert.ok(ROUTES.includes(v), `${v} is a route`);
  }
  assert.equal(homeOf('NO_SUCH_STATE'), 'starting');
  assert.equal(allowedIn('NO_SUCH_STATE', 'starting'), false);
  assert.deepEqual(ROUTES, ['starting', 'device', 'login', 'home', 'ack', 'password', 'pin', 'about', 'wipe', 'elsewhere']);
  assert.ok(Object.isFrozen(ROUTES));
  // Every route some state allows, and none that no state allows.
  assert.deepEqual([...new Set(Object.values(ALLOWED).flat())].sort(), [...ROUTES].sort());
});

test('sessionHome and sessionAllows follow the session state and the gate', () => {
  assert.deepEqual(SESSION_VIEWS, { LOCKED: ['login'], PICKER: ['login'], IDLE: ['login'], ACTIVE: ['home', 'pin'], GATE: [] });
  assert.deepEqual(GATE_VIEWS, { policy_ack: 'ack', password_change: 'password' });
  assert.ok(Object.isFrozen(SESSION_VIEWS) && Object.isFrozen(GATE_VIEWS));
  assert.deepEqual(Object.keys(SESSION_VIEWS).sort(), [...STATES].sort(), 'one row per session.js state');
  // Every session view is a registered tablet's view.
  for (const v of [...Object.values(SESSION_VIEWS).flat(), ...Object.values(GATE_VIEWS)]) assert.ok(allowedIn('REGISTERED', v), v);

  // The home view of each state.
  assert.equal(sessionHome('LOCKED', null), 'login');
  assert.equal(sessionHome('PICKER', null), 'login');
  assert.equal(sessionHome('IDLE', null), 'login');
  assert.equal(sessionHome('ACTIVE', null), 'home');
  assert.equal(sessionHome('GATE', 'policy_ack'), 'ack');
  assert.equal(sessionHome('GATE', 'password_change'), 'password');
  assert.equal(sessionHome('GATE', null), 'login', 'a gate without a kind is the lock screen');
  assert.equal(sessionHome('GATE', 'something_else'), 'login');
  assert.equal(sessionHome('NO_SUCH_STATE', null), 'login', 'an unknown state is the lock screen');
  assert.equal(sessionHome('ACTIVE', 'policy_ack'), 'home', 'outside GATE the gate is ignored');

  // What each state allows: exactly its row, About always.
  const views = ['login', 'home', 'ack', 'password', 'pin', 'about'];
  const allowedFor = (state, gate) => views.filter((v) => sessionAllows(state, gate, v));
  assert.deepEqual(allowedFor('LOCKED', null), ['login', 'about']);
  assert.deepEqual(allowedFor('PICKER', null), ['login', 'about']);
  assert.deepEqual(allowedFor('IDLE', null), ['login', 'about']);
  assert.deepEqual(allowedFor('ACTIVE', null), ['home', 'pin', 'about'], 'the PIN-set view only while someone works');
  assert.deepEqual(allowedFor('GATE', 'policy_ack'), ['ack', 'about'], 'at a gate, its view only: no home, no lock screen');
  assert.deepEqual(allowedFor('GATE', 'password_change'), ['password', 'about']);
  assert.deepEqual(allowedFor('GATE', null), ['about']);
  assert.deepEqual(allowedFor('NO_SUCH_STATE', null), ['login', 'about']);
  for (const state of STATES) {
    for (const gate of [null, 'policy_ack', 'password_change']) {
      assert.equal(sessionAllows(state, gate, sessionHome(state, gate)) || sessionHome(state, gate) === 'login', true,
        `${state}/${gate}: its home is allowed (or the lock screen of a gate without a view)`);
    }
  }

  // Over the router, as app.js wires it: a registered tablet's view needs the session's leave too.
  const env = fakeEnv({ hash: '#/home' });
  const rendered = [];
  const s = { state: 'LOCKED', gate: null };
  const router = createRouter({ env, render: (n) => rendered.push(n), allowed: (n) => allowedIn('REGISTERED', n) && sessionAllows(s.state, s.gate, n),
    fallback: () => sessionHome(s.state, s.gate) });
  router.start();
  assert.deepEqual(rendered, ['login'], 'home while LOCKED: the lock screen');
  s.state = 'GATE';
  s.gate = 'policy_ack';
  router.show('home');
  router.show('login');
  router.show('password');
  router.show('about');
  assert.deepEqual(rendered, ['login', 'ack', 'ack', 'ack', 'about']);
  s.state = 'ACTIVE';
  s.gate = null;
  router.show('pin');
  router.show('ack');
  assert.deepEqual(rendered.slice(5), ['pin', 'home']);
  router.stop();
});

test('parseHash knows the S3 routes', () => {
  for (const r of ['home', 'ack', 'password', 'pin']) assert.equal(parseHash('#/' + r), r);
  for (const h of ['#/pin_set', '#/policy', '#/picker', '#/Home', '#/home/', '#/ack?x=1']) assert.equal(parseHash(h), null, h);
});

test('About is refused while WIPING and falls back to wipe', () => {
  for (const state of ['WIPING', 'ERASED']) {
    const t = setup(state, '#/about');
    t.router.start();
    assert.deepEqual(t.rendered, ['wipe']);
    assert.equal(t.router.current(), 'wipe');
    t.router.show('about');
    assert.deepEqual(t.rendered, ['wipe', 'wipe']);
    t.router.stop();
  }
  const ok = setup('REGISTERED', '#/about');
  ok.router.start();
  assert.deepEqual(ok.rendered, ['about'], 'About is allowed at the lock screen');
});

test('an unknown hash falls back to the state\'s home view', async () => {
  const t = setup('REGISTERED', '#/nowhere');
  t.router.start();
  assert.deepEqual(t.rendered, ['login']);
  t.env.set('hash', '#/device'); // allowed only while unregistered
  t.env.fire('hashchange');
  assert.deepEqual(t.rendered, ['login', 'login'], 'device is not allowed: the home view again');
  t.s.state = 'UNREGISTERED';
  t.env.set('hash', '#/');
  t.env.fire('hashchange');
  assert.deepEqual(t.rendered, ['login', 'login', 'device']);
  t.router.stop();
  await flush();
});

test('go() sets the hash and the hashchange that follows does not re-render', async () => {
  const t = setup('UNREGISTERED', '');
  t.router.start();
  assert.deepEqual(t.rendered, ['device']);
  t.router.go('about');
  assert.equal(t.env.hash(), '#/about');
  assert.deepEqual(t.rendered, ['device', 'about']);
  await flush(); // fake-env fires hashchange in a later task, as location.hash does
  assert.deepEqual(t.rendered, ['device', 'about'], 'the hashchange of go() is a no-op');
  t.router.go('about');
  await flush();
  assert.deepEqual(t.rendered, ['device', 'about', 'about'], 'go() to the current view draws it again, once');
  // A person's own hash change is followed.
  t.env.setHash('#/device');
  await flush();
  assert.deepEqual(t.rendered, ['device', 'about', 'about', 'device']);
  assert.equal(t.router.current(), 'device');
  t.router.stop();
});

test('stop() removes the listener', async () => {
  const t = setup('UNREGISTERED', '#/device');
  t.router.start();
  t.router.stop();
  t.env.setHash('#/about');
  await flush();
  assert.deepEqual(t.rendered, ['device']);
  t.router.stop(); // twice is harmless
});
