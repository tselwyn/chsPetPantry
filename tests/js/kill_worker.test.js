// The kill worker station.sw_kill serves (50-design D-08): StationShell::KILL_WORKER, read from the PHP source and run in
// node:vm over the fakes of support/fake-sw.js. It removes the Station's cached files and unregisters; it never touches
// the records stored on the tablet and never claims a window.
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
import { fakeCaches, workerSelf } from './support/fake-sw.js';

const SCOPE = 'http://localhost:8088/station/';
const SHELL_PHP = new URL('../../src/Station/StationShell.php', import.meta.url);

/** The nowdoc between <<<'JS' and the closing JS; of StationShell.php, exactly as sw.php echoes it. */
function killWorkerSource() {
  const php = readFileSync(SHELL_PHP, 'utf8');
  const m = /public const KILL_WORKER = <<<'JS'\n([\s\S]*?)\nJS;/.exec(php);
  assert.ok(m, 'StationShell.php declares KILL_WORKER as a JS nowdoc');
  return m[1];
}

/**
 * Runs the kill worker's install and activate over fresh fakes. `clients` are the open windows ({url, controlled}).
 * Every call it makes on the platform is recorded in `log`, in order, by spies laid over the fakes.
 */
async function runKillWorker({ clients = [], cacheNames = [] } = {}) {
  const caches = fakeCaches();
  for (const name of cacheNames) await caches.open(name);
  const worker = workerSelf({ scope: SCOPE, clients });
  const self = worker.self;
  const log = [];
  const touched = [];

  const spy = (target, name, entry) => {
    const original = target[name];
    target[name] = function (...args) {
      log.push(entry(...args));
      return original.apply(this, args);
    };
  };
  spy(caches, 'delete', (name) => `caches.delete ${name}`);
  spy(self, 'skipWaiting', () => 'skipWaiting');
  spy(self.registration, 'unregister', () => 'unregister');
  spy(self.clients, 'claim', () => 'claim');
  const wrapped = new WeakSet();
  const matchAll = self.clients.matchAll;
  self.clients.matchAll = async function (options = {}) {
    log.push(`matchAll ${options.includeUncontrolled ? 'all' : 'controlled'}`);
    const list = await matchAll.call(this, options);
    for (const client of list) {
      if (wrapped.has(client)) continue;
      wrapped.add(client);
      spy(client, 'postMessage', (data) => `post ${client.url} ${JSON.stringify(data)}`);
      spy(client, 'navigate', (url) => `navigate ${client.url} -> ${url}`);
    }
    return list;
  };

  const context = vm.createContext({ self, caches, console });
  Object.defineProperty(context, 'indexedDB', { get() { touched.push('indexedDB'); return undefined; } });
  vm.runInContext(killWorkerSource(), context, { filename: 'kill-worker.js' });

  for (const type of ['install', 'activate']) {
    const pending = [];
    await worker.dispatch(type, { type, waitUntil(promise) { pending.push(promise); } });
    await Promise.all(pending);
    assert.equal(pending.length, 1, `${type} calls waitUntil once`);
  }
  return { caches, log, touched };
}

test('it deletes only the pfpms-shell caches', async () => {
  const { caches, log } = await runKillWorker({
    cacheNames: ['pfpms-shell-0.1.0-dev+aaaaaaaaaa', 'other-app', 'pfpms-shell-0.1.0-dev+bbbbbbbbbb', 'pfpms-data', 'workbox-precache'],
  });
  assert.deepEqual(await caches.keys(), ['other-app', 'pfpms-data', 'workbox-precache']);
  assert.deepEqual(log.filter((line) => line.startsWith('caches.delete')),
    ['caches.delete pfpms-shell-0.1.0-dev+aaaaaaaaaa', 'caches.delete pfpms-shell-0.1.0-dev+bbbbbbbbbb']);
});

test('it unregisters', async () => {
  const { log } = await runKillWorker({ cacheNames: ['pfpms-shell-x'], clients: [{ url: SCOPE, controlled: true }] });
  assert.equal(log.filter((line) => line === 'unregister').length, 1);
  const at = log.indexOf('unregister');
  assert.ok(at > log.indexOf('caches.delete pfpms-shell-x'), 'after the caches are gone');
  assert.ok(at > log.indexOf('matchAll all') && at > log.indexOf('matchAll controlled'), 'after it listed the windows');
  assert.ok(log.findIndex((line) => line.startsWith('post ')) > at, 'and before it tells them');
  assert.equal(log[0], 'skipWaiting', 'install skips waiting');
});

test('it posts SW_KILLED to every window, controlled or not', async () => {
  const { log } = await runKillWorker({
    clients: [{ url: SCOPE, controlled: true }, { url: SCOPE + '#/about', controlled: false }, { url: SCOPE + 'index.php', controlled: true }],
  });
  assert.deepEqual(log.filter((line) => line.startsWith('post ')).sort(), [
    `post ${SCOPE} {"type":"SW_KILLED"}`,
    `post ${SCOPE}#/about {"type":"SW_KILLED"}`,
    `post ${SCOPE}index.php {"type":"SW_KILLED"}`,
  ]);
});

test('it navigates only the controlled windows', async () => {
  const { log } = await runKillWorker({
    clients: [{ url: SCOPE, controlled: true }, { url: SCOPE + '#/about', controlled: false }, { url: SCOPE + 'index.php', controlled: true }],
  });
  assert.deepEqual(log.filter((line) => line.startsWith('navigate ')).sort(), [
    `navigate ${SCOPE} -> ${SCOPE}`,
    `navigate ${SCOPE}index.php -> ${SCOPE}index.php`,
  ], 'each controlled window reloads its own URL, from the network now that nothing controls it');
  const lastPost = log.map((line) => line.startsWith('post ')).lastIndexOf(true);
  assert.ok(log.findIndex((line) => line.startsWith('navigate ')) > lastPost, 'after every window was told');
});

test('it never claims', async () => {
  const { log } = await runKillWorker({ cacheNames: ['pfpms-shell-x'], clients: [{ url: SCOPE, controlled: false }] });
  assert.ok(!log.includes('claim'), 'clients.claim() would take over uncontrolled windows');
  assert.doesNotMatch(killWorkerSource(), /\bclaim\s*\(/);
});

test('it never mentions IndexedDB', async () => {
  const { touched } = await runKillWorker({ cacheNames: ['pfpms-shell-x'], clients: [{ url: SCOPE, controlled: true }] });
  assert.deepEqual(touched, [], 'indexedDB is never read');
  assert.doesNotMatch(killWorkerSource(), /indexeddb|deleteDatabase|localStorage|sessionStorage/i, 'records stored on the tablet are kept');
});
