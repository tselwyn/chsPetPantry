// The browser environment adapter (docs/design/50-design-station.md §7.2): the one module that reads the page's
// platform. Every other module receives what it needs through the Env this returns, so the real modules run under
// Node with tests/js/support/fake-env.js, which implements the same members.

/**
 * @typedef {object} Env
 * @property {() => number} now wall clock, ms (Date.now())
 * @property {() => number} mono monotonic clock, ms (performance.now(); pauses while the device sleeps)
 * @property {() => 'standalone'|'fullscreen'|'minimal-ui'|'browser'} displayMode
 * @property {() => Promise<boolean>} persisted never rejects
 * @property {() => Promise<boolean>} persist asks for persistent storage; never rejects
 * @property {() => Promise<number|null>} estimateKb storage used, in KB; null when unknown
 * @property {() => boolean} online
 * @property {() => boolean} visible
 * @property {(event: 'online'|'offline'|'visible'|'hidden'|'input'|'hashchange', cb: () => void) => () => void} on
 *   returns the unsubscribe function
 * @property {() => string} hash
 * @property {(h: string) => void} setHash
 * @property {() => Promise<{detect(source: any): Promise<{rawValue: string}[]>}|null>} barcodeDetector a QR
 *   detector, or null when the browser has none
 * @property {() => Promise<MediaStream>} camera the back camera; rejects when refused
 * @property {LockManager|null} locks
 * @property {Crypto} crypto
 * @property {(n: number) => Uint8Array} random n random bytes
 * @property {() => string} uuid
 * @property {IDBFactory|null} indexedDB
 * @property {(url: string, init?: RequestInit) => Promise<Response>} fetch
 * @property {CacheStorage|null} caches
 * @property {ServiceWorkerContainer|null} serviceWorker
 * @property {() => string} shellBuild the build the shell was served with (<html data-build>)
 * @property {() => string} scope the Station's absolute URL, ending in a slash
 * @property {() => void} reload
 * @property {Function} setTimeout
 * @property {Function} clearTimeout
 * @property {Function} setInterval
 * @property {Function} clearInterval
 * @property {(line: string) => void} log a console line prefixed [pfpms] (never a person's name)
 * @property {(ms: number) => string} formatTime a date and time in the tablet's own zone and locale
 * @property {Document|null} document
 */

const DISPLAY_MODES = ['standalone', 'fullscreen', 'minimal-ui'];

/**
 * The Env of a browser window.
 * @param {object} [win] the window (globalThis in the page; a plain object in env.test.js)
 * @returns {Env}
 */
export function browserEnv(win = globalThis) {
  const nav = win.navigator ?? {};
  const doc = win.document ?? null;
  const read = (get) => { try { return get() ?? null; } catch { return null; } };
  return {
    now: () => Date.now(),
    mono: () => win.performance.now(),
    displayMode() {
      try {
        if (nav.standalone === true) return 'standalone'; // iPadOS home-screen app
        for (const mode of DISPLAY_MODES) if (win.matchMedia?.(`(display-mode: ${mode})`)?.matches === true) return mode;
      } catch { /* no matchMedia */ }
      return 'browser';
    },
    async persisted() {
      try { return (await nav.storage?.persisted?.()) === true; } catch { return false; }
    },
    async persist() {
      try { return (await nav.storage?.persist?.()) === true; } catch { return false; }
    },
    async estimateKb() {
      try {
        const kb = Math.round((await nav.storage.estimate()).usage / 1024);
        return Number.isFinite(kb) ? kb : null;
      } catch { return null; }
    },
    online: () => nav.onLine !== false,
    visible: () => doc?.visibilityState === 'visible',
    on(event, cb) {
      const handler = () => cb();
      switch (event) {
        case 'online':
        case 'offline':
        case 'hashchange':
          win.addEventListener(event, handler);
          return () => win.removeEventListener(event, handler);
        case 'visible':
        case 'hidden': {
          const change = () => { if ((doc.visibilityState === 'visible') === (event === 'visible')) cb(); };
          doc.addEventListener('visibilitychange', change);
          return () => doc.removeEventListener('visibilitychange', change);
        }
        case 'input': {
          const opts = { capture: true, passive: true };
          doc.addEventListener('pointerdown', handler, opts);
          doc.addEventListener('keydown', handler, opts);
          return () => {
            doc.removeEventListener('pointerdown', handler, opts);
            doc.removeEventListener('keydown', handler, opts);
          };
        }
        default:
          throw new TypeError('env.on: unknown event ' + event);
      }
    },
    hash: () => win.location.hash,
    setHash(h) { win.location.hash = h; },
    async barcodeDetector() {
      try {
        if (!('BarcodeDetector' in win)) return null;
        const formats = await win.BarcodeDetector.getSupportedFormats();
        return Array.isArray(formats) && formats.includes('qr_code') ? new win.BarcodeDetector({ formats: ['qr_code'] }) : null;
      } catch { return null; }
    },
    async camera() {
      return nav.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
    },
    locks: read(() => nav.locks),
    crypto: win.crypto,
    random: (n) => win.crypto.getRandomValues(new Uint8Array(n)),
    uuid: () => win.crypto.randomUUID(),
    indexedDB: read(() => win.indexedDB),
    fetch: (url, init) => win.fetch(url, init),
    caches: read(() => win.caches),
    serviceWorker: read(() => nav.serviceWorker),
    shellBuild: () => doc?.documentElement?.dataset?.build ?? '',
    scope: () => new URL('./', win.location.href).href,
    reload: () => win.location.reload(),
    setTimeout: win.setTimeout.bind(win),
    clearTimeout: win.clearTimeout.bind(win),
    setInterval: win.setInterval.bind(win),
    clearInterval: win.clearInterval.bind(win),
    log: (line) => win.console.info('[pfpms] ' + line),
    formatTime: (ms) => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(ms),
    document: doc,
  };
}
