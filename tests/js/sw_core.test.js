// The Station's service worker core (public/station/js/sw-core.js, 50-design §7.7), loaded with node:vm the way
// station/sw.php composes it (const BUILD, const PRECACHE, then the core), over the fakes of support/fake-sw.js.
import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { fakeCaches, fakeWorker, loadWorker, workerSelf } from './support/fake-sw.js';

const SCOPE = 'http://localhost:8088/station/';
const BUILD = '0.1.0-dev+0123456789';
const NAME = 'pfpms-shell-' + BUILD;
const PNG = Uint8Array.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0, 0, 0, 13, 0x49, 0x48, 0x44, 0x52]);

/** The served files: path → body and the exact Content-Type php -S or Apache sends; family is the precache type. */
const FILES = {
  './': { body: '<!doctype html>\n<html lang="en" data-build="x"><main id="app" class="app"></main></html>\n', type: 'text/html; charset=utf-8', family: 'text/html' },
  'boot.js': { body: 'export const WATCHDOG_MS = 15000;\n', type: 'application/javascript', family: 'javascript' },
  'js/app.js': { body: 'export async function start() {}\n', type: 'text/javascript; charset=utf-8', family: 'javascript' },
  'css/station.css': { body: 'body { margin: 0; }\n', type: 'text/css; charset=UTF-8', family: 'text/css' },
  'manifest.json': { body: '{\n  "name": "Pet Pantry Station"\n}\n', type: 'application/json', family: 'json' },
  'icons/icon-192.png': { body: PNG, type: 'image/png', family: 'image/png' },
};
const sha256 = (body) => createHash('sha256').update(body).digest('hex');
const PRECACHE = Object.entries(FILES).map(([path, f]) => ({ path, sha256: sha256(f.body), type: f.family }));
const abs = (path) => new URL(path, SCOPE).href;
const SHELL_URL = abs('./');

/**
 * The network: serves FILES at their absolute URLs (any query ignored), 404 for anything else. `serve` overrides a
 * path: {status}, {type}, {body} or 'redirect' (fetch rejects TypeError, as redirect: 'error' does). Every request is
 * kept in `.requests` and logged as 'fetch <url>'.
 */
function network({ log = [], serve = {} } = {}) {
  const requests = [];
  async function fetch(request) {
    const url = typeof request === 'string' ? request : request.url;
    requests.push(request);
    log.push(`fetch ${url}`);
    const u = new URL(url);
    u.search = '';
    const path = Object.keys(FILES).find((p) => abs(p) === u.href);
    const override = path === undefined ? undefined : serve[path];
    if (override === 'redirect') throw new TypeError('Failed to fetch');
    if (path === undefined) return new Response('network: ' + u.pathname, { status: 404, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    const f = { ...FILES[path], status: 200, ...(override ?? {}) };
    return new Response(f.body, { status: f.status, headers: { 'Content-Type': f.type } });
  }
  return { fetch, requests };
}

function setup({ build = BUILD, precache = PRECACHE, active = null, serve = {}, clients = [], failPutAt = 0 } = {}) {
  const log = [];
  const caches = fakeCaches({ log, failPutAt });
  const net = network({ log, serve });
  const worker = workerSelf({ scope: SCOPE, active, clients, log });
  const context = loadWorker({ build, precache, self: worker.self, caches, fetch: net.fetch });
  return { ...worker, sw: context.PFPMS_SW, context, caches, net, log };
}

const bodyOf = async (response) => new Uint8Array(await response.arrayBuffer());
const bytesOf = (body) => (typeof body === 'string' ? new TextEncoder().encode(body) : body);
const seed = (caches, name, entries) => caches.store.set(name, new Map(entries.map(([url, body]) => [url, new Response(body)])));

test('shouldHandle only takes same-origin GETs in the scope, never /api/ or sw.php', () => {
  const { sw } = setup();
  const yes = (url, scope = SCOPE) => assert.equal(sw.shouldHandle(url, scope, 'GET'), true, url);
  const no = (url, scope = SCOPE, method = 'GET') => assert.equal(sw.shouldHandle(url, scope, method), false, `${method} ${url}`);
  yes(SCOPE);
  yes(SCOPE + 'index.php');
  yes(SCOPE + 'js/app.js?v=' + encodeURIComponent(BUILD));
  yes(SCOPE + 'icons/icon-192.png');
  no(SCOPE + 'js/app.js', SCOPE, 'POST');
  no(SCOPE, SCOPE, 'HEAD');
  no('http://127.0.0.1:8088/station/js/app.js');      // another origin
  no('https://localhost:8088/station/js/app.js');      // another origin (scheme)
  no('http://localhost:8088/admin_devices.php');       // outside the scope
  no('http://localhost:8088/stationx/app.js');         // outside the scope (prefix only)
  no('http://localhost:8088/api/ping.php');
  no(SCOPE + 'api/ping.php');                          // /api/ inside the scope
  no('http://localhost:8088/api/ping.php', 'http://localhost:8088/'); // /api/ under a root scope
  no(SCOPE + 'sw.php');
  no(SCOPE + 'sw.php?v=1');
});

test('install fetches every entry with cache reload and redirect error, verifies, then caches', async () => {
  const t = setup();
  const done = await t.dispatch('install');
  assert.equal(done.event.type, 'install');
  assert.deepEqual(t.net.requests.map((r) => r.url), PRECACHE.map((e) => abs(e.path)));
  for (const request of t.net.requests) {
    assert.ok(request instanceof Request, 'a Request, so the options are the ones the browser sees');
    assert.equal(request.method, 'GET');
    assert.equal(request.cache, 'reload');
    assert.equal(request.redirect, 'error');
    assert.equal(request.credentials, 'same-origin');
  }
  assert.deepEqual(await t.caches.keys(), [NAME]);
  assert.deepEqual([...t.caches.store.get(NAME).keys()], PRECACHE.map((e) => abs(e.path)));
  for (const [path, f] of Object.entries(FILES)) {
    const hit = await t.caches.match(abs(path), { cacheName: NAME });
    assert.deepEqual(await bodyOf(hit), bytesOf(f.body), `${path} is cached byte for byte`);
    assert.equal(hit.headers.get('content-type'), f.type, `${path} keeps its headers`);
  }
  // Every entry is fetched and verified before the cache is opened.
  const opened = t.log.indexOf('open ' + NAME);
  assert.ok(opened > Math.max(...t.log.map((line, i) => (line.startsWith('fetch ') ? i : -1))), t.log.join('\n'));
  assert.deepEqual(t.log.slice(opened + 1), [...PRECACHE.map((e) => 'put ' + abs(e.path)), 'skipWaiting']);
});

test('install fails on a hash mismatch and leaves no new cache, keeping the old one', async () => {
  const bad = PRECACHE.map((e) => (e.path === 'js/app.js' ? { ...e, sha256: sha256('export async function start() { evil(); }\n') } : e));
  const t = setup({ precache: bad });
  seed(t.caches, 'pfpms-shell-0.1.0-dev+aaaaaaaaaa', [[SHELL_URL, 'old shell']]);
  await assert.rejects(t.dispatch('install'), /precache check failed for js\/app\.js/);
  assert.deepEqual(await t.caches.keys(), ['pfpms-shell-0.1.0-dev+aaaaaaaaaa'], 'no new cache');
  assert.equal(await (await t.caches.match(SHELL_URL)).text(), 'old shell', 'the running version keeps its files');
  assert.deepEqual(t.caches.opened, [], 'nothing was cached before every entry passed');
  assert.equal(t.self.skipWaitingCalls, 0);

  // A failed reinstall of a build whose cache already exists keeps that cache.
  const again = setup({ precache: bad });
  seed(again.caches, NAME, [[SHELL_URL, 'cached shell']]);
  await assert.rejects(again.dispatch('install'), /precache check failed/);
  assert.deepEqual(await again.caches.keys(), [NAME]);
  assert.equal(await (await again.caches.match(SHELL_URL, { cacheName: NAME })).text(), 'cached shell');
});

test('a put that fails mid-way (a full tablet) fails the install and leaves no partial new cache', async () => {
  // Every entry verified, so the cache is opened and filling when the quota runs out on the third put.
  const t = setup({ failPutAt: 3 });
  seed(t.caches, 'pfpms-shell-0.1.0-dev+aaaaaaaaaa', [[SHELL_URL, 'old shell']]);
  await assert.rejects(t.dispatch('install'), (e) => e.name === 'QuotaExceededError');
  const opened = t.log.indexOf('open ' + NAME);
  assert.deepEqual(t.log.slice(opened), ['open ' + NAME, 'put ' + SHELL_URL, 'put ' + abs('boot.js'), 'put ' + abs('js/app.js') + ' failed',
    'delete ' + NAME], 'two entries were put before the failure, then the new cache was deleted');
  assert.deepEqual(await t.caches.keys(), ['pfpms-shell-0.1.0-dev+aaaaaaaaaa'], 'no partial cache of the new build is left');
  assert.equal(await (await t.caches.match(SHELL_URL)).text(), 'old shell', 'the running version keeps its files');
  assert.equal(t.self.skipWaitingCalls, 0);

  // A failed reinstall of a build whose cache already exists keeps that cache (it is the running build's).
  const again = setup({ failPutAt: 1 });
  seed(again.caches, NAME, [[SHELL_URL, 'cached shell']]);
  await assert.rejects(again.dispatch('install'), (e) => e.name === 'QuotaExceededError');
  assert.deepEqual(await again.caches.keys(), [NAME]);
  assert.equal(await (await again.caches.match(SHELL_URL, { cacheName: NAME })).text(), 'cached shell');
});

test('install fails on a Content-Type family mismatch', async () => {
  // A captive portal answering a script with its HTML page, and a PNG sent as a download.
  for (const [path, type] of [['js/app.js', 'text/html; charset=utf-8'], ['icons/icon-192.png', 'application/octet-stream'], ['./', 'text/plain']]) {
    const t = setup({ serve: { [path]: { type } } });
    await assert.rejects(t.dispatch('install'), new RegExp('precache check failed for ' + path.replace(/[./]/g, '\\$&')), path);
    assert.deepEqual(await t.caches.keys(), [], path);
  }
  const { sw } = setup();
  assert.equal(sw.typeMatches('javascript', 'application/javascript'), true);
  assert.equal(sw.typeMatches('javascript', 'text/javascript; charset=utf-8'), true);
  assert.equal(sw.typeMatches('text/css', 'text/css; charset=UTF-8'), true);
  assert.equal(sw.typeMatches('json', 'application/manifest+json'), true);
  assert.equal(sw.typeMatches('text/html', 'TEXT/HTML'), true);
  assert.equal(sw.typeMatches('text/html', null), false);
  assert.equal(sw.typeMatches('javascript', 'text/html'), false);
});

test('install fails on a 404 or a redirect', async () => {
  const missing = setup({ serve: { 'css/station.css': { status: 404 } } });
  await assert.rejects(missing.dispatch('install'), /precache check failed for css\/station\.css/);
  assert.deepEqual(await missing.caches.keys(), []);

  const moved = setup({ serve: { 'manifest.json': 'redirect' } });
  await assert.rejects(moved.dispatch('install'), (e) => e.name === 'TypeError' && /Failed to fetch/.test(e.message));
  assert.deepEqual(await moved.caches.keys(), []);
  assert.equal(moved.self.skipWaitingCalls, 0);

  // verify()'s own guard: a response that was redirected is refused even with the right type and bytes.
  const entry = PRECACHE.find((e) => e.path === 'manifest.json');
  const ok = new Response(FILES['manifest.json'].body, { headers: { 'Content-Type': 'application/json' } });
  assert.equal(await moved.sw.verify(entry, ok), true);
  const redirected = new Response(FILES['manifest.json'].body, { headers: { 'Content-Type': 'application/json' } });
  Object.defineProperty(redirected, 'redirected', { value: true });
  assert.equal(await moved.sw.verify(entry, redirected), false);
  assert.equal(await moved.sw.verify(entry, null), false);
});

test('the first install skips waiting; an update waits', async () => {
  const first = setup({ active: null });
  await first.dispatch('install');
  assert.equal(first.self.skipWaitingCalls, 1);

  const update = setup({ active: fakeWorker('activated') });
  await update.dispatch('install');
  assert.deepEqual(await update.caches.keys(), [NAME], 'the update is installed');
  assert.equal(update.self.skipWaitingCalls, 0, "it waits for the app's SKIP_WAITING");
});

test('activate deletes only other pfpms-shell caches, then claims', async () => {
  const t = setup();
  for (const name of ['pfpms-shell-0.1.0-dev+aaaaaaaaaa', NAME, 'other-app', 'pfpms-shell-0.1.0-dev+bbbbbbbbbb', 'pfpms-data']) seed(t.caches, name, []);
  await t.dispatch('activate');
  assert.deepEqual(await t.caches.keys(), [NAME, 'other-app', 'pfpms-data']);
  assert.deepEqual(t.log, ['delete pfpms-shell-0.1.0-dev+aaaaaaaaaa', 'delete pfpms-shell-0.1.0-dev+bbbbbbbbbb', 'claim']);
  assert.equal(t.self.clients.claimCalls, 1);
});

test('a navigation gets the cached shell; a file matches ignoring the query; a miss goes to the network', async () => {
  const t = setup();
  await t.dispatch('install');
  const fetched = t.net.requests.length;

  // Node's Request cannot be built with mode 'navigate', so the navigation is a plain request object.
  for (const url of [SCOPE, SCOPE + 'index.php', SCOPE + '#/about', SCOPE + 'index.php?from=home']) {
    const nav = await t.dispatch('fetch', { request: { url, method: 'GET', mode: 'navigate' } });
    assert.equal(nav.responded, true, url);
    assert.equal(await nav.response.text(), FILES['./'].body, `${url} gets the shell`);
    assert.equal(nav.response.headers.get('content-type'), 'text/html; charset=utf-8');
  }
  const file = await t.dispatch('fetch', { request: new Request(SCOPE + 'js/app.js?v=' + encodeURIComponent(BUILD)) });
  assert.equal(await file.response.text(), FILES['js/app.js'].body);
  const icon = await t.dispatch('fetch', { request: new Request(SCOPE + 'icons/icon-192.png') });
  assert.deepEqual(await bodyOf(icon.response), PNG);
  assert.equal(t.net.requests.length, fetched, 'the cache answered');

  const miss = await t.dispatch('fetch', { request: new Request(SCOPE + 'js/later.js') });
  assert.equal(miss.responded, true);
  assert.equal(await miss.response.text(), 'network: /station/js/later.js');
  assert.deepEqual(t.net.requests.slice(fetched).map((r) => r.url), [SCOPE + 'js/later.js']);
});

test('after its cache is deleted, a fetch never re-creates it', async () => {
  const t = setup();
  await t.dispatch('install');
  assert.equal(await t.caches.delete(NAME), true); // what Repair or a wipe does while this worker still controls the page
  t.caches.opened.length = 0;
  const fetched = t.net.requests.length;

  const nav = await t.dispatch('fetch', { request: { url: SCOPE, method: 'GET', mode: 'navigate' } });
  const file = await t.dispatch('fetch', { request: new Request(SCOPE + 'js/app.js?v=1') });
  assert.equal(await nav.response.text(), FILES['./'].body, 'the shell comes from the network');
  assert.equal(await file.response.text(), FILES['js/app.js'].body);
  assert.deepEqual(t.net.requests.slice(fetched).map((r) => r.url), [SCOPE, SCOPE + 'js/app.js?v=1']);
  assert.deepEqual(await t.caches.keys(), [], 'the cache stays deleted');
  assert.deepEqual(t.caches.opened, [], 'respond() never opens a cache');

  // The same on a worker whose cache never existed.
  const fresh = setup();
  const answer = await fresh.dispatch('fetch', { request: new Request(SCOPE + 'css/station.css') });
  assert.equal(await answer.response.text(), FILES['css/station.css'].body);
  assert.deepEqual(await fresh.caches.keys(), []);
  assert.deepEqual(fresh.caches.opened, []);
});

test('requests shouldHandle refuses get no respondWith', async () => {
  const t = setup();
  await t.dispatch('install');
  const fetched = t.net.requests.length;
  const refused = [
    new Request(SCOPE + 'js/app.js', { method: 'POST', body: '{}' }),
    new Request('http://localhost:8088/api/device/heartbeat.php', { method: 'POST', body: '{}' }),
    new Request('http://localhost:8088/api/ping.php'),
    new Request(SCOPE + 'api/ping.php'),
    new Request(SCOPE + 'sw.php'),
    new Request('http://localhost:8088/admin_devices.php'),
    new Request('http://127.0.0.1:8088/station/js/app.js'),
    { url: 'http://localhost:8088/login.php', method: 'GET', mode: 'navigate' },
  ];
  for (const request of refused) {
    const d = t.dispatch('fetch', { request });
    assert.equal(d.responded, false, `${request.method} ${request.url}`);
    assert.deepEqual(await d, { event: d.event, responded: false, response: null });
  }
  assert.equal(t.net.requests.length, fetched, 'the browser, not the worker, sends them');
});

test('SKIP_WAITING calls skipWaiting', async () => {
  const t = setup({ active: fakeWorker('activated') });
  await t.dispatch('message', { data: { type: 'OTHER' } });
  await t.dispatch('message', { data: null });
  await t.dispatch('message', {});
  assert.equal(t.self.skipWaitingCalls, 0);
  await t.dispatch('message', { data: { type: 'SKIP_WAITING' } });
  assert.equal(t.self.skipWaitingCalls, 1);
});
