// Test support (S2 spec §6.1): a scripted fetch. Every request is recorded; each one needs a reply set up in advance,
// so a stray call fails its test. Not a suite (no .test.js suffix). Nothing here touches the network.
//
// An answer never arrives in the task that sent the request (it waits one setImmediate, as a network answer does), so
// awaiting a fetch inside db.tx() trips the memory IndexedDB's auto-commit trap, as it does in a browser.

const aborted = () => new DOMException('The operation was aborted.', 'AbortError');
const nextTask = () => new Promise((resolve) => setImmediate(resolve));

/** The captive portal page .html() answers with. */
export const CAPTIVE_PORTAL_HTML = '<!doctype html><title>Sign in to the Wi-Fi</title><p>Accept the terms to continue.</p>';

/**
 * @typedef {object} RecordedRequest
 * @property {string} url the URL exactly as passed to fetch (relative URLs stay relative)
 * @property {string} method upper case; GET when none was given
 * @property {Object<string, string>} headers lower-case name → value
 * @property {string|undefined} body init.body as given
 * @property {string|undefined} credentials
 * @property {string|undefined} mode
 * @property {string|undefined} cache
 * @property {string|undefined} redirect
 * Not enumerable (so deepEqual against the eight fields above still works): `signal` (init.signal or null), `init`.
 */

/**
 * A match: a predicate over the RecordedRequest, or {method?, url?} where url is a RegExp (tested against the URL) or
 * a string (equal to the URL or its ending, so 'api/ping.php' matches '../api/ping.php'); {} or null matches all.
 * A reply: a Response (each use gets a clone), a function (request, init) returning a Response (or a promise of one,
 * or 'offline' or 'hang'), 'offline' (rejects TypeError('Failed to fetch')) or 'hang' (never settles until aborted).
 * Replies are tried in the order they were set up; each serves `times` requests (default 1; Infinity for all).
 * @returns {Function & {requests: RecordedRequest[], on: Function, json: Function, html: Function, offline: Function, hang: Function, pending: Function}}
 */
export function fakeFetch() {
  const rules = [];
  const requests = [];

  const matches = (match, req) => {
    if (match === null || match === undefined) return true;
    if (typeof match === 'function') return match(req) === true;
    if (match.method !== undefined && String(match.method).toUpperCase() !== req.method) return false;
    if (match.url instanceof RegExp) {
      match.url.lastIndex = 0;
      return match.url.test(req.url);
    }
    if (match.url !== undefined) return req.url === match.url || req.url.endsWith(String(match.url));
    return true;
  };

  const hang = (signal) => new Promise((resolve, reject) => {
    if (!signal) return;
    if (signal.aborted) { reject(aborted()); return; }
    signal.addEventListener('abort', () => reject(aborted()), { once: true });
  });

  /** Settles like `promise`, or rejects AbortError as soon as the signal aborts. */
  const abortable = (promise, signal) => {
    if (!signal) return promise;
    return new Promise((resolve, reject) => {
      if (signal.aborted) { reject(aborted()); return; }
      const onAbort = () => reject(aborted());
      signal.addEventListener('abort', onAbort, { once: true });
      promise.then((v) => { signal.removeEventListener('abort', onAbort); resolve(v); }, (e) => { signal.removeEventListener('abort', onAbort); reject(e); });
    });
  };

  async function fetch(url, init = {}) {
    const headers = {};
    new Headers(init.headers ?? {}).forEach((value, name) => { headers[name] = value; });
    const req = {
      url: String(url),
      method: String(init.method ?? 'GET').toUpperCase(),
      headers,
      body: init.body,
      credentials: init.credentials,
      mode: init.mode,
      cache: init.cache,
      redirect: init.redirect,
    };
    Object.defineProperty(req, 'signal', { value: init.signal ?? null, enumerable: false });
    Object.defineProperty(req, 'init', { value: init, enumerable: false });
    requests.push(req);

    const signal = init.signal ?? null;
    await nextTask();
    if (signal?.aborted) throw aborted();
    const rule = rules.find((r) => r.times > 0 && matches(r.match, req));
    if (!rule) throw new Error(`fake-fetch: no reply for ${req.method} ${req.url}`);
    rule.times -= 1;
    let reply = rule.reply;
    if (typeof reply === 'function') reply = await abortable(Promise.resolve().then(() => rule.reply(req, init)), signal);
    else if (reply instanceof Response) reply = reply.clone();
    if (reply === 'offline') throw new TypeError('Failed to fetch');
    if (reply === 'hang') return hang(signal);
    if (!(reply instanceof Response)) throw new TypeError('fake-fetch: a reply must be a Response, a function returning one, offline or hang');
    if (signal?.aborted) throw aborted();
    return reply;
  }

  fetch.requests = requests;
  /** Adds a reply rule; returns fetch (chainable). */
  fetch.on = (match, reply, { times = 1 } = {}) => {
    rules.push({ match, reply, times });
    return fetch;
  };
  /** A JSON answer (Content-Type application/json; charset=utf-8, unless headers says otherwise). */
  fetch.json = (match, status, body, headers = {}, options = {}) => fetch.on(match, () => {
    const h = new Headers({ 'Content-Type': 'application/json; charset=utf-8' });
    for (const [name, value] of Object.entries(headers)) h.set(name, value);
    return new Response(JSON.stringify(body), { status, headers: h });
  }, options);
  /** A captive portal's HTML page (text/html). */
  fetch.html = (match, status = 200, options = {}) => fetch.on(match,
    () => new Response(CAPTIVE_PORTAL_HTML, { status, headers: { 'Content-Type': 'text/html; charset=UTF-8' } }), options);
  /** No network: rejects TypeError('Failed to fetch'). */
  fetch.offline = (match, options = {}) => fetch.on(match, 'offline', options);
  /** Never answers; rejects AbortError when the request's signal aborts. */
  fetch.hang = (match, options = {}) => fetch.on(match, 'hang', options);
  /** How many replies set up are still unused. */
  fetch.pending = () => rules.reduce((n, r) => n + (Number.isFinite(r.times) ? r.times : 0), 0);
  return fetch;
}
