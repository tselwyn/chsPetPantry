// Test support (S2 spec §6.1): a fake Web Locks LockManager (navigator.locks) shared by the windows of one tablet.
// Not a suite (no .test.js suffix). Exclusive locks only, which is all app.js asks for.
//
// request(name, options, callback), as in the Web Locks API:
// - the default: the callback runs (in a later microtask) once the lock is free, queued in request order; the lock is
//   held until the promise the callback returned settles, and request() settles like that promise;
// - ifAvailable: when the lock is held (or others wait for it), the callback runs at once with null;
// - steal: the current holder loses the lock at once (its request() promise rejects with AbortError, whatever its
//   callback still does) and the stealer's callback runs at once; queued requests stay queued.

const aborted = () => new DOMException('The lock request was aborted.', 'AbortError');

/**
 * @returns {{request: (name: string, options: object|Function, callback?: Function) => Promise<any>, held: (name: string) => boolean,
 *   waiting: (name: string) => number, grants: string[]}}
 *   held(name): the lock is held now; waiting(name): requests queued for it; grants: 'grant <name>', 'steal <name>' and
 *   'null <name>' (an ifAvailable answer) in order
 */
export function fakeLockManager() {
  const table = new Map(); // name → {holder: entry|null, queue: entry[]}
  const grants = [];
  const slot = (name) => table.get(name) ?? table.set(name, { holder: null, queue: [] }).get(name);
  const settle = (entry, ok, value) => {
    if (entry.settled) return;
    entry.settled = true;
    if (ok) entry.resolve(value); else entry.reject(value);
  };

  function release(name, entry) {
    const s = slot(name);
    if (s.holder !== entry) return; // stolen meanwhile: the stealer holds it now
    s.holder = null;
    const next = s.queue.shift();
    if (next) grant(name, next);
  }

  function grant(name, entry) {
    slot(name).holder = entry;
    grants.push('grant ' + name);
    Promise.resolve()
      .then(() => entry.callback({ name, mode: 'exclusive' }))
      .then((v) => { release(name, entry); settle(entry, true, v); }, (e) => { release(name, entry); settle(entry, false, e); });
  }

  return {
    grants,
    request(name, options, callback) {
      const opts = typeof options === 'function' ? {} : (options ?? {});
      const cb = typeof options === 'function' ? options : callback;
      if (typeof cb !== 'function') return Promise.reject(new TypeError('fake-locks: request needs a callback'));
      if (opts.steal && opts.ifAvailable) return Promise.reject(new DOMException('steal and ifAvailable cannot be combined.', 'NotSupportedError'));
      return new Promise((resolve, reject) => {
        const entry = { callback: cb, resolve, reject, settled: false };
        const s = slot(String(name));
        if (opts.steal) {
          const old = s.holder;
          s.holder = null;
          if (old) settle(old, false, aborted());
          grants.push('steal ' + name);
          grant(String(name), entry);
          return;
        }
        if (opts.ifAvailable && (s.holder !== null || s.queue.length > 0)) {
          grants.push('null ' + name);
          Promise.resolve().then(() => cb(null)).then((v) => settle(entry, true, v), (e) => settle(entry, false, e));
          return;
        }
        if (s.holder === null) grant(String(name), entry); else s.queue.push(entry);
      });
    },
    held: (name) => slot(String(name)).holder !== null,
    waiting: (name) => slot(String(name)).queue.length,
  };
}
