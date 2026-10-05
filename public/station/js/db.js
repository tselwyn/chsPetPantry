// The Station's IndexedDB adapter and schema v1 (docs/design/50-design-station.md §7.3, D-10). It works on the
// IDBFactory it is given (the page's, from app.js; the memory IndexedDB in the Node suites), so the real code is
// what the tests run.
//
// The auto-commit trap (the reason this wrapper exists): inside tx(), await ONLY t.* calls. Crypto, network, timers
// and other awaits go before or after. Once control returns to the event loop with no request pending, a transaction
// commits by itself; a later t.* call then fails, and is rethrown as DbTxInactive. What fn wrote before that await is
// already committed, so a multi-step write that trips the trap is left half done. Which awaits trip it depends on the
// engine (Chromium finishes short WebCrypto calls inside a transaction, but not PBKDF2, timers or fetch; WebKit may
// differ): the memory IndexedDB of the Node suites trips it on every await that is not a request, on purpose.

/** The database name. */
export const DB_NAME = 'pfpms';
/** Schema version 1: the seven stores below. */
export const DB_VERSION = 1;
/** Every store of schema v1, with its key path (null: out-of-line string keys) and indexes. */
export const STORES = Object.freeze({
  meta: { keyPath: null, indexes: [] },
  keyring: { keyPath: 'user_id', indexes: [{ name: 'lookup', keyPath: 'lookup', unique: false, multiEntry: true }] },
  vault_users: { keyPath: 'k', indexes: [] },
  sessions: { keyPath: 'k', indexes: [] },
  outbox: { keyPath: 'client_uuid', indexes: [{ name: 'seq', keyPath: 'seq', unique: true, multiEntry: false },
    { name: 'state', keyPath: 'state', unique: false, multiEntry: false }, { name: 'kind', keyPath: 'kind', unique: false, multiEntry: false }] },
  drafts: { keyPath: 'k', indexes: [] },
  pack: { keyPath: 'k', indexes: [] },
});

/** The connection went away (closed by this page, another window's delete or upgrade, or storage cleared). */
export class DbClosed extends Error {
  constructor() { super('The Station database is closed.'); this.name = 'DbClosed'; }
}
/** The auto-commit trap: a t.* call after a non-request await inside db.tx() (see the top of this file). */
export class DbTxInactive extends Error {
  constructor() { super('Only IndexedDB requests may be awaited inside db.tx(): do crypto and network work before or after it.'); this.name = 'DbTxInactive'; }
}

/**
 * Additive only: a later version adds stores and indexes, and never rewrites outbox, sessions or meta.
 * @param {IDBDatabase} db the connection being upgraded (inside onupgradeneeded)
 * @param {number} oldVersion 0 for a new database
 */
export function upgrade(db, oldVersion) {
  if (oldVersion < 1) {
    for (const [name, s] of Object.entries(STORES)) {
      const store = db.createObjectStore(name, s.keyPath === null ? undefined : { keyPath: s.keyPath });
      for (const i of s.indexes) store.createIndex(i.name, i.keyPath, { unique: i.unique, multiEntry: i.multiEntry });
    }
  }
}

const settle = (request) => new Promise((resolve, reject) => {
  request.onsuccess = () => resolve(request.result);
  request.onerror = (e) => { e?.preventDefault?.(); reject(request.error); };
});

/**
 * @typedef {object} DbOps the eight data methods; inside tx() they make requests on that one transaction
 * @property {(store: string, key: IDBValidKey) => Promise<any>} get resolves undefined when there is no record
 * @property {(store: string, value: any, key?: IDBValidKey) => Promise<IDBValidKey>} put the key is required for
 *   meta and forbidden for the keyPath stores (DataError); the value is cloned
 * @property {(store: string, key: IDBValidKey) => Promise<void>} delete
 * @property {(store: string) => Promise<any[]>} all in key order
 * @property {(store: string) => Promise<IDBValidKey[]>} keys in key order
 * @property {(store: string, index: string, value: IDBValidKey) => Promise<any[]>} byIndex a multiEntry index
 *   matches any element
 * @property {(store: string, index?: string|null, value?: IDBValidKey) => Promise<number>} count
 * @property {(store: string) => Promise<void>} clear
 */
/**
 * @typedef {DbOps & {
 *   tx: <T>(stores: string[], mode: 'readonly'|'readwrite', fn: (t: DbOps) => T|Promise<T>) => Promise<T>,
 *   close: () => void,
 *   closed: () => boolean,
 *   destroy: (options?: {onBlocked?: () => void, retryMs?: number, timers?: object}) => Promise<void>,
 * }} Db
 * tx() resolves with fn's value after complete; if fn throws or rejects while the transaction is alive, it is aborted
 * and nothing it wrote remains (the trap is the exception, see the top of this file). Every method of a closed
 * adapter throws DbClosed; close() is idempotent; destroy() closes this connection first, then deletes the database.
 */

/**
 * Opens (and on first use creates) the database.
 * @param {IDBFactory} idb
 * @param {{name?: string, onBlocked?: () => void, onClosed?: (why: 'versionchange'|'closed'|'close') => void}} [options]
 *   onBlocked: another window keeps an older connection open (the app shows close_other_window; the open keeps
 *   waiting); onClosed: 'versionchange' (another window deletes or upgrades), 'closed' (storage cleared under the
 *   page) or 'close' (this page's own close() or destroy(), never "storage was cleared")
 * @returns {Promise<Db>} rejects with the request's DOMException
 */
export function openDb(idb, { name = DB_NAME, onBlocked = () => {}, onClosed = () => {} } = {}) {
  return new Promise((resolve, reject) => {
    const request = idb.open(name, DB_VERSION);
    request.onupgradeneeded = (e) => upgrade(request.result, e.oldVersion);
    request.onblocked = () => onBlocked();
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(adapter(idb, request.result, name, onClosed));
  });
}

/**
 * Deletes the database. While another connection blocks it, calls onBlocked() and asks again every retryMs (the
 * queued requests are harmless); resolves on the first success.
 * @param {IDBFactory} idb
 * @param {{name?: string, onBlocked?: () => void, retryMs?: number, timers?: {setTimeout: Function, clearTimeout: Function}}} [options]
 * @returns {Promise<void>}
 */
export function deleteDb(idb, { name = DB_NAME, onBlocked = () => {}, retryMs = 2000, timers = globalThis } = {}) {
  return new Promise((resolve, reject) => {
    let done = false;
    let timer = null;
    const attempt = () => {
      const r = idb.deleteDatabase(name);
      r.onsuccess = () => { if (!done) { done = true; if (timer) timers.clearTimeout(timer); resolve(); } };
      r.onerror = () => { if (!done) { done = true; if (timer) timers.clearTimeout(timer); reject(r.error); } };
      r.onblocked = () => {
        onBlocked();
        if (!timer && !done) timer = timers.setTimeout(() => { timer = null; if (!done) attempt(); }, retryMs);
      };
    };
    attempt();
  });
}

function adapter(idb, db, name, onClosed) {
  let closed = false;
  const markClosed = (why) => { if (!closed) { closed = true; onClosed(why); } };
  db.onversionchange = () => { db.close(); markClosed('versionchange'); };
  db.onclose = () => markClosed('closed');
  // A closing connection refuses new transactions synchronously (InvalidStateError), even before its close event.
  const stillOpen = () => {
    if (closed) return false;
    try { db.transaction(['meta'], 'readonly').abort(); return true; } catch { return false; }
  };
  // Errors from db.transaction(): InvalidStateError means the connection is closing.
  const guard = (e) => (closed || e?.name === 'InvalidStateError' ? new DbClosed() : e);
  // Errors from a t.* call: a finished or inactive transaction on an open connection is the auto-commit trap.
  const guardInTx = (e) => {
    if (e?.name === 'InvalidStateError' || e?.name === 'TransactionInactiveError') return stillOpen() ? new DbTxInactive() : new DbClosed();
    if (e instanceof DbTxInactive || e instanceof DbClosed) return e;
    return closed ? new DbClosed() : e;
  };
  const ops = (t) => ({
    get: (store, key) => settle(t.objectStore(store).get(key)),
    put: (store, value, key) => settle(key === undefined ? t.objectStore(store).put(value) : t.objectStore(store).put(value, key)),
    delete: (store, key) => settle(t.objectStore(store).delete(key)).then(() => undefined),
    all: (store) => settle(t.objectStore(store).getAll()),
    keys: (store) => settle(t.objectStore(store).getAllKeys()),
    byIndex: (store, index, value) => settle(t.objectStore(store).index(index).getAll(value)),
    count: (store, index = null, value = undefined) => settle(index === null ? t.objectStore(store).count() : t.objectStore(store).index(index).count(value)),
    clear: (store) => settle(t.objectStore(store).clear()).then(() => undefined),
  });
  const wrap = (t) => Object.fromEntries(Object.entries(ops(t)).map(([k, f]) => [k, (...a) => {
    try { return f(...a).catch((e) => { throw guardInTx(e); }); } catch (e) { return Promise.reject(guardInTx(e)); }
  }]));
  async function tx(stores, mode, fn) {
    if (closed) throw new DbClosed();
    if (mode !== 'readonly' && mode !== 'readwrite') throw new TypeError('db.tx: mode must be readonly or readwrite');
    let t;
    try { t = db.transaction(stores, mode); } catch (e) { throw guard(e); }
    const done = new Promise((resolve, reject) => {
      t.oncomplete = () => resolve();
      t.onabort = () => reject(guard(t.error) ?? new DOMException('The transaction was aborted.', 'AbortError'));
    });
    done.catch(() => {});
    let result;
    try {
      result = await fn(wrap(t));
    } catch (e) {
      try { t.abort(); } catch { /* already finished (the auto-commit trap: what fn wrote before it is committed) */ }
      await done.catch(() => {});
      throw guardInTx(e);
    }
    await done;
    return result;
  }
  return {
    get: (s, k) => tx([s], 'readonly', (t) => t.get(s, k)),
    put: (s, v, k) => tx([s], 'readwrite', (t) => t.put(s, v, k)),
    delete: (s, k) => tx([s], 'readwrite', (t) => t.delete(s, k)),
    all: (s) => tx([s], 'readonly', (t) => t.all(s)),
    keys: (s) => tx([s], 'readonly', (t) => t.keys(s)),
    byIndex: (s, i, v) => tx([s], 'readonly', (t) => t.byIndex(s, i, v)),
    count: (s, i = null, v = undefined) => tx([s], 'readonly', (t) => t.count(s, i, v)),
    clear: (s) => tx([s], 'readwrite', (t) => t.clear(s)),
    tx,
    close: () => { if (!closed) { db.close(); markClosed('close'); } },
    closed: () => closed,
    destroy: async ({ onBlocked = () => {}, retryMs = 2000, timers = globalThis } = {}) => {
      if (!closed) { db.close(); markClosed('close'); }
      await deleteDb(idb, { name, onBlocked, retryMs, timers });
    },
  };
}
