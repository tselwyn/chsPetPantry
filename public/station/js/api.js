// The Station's transport (docs/design/50-design-station.md §5.2, §5.5, §7.2, D-38): headers, CSRF, the device
// proof, the error envelope as ApiError, offline detection, timeouts and the two one-time retries. Every request goes
// through env.fetch; every failure on the network is an ApiError.
import { proofHeader } from './proof.js';

/** Statuses that mean "the server is not answering" (a proxy, maintenance): offline, not an error of the request. */
export const OFFLINE_STATUSES = Object.freeze([502, 503, 504]);
/** The default timeout; it covers the fetch and the body read. */
export const DEFAULT_TIMEOUT_MS = 15000;

/** A failed call: kind 'offline' (no usable answer) or 'http' (the server's error envelope). */
export class ApiError extends Error {
  /**
   * @param {{kind: 'offline'|'http', status?: number, code: string, message?: string, extra?: object, retryAfter?: number|null}} fields
   *   status 0 when no answer arrived; message is the server's people-facing text ('' when none); extra is the
   *   envelope without error and message (directive, incident, server_time, …); retryAfter is whole seconds
   */
  constructor({ kind, status = 0, code, message = '', extra = {}, retryAfter = null }) {
    super(message);
    this.name = 'ApiError';
    this.kind = kind;
    this.status = status;
    this.code = code;
    this.extra = extra;
    this.retryAfter = retryAfter;
  }
  /** @returns {boolean} kind === 'offline' */
  get offline() { return this.kind === 'offline'; }
}

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const textOr = (v, fallback) => (typeof v === 'string' ? v : fallback);
const retryAfterOf = (res) => {
  const v = (res.headers.get('Retry-After') ?? '').trim();
  return /^\d{1,9}$/.test(v) ? Number(v) : null;
};
/** The endpoint a proof signs: the path without its query, as Request::scriptPath() gives it on the server. */
const endpointOf = (path) => path.split(/[?#]/, 1)[0];

/** Settles like `promise`, or rejects AbortError once `signal` aborts (so the timeout also covers the body read). */
function untilAborted(promise, signal) {
  promise.catch(() => {});
  return new Promise((resolve, reject) => {
    const onAbort = () => reject(new DOMException('The operation was aborted.', 'AbortError'));
    if (signal.aborted) { onAbort(); return; }
    signal.addEventListener('abort', onAbort, { once: true });
    promise.then(
      (v) => { signal.removeEventListener('abort', onAbort); resolve(v); },
      (e) => { signal.removeEventListener('abort', onAbort); reject(e); },
    );
  });
}

/**
 * @typedef {object} CallOptions
 * @property {boolean} [device] send Authorization: PFPMS-Device and the PFPMS-Proof header
 * @property {boolean} [session] send the session cookie (credentials same-origin) and, on a POST, X-CSRF-Token
 * @property {boolean} [clearSite] credentials same-origin, so the browser applies a Clear-Site-Data answer (wipes)
 * @property {number} [timeoutMs] DEFAULT_TIMEOUT_MS when omitted
 * @property {number[]} [okStatuses] statuses answered with the body like a 2xx (S5's push: [409])
 */
/**
 * @typedef {object} Api
 * @property {(path: string, opts?: CallOptions) => Promise<object>} get path relative to public/, e.g. 'api/ping.php'; a
 *   query is sent as given ('api/sync/status.php?uuids=…') and left out of the proof, which signs the path alone
 * @property {(path: string, body: object, opts?: CallOptions) => Promise<object>} post the body is serialised once;
 *   that exact string is sent, signed and resent on a retry
 * @property {() => string|null} csrf
 * @property {(token: string|null) => void} setCsrf
 * @property {() => Promise<object>} refreshCsrf GET api/session.php (session, 5 s); returns its body
 */

/**
 * @param {{env: import('./env.js').Env, clock: import('./clock.js').Clock, base?: string,
 *   device?: () => {credential: string, proofKey: CryptoKey|null}|null}} deps
 *   base: the app root relative to the Station ('../', so a subdirectory install works); device: the registered
 *   tablet's credentials, kept in memory by device.js (so they work after Clear-Site-Data)
 * @returns {Api}
 */
export function createApi({ env, clock, base = '../', device = () => null }) {
  let token = null;
  let refreshing = null;

  async function send(method, path, bodyText, o) {
    const headers = { 'X-PFPMS-Client': 'station', Accept: 'application/json' };
    if (bodyText !== undefined) headers['Content-Type'] = 'application/json';
    let tablet = null;
    if (o.device) {
      tablet = device();
      if (!tablet) throw new Error('api: device call without a registered tablet');
    }
    if (o.session && method !== 'GET') {
      if (token === null) await api.refreshCsrf();
      if (token !== null) headers['X-CSRF-Token'] = token;
    }
    const controller = new AbortController();
    let timedOut = false;
    const timer = env.setTimeout(() => { timedOut = true; controller.abort(); }, o.timeoutMs);
    try {
      if (tablet) {
        headers.Authorization = 'PFPMS-Device ' + tablet.credential;
        if (tablet.proofKey) {
          try {
            headers['PFPMS-Proof'] = await proofHeader(env.crypto, tablet.proofKey, method, endpointOf(path), Math.round(clock.serverNow()), bodyText ?? '');
          } catch (e) {
            // A time no proof can carry (before 2001-09-09 or from 2286-11-20: not 13 digits of ms). Nothing is sent;
            // the call fails like any other that got no answer, and a later answer's server_time can correct the clock.
            if (e instanceof RangeError) throw new ApiError({ kind: 'offline', code: 'clock' });
            throw e;
          }
        }
      }
      const init = {
        method,
        headers,
        credentials: o.session || o.clearSite ? 'same-origin' : 'omit',
        mode: 'same-origin',
        cache: 'no-store',
        redirect: 'error', // a captive portal's redirect becomes a network error
        signal: controller.signal,
      };
      if (bodyText !== undefined) init.body = bodyText;
      const sentAt = env.now();
      let res;
      try {
        res = await env.fetch(base + path, init);
      } catch {
        throw new ApiError({ kind: 'offline', code: timedOut ? 'timeout' : 'network' });
      }
      const receivedAt = env.now();
      let body = null;
      if ((res.headers.get('Content-Type') ?? '').toLowerCase().startsWith('application/json')) {
        let raw;
        try {
          raw = await untilAborted(res.text(), controller.signal);
        } catch {
          throw new ApiError({ kind: 'offline', status: res.status, code: timedOut ? 'timeout' : 'network' });
        }
        try {
          const parsed = JSON.parse(raw);
          body = isObject(parsed) ? parsed : null;
        } catch {
          body = null;
        }
      }
      if (typeof body?.server_time === 'string') clock?.learn(body.server_time, sentAt, receivedAt);
      if (typeof body?.csrf === 'string') token = body.csrf;
      const status = res.status;
      if (OFFLINE_STATUSES.includes(status)) {
        throw new ApiError({ kind: 'offline', status, code: textOr(body?.error, 'unavailable'), message: textOr(body?.message, ''), retryAfter: retryAfterOf(res) });
      }
      if (body === null) throw new ApiError({ kind: 'offline', status, code: 'not_json' });
      if (res.ok || o.okStatuses.includes(status)) return body;
      const { error, message, ...extra } = body;
      throw new ApiError({ kind: 'http', status, code: textOr(error, 'http_' + status), message: textOr(message, ''), extra, retryAfter: retryAfterOf(res) });
    } finally {
      env.clearTimeout(timer);
    }
  }

  async function call(method, path, body, opts) {
    const { device: dev = false, session = false, clearSite = false, timeoutMs = DEFAULT_TIMEOUT_MS, okStatuses = [] } = opts ?? {};
    const o = { device: dev, session, clearSite, timeoutMs, okStatuses };
    const bodyText = method === 'GET' ? undefined : JSON.stringify(body);
    if (method !== 'GET' && typeof bodyText !== 'string') throw new TypeError('api.post: the body must be a plain object');
    let csrfRetried = false;
    let proofRetried = false;
    for (;;) {
      try {
        return await send(method, path, bodyText, o);
      } catch (e) {
        if (e instanceof ApiError && e.kind === 'http') {
          if (e.code === 'csrf_failed' && o.session && method !== 'GET' && !csrfRetried) {
            csrfRetried = true;
            await api.refreshCsrf();
            continue;
          }
          // The clock already learned the envelope's server_time: the resend carries a fresh proof.
          if (e.code === 'device_proof_stale' && o.device && !proofRetried) {
            proofRetried = true;
            continue;
          }
        }
        throw e;
      }
    }
  }

  const api = {
    get: (path, opts = {}) => call('GET', path, undefined, opts),
    post: (path, body, opts = {}) => call('POST', path, body, opts),
    csrf: () => token,
    setCsrf(t) { token = typeof t === 'string' ? t : null; },
    refreshCsrf() {
      if (refreshing === null) {
        refreshing = call('GET', 'api/session.php', undefined, { session: true, timeoutMs: 5000 }).finally(() => { refreshing = null; });
      }
      return refreshing;
    },
  };
  return api;
}
