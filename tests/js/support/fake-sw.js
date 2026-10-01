// Service worker fakes for the Node suites (S2 spec §6.1): CacheStorage, workers, registrations, the page's
// ServiceWorkerContainer, the worker's global scope, and a node:vm loader that runs a worker's code the way
// station/sw.php composes it (StationShell::worker()). Nothing here touches real time or the network.

import vm from 'node:vm';
import { readFileSync } from 'node:fs';

/** The worker core that station/sw.php inlines. */
export const SW_CORE_URL = new URL('../../../public/station/js/sw-core.js', import.meta.url);
/** The scope every fake defaults to (the dev server's Station). */
export const DEFAULT_SCOPE = 'http://localhost:8088/station/';
/** The build loadWorker() uses when none is given (fake-env.js's shellBuild). */
export const DEFAULT_BUILD = 'test+0000000000';
/** The message fakeContainer({register: 'reject'}) rejects with (what the desktop app's browser pane says). */
export const REGISTER_REJECTION = 'Failed to register a ServiceWorker: An unknown error occurred when fetching the script.';

/** A minimal EventTarget: listeners per type, each added once, called in order with the event. */
function eventTarget(owner) {
  const listeners = new Map();
  return {
    add(type, fn) { if (typeof fn !== 'function') return; if (!listeners.has(type)) listeners.set(type, []); const l = listeners.get(type); if (!l.includes(fn)) l.push(fn); },
    remove(type, fn) { const l = listeners.get(type); if (l) listeners.set(type, l.filter((f) => f !== fn)); },
    emit(type, init = {}) { const event = { type, target: owner, ...init }; for (const fn of [...(listeners.get(type) ?? [])]) fn.call(owner, event); return event; },
    count(type) { return (listeners.get(type) ?? []).length; },
  };
}

/**
 * A fake CacheStorage.
 * - `.store`: Map<cache name, Map<absolute URL, Response>> in creation order (a Request is keyed by request.url, the
 *   fragment dropped). Tests may seed it directly.
 * - `open(name)` creates the cache when missing (recorded in `.opened`) and resolves a Cache with put(request,
 *   response), match(request, {ignoreSearch}), keys() (Requests) and delete(request).
 * - `match(request, {cacheName, ignoreSearch})`: with a cacheName that does not exist it resolves undefined WITHOUT
 *   creating it; without one it searches every cache in creation order. Matches are clones, so a body can be read again.
 * - `.log` records 'open <name>', 'delete <name>' and 'put <url>' in order (pass your own array to share it).
 * - `failPutAt: n` makes the n-th Cache.put() (counted across every cache, from 1) reject with a QuotaExceededError
 *   DOMException, as a full iPad does; the puts before it succeed, so the cache holds part of the entries. It is
 *   logged as 'put <url> failed' and stores nothing.
 * @param {{log?: string[], failPutAt?: number}} [options]
 */
export function fakeCaches({ log = [], failPutAt = 0 } = {}) {
  const store = new Map();
  const opened = [];
  let puts = 0;
  const keyOf = (request) => {
    const u = new URL(typeof request === 'string' ? request : request.url);
    u.hash = '';
    return u.href;
  };
  const withoutSearch = (href) => { const u = new URL(href); u.search = ''; return u.href; };
  const lookup = (entries, request, { ignoreSearch = false } = {}) => {
    const want = ignoreSearch ? withoutSearch(keyOf(request)) : keyOf(request);
    for (const [url, response] of entries) {
      if ((ignoreSearch ? withoutSearch(url) : url) === want) return response.clone();
    }
    return undefined;
  };
  // A Cache object keeps its entries even after its name is deleted from the storage (as in a browser).
  const cacheOf = (entries) => ({
    async put(request, response) {
      if (!response || typeof response.clone !== 'function') throw new TypeError('fake-caches: put needs a Response');
      if (response.bodyUsed) throw new TypeError('fake-caches: the response body is already used');
      const key = keyOf(request);
      if (++puts === failPutAt) {
        log.push(`put ${key} failed`);
        throw new DOMException('Quota exceeded', 'QuotaExceededError');
      }
      log.push(`put ${key}`);
      entries.set(key, response);
    },
    async match(request, options = {}) { return lookup(entries, request, options); },
    async keys() { return [...entries.keys()].map((url) => new Request(url)); },
    async delete(request) { return entries.delete(keyOf(request)); },
  });
  return {
    store,
    opened,
    log,
    async open(name) {
      const n = String(name);
      opened.push(n);
      log.push(`open ${n}`);
      if (!store.has(n)) store.set(n, new Map());
      return cacheOf(store.get(n));
    },
    async has(name) { return store.has(String(name)); },
    async delete(name) { log.push(`delete ${name}`); return store.delete(String(name)); },
    async keys() { return [...store.keys()]; },
    async match(request, { cacheName, ignoreSearch = false } = {}) {
      if (cacheName !== undefined) {
        const entries = store.get(String(cacheName));
        return entries ? lookup(entries, request, { ignoreSearch }) : undefined;
      }
      for (const entries of store.values()) {
        const hit = lookup(entries, request, { ignoreSearch });
        if (hit) return hit;
      }
      return undefined;
    },
  };
}

/**
 * A fake ServiceWorker: `state`, `postMessage(data)` (a structured clone, recorded in `.messages`), statechange
 * listeners, and `setState(s)`, which sets the state and fires statechange.
 * @param {string} [state]
 * @param {{scriptURL?: string}} [options]
 */
export function fakeWorker(state = 'installed', { scriptURL = DEFAULT_SCOPE + 'sw.php' } = {}) {
  const worker = {
    state,
    scriptURL,
    messages: [],
    postMessage(data) { worker.messages.push(structuredClone(data)); },
    addEventListener(type, fn) { events.add(type, fn); },
    removeEventListener(type, fn) { events.remove(type, fn); },
    setState(s) { worker.state = s; events.emit('statechange'); },
    listenerCount(type) { return events.count(type); },
  };
  const events = eventTarget(worker);
  return worker;
}

/**
 * A fake ServiceWorkerRegistration: `scope`, `active`, `waiting`, `installing` (all writable), `update()` (counted in
 * `.updates`), `unregister()` (sets `.unregistered`), updatefound listeners and `fireUpdateFound(worker)`, which sets
 * `installing` and fires updatefound.
 * @param {{scope?: string, active?: object|null, waiting?: object|null, installing?: object|null}} [options]
 */
export function fakeRegistration({ scope = DEFAULT_SCOPE, active = null, waiting = null, installing = null } = {}) {
  const registration = {
    scope,
    active,
    waiting,
    installing,
    updates: 0,
    unregistered: false,
    async update() { registration.updates += 1; return registration; },
    async unregister() { registration.unregistered = true; return true; },
    addEventListener(type, fn) { events.add(type, fn); },
    removeEventListener(type, fn) { events.remove(type, fn); },
    fireUpdateFound(worker) { registration.installing = worker; events.emit('updatefound'); },
    listenerCount(type) { return events.count(type); },
  };
  const events = eventTarget(registration);
  return registration;
}

/**
 * A fake ServiceWorkerContainer (navigator.serviceWorker).
 * - `register(url, opts)` is recorded in `.registered` as {url, opts} (url as given: a TrustedScriptURL stays one);
 *   `register: 'ok'` resolves the registration (made on first use when none was given), `'reject'` rejects
 *   TypeError(REGISTER_REJECTION), `'hang'` never settles. `.registerMode` may be changed later.
 * - `getRegistration()` resolves the registration or undefined; `getRegistrations()` every registration of the origin
 *   (`registrations`, defaulting to [registration]).
 * - controllerchange and message listeners; `fireControllerChange(worker)` sets `controller` and fires controllerchange;
 *   `postFromWorker(data)` fires a message event with a structured clone of data.
 * @param {{controller?: object|null, registration?: object|null, registrations?: object[], register?: 'ok'|'reject'|'hang'}} [options]
 */
export function fakeContainer({ controller = null, registration = null, registrations = null, register = 'ok' } = {}) {
  const list = registrations ?? (registration ? [registration] : []);
  const container = {
    controller,
    registration,
    registered: [],
    registerMode: register,
    register(url, opts) {
      container.registered.push({ url, opts });
      if (container.registerMode === 'reject') return Promise.reject(new TypeError(REGISTER_REJECTION));
      if (container.registerMode === 'hang') return new Promise(() => {});
      if (!container.registration) {
        container.registration = fakeRegistration({ scope: new URL(opts?.scope ?? './', DEFAULT_SCOPE).href });
        list.push(container.registration);
      }
      return Promise.resolve(container.registration);
    },
    async getRegistration() { return container.registration ?? undefined; },
    async getRegistrations() { return [...list]; },
    addEventListener(type, fn) { events.add(type, fn); },
    removeEventListener(type, fn) { events.remove(type, fn); },
    fireControllerChange(worker = fakeWorker('activated')) { container.controller = worker; events.emit('controllerchange'); },
    postFromWorker(data) { events.emit('message', { data: structuredClone(data), source: container.controller }); },
    listenerCount(type) { return events.count(type); },
  };
  const events = eventTarget(container);
  return container;
}

/**
 * The worker's global scope, for node:vm (loadWorker()).
 * - `clients` is a list of {url, controlled}; each given object gains `postMessage(data)` (→ `.messages`) and
 *   `navigate(url)` (a promise, recorded in `.navigations`). `self.clients.matchAll({type: 'window'})` returns the
 *   controlled ones, `{includeUncontrolled: true}` all of them.
 * - `self.registration` has `scope`, `active` (writable; null means a first install) and `unregister()`
 *   (`.unregistered`); `self.skipWaiting()` and `self.clients.claim()` are counted (`skipWaitingCalls`, `claimCalls`).
 * - `log` records 'skipWaiting', 'claim', 'unregister', 'postMessage <url>' and 'navigate <url>' in order (pass the
 *   same array to fakeCaches() to see the order across both).
 * - `dispatch(type, event)` calls every listener of `type` with an event that has waitUntil() and respondWith() (any
 *   of the given event's own are called too). It returns a promise that settles once every waitUntil() and
 *   respondWith() promise has, resolving {event, responded, response} (response: the resolved respondWith value, or
 *   null) and rejecting with the first rejection; the promise also carries `.event` and `.responded` at once.
 * @param {{scope?: string, clients?: Array<{url: string, controlled: boolean}>, active?: object|null, log?: string[]}} [options]
 */
export function workerSelf({ scope = DEFAULT_SCOPE, clients = [], active = null, log = [] } = {}) {
  const listeners = {};
  const windows = clients.map((c) => {
    c.messages = c.messages ?? [];
    c.navigations = c.navigations ?? [];
    c.postMessage = (data) => { log.push(`postMessage ${c.url}`); c.messages.push(structuredClone(data)); };
    c.navigate = (url) => { log.push(`navigate ${url}`); c.navigations.push(url); return Promise.resolve(c); };
    return c;
  });
  const self = {
    registration: {
      scope,
      active,
      unregistered: false,
      async unregister() { log.push('unregister'); self.registration.unregistered = true; return true; },
    },
    skipWaitingCalls: 0,
    async skipWaiting() { log.push('skipWaiting'); self.skipWaitingCalls += 1; },
    clients: {
      claimCalls: 0,
      async claim() { log.push('claim'); self.clients.claimCalls += 1; },
      async matchAll({ type = 'window', includeUncontrolled = false } = {}) {
        if (type !== 'window' && type !== 'all') return [];
        return windows.filter((w) => includeUncontrolled || w.controlled);
      },
    },
    addEventListener(type, fn) { (listeners[type] ??= []).push(fn); },
    removeEventListener(type, fn) { listeners[type] = (listeners[type] ?? []).filter((f) => f !== fn); },
  };
  function dispatch(type, init = {}) {
    const waits = [];
    const responses = [];
    const event = {
      type,
      ...init,
      waitUntil(p) { waits.push(Promise.resolve(p)); init.waitUntil?.(p); },
      respondWith(p) {
        if (responses.length > 0) throw new Error('fake-sw: respondWith() was called twice');
        responses.push(Promise.resolve(p));
        init.respondWith?.(p);
      },
    };
    for (const fn of [...(listeners[type] ?? [])]) fn.call(self, event);
    const responded = responses.length > 0;
    const settled = Promise.all([Promise.all(waits), responded ? responses[0] : null])
      .then(([, response]) => ({ event, responded, response }));
    settled.catch(() => {}); // a caller that only reads .responded must not see an unhandled rejection
    return Object.assign(settled, { event, responded });
  }
  return { self, listeners, dispatch, clients: windows, log };
}

/**
 * Runs a worker's code in a fresh node:vm context and returns that context. The source is
 * `const BUILD = <json>;\nconst PRECACHE = <json>;\n` + core, as StationShell::worker() composes it (the kill worker
 * ignores both lines). The context's globals are exactly self, caches, fetch (the given ones), addEventListener (bound
 * to self) and Node's own crypto, URL, Request, Response, Headers, TextEncoder, console, setTimeout and clearTimeout:
 * a fresh context has none of the Web APIs, so anything else the code reaches for is a ReferenceError.
 * A redirect is modelled as `fetch` rejecting TypeError('Failed to fetch'), what redirect: 'error' does in a browser.
 * `var PFPMS_SW` is readable as context.PFPMS_SW.
 * @param {{core?: string, build?: string, precache?: Array<{path: string, sha256: string, type: string}>, self?: object, caches?: object, fetch?: Function}} [options]
 * @returns {object} the vm context
 */
export function loadWorker({ core, build = DEFAULT_BUILD, precache = [], self, caches, fetch } = {}) {
  const scope = self ?? workerSelf().self;
  const context = vm.createContext({
    self: scope,
    caches: caches ?? fakeCaches(),
    fetch: fetch ?? (async (request) => { throw new Error(`fake-sw: no fetch for ${typeof request === 'string' ? request : request?.url}`); }),
    addEventListener: scope.addEventListener.bind(scope),
    crypto: globalThis.crypto,
    URL,
    Request,
    Response,
    Headers,
    TextEncoder,
    console,
    setTimeout,
    clearTimeout,
  });
  const source = 'const BUILD = ' + JSON.stringify(build) + ';\nconst PRECACHE = ' + JSON.stringify(precache) + ';\n'
    + (core ?? readFileSync(SW_CORE_URL, 'utf8'));
  vm.runInContext(source, context, { filename: 'sw.php' });
  return context;
}
