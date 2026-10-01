// public/station/boot.js (50-design §7.7, BH-06, BH-07): the start plan, the first-start wait, the watchdog, the
// Trusted Types policy, the failure screen and Repair, with the platform injected (virtual timers from fake-env.js,
// the service worker fakes of fake-sw.js, the DOM of fake-dom.js). Importing boot.js in Node starts nothing.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeEnv, flush } from './support/fake-env.js';
import { REGISTER_REJECTION, fakeCaches, fakeContainer, fakeRegistration, fakeWorker } from './support/fake-sw.js';
import { fakeDocument, text } from './support/fake-dom.js';
import {
  BOOT_COPY, PING_TIMEOUT_MS, STORAGE_KEY, WATCHDOG_MS, WORKER_WAIT_MS,
  boot, bootPlan, failureOrder, ping, repair, waitForWorker,
} from '../../public/station/boot.js';

const SCOPE = 'http://localhost:8088/station/';

/** sessionStorage: string values only, as in a browser. */
function memoryStorage(state = null) {
  const data = new Map(state === null ? [] : [[STORAGE_KEY, JSON.stringify(state)]]);
  return {
    data,
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => { data.set(k, String(v)); },
    removeItem: (k) => { data.delete(k); },
  };
}
const stateOf = (storage) => JSON.parse(storage.getItem(STORAGE_KEY));

class FakeTrustedScriptURL {
  constructor(value) { this.value = value; }
  toString() { return this.value; }
}

/** window.trustedTypes under the Station CSP: only the policy name pfpms-sw, and only once. */
function fakeTrustedTypes() {
  const policies = [];
  return {
    policies,
    createPolicy(name, rules) {
      if (name !== 'pfpms-sw' || policies.some((p) => p.name === name)) throw new TypeError(`Policy "${name}" disallowed.`);
      const policy = { name, createScriptURL: (u) => new FakeTrustedScriptURL(rules.createScriptURL(u)) };
      policies.push(policy);
      return policy;
    },
  };
}

/**
 * The answer of api/ping.php: 'ok' (200 JSON {ok: true}), 'false' (200 JSON {ok: false}), 'html' (a captive portal),
 * 'error' (503 JSON), 'down' (fetch rejects) or 'hang' (until aborted). Requests are kept in `.requests`.
 */
function pingFetch(mode = 'down', log = []) {
  const requests = [];
  const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json; charset=utf-8' } });
  const fetch = async (url, init = {}) => {
    requests.push({ url, init });
    log.push('ping');
    if (mode === 'ok') return json(200, { ok: true, server_time: '2026-10-01 12:00:00.000' });
    if (mode === 'false') return json(200, { ok: false });
    if (mode === 'error') return json(503, { ok: false, error: { code: 'maintenance' } });
    if (mode === 'html') return new Response('<!doctype html><title>Sign in to the Wi-Fi</title>', { status: 200, headers: { 'Content-Type': 'text/html' } });
    if (mode === 'hang') {
      return new Promise((resolve, reject) => {
        init.signal?.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')));
      });
    }
    throw new TypeError('Failed to fetch');
  };
  fetch.requests = requests;
  return fetch;
}

/** app.js's start(): records each call and, by default, calls started() as app.js does after mounting Starting…. */
function fakeApp(behaviour = (info) => info.started()) {
  const app = {
    starts: [],
    results: [],
    async start(info) { app.starts.push(info); app.results.push(await behaviour(info)); },
  };
  return app;
}

/** Timers over fakeEnv that also know which timers are still pending. */
function trackedTimers(env) {
  const live = new Set();
  return {
    live,
    setTimeout: (fn, ms) => { const id = env.setTimeout(() => { live.delete(id); fn(); }, ms); live.add(id); return id; },
    clearTimeout: (id) => { live.delete(id); env.clearTimeout(id); },
  };
}

/** boot()'s platform (see browserBoot()), all fakes. */
function setup({ storage = memoryStorage(), container = fakeContainer(), trustedTypes = fakeTrustedTypes(), pingMode = 'down',
  fetch = pingFetch(pingMode), app = fakeApp(), importApp = null, caches = fakeCaches(), global = {} } = {}) {
  const env = fakeEnv();
  const timers = trackedTimers(env);
  const document = fakeDocument();
  const logs = [];
  const t = { env, timers, storage, container, trustedTypes, document, logs, app, caches, global, reloads: 0, fetch };
  t.b = {
    global, storage, timers, trustedTypes, serviceWorker: container, caches, fetch: t.fetch,
    reload: () => { t.reloads += 1; }, scope: SCOPE, document, importApp: importApp ?? (async () => app), log: (m) => logs.push(m),
  };
  t.root = () => document.getElementById('app');
  t.screen = () => t.root().querySelector('section.boot-failed');
  t.buttons = () => t.root().querySelectorAll('section.boot-failed .actions button');
  t.labels = () => t.buttons().map((b) => text(b));
  return t;
}

/** A page with an active worker registration but no controller (a hard reload). */
const hardReloadContainer = () => fakeContainer({ registration: fakeRegistration({ scope: SCOPE, active: fakeWorker('activated') }) });

test('bootPlan imports when there is no worker API or the page is controlled', async () => {
  for (const activeRegistration of [false, true]) {
    for (const reloadedOnce of [false, true]) {
      assert.equal(bootPlan({ hasWorkerApi: false, controlled: false, activeRegistration, reloadedOnce }), 'import');
      assert.equal(bootPlan({ hasWorkerApi: true, controlled: true, activeRegistration, reloadedOnce }), 'import');
    }
  }
  // No worker API at all: the app starts at once, without a registration.
  const bare = setup({ container: null });
  await boot(bare.b);
  assert.equal(bare.app.starts.length, 1);
  assert.equal(bare.app.starts[0].registration, null);
  assert.equal(bare.app.starts[0].controlledAtLoad, false);
  assert.equal(bare.reloads, 0);

  // A controlled page registers (so the browser checks for updates) and starts the app at once.
  const container = fakeContainer({ controller: fakeWorker('activated'), registration: fakeRegistration({ scope: SCOPE, active: fakeWorker('activated') }) });
  const controlled = setup({ container });
  await boot(controlled.b);
  assert.equal(container.registered.length, 1);
  assert.equal(controlled.app.starts.length, 1);
  assert.equal(controlled.app.starts[0].registration, container.registration);
  assert.equal(controlled.app.starts[0].controlledAtLoad, true);
  assert.equal(controlled.reloads, 0);
});

test('bootPlan reloads once after a hard reload, then imports', async () => {
  assert.equal(bootPlan({ hasWorkerApi: true, controlled: false, activeRegistration: true, reloadedOnce: false }), 'reload');
  assert.equal(bootPlan({ hasWorkerApi: true, controlled: false, activeRegistration: true, reloadedOnce: true }), 'import');

  const t = setup({ container: hardReloadContainer() });
  await boot(t.b);
  assert.equal(t.reloads, 1);
  assert.equal(t.app.starts.length, 0, 'nothing of the app ran before the reload');
  assert.equal(t.container.registered.length, 0);
});

test('bootPlan waits on a first start', async () => {
  for (const reloadedOnce of [false, true]) {
    assert.equal(bootPlan({ hasWorkerApi: true, controlled: false, activeRegistration: false, reloadedOnce }), 'wait');
  }
  // The worker installs but never takes control (a slow precache): the app starts after the wait, from the network.
  const t = setup();
  const running = boot(t.b);
  await flush();
  assert.equal(t.container.registered.length, 1);
  await t.env.advance(WORKER_WAIT_MS - 1);
  assert.equal(t.app.starts.length, 0, 'still waiting for the worker');
  await t.env.advance(1);
  await running;
  assert.equal(t.app.starts.length, 1);
  assert.equal(t.app.starts[0].registration, t.container.registration, 'the registration reaches the app');
  assert.equal(t.app.starts[0].controlledAtLoad, false);
  assert.equal(t.reloads, 0);
});

test('waitForWorker resolves reload on controllerchange', async () => {
  const env = fakeEnv();
  const timers = trackedTimers(env);
  const container = fakeContainer();
  const waiting = waitForWorker({ container, register: () => container.register('sw.php', { scope: './' }), timers });
  await flush();
  container.fireControllerChange();
  assert.equal(await waiting, 'reload');
  assert.equal(timers.live.size, 0, 'the 8-s timer is cleared');
  assert.equal(container.listenerCount('controllerchange'), 0);
  assert.equal(container.listenerCount('message'), 0);
});

test('waitForWorker imports at once when registration is rejected (the browser pane), logging service worker registration failed: <message>', async () => {
  const env = fakeEnv();
  const timers = trackedTimers(env);
  const container = fakeContainer({ register: 'reject' });
  const logs = [];
  const how = await waitForWorker({ container, register: () => container.register('sw.php', { scope: './' }), timers, log: (m) => logs.push(m) });
  assert.equal(how, 'import');
  assert.deepEqual(logs, ['service worker registration failed: ' + REGISTER_REJECTION]);
  assert.equal(timers.live.size, 0, 'no 8-s wait');
  assert.equal(container.listenerCount('controllerchange'), 0);

  // Through boot(): the first-start path logs the same line and the app starts at once, uncontrolled.
  const t = setup({ container: fakeContainer({ register: 'reject' }) });
  await boot(t.b);
  assert.deepEqual(t.logs, ['service worker registration failed: ' + REGISTER_REJECTION]);
  assert.equal(t.app.starts.length, 1);
  assert.equal(t.app.starts[0].registration, null);
  // A controlled page whose update registration fails logs it the same way.
  const c = setup({ container: fakeContainer({ controller: fakeWorker('activated'), register: 'reject' }) });
  await boot(c.b);
  assert.deepEqual(c.logs, ['service worker registration failed: ' + REGISTER_REJECTION]);
  assert.equal(c.app.starts.length, 1);
});

test('waitForWorker imports at once on SW_KILLED', async () => {
  const env = fakeEnv();
  const timers = trackedTimers(env);
  const container = fakeContainer({ register: 'hang' });
  let how = null;
  const waiting = waitForWorker({ container, register: () => container.register('sw.php', { scope: './' }), timers }).then((h) => { how = h; });
  await flush();
  container.postFromWorker({ type: 'SOMETHING_ELSE' });
  await flush();
  assert.equal(how, null, 'other messages are ignored');
  container.postFromWorker({ type: 'SW_KILLED' });
  await waiting;
  assert.equal(how, 'import');
  assert.equal(timers.live.size, 0);
  assert.equal(container.listenerCount('message'), 0);
});

test('waitForWorker imports after 8 s', async () => {
  assert.equal(WORKER_WAIT_MS, 8000);
  const env = fakeEnv();
  const timers = trackedTimers(env);
  const container = fakeContainer({ register: 'hang' });
  let how = null;
  const waiting = waitForWorker({ container, register: () => container.register('sw.php', { scope: './' }), timers }).then((h) => { how = h; });
  await env.advance(7999);
  assert.equal(how, null);
  await env.advance(1);
  await waiting;
  assert.equal(how, 'import');
  // A controllerchange after the wait changes nothing.
  container.fireControllerChange();
  assert.equal(how, 'import');
});

test('a hard reload never loops', async () => {
  const storage = memoryStorage();
  const container = hardReloadContainer();
  const first = setup({ storage, container });
  await boot(first.b);
  assert.equal(first.reloads, 1);
  assert.deepEqual(stateOf(storage), { attempts: 0, reloaded: true });

  // The reload came back uncontrolled again (another hard reload, or a worker that cannot take control): no second reload.
  const second = setup({ storage, container });
  await boot(second.b);
  assert.equal(second.reloads, 0);
  assert.equal(second.app.starts.length, 1);
  assert.deepEqual(stateOf(storage), { attempts: 0, reloaded: false }, 'started() reset the guard');

  // A start that never reaches started() keeps the guard, so even then there is no loop.
  const stuck = setup({ storage: memoryStorage({ attempts: 1, reloaded: true }), container, app: fakeApp(() => {}) });
  await boot(stuck.b);
  assert.equal(stuck.reloads, 0);
});

test('a planned reload does not count as a failed start', async () => {
  // The hard-reload path.
  const hard = setup({ storage: memoryStorage({ attempts: 2, reloaded: false }), container: hardReloadContainer() });
  await boot(hard.b);
  assert.equal(hard.reloads, 1);
  assert.deepEqual(stateOf(hard.storage), { attempts: 2, reloaded: true });
  await hard.env.advance(WATCHDOG_MS);
  assert.equal(hard.screen(), null, 'the watchdog does not fire during a planned reload');

  // The first-start path: the new worker took control.
  const first = setup({ storage: memoryStorage({ attempts: 2, reloaded: false }) });
  const running = boot(first.b);
  await flush();
  first.container.fireControllerChange();
  await running;
  assert.equal(first.reloads, 1);
  assert.equal(first.app.starts.length, 0, 'the app runs only after the reload, from the verified cache');
  assert.deepEqual(stateOf(first.storage), { attempts: 2, reloaded: true });
  await first.env.advance(WATCHDOG_MS);
  assert.equal(first.screen(), null);
});

test('sw.php is registered through the pfpms-sw policy with scope ./ and updateViaCache none', async () => {
  const container = fakeContainer({ controller: fakeWorker('activated') });
  const t = setup({ container });
  await boot(t.b);
  assert.deepEqual(t.trustedTypes.policies.map((p) => p.name), ['pfpms-sw'], 'one policy, created once');
  assert.equal(container.registered.length, 1);
  const { url, opts } = container.registered[0];
  assert.ok(url instanceof FakeTrustedScriptURL, 'a TrustedScriptURL, not a string');
  assert.equal(String(url), 'sw.php');
  assert.deepEqual(opts, { scope: './', updateViaCache: 'none' });

  // The first-start path registers the same way.
  const first = setup({ container: fakeContainer({ register: 'hang' }) });
  boot(first.b);
  await flush();
  assert.ok(first.container.registered[0].url instanceof FakeTrustedScriptURL);
  assert.deepEqual(first.container.registered[0].opts, { scope: './', updateViaCache: 'none' });
});

test('without Trusted Types the plain string is used', async () => {
  const container = fakeContainer({ controller: fakeWorker('activated') });
  const t = setup({ container, trustedTypes: null });
  await boot(t.b);
  assert.equal(container.registered[0].url, 'sw.php');
  assert.deepEqual(container.registered[0].opts, { scope: './', updateViaCache: 'none' });

  // A trustedTypes whose createPolicy refuses (a policy name the CSP does not allow) falls back the same way.
  const refusing = { createPolicy() { throw new TypeError('Policy disallowed.'); } };
  const r = setup({ container: fakeContainer({ controller: fakeWorker('activated') }), trustedTypes: refusing });
  await boot(r.b);
  assert.equal(r.container.registered[0].url, 'sw.php');
  assert.equal(r.app.starts.length, 1);
});

test('the policy refuses any URL but sw.php', async () => {
  const t = setup({ container: fakeContainer({ controller: fakeWorker('activated') }) });
  await boot(t.b);
  const [policy] = t.trustedTypes.policies;
  assert.equal(String(policy.createScriptURL('sw.php')), 'sw.php');
  for (const url of ['sw.php?kill=0', './sw.php', 'https://evil.example/sw.php', 'js/app.js', 'SW.PHP', '']) {
    assert.throws(() => policy.createScriptURL(url), TypeError, JSON.stringify(url));
  }
});

test('__pfpmsStarted clears the watchdog and the attempt count', async () => {
  const t = setup({ storage: memoryStorage({ attempts: 2, reloaded: true }), container: null });
  await boot(t.b);
  assert.equal(typeof t.global.__pfpmsStarted, 'function');
  assert.deepEqual(t.app.results, [true]);
  assert.deepEqual(stateOf(t.storage), { attempts: 0, reloaded: false });
  assert.equal(t.timers.live.size, 0, 'the watchdog is cleared');
  await t.env.advance(WATCHDOG_MS * 2);
  assert.equal(t.screen(), null);
});

test('__pfpmsStarted returns false once the watchdog fired', async () => {
  let resolveImport;
  const app = fakeApp();
  const t = setup({ container: null, app, importApp: () => new Promise((resolve) => { resolveImport = resolve; }) });
  const running = boot(t.b);
  await flush();
  await t.env.advance(WATCHDOG_MS);
  assert.ok(t.screen(), 'the failure screen shows');
  resolveImport(app);
  await running;
  assert.deepEqual(app.results, [false], 'the app learns it must stop');
  assert.ok(t.screen(), 'the failure screen stays');
  assert.deepEqual(stateOf(t.storage), { attempts: 1, reloaded: false }, 'the failed start still counts');
});

test('the watchdog shows the failure screen with Try again first', async () => {
  assert.equal(WATCHDOG_MS, 15000);
  const t = setup({ container: null, pingMode: 'ok', importApp: () => new Promise(() => {}) });
  boot(t.b);
  await flush();
  await t.env.advance(WATCHDOG_MS - 1);
  assert.equal(t.screen(), null);
  await t.env.advance(1);
  assert.ok(t.screen());
  assert.deepEqual(t.labels(), [BOOT_COPY.try_again, BOOT_COPY.repair_app]);
  await flush();
  assert.equal(t.fetch.requests.length, 1, 'it pinged');
  assert.deepEqual(t.labels(), [BOOT_COPY.try_again, BOOT_COPY.repair_app], 'one failed start: Try again stays first');
  t.buttons()[0].dispatch('click');
  assert.equal(t.reloads, 1, 'Try again reloads');
});

test('an import failure shows the failure screen', async () => {
  const t = setup({ container: null, importApp: async () => { throw new TypeError('Failed to fetch dynamically imported module'); } });
  await boot(t.b);
  assert.ok(t.screen(), 'at once, without waiting for the watchdog');
  assert.deepEqual(t.logs, ['the app did not load: Failed to fetch dynamically imported module']);
  await flush();
  assert.deepEqual(t.labels(), [BOOT_COPY.try_again, BOOT_COPY.repair_app]);

  // An app whose start() throws shows it too.
  const s = setup({ container: null, app: fakeApp(() => { throw new Error('boom'); }) });
  await boot(s.b);
  assert.ok(s.screen());
  assert.deepEqual(s.logs, ['the app stopped: boom']);
});

test('failureOrder puts Repair first only after 3 failed starts while ping answers', async () => {
  assert.deepEqual(failureOrder({ attempts: 0, pingOk: true }), ['retry', 'repair']);
  assert.deepEqual(failureOrder({ attempts: 2, pingOk: true }), ['retry', 'repair']);
  assert.deepEqual(failureOrder({ attempts: 3, pingOk: true }), ['repair', 'retry']);
  assert.deepEqual(failureOrder({ attempts: 7, pingOk: true }), ['repair', 'retry']);
  assert.deepEqual(failureOrder({ attempts: 3, pingOk: false }), ['retry', 'repair']);
  assert.deepEqual(failureOrder({ attempts: 9, pingOk: false }), ['retry', 'repair']);

  // The third failed start in this tab, with the server answering: Repair becomes the primary button.
  let answer;
  const slowPing = Object.assign(async (url, init) => { await new Promise((resolve) => { answer = resolve; }); return pingFetch('ok')(url, init); }, { requests: [] });
  const online = setup({ container: null, fetch: slowPing, storage: memoryStorage({ attempts: 2, reloaded: false }), importApp: () => new Promise(() => {}) });
  boot(online.b);
  await flush();
  await online.env.advance(WATCHDOG_MS);
  assert.deepEqual(online.labels(), [BOOT_COPY.try_again, BOOT_COPY.repair_app], 'Try again first until ping answers');
  answer();
  await flush();
  assert.deepEqual(online.labels(), [BOOT_COPY.repair_app, BOOT_COPY.try_again]);
  assert.equal(online.buttons()[0].getAttribute('class'), 'button button-primary');
  online.buttons()[1].dispatch('click');
  assert.equal(online.reloads, 1, 'the second button is now Try again');

  // The same start offline: Try again stays first.
  const offline = setup({ container: null, pingMode: 'down', storage: memoryStorage({ attempts: 2, reloaded: false }), importApp: () => new Promise(() => {}) });
  boot(offline.b);
  await flush();
  await offline.env.advance(WATCHDOG_MS);
  await flush();
  assert.deepEqual(offline.labels(), [BOOT_COPY.try_again, BOOT_COPY.repair_app]);
});

test('repair is refused when api/ping.php fails and removes nothing', async () => {
  for (const mode of ['down', 'false', 'error', 'html', 'hang']) {
    const env = fakeEnv();
    const fetch = pingFetch(mode);
    const registration = fakeRegistration({ scope: SCOPE, active: fakeWorker('activated') });
    const container = fakeContainer({ registration });
    const caches = fakeCaches();
    caches.store.set('pfpms-shell-0.1.0-dev+aaaaaaaaaa', new Map());
    let reloads = 0;
    const result = repair({ fetch, timers: env, container, caches, scope: SCOPE, reload: () => { reloads += 1; } });
    if (mode === 'hang') await env.advance(PING_TIMEOUT_MS);
    assert.equal(await result, 'offline', mode);
    assert.equal(registration.unregistered, false, mode);
    assert.deepEqual(await caches.keys(), ['pfpms-shell-0.1.0-dev+aaaaaaaaaa'], mode);
    assert.equal(reloads, 0, mode);
  }

  // ping's request: same-origin, no cookies, never cached, never redirected.
  const env = fakeEnv();
  const fetch = pingFetch('ok');
  assert.equal(await ping({ fetch, timers: env }), true);
  assert.equal(fetch.requests[0].url, '../api/ping.php');
  const { init } = fetch.requests[0];
  assert.deepEqual(init.headers, { 'X-PFPMS-Client': 'station', Accept: 'application/json' });
  assert.equal(init.credentials, 'omit');
  assert.equal(init.cache, 'no-store');
  assert.equal(init.redirect, 'error');
  assert.equal(init.mode, 'same-origin');
  assert.ok(init.signal instanceof AbortSignal);

  // The failure screen's Repair the app, offline: it says so and removes nothing.
  const registration = fakeRegistration({ scope: SCOPE, active: fakeWorker('activated') });
  const t = setup({ container: fakeContainer({ controller: fakeWorker('activated'), registration }), pingMode: 'down', importApp: async () => { throw new Error('gone'); } });
  t.caches.store.set('pfpms-shell-0.1.0-dev+aaaaaaaaaa', new Map());
  await boot(t.b);
  await flush();
  const status = t.root().querySelector('section.boot-failed p.status');
  assert.equal(text(status), '');
  t.buttons().find((b) => text(b) === BOOT_COPY.repair_app).dispatch('click');
  assert.equal(text(status), BOOT_COPY.repairing);
  await flush();
  assert.equal(text(status), BOOT_COPY.repair_offline);
  assert.equal(registration.unregistered, false);
  assert.deepEqual(await t.caches.keys(), ['pfpms-shell-0.1.0-dev+aaaaaaaaaa']);
  assert.equal(t.reloads, 0);
});

test('repair online unregisters this scope only, deletes only pfpms-shell caches and reloads', async () => {
  const log = [];
  const trap = (name) => ({ get() { throw new Error(`repair touched ${name}`); }, configurable: true });
  const global = {};
  Object.defineProperty(global, 'indexedDB', trap('indexedDB'));
  const station = fakeRegistration({ scope: SCOPE });
  const sub = fakeRegistration({ scope: SCOPE + 'sub/' });
  const root = fakeRegistration({ scope: 'http://localhost:8088/' });
  const other = fakeRegistration({ scope: 'http://localhost:8088/other/' });
  for (const r of [station, sub, root, other]) {
    const unregister = r.unregister;
    r.unregister = () => { log.push('unregister ' + r.scope); return unregister(); };
  }
  const container = fakeContainer({ controller: fakeWorker('activated'), registration: station, registrations: [root, station, other, sub] });
  const caches = fakeCaches({ log });
  for (const name of ['pfpms-shell-0.1.0-dev+aaaaaaaaaa', 'other-app', 'pfpms-shell-0.1.0-dev+bbbbbbbbbb', 'pfpms-data']) caches.store.set(name, new Map());
  const t = setup({ container, caches, global, fetch: pingFetch('ok', log) });
  const saved = Object.getOwnPropertyDescriptors(globalThis);
  Object.defineProperty(globalThis, 'indexedDB', trap('indexedDB'));
  Object.defineProperty(globalThis, 'caches', trap('caches'));
  try {
    await boot(t.b);
    assert.equal(t.app.starts.length, 1);
    // About's Repair this app reaches boot's repair() through start()'s repair callback.
    assert.equal(await t.app.starts[0].repair(), 'repaired');
  } finally {
    for (const name of ['indexedDB', 'caches']) {
      if (saved[name]) Object.defineProperty(globalThis, name, saved[name]); else delete globalThis[name];
    }
  }
  assert.equal(log[0], 'ping', 'it pings before it removes anything');
  assert.deepEqual(log.filter((l) => l.startsWith('unregister ')).sort(), ['unregister ' + SCOPE, 'unregister ' + SCOPE + 'sub/']);
  assert.equal(root.unregistered, false);
  assert.equal(other.unregistered, false);
  assert.deepEqual(await caches.keys(), ['other-app', 'pfpms-data']);
  assert.equal(t.reloads, 1);
});

test('the failure screen uses textContent only', async () => {
  // fake-dom throws on innerHTML, outerHTML, insertAdjacentHTML and document.write, so building the screen proves it.
  const t = setup({ container: null, importApp: async () => { throw new Error('gone'); } });
  t.root().append(t.document.createElement('p'));
  await boot(t.b);
  const root = t.root();
  assert.equal(root.children.length, 1, "it replaces #app's children");
  const section = root.children[0];
  assert.equal(section.tagName, 'SECTION');
  assert.equal(section.getAttribute('class'), 'boot-failed');
  assert.equal(section.getAttribute('role'), 'alert');
  assert.deepEqual(section.children.map((c) => c.tagName), ['H1', 'DIV', 'P']);
  const [h1, actions, status] = section.children;
  assert.equal(text(h1), BOOT_COPY.boot_failed);
  assert.equal(actions.getAttribute('class'), 'actions');
  assert.deepEqual(actions.children.map((b) => [b.tagName, b.getAttribute('class'), text(b)]), [
    ['BUTTON', 'button button-primary', BOOT_COPY.try_again],
    ['BUTTON', 'button', BOOT_COPY.repair_app],
  ]);
  assert.equal(status.getAttribute('class'), 'status');
  assert.equal(status.getAttribute('aria-live'), 'polite');
  assert.equal(text(status), '');
  // Every node is an element or a text node holding one of BOOT_COPY's strings.
  const strings = new Set(Object.values(BOOT_COPY));
  const visit = (node) => {
    for (const child of node.childNodes) {
      if (child.nodeType === 3) assert.ok(strings.has(child.data), child.data);
      else visit(child);
    }
  };
  visit(section);
});

test('a slow first start gets the whole watchdog after the worker wait', async () => {
  // The worker installs but does not take control within WORKER_WAIT_MS (a slow precache): the app's module graph then
  // loads from the network and gets the full WATCHDOG_MS, not the 7 s the wait left of it.
  let resolveImport;
  const app = fakeApp();
  const t = setup({ app, importApp: () => new Promise((resolve) => { resolveImport = resolve; }) });
  const running = boot(t.b);
  await flush();
  await t.env.advance(WORKER_WAIT_MS);
  await flush();
  assert.equal(typeof resolveImport, 'function', 'the import started after the wait');
  await t.env.advance(WATCHDOG_MS - 1);
  assert.equal(t.screen(), null, 'no failure screen on a start that is slow but healthy');
  resolveImport(app);
  await running;
  assert.deepEqual(app.results, [true], 'started() says yes');
  assert.equal(t.screen(), null);
  assert.equal(t.timers.live.size, 0, 'the watchdog is cleared');
  assert.deepEqual(stateOf(t.storage), { attempts: 0, reloaded: false }, 'not counted as a failed start');
  // When the app never arrives, the failure screen shows WATCHDOG_MS after the wait ended.
  const u = setup({ importApp: () => new Promise(() => {}) });
  boot(u.b);
  await flush();
  await u.env.advance(WORKER_WAIT_MS + WATCHDOG_MS - 1);
  assert.equal(u.screen(), null);
  await u.env.advance(1);
  assert.ok(u.screen());
});

test('the app gets registerWorker: sw.php again through the same policy and options, for a start whose registration was refused', async () => {
  // A first start while sw.php answers 503 (maintenance): the registration is refused and the app starts without one.
  const t = setup({ container: fakeContainer({ register: 'reject' }) });
  await boot(t.b);
  const [info] = t.app.starts;
  assert.equal(info.registration, null);
  assert.equal(typeof info.registerWorker, 'function');
  // Later (the server answers again) the app registers through it.
  t.container.registerMode = 'ok';
  const registration = await info.registerWorker();
  assert.equal(registration, t.container.registration);
  assert.equal(t.container.registered.length, 2);
  const { url, opts } = t.container.registered[1];
  assert.ok(url instanceof FakeTrustedScriptURL, 'the TrustedScriptURL of the one pfpms-sw policy');
  assert.equal(String(url), 'sw.php');
  assert.deepEqual(opts, { scope: './', updateViaCache: 'none' });
  assert.deepEqual(t.trustedTypes.policies.map((p) => p.name), ['pfpms-sw'], 'no second policy (the CSP allows one)');
  // Without Trusted Types it is the plain string, as boot.js's own register; without a worker API there is none.
  const plain = setup({ container: fakeContainer({ register: 'reject' }), trustedTypes: null });
  await boot(plain.b);
  plain.container.registerMode = 'ok';
  await plain.app.starts[0].registerWorker();
  assert.equal(plain.container.registered[1].url, 'sw.php');
  const bare = setup({ container: null });
  await boot(bare.b);
  assert.equal(bare.app.starts[0].registerWorker, null);
});
