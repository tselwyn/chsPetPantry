// public/station/js/env.js (S2 spec §3.1): browserEnv() over a plain-object window has exactly the Env members and
// reads the platform the way the table says; fakeEnv() (tests/js/support/fake-env.js) has every one of them, and its
// own controls (virtual timers, sleep, wall-clock jumps, events) behave as the other suites rely on.
import test from 'node:test';
import assert from 'node:assert/strict';
import { browserEnv } from '../../public/station/js/env.js';
import { DEFAULT_NOW, fakeEnv, flush } from './support/fake-env.js';

/** The members of the Env table (§3.1), in its order. */
const MEMBERS = ['now', 'mono', 'displayMode', 'persisted', 'persist', 'estimateKb', 'online', 'visible', 'on', 'hash', 'setHash',
  'barcodeDetector', 'camera', 'locks', 'crypto', 'random', 'uuid', 'indexedDB', 'fetch', 'caches', 'serviceWorker', 'shellBuild',
  'scope', 'reload', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'log', 'formatTime', 'document'];

/** A minimal event target that records add/remove calls with their options. */
function target(name, calls) {
  const listeners = [];
  return {
    listeners,
    addEventListener(type, fn, options) { calls.push(['add', name, type, options]); listeners.push({ type, fn, options }); },
    removeEventListener(type, fn, options) {
      calls.push(['remove', name, type, options]);
      const i = listeners.findIndex((l) => l.type === type && l.fn === fn);
      if (i >= 0) listeners.splice(i, 1);
    },
    emit(type) { for (const l of listeners.filter((x) => x.type === type)) l.fn({ type }); },
  };
}

/** A plain object window: navigator, document, location, matchMedia, performance, timers, crypto, indexedDB, caches, fetch, console. */
function fakeWindow({ standalone, media = [], storage, estimate, mediaDevices, barcode } = {}) {
  const calls = [];
  const win = target('window', calls);
  const doc = target('document', calls);
  doc.visibilityState = 'visible';
  doc.documentElement = { dataset: { build: '0.1.0-dev+abc1234567' } };
  Object.assign(win, {
    calls,
    navigator: {
      standalone,
      onLine: true,
      storage: storage ?? {
        persisted: async () => true,
        persist: async () => true,
        estimate: estimate ?? (async () => ({ usage: 812 * 1024 + 300, quota: 1e9 })),
      },
      locks: { name: 'locks' },
      serviceWorker: { name: 'serviceWorker' },
      mediaDevices: mediaDevices ?? { getUserMedia: async (constraints) => ({ constraints }) },
    },
    document: doc,
    location: { href: 'http://localhost:8088/station/index.php#/about', hash: '#/about', reloads: 0, reload() { this.reloads += 1; } },
    matchMedia: (q) => ({ matches: media.includes(q) }),
    performance: { now: () => 4321.5 },
    crypto: globalThis.crypto,
    indexedDB: { name: 'indexedDB' },
    caches: { name: 'caches' },
    logs: [],
    console: { info: (...a) => win.logs.push(a) },
  });
  win.fetch = function fetch(url, init) { calls.push(['fetch', this === win, url, init]); return Promise.resolve('response'); };
  for (const name of ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval']) {
    win[name] = function timer(...a) { calls.push([name, this === win, ...a]); return 7; };
  }
  if (barcode !== undefined) win.BarcodeDetector = barcode;
  return win;
}

test('browserEnv over a fake window has exactly the §3.1 members', async () => {
  const win = fakeWindow();
  const env = browserEnv(win);
  assert.deepEqual(Object.keys(env).sort(), [...MEMBERS].sort());
  assert.equal(MEMBERS.length, 31);

  assert.ok(Math.abs(env.now() - Date.now()) < 1000);
  assert.equal(env.mono(), 4321.5);
  assert.equal(env.online(), true);
  win.navigator.onLine = false;
  assert.equal(env.online(), false);
  assert.equal(env.visible(), true);
  win.document.visibilityState = 'hidden';
  assert.equal(env.visible(), false);
  assert.equal(await env.estimateKb(), 812, 'usage / 1024, rounded');
  assert.equal(env.hash(), '#/about');
  env.setHash('#/device');
  assert.equal(win.location.hash, '#/device');
  assert.equal(env.shellBuild(), '0.1.0-dev+abc1234567');
  delete win.document.documentElement.dataset.build;
  assert.equal(env.shellBuild(), '');
  assert.equal(env.scope(), 'http://localhost:8088/station/');
  env.reload();
  assert.equal(win.location.reloads, 1);
  assert.equal(env.locks, win.navigator.locks);
  assert.equal(env.serviceWorker, win.navigator.serviceWorker);
  assert.equal(env.indexedDB, win.indexedDB);
  assert.equal(env.caches, win.caches);
  assert.equal(env.crypto, globalThis.crypto);
  assert.equal(env.document, win.document);
  assert.equal(env.random(16).length, 16);
  assert.match(env.uuid(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
  env.log('heartbeat ok');
  assert.deepEqual(win.logs, [['[pfpms] heartbeat ok']]);
  assert.equal(typeof env.formatTime(DEFAULT_NOW), 'string');
  assert.ok(env.formatTime(DEFAULT_NOW).length > 0);

  // fetch and the timers are the window's, called on the window.
  assert.equal(await env.fetch('../api/ping.php', { method: 'GET' }), 'response');
  assert.deepEqual(win.calls.find((c) => c[0] === 'fetch'), ['fetch', true, '../api/ping.php', { method: 'GET' }]);
  const fn = () => {};
  assert.equal(env.setTimeout(fn, 5), 7);
  env.clearTimeout(7);
  env.setInterval(fn, 15000);
  env.clearInterval(7);
  assert.deepEqual(win.calls.filter((c) => /Timeout|Interval/.test(c[0])),
    [['setTimeout', true, fn, 5], ['clearTimeout', true, 7], ['setInterval', true, fn, 15000], ['clearInterval', true, 7]]);

  // The camera asks for the back camera, video only.
  assert.deepEqual(await env.camera(), { constraints: { video: { facingMode: 'environment' }, audio: false } });
  await assert.rejects(browserEnv(fakeWindow({ mediaDevices: { getUserMedia: async () => { throw new DOMException('Permission denied', 'NotAllowedError'); } } })).camera(),
    { name: 'NotAllowedError' });

  // A QR detector only when the browser has one that reads qr_code.
  assert.equal(await env.barcodeDetector(), null, 'no BarcodeDetector');
  class Detector {
    static async getSupportedFormats() { return ['ean_13', 'qr_code']; }
    constructor(options) { this.options = options; }
  }
  const detector = await browserEnv(fakeWindow({ barcode: Detector })).barcodeDetector();
  assert.ok(detector instanceof Detector);
  assert.deepEqual(detector.options, { formats: ['qr_code'] });
  class NoQr { static async getSupportedFormats() { return ['ean_13']; } }
  assert.equal(await browserEnv(fakeWindow({ barcode: NoQr })).barcodeDetector(), null);
  class Broken { static async getSupportedFormats() { throw new Error('no'); } }
  assert.equal(await browserEnv(fakeWindow({ barcode: Broken })).barcodeDetector(), null);

  // A window without the optional platform parts gives null, never a throw.
  const bare = fakeWindow();
  delete bare.navigator.locks;
  delete bare.navigator.serviceWorker;
  delete bare.caches;
  Object.defineProperty(bare, 'indexedDB', { get() { throw new DOMException('denied', 'SecurityError'); } });
  const e2 = browserEnv(bare);
  assert.equal(e2.locks, null);
  assert.equal(e2.serviceWorker, null);
  assert.equal(e2.caches, null);
  assert.equal(e2.indexedDB, null);
});

test('fakeEnv has every browserEnv member', () => {
  const real = browserEnv(fakeWindow());
  const fake = fakeEnv();
  for (const name of MEMBERS) {
    assert.ok(name in fake, name);
    assert.equal(typeof fake[name], typeof real[name], name);
  }
  // Its defaults (§6.1).
  assert.equal(fake.now(), DEFAULT_NOW);
  assert.equal(new Date(fake.now()).toISOString(), '2026-10-01T12:00:00.000Z');
  assert.equal(fake.mono(), 1000);
  assert.equal(fake.displayMode(), 'standalone');
  assert.equal(fake.online(), true);
  assert.equal(fake.visible(), true);
  assert.equal(fake.shellBuild(), 'test+0000000000');
  assert.equal(fake.scope(), 'http://localhost:8088/station/');
  assert.equal(fake.hash(), '');
  assert.equal(fake.formatTime(DEFAULT_NOW), '2026-10-01T12:00:00.000Z');
  assert.equal(fake.crypto, globalThis.crypto);
  assert.equal(typeof fake.indexedDB.open, 'function', 'a memory IndexedDB');
  assert.ok(Array.isArray(fake.fetch.requests), 'fakeFetch()');
  for (const name of ['locks', 'caches', 'serviceWorker', 'document']) assert.equal(fake[name], null, name);
  assert.deepEqual([fake.logs, fake.reloads, fake.streams], [[], 0, []]);
  // Options replace the platform objects.
  const locks = {};
  assert.equal(fakeEnv({ locks }).locks, locks);
});

test('displayMode prefers navigator.standalone, then the media queries', () => {
  const q = (m) => `(display-mode: ${m})`;
  assert.equal(browserEnv(fakeWindow({ standalone: true, media: [q('minimal-ui')] })).displayMode(), 'standalone');
  assert.equal(browserEnv(fakeWindow({ media: [q('fullscreen'), q('minimal-ui')] })).displayMode(), 'fullscreen');
  assert.equal(browserEnv(fakeWindow({ media: [q('standalone'), q('fullscreen')] })).displayMode(), 'standalone');
  assert.equal(browserEnv(fakeWindow({ media: [q('minimal-ui')] })).displayMode(), 'minimal-ui');
  assert.equal(browserEnv(fakeWindow({ standalone: false })).displayMode(), 'browser');
  const noMedia = fakeWindow();
  delete noMedia.matchMedia;
  assert.equal(browserEnv(noMedia).displayMode(), 'browser');
  const throwing = fakeWindow();
  throwing.matchMedia = () => { throw new Error('no'); };
  assert.equal(browserEnv(throwing).displayMode(), 'browser');
  // The fake follows set().
  const env = fakeEnv();
  env.set('displayMode', 'browser');
  assert.equal(env.displayMode(), 'browser');
});

test('persisted and persist never throw', async () => {
  const cases = [
    ['no storage at all', {}],
    ['methods missing', { storage: {} }],
    ['methods throw', { storage: { persisted: () => { throw new Error('x'); }, persist: () => { throw new Error('x'); }, estimate: () => { throw new Error('x'); } } }],
    ['methods reject', { storage: { persisted: async () => { throw new Error('x'); }, persist: async () => { throw new Error('x'); }, estimate: async () => { throw new Error('x'); } } }],
    ['not a boolean', { storage: { persisted: async () => 'yes', persist: async () => 1, estimate: async () => ({}) } }],
  ];
  for (const [label, opts] of cases) {
    const win = fakeWindow();
    if (label === 'no storage at all') delete win.navigator.storage;
    else win.navigator.storage = opts.storage;
    const env = browserEnv(win);
    assert.equal(await env.persisted(), false, label);
    assert.equal(await env.persist(), false, label);
    assert.equal(await env.estimateKb(), null, label);
  }
  const env = browserEnv(fakeWindow());
  assert.equal(await env.persisted(), true);
  assert.equal(await env.persist(), true);

  // The fake: persist() resolves persistResult and, when true, sets persisted.
  const fake = fakeEnv({ persisted: false, persistResult: false });
  assert.equal(await fake.persisted(), false);
  assert.equal(await fake.persist(), false);
  assert.equal(await fake.persisted(), false);
  fake.set('persistResult', true);
  assert.equal(await fake.persist(), true);
  assert.equal(await fake.persisted(), true);
  assert.equal(fake.persistCalls, 2);
  fake.set('estimateKb', null);
  assert.equal(await fake.estimateKb(), null);
});

test('on() unsubscribes', async () => {
  const win = fakeWindow();
  const env = browserEnv(win);
  const seen = [];
  const offs = ['online', 'offline', 'hashchange', 'visible', 'hidden', 'input'].map((e) => env.on(e, () => seen.push(e)));
  assert.deepEqual(win.calls.filter((c) => c[0] === 'add').map((c) => c.slice(1)), [
    ['window', 'online', undefined], ['window', 'offline', undefined], ['window', 'hashchange', undefined],
    ['document', 'visibilitychange', undefined], ['document', 'visibilitychange', undefined],
    ['document', 'pointerdown', { capture: true, passive: true }], ['document', 'keydown', { capture: true, passive: true }],
  ]);
  win.emit('online');
  win.emit('hashchange');
  win.document.visibilityState = 'hidden';
  win.document.emit('visibilitychange');
  win.document.visibilityState = 'visible';
  win.document.emit('visibilitychange');
  win.document.emit('pointerdown');
  win.document.emit('keydown');
  assert.deepEqual(seen, ['online', 'hashchange', 'hidden', 'visible', 'input', 'input']);
  for (const off of offs) off();
  assert.equal(win.listeners.length, 0);
  assert.equal(win.document.listeners.length, 0, 'removed with the same capture flag');
  win.emit('online');
  win.document.emit('keydown');
  assert.equal(seen.length, 6);
  assert.throws(() => env.on('resize', () => {}), TypeError);

  // The fake: on()/fire(), and unsubscribing.
  const fake = fakeEnv();
  const got = [];
  const off = fake.on('online', () => got.push('online'));
  fake.on('offline', () => got.push('offline'));
  fake.fire('offline');
  assert.equal(fake.online(), false, 'fire(offline) is what the browser reports');
  fake.fire('online');
  off();
  fake.fire('online');
  assert.deepEqual(got, ['offline', 'online']);
  fake.on('hidden', () => got.push('hidden'));
  fake.fire('hidden');
  assert.equal(fake.visible(), false);
  assert.throws(() => fake.on('resize', () => {}), TypeError);
  assert.throws(() => fake.fire('resize'), TypeError);
  // setHash() fires hashchange in a later task when the hash changed; set('hash') is silent.
  let changes = 0;
  fake.on('hashchange', () => { changes += 1; });
  fake.setHash('#/about');
  assert.equal(fake.hash(), '#/about');
  assert.equal(changes, 0);
  await flush();
  assert.equal(changes, 1);
  fake.setHash('#/about');
  fake.set('hash', '#/device');
  await flush();
  assert.equal(changes, 1);
  assert.equal(fake.hash(), '#/device');
});

test('fakeEnv: virtual timers, advance, sleep and jumpWall', async () => {
  const env = fakeEnv();
  const ran = [];
  env.setTimeout(() => ran.push(['b', env.now() - DEFAULT_NOW, env.mono()]), 200);
  env.setTimeout(() => ran.push(['a', env.now() - DEFAULT_NOW, env.mono()]), 100);
  const cancelled = env.setTimeout(() => ran.push(['never']), 150);
  env.clearTimeout(cancelled);
  env.setTimeout((x, y) => ran.push(['args', x, y]), 100, 1, 2);
  const tick = env.setInterval(() => ran.push(['tick', env.now() - DEFAULT_NOW]), 60);
  assert.equal(env.pendingTimers(), 4);
  await env.advance(199);
  assert.deepEqual(ran, [['tick', 60], ['a', 100, 1100], ['args', 1, 2], ['tick', 120], ['tick', 180]]);
  await env.advance(1);
  assert.deepEqual(ran.at(-1), ['b', 200, 1200]);
  env.clearInterval(tick);
  assert.equal(env.pendingTimers(), 0);
  assert.equal(env.now(), DEFAULT_NOW + 200);
  assert.equal(env.mono(), 1200);

  // A timer set by a timer runs in the same advance when it is due.
  const chain = [];
  env.setTimeout(() => { chain.push(1); env.setTimeout(() => chain.push(2), 0); }, 10);
  await env.advance(10);
  assert.deepEqual(chain, [1, 2]);

  // sleep(): the wall clock moves, the monotonic clock does not; due timers fire on wake, an interval once.
  const woke = [];
  env.setTimeout(() => woke.push(['timeout', env.now() - DEFAULT_NOW, env.mono()]), 1000);
  const iv = env.setInterval(() => woke.push(['interval']), 100);
  const mono = env.mono();
  await env.sleep(7200000);
  assert.equal(env.mono(), mono);
  assert.equal(env.now(), DEFAULT_NOW + 210 + 7200000);
  assert.deepEqual(woke, [['interval'], ['timeout', 210 + 7200000, mono]]);
  await env.advance(100);
  assert.equal(woke.filter((w) => w[0] === 'interval').length, 2, 'then it keeps its pace');
  env.clearInterval(iv);

  // jumpWall(): the wall clock alone; timers are not affected.
  const before = env.mono();
  env.jumpWall(-3600000);
  assert.equal(env.now(), DEFAULT_NOW + 210 + 7200000 + 100 - 3600000);
  assert.equal(env.mono(), before);

  // A timer that throws: the others still run, and advance() rejects with the first error.
  const order = [];
  env.setTimeout(() => { throw new Error('first'); }, 5);
  env.setTimeout(() => order.push('after'), 6);
  await assert.rejects(env.advance(10), /first/);
  assert.deepEqual(order, ['after']);
  assert.throws(() => env.setTimeout('alert(1)', 5), TypeError);
});

test('fakeEnv: random, uuid, log, reload, camera and barcodeDetector', async () => {
  const a = fakeEnv();
  const b = fakeEnv();
  const c = fakeEnv({ seed: 7 });
  assert.deepEqual(a.random(16), b.random(16), 'seeded: the same bytes every run');
  assert.notDeepEqual(a.random(16), a.random(16));
  assert.notDeepEqual(fakeEnv().random(16), c.random(16));
  assert.equal(a.random(32).length, 32);
  assert.equal(a.uuid(), '00000000-0000-4000-8000-000000000001');
  assert.equal(a.uuid(), '00000000-0000-4000-8000-000000000002');
  a.log('one');
  a.reload();
  a.reload();
  assert.deepEqual(a.logs, ['one']);
  assert.equal(a.reloads, 2);

  // camera 'ok': a stream of two tracks, each stoppable; every stream is listed.
  const stream = await a.camera();
  assert.equal(stream.getTracks().length, 2);
  assert.equal(stream.active, true);
  stream.getTracks().forEach((t) => t.stop());
  assert.ok(stream.getTracks().every((t) => t.stopped));
  assert.equal(stream.active, false);
  await a.camera();
  assert.equal(a.streams.length, 2);
  await assert.rejects(fakeEnv({ camera: 'refused' }).camera(), (e) => e instanceof DOMException && e.name === 'NotAllowedError' && e.message === 'Permission denied');

  // barcodeDetector: null by default; with results, detect() returns each list in turn, then [].
  assert.equal(await a.barcodeDetector(), null);
  const scanning = fakeEnv({ barcodeDetector: { results: [[], ['PFPMS-DEVICE:1:ABC']] } });
  const detector = await scanning.barcodeDetector();
  assert.equal(await scanning.barcodeDetector(), detector, 'the same detector each time');
  assert.deepEqual(await detector.detect('video'), []);
  assert.deepEqual(await detector.detect('video'), [{ rawValue: 'PFPMS-DEVICE:1:ABC' }]);
  assert.deepEqual(await detector.detect('video'), []);
  assert.equal(detector.detectCalls, 3);
  assert.throws(() => scanning.set('nope', 1), TypeError);
});
