/* PFPMS Station service worker core (docs/design/50-design-station.md §7.7). A classic script: station/sw.php
 * (StationShell::worker()) first sends two lines, the BUILD string and the verified PRECACHE list of
 * {path, sha256, type} entries, and then this file, which reads both and declares neither. */
'use strict';

var PFPMS_SW = (function () {
  var PREFIX = 'pfpms-shell-';

  function cacheName(build) { return PREFIX + build; }

  /** Same-origin GETs under the scope, never /api/ and never sw.php. */
  function shouldHandle(url, scopeUrl, method) {
    if (method !== 'GET') return false;
    var u = new URL(url);
    var s = new URL(scopeUrl);
    if (u.origin !== s.origin || !u.pathname.startsWith(s.pathname)) return false;
    if (u.pathname.indexOf('/api/') !== -1) return false;
    return !u.pathname.endsWith('/sw.php');
  }

  function typeMatches(family, contentType) {
    return (contentType || '').toLowerCase().indexOf(family) !== -1;
  }

  function hex(buffer) {
    return Array.from(new Uint8Array(buffer), function (b) { return b.toString(16).padStart(2, '0'); }).join('');
  }

  /** ok, not redirected, the Content-Type family, and hex(SHA-256(body)) === entry.sha256. Consumes the body: pass a clone. */
  async function verify(entry, response) {
    if (!response || !response.ok || response.redirected) return false;
    if (!typeMatches(entry.type, response.headers.get('content-type'))) return false;
    return hex(await crypto.subtle.digest('SHA-256', await response.arrayBuffer())) === entry.sha256;
  }

  /** Fetch and verify every entry (cache: 'reload'); only when all pass, put them. Any mismatch throws, so the install fails. */
  async function install(scope, build, precache) {
    var name = cacheName(build);
    var existed = await caches.has(name);
    try {
      var fetched = await Promise.all(precache.map(async function (entry) {
        var url = new URL(entry.path, scope).href;
        var res = await fetch(new Request(url, { cache: 'reload', credentials: 'same-origin', redirect: 'error' }));
        if (!(await verify(entry, res.clone()))) throw new Error('pfpms: precache check failed for ' + entry.path);
        return [url, res];
      }));
      var cache = await caches.open(name);
      for (var i = 0; i < fetched.length; i++) await cache.put(fetched[i][0], fetched[i][1]);
    } catch (e) {
      if (!existed) await caches.delete(name);
      throw e;
    }
  }

  /** Delete every pfpms-shell-* cache except this build's. */
  async function activate(build) {
    var keep = cacheName(build);
    var names = await caches.keys();
    for (var i = 0; i < names.length; i++) {
      if (names[i].startsWith(PREFIX) && names[i] !== keep) await caches.delete(names[i]);
    }
  }

  /** Navigations get the cached shell ('./', unchanged, with its CSP); files match ignoring the query; else the network.
   *  caches.match() with a cacheName never creates a cache: caches.open() here would re-create the cache that Repair or
   *  a wipe has just deleted, while this worker still controls the page (and a later failed install would then keep it). */
  async function respond(request, scope, build) {
    var name = cacheName(build);
    var hit = request.mode === 'navigate'
      ? await caches.match(new URL('./', scope).href, { cacheName: name })
      : await caches.match(request, { cacheName: name, ignoreSearch: true });
    return hit || fetch(request);
  }

  return { PREFIX: PREFIX, cacheName: cacheName, shouldHandle: shouldHandle, typeMatches: typeMatches, verify: verify,
    install: install, activate: activate, respond: respond };
})();

if (typeof self !== 'undefined' && self.registration) {
  self.addEventListener('install', function (event) {
    event.waitUntil(PFPMS_SW.install(self.registration.scope, BUILD, PRECACHE).then(function () {
      if (!self.registration.active) return self.skipWaiting(); // first install only; an update waits for the app's SKIP_WAITING
    }));
  });
  self.addEventListener('activate', function (event) {
    event.waitUntil(PFPMS_SW.activate(BUILD).then(function () { return self.clients.claim(); }));
  });
  self.addEventListener('fetch', function (event) {
    if (!PFPMS_SW.shouldHandle(event.request.url, self.registration.scope, event.request.method)) return;
    event.respondWith(PFPMS_SW.respond(event.request, self.registration.scope, BUILD));
  });
  self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
  });
}
