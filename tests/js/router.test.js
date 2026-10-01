// public/station/js/router.js (S2 spec §3.12): hash routes gated by the tablet state, the ALLOWED table, and the
// router's own hashchange handling, over fake-env.js.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeEnv, flush } from './support/fake-env.js';
import { ALLOWED, ROUTES, allowedIn, createRouter, homeOf, parseHash } from '../../public/station/js/router.js';

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
    STARTING: ['starting'], UNREGISTERED: ['device', 'about'], REGISTERED: ['login', 'about'], WIPING: ['wipe'], ERASED: ['wipe'],
    ELSEWHERE: ['elsewhere'], REPLACED: ['elsewhere'], NO_STORAGE: ['starting'],
  });
  assert.ok(Object.isFrozen(ALLOWED));
  for (const [state, views] of Object.entries(ALLOWED)) {
    assert.equal(homeOf(state), views[0]);
    for (const v of views) assert.ok(ROUTES.includes(v), `${v} is a route`);
  }
  assert.equal(homeOf('NO_SUCH_STATE'), 'starting');
  assert.equal(allowedIn('NO_SUCH_STATE', 'starting'), false);
  assert.deepEqual(ROUTES, ['starting', 'device', 'login', 'about', 'wipe', 'elsewhere']);
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
