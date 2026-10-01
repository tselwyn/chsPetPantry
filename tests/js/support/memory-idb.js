// Test support (S2 spec §6.1): an in-memory IDBFactory with the IndexedDB behaviours public/station/js/db.js depends
// on, so every Node suite runs the real db.js. Not a suite (no .test.js suffix). Nothing here uses real time.
//
// What it reproduces (Chromium 152 and the IndexedDB specification):
// - structured clone on put and on get (a non-extractable CryptoKey survives and still signs);
// - in-line (keyPath) and out-of-line keys: a wrong key form throws DataError synchronously; key order is
//   number < Date < string < binary < array (numbers before strings);
// - a write (put, add, delete, clear) in a readonly transaction throws ReadOnlyError synchronously;
// - unique and multiEntry indexes; a duplicate in a unique index is a request error (ConstraintError) that aborts
//   the transaction only when no handler called preventDefault() (db.js's settle() calls it, so db.tx() aborts
//   through its own catch, as in Chromium);
// - atomic transactions: a readwrite transaction writes to working copies, committed on complete and dropped on
//   abort; transactions whose scopes overlap run one after the other (readonly ones may share), in creation order;
// - auto-commit, deliberately stricter than Chromium: a transaction is active in the task that created it and while
//   each of its request events is dispatched, together with the microtasks that follow, and inactive from then on
//   (process.nextTick queued from a microtask runs only after the whole microtask queue, before any other task).
//   Request events are dispatched in setImmediate tasks. A transaction with no pending request commits as soon as it
//   is inactive. So any await between two requests that waits for another task (WebCrypto, a timer, a fetch) lets
//   it commit: then objectStore() throws InvalidStateError ("The transaction has finished."), a request through
//   a store object held from before throws TransactionInactiveError, and a request on an inactive transaction that
//   has not finished yet throws TransactionInactiveError. Only awaits that settle in microtasks keep it alive (as in
//   browsers). Node 24's WebCrypto digest, sign, generateKey, encrypt and deriveBits settle in a later task and trip
//   it; its importKey and exportKey settle within the task, so an await of those alone cannot be seen here;
// - connection.transaction() throws InvalidStateError once close() was called or the connection was force-closed;
// - deleteDatabase() and open() with a higher version fire versionchange at every open connection, then blocked
//   at the request while any of them stays open (a closing connection stays open until its transactions finish),
//   and go on when the last one closes; open and delete requests for one name are processed one at a time, in order
//   (so a repeated deleteDatabase() just waits its turn);
// - close() (no close event), and the test control _forceClose(name), which is what Clear-Site-Data does: it aborts
//   every live transaction, closes every connection, fires close at once (as Chromium's ForceClose() does: the
//   aborted requests' error events and the abort events follow in later tasks) and deletes the database.
//
// Test helpers: _connectionCount(name), _exists(name), _version(name), _dump(name) → {store: [[key, value], …]}
// (committed data, key order, cloned), _log (the factory's events, in order: 'open <name> <version>',
// 'upgrade <name> <old> <new>', 'versionchange <name>' (one per connection told), 'blocked <name>',
// 'close <name>', 'deleteDatabase <name>', 'deleted <name>', 'forceClose <name>', 'complete <name> <stores>',
// 'abort <name> <stores>'), and _errors (exceptions thrown by event handlers; IndexedDB aborts the transaction then).
// Not implemented: key ranges, cursors, autoIncrement and transaction.commit().

const later = (fn) => setImmediate(fn);
/** Runs fn after the current task and every microtask it queued, before any other task. */
const afterTask = (fn) => queueMicrotask(() => process.nextTick(fn));
const dom = (name, message) => new DOMException(message, name);
const MSG = {
  inactive: 'The transaction is not active.',
  finished: 'The transaction has finished.',
  readOnly: 'The transaction is read-only.',
  closing: 'The database connection is closing.',
  aborted: 'The transaction was aborted, so the request cannot be fulfilled.',
  invalidKey: 'The parameter is not a valid key.',
};

// ---- keys -------------------------------------------------------------------------------------------------------

/** 0 = not a valid key; otherwise the type's rank in IndexedDB key order. */
function keyType(k, seen = new Set()) {
  if (typeof k === 'number') return Number.isNaN(k) ? 0 : 1;
  if (k instanceof Date) return Number.isNaN(k.getTime()) ? 0 : 2;
  if (typeof k === 'string') return 3;
  if (k instanceof ArrayBuffer || ArrayBuffer.isView(k)) return 4;
  if (Array.isArray(k)) {
    if (seen.has(k)) return 0;
    seen.add(k);
    const ok = k.every((x) => keyType(x, seen) !== 0);
    seen.delete(k);
    return ok ? 5 : 0;
  }
  return 0;
}
const validKey = (k) => keyType(k) !== 0;
const bytesOf = (k) => (k instanceof ArrayBuffer ? new Uint8Array(k) : new Uint8Array(k.buffer, k.byteOffset, k.byteLength));

function cmp(a, b) {
  const ta = keyType(a);
  const tb = keyType(b);
  if (ta !== tb) return ta < tb ? -1 : 1;
  if (ta === 1 || ta === 3) return a < b ? -1 : a > b ? 1 : 0; // strings: UTF-16 code unit order, as IndexedDB
  if (ta === 2) return cmp(a.getTime(), b.getTime());
  const x = ta === 4 ? bytesOf(a) : a;
  const y = ta === 4 ? bytesOf(b) : b;
  for (let i = 0; i < Math.min(x.length, y.length); i++) {
    const c = cmp(x[i], y[i]);
    if (c !== 0) return c;
  }
  return cmp(x.length, y.length);
}

/** A text that is the same for equal keys and different for different ones (the rows' Map key). */
function encode(k) {
  switch (keyType(k)) {
    case 1: return 'n' + String(k === 0 ? 0 : k);
    case 2: return 'd' + k.getTime();
    case 3: return 's' + k;
    case 4: return 'b' + [...bytesOf(k)].join(',');
    default: return 'a' + JSON.stringify(k.map(encode));
  }
}

function validKeyPath(p) {
  const one = (s) => typeof s === 'string' && (s === '' || s.split('.').every((part) => /^[A-Za-z_$][\w$]*$/.test(part)));
  return Array.isArray(p) ? p.length > 0 && p.every((s) => one(s) && s !== '') : one(p);
}

/** Evaluates a key path on a value: {ok: true, key} or {ok: false}. */
function evaluate(value, keyPath) {
  if (Array.isArray(keyPath)) {
    const out = [];
    for (const p of keyPath) {
      const r = evaluate(value, p);
      if (!r.ok) return r;
      out.push(r.key);
    }
    return { ok: true, key: out };
  }
  if (keyPath === '') return { ok: true, key: value };
  let v = value;
  for (const part of keyPath.split('.')) {
    if (v === null || typeof v !== 'object' || !(part in v)) return { ok: false };
    v = v[part];
  }
  return { ok: true, key: v };
}

/** The keys a record has in an index: none, one, or (multiEntry over an array) each valid element once. */
function indexKeys(idx, value) {
  const r = evaluate(value, idx.keyPath);
  if (!r.ok) return [];
  if (idx.multiEntry && Array.isArray(r.key)) {
    const seen = new Set();
    const out = [];
    for (const x of r.key) {
      if (!validKey(x) || seen.has(encode(x))) continue;
      seen.add(encode(x));
      out.push(x);
    }
    return out;
  }
  return validKey(r.key) ? [r.key] : [];
}

/** getAll()/count() queries: undefined or null → everything, a key → that key (key ranges are not implemented). */
function queryFilter(query) {
  if (query === undefined || query === null) return () => true;
  if (!validKey(query)) throw dom('DataError', MSG.invalidKey);
  return (k) => cmp(k, query) === 0;
}

const sortedRows = (rows) => [...rows.values()].sort((a, b) => cmp(a.key, b.key));
const limit = (list, count) => (count === undefined || count === 0 ? list : list.slice(0, count));

/** A DOMStringList: a sorted array with contains() and item(). */
function stringList(names) {
  const list = [...names].sort();
  return Object.assign(list, { contains: (n) => list.includes(n), item: (i) => list[i] ?? null });
}

// ---- events -----------------------------------------------------------------------------------------------------

class Target {
  constructor() { this._listeners = new Map(); }
  addEventListener(type, fn) {
    if (typeof fn !== 'function') return;
    const list = this._listeners.get(type) ?? [];
    if (!list.includes(fn)) list.push(fn);
    this._listeners.set(type, list);
  }
  removeEventListener(type, fn) {
    const list = this._listeners.get(type);
    if (list) this._listeners.set(type, list.filter((f) => f !== fn));
  }
}

/**
 * Calls on<type> and the listeners of each target of `path` in turn (the target first, then where the event bubbles).
 * @returns {{event: object, threw: unknown}} threw: the first exception a handler threw (also kept in _errors)
 */
function dispatch(factory, path, type, init = {}) {
  const event = {
    type, target: path[0], currentTarget: path[0], bubbles: false, cancelable: false, defaultPrevented: false,
    preventDefault() { if (this.cancelable) this.defaultPrevented = true; },
    stopPropagation() { this._stopped = true; },
    ...init,
  };
  let threw = null;
  for (const t of path) {
    if (!t) continue;
    event.currentTarget = t;
    for (const h of [t['on' + type], ...(t._listeners.get(type) ?? [])]) {
      if (typeof h !== 'function') continue;
      try { h.call(t, event); } catch (e) { if (threw === null) threw = e; factory._errors.push(e); }
    }
    if (event._stopped) break;
  }
  return { event, threw };
}

// ---- requests, stores, indexes ----------------------------------------------------------------------------------

class IdbRequest extends Target {
  constructor(source, transaction) {
    super();
    this.source = source;
    this.transaction = transaction;
    this.readyState = 'pending';
    this.result = undefined;
    this.error = null;
    this.onsuccess = null;
    this.onerror = null;
  }
}

class IdbOpenRequest extends IdbRequest {
  constructor() {
    super(null, null);
    this.onblocked = null;
    this.onupgradeneeded = null;
  }
}

class ObjectStore {
  constructor(tx, name) {
    this.transaction = tx;
    this._name = name;
  }
  get _def() { return this.transaction._data.stores.get(this._name); }
  get name() { return this._name; }
  get keyPath() { return this._def.keyPath; }
  get autoIncrement() { return false; }
  get indexNames() { return stringList(this._def.indexes.keys()); }
  _active() { if (this.transaction._finished || !this.transaction._active) throw dom('TransactionInactiveError', MSG.inactive); }
  _writable() { this._active(); if (this.transaction.mode === 'readonly') throw dom('ReadOnlyError', MSG.readOnly); }
  _rows() { return this.transaction._rows(this._name); }

  put(value, key) { return this._write(value, key, false); }
  add(value, key) { return this._write(value, key, true); }
  _write(value, key, noOverwrite) {
    this._writable();
    const def = this._def;
    if (def.keyPath !== null && key !== undefined) throw dom('DataError', 'The object store uses in-line keys and the key parameter was provided.');
    if (def.keyPath === null && key === undefined) throw dom('DataError', 'The object store uses out-of-line keys and has no key generator and the key parameter was not provided.');
    if (def.keyPath === null && !validKey(key)) throw dom('DataError', MSG.invalidKey);
    const clone = structuredClone(value); // DataCloneError, synchronously, as in a browser
    let k = key;
    if (def.keyPath !== null) {
      const r = evaluate(clone, def.keyPath);
      if (!r.ok || !validKey(r.key)) throw dom('DataError', "Evaluating the object store's key path did not yield a value.");
      k = r.key;
    }
    const stored = structuredClone(k);
    return this.transaction._request(this, () => {
      const rows = this._rows();
      const id = encode(stored);
      if (noOverwrite && rows.has(id)) throw dom('ConstraintError', 'Key already exists in the object store.');
      for (const idx of def.indexes.values()) {
        if (!idx.unique) continue;
        for (const ik of indexKeys(idx, clone)) {
          for (const [otherId, row] of rows) {
            if (otherId !== id && indexKeys(idx, row.value).some((x) => cmp(x, ik) === 0)) {
              throw dom('ConstraintError', `Unable to add key to index '${idx.name}': at least one key does not satisfy the uniqueness requirements.`);
            }
          }
        }
      }
      rows.set(id, { key: stored, value: clone });
      return structuredClone(stored);
    });
  }
  get(key) {
    this._active();
    if (!validKey(key)) throw dom('DataError', 'No key or key range specified.');
    const id = encode(key);
    return this.transaction._request(this, () => {
      const row = this._rows().get(id);
      return row === undefined ? undefined : structuredClone(row.value);
    });
  }
  getAll(query, count) {
    this._active();
    const keep = queryFilter(query);
    return this.transaction._request(this, () => limit(sortedRows(this._rows()).filter((r) => keep(r.key)), count).map((r) => structuredClone(r.value)));
  }
  getAllKeys(query, count) {
    this._active();
    const keep = queryFilter(query);
    return this.transaction._request(this, () => limit(sortedRows(this._rows()).filter((r) => keep(r.key)), count).map((r) => structuredClone(r.key)));
  }
  count(query) {
    this._active();
    const keep = queryFilter(query);
    return this.transaction._request(this, () => [...this._rows().values()].filter((r) => keep(r.key)).length);
  }
  delete(key) {
    this._writable();
    if (!validKey(key)) throw dom('DataError', 'No key or key range specified.');
    const id = encode(key);
    return this.transaction._request(this, () => { this._rows().delete(id); return undefined; });
  }
  clear() {
    this._writable();
    return this.transaction._request(this, () => { this._rows().clear(); return undefined; });
  }
  index(name) {
    if (this.transaction._finished) throw dom('InvalidStateError', MSG.finished);
    const idx = this._def.indexes.get(name);
    if (!idx) throw dom('NotFoundError', 'The specified index was not found.');
    return new Index(this, idx);
  }
  createIndex(name, keyPath, { unique = false, multiEntry = false } = {}) {
    const tx = this.transaction;
    if (tx.mode !== 'versionchange' || tx._finished) throw dom('InvalidStateError', 'The database is not running a version change transaction.');
    this._active();
    const def = this._def;
    if (def.indexes.has(name)) throw dom('ConstraintError', 'An index with the specified name already exists.');
    if (!validKeyPath(keyPath)) throw dom('SyntaxError', 'The keyPath argument contains an invalid key path.');
    if (multiEntry && Array.isArray(keyPath)) throw dom('InvalidAccessError', 'The keyPath argument was an array and the multiEntry option is true.');
    const idx = { name, keyPath, unique: unique === true, multiEntry: multiEntry === true };
    def.indexes.set(name, idx);
    if (idx.unique) {
      const seen = new Set();
      const clash = [...this._rows().values()].some((row) => indexKeys(idx, row.value).some((k) => {
        const e = encode(k);
        if (seen.has(e)) return true;
        seen.add(e);
        return false;
      }));
      if (clash) later(() => { if (!tx._finished) tx._abort(dom('ConstraintError', `Unable to create index '${name}': existing records are not unique.`)); });
    }
    return new Index(this, idx);
  }
  deleteIndex(name) {
    const tx = this.transaction;
    if (tx.mode !== 'versionchange' || tx._finished) throw dom('InvalidStateError', 'The database is not running a version change transaction.');
    this._active();
    if (!this._def.indexes.delete(name)) throw dom('NotFoundError', 'The specified index was not found.');
  }
}

class Index {
  constructor(store, def) {
    this.objectStore = store;
    this._def = def;
  }
  get name() { return this._def.name; }
  get keyPath() { return this._def.keyPath; }
  get unique() { return this._def.unique; }
  get multiEntry() { return this._def.multiEntry; }
  /** [{ik, row}] in index key order, then primary key order. */
  _entries(keep) {
    const out = [];
    for (const row of this.objectStore._rows().values()) for (const ik of indexKeys(this._def, row.value)) if (keep(ik)) out.push({ ik, row });
    return out.sort((a, b) => cmp(a.ik, b.ik) || cmp(a.row.key, b.row.key));
  }
  _read(query, pick) {
    this.objectStore._active();
    const keep = queryFilter(query);
    return this.objectStore.transaction._request(this, () => pick(this._entries(keep)));
  }
  get(key) {
    if (!validKey(key)) throw dom('DataError', 'No key or key range specified.');
    return this._read(key, (e) => (e.length ? structuredClone(e[0].row.value) : undefined));
  }
  getKey(key) {
    if (!validKey(key)) throw dom('DataError', 'No key or key range specified.');
    return this._read(key, (e) => (e.length ? structuredClone(e[0].row.key) : undefined));
  }
  getAll(query, count) { return this._read(query, (e) => limit(e, count).map((x) => structuredClone(x.row.value))); }
  getAllKeys(query, count) { return this._read(query, (e) => limit(e, count).map((x) => structuredClone(x.row.key))); }
  count(query) { return this._read(query, (e) => e.length); }
}

// ---- transactions -----------------------------------------------------------------------------------------------

class Transaction extends Target {
  constructor(factory, conn, names, mode) {
    super();
    this._factory = factory;
    this._conn = conn;
    this._data = conn._data;
    this._names = names;
    this.db = conn;
    this.mode = mode;
    this.error = null;
    this.durability = 'default';
    this.oncomplete = null;
    this.onabort = null;
    this.onerror = null;
    this._active = true;      // active in the task that created it
    this._finished = false;
    this._started = false;     // started: no earlier transaction with an overlapping scope is still running
    this._processing = false;  // a request is queued for dispatch
    this._queue = [];
    this._work = new Map();    // store name → working copy of its rows (readwrite and versionchange)
    this._stores = new Map();
    this._onDone = null;
    this._revert = null;
    this._data.live.push(this);
    conn._live.add(this);
    afterTask(() => this._endOfTask());
  }
  get objectStoreNames() { return stringList(this.mode === 'versionchange' ? this._data.stores.keys() : this._names); }
  objectStore(name) {
    if (this._finished) throw dom('InvalidStateError', MSG.finished);
    const inScope = this.mode === 'versionchange' ? this._data.stores.has(name) : this._names.includes(name);
    if (!inScope) throw dom('NotFoundError', 'The specified object store was not found.');
    if (!this._stores.has(name)) this._stores.set(name, new ObjectStore(this, name));
    return this._stores.get(name);
  }
  abort() {
    if (this._finished) throw dom('InvalidStateError', MSG.finished);
    this._abort(null);
  }
  _rows(name) {
    const base = this._data.stores.get(name).rows;
    if (this.mode === 'readonly') return base;
    if (!this._work.has(name)) this._work.set(name, new Map(base));
    return this._work.get(name);
  }
  _request(source, op) {
    const req = new IdbRequest(source, this);
    this._queue.push({ req, op });
    this._pump();
    return req;
  }
  _overlaps(other) {
    if (this.mode === 'versionchange' || other.mode === 'versionchange') return true;
    return this._names.some((n) => other._names.includes(n));
  }
  _canStart() {
    for (const other of this._data.live) {
      if (other === this) return true;
      if (!other._finished && (this.mode !== 'readonly' || other.mode !== 'readonly') && this._overlaps(other)) return false;
    }
    return true;
  }
  _endOfTask() {
    if (this._finished) return;
    this._active = false;
    this._pump();
  }
  /** Inactive: dispatch the next request in a new task, or commit when none is pending. */
  _pump() {
    if (this._finished || this._active || this._processing) return;
    if (!this._started) {
      if (!this._canStart()) return;
      this._started = true;
    }
    if (this._queue.length > 0) {
      this._processing = true;
      later(() => this._process());
      return;
    }
    this._commit();
  }
  _process() {
    this._processing = false;
    if (this._finished) return;
    const { req, op } = this._queue.shift();
    let result;
    let error = null;
    try { result = op(); } catch (e) { error = e; }
    req.readyState = 'done';
    this._active = true;
    if (error === null) {
      req.result = result;
      const { threw } = dispatch(this._factory, [req], 'success');
      if (threw !== null && !this._finished) this._abort(dom('AbortError', 'A request event handler threw an exception.'));
    } else {
      req.result = undefined;
      req.error = error;
      const { event, threw } = dispatch(this._factory, [req, this, this._conn], 'error', { bubbles: true, cancelable: true });
      if (!this._finished && (threw !== null || !event.defaultPrevented)) this._abort(threw !== null ? dom('AbortError', 'A request event handler threw an exception.') : error);
    }
    if (!this._finished) afterTask(() => this._endOfTask());
  }
  _label() { return `${this._data.name} ${[...this.objectStoreNames].join(',')}`; }
  _commit() {
    const label = this._label();
    this._finished = true;
    this._active = false;
    if (this.mode !== 'readonly') {
      for (const [name, rows] of this._work) {
        const def = this._data.stores.get(name);
        if (def) def.rows = rows;
      }
    }
    this._work.clear();
    this._retire();
    later(() => {
      this._factory._log.push('complete ' + label);
      dispatch(this._factory, [this], 'complete');
      this._onDone?.('complete');
      this._factory._settled(this._data);
    });
  }
  _abort(error) {
    const label = this._label();
    this._finished = true;
    this._active = false;
    this.error = error;
    this._work.clear();
    const pending = this._queue.splice(0);
    for (const { req } of pending) {
      req.readyState = 'done';
      req.result = undefined;
      req.error = dom('AbortError', MSG.aborted);
    }
    this._revert?.();
    this._retire();
    later(() => {
      for (const { req } of pending) dispatch(this._factory, [req, this, this._conn], 'error', { bubbles: true, cancelable: true });
      this._factory._log.push('abort ' + label);
      dispatch(this._factory, [this, this._conn], 'abort', { bubbles: true });
      this._onDone?.('abort');
      this._factory._settled(this._data);
    });
  }
  _retire() {
    const i = this._data.live.indexOf(this);
    if (i >= 0) this._data.live.splice(i, 1);
    this._conn._live.delete(this);
    this._conn._checkClosed();
  }
}

// ---- connections ------------------------------------------------------------------------------------------------

class Connection extends Target {
  constructor(factory, data) {
    super();
    this._factory = factory;
    this._data = data;
    this._version = data.version;
    this._closePending = false; // close() was called, or a forced close
    this._closed = false;       // closed: close pending and every transaction finished
    this._live = new Set();
    this._upgrade = null;
    this.onabort = null;
    this.onclose = null;
    this.onerror = null;
    this.onversionchange = null;
  }
  get name() { return this._data.name; }
  get version() { return this._version; }
  get objectStoreNames() { return stringList(this._data.stores.keys()); }
  transaction(storeNames, mode = 'readonly') {
    if (mode !== 'readonly' && mode !== 'readwrite') {
      throw new TypeError(`Failed to execute 'transaction' on 'IDBDatabase': The provided value '${mode}' is not a valid enum value of type IDBTransactionMode.`);
    }
    if (this._closePending) throw dom('InvalidStateError', MSG.closing);
    if (this._upgrade !== null) throw dom('InvalidStateError', 'A version change transaction is running.');
    const names = [...new Set(typeof storeNames === 'string' ? [storeNames] : [...storeNames])].sort();
    if (names.length === 0) throw dom('InvalidAccessError', 'The storeNames parameter was empty.');
    for (const n of names) if (!this._data.stores.has(n)) throw dom('NotFoundError', 'One of the specified object stores was not found.');
    return new Transaction(this._factory, this, names, mode);
  }
  close() {
    if (this._closePending) return;
    this._closePending = true;
    this._factory._log.push('close ' + this.name);
    this._checkClosed();
  }
  _checkClosed() {
    if (this._closePending && !this._closed && this._live.size === 0) {
      this._closed = true;
      this._factory._recheck(this.name);
    }
  }
  createObjectStore(name, { keyPath = null, autoIncrement = false } = {}) {
    const tx = this._upgradeTx();
    if (this._data.stores.has(name)) throw dom('ConstraintError', 'An object store with the specified name already exists.');
    if (autoIncrement) throw dom('NotSupportedError', 'memory-idb: autoIncrement is not implemented.');
    const path = keyPath ?? null;
    if (path !== null && !validKeyPath(path)) throw dom('SyntaxError', 'The keyPath option is not a valid key path.');
    this._data.stores.set(name, { name, keyPath: path, indexes: new Map(), rows: new Map() });
    return tx.objectStore(name);
  }
  deleteObjectStore(name) {
    this._upgradeTx();
    if (!this._data.stores.delete(name)) throw dom('NotFoundError', 'The specified object store was not found.');
  }
  _upgradeTx() {
    const tx = this._upgrade;
    if (tx === null || tx._finished) throw dom('InvalidStateError', 'The database is not running a version change transaction.');
    if (!tx._active) throw dom('TransactionInactiveError', 'The version change transaction is not active.');
    return tx;
  }
}

// ---- the factory ------------------------------------------------------------------------------------------------

/**
 * A fake IDBFactory: open(name, version), deleteDatabase(name), cmp(a, b), databases(), plus the test controls and
 * helpers described at the top of this file.
 * @returns {object}
 */
export function createMemoryIndexedDB() {
  const dbs = new Map();         // name → {name, version, stores: Map<name, {name, keyPath, indexes, rows}>, live: Transaction[]}
  const connections = new Map(); // name → Set<Connection>
  const queues = new Map();      // name → [{kind: 'open'|'delete', req, version, waiting}]
  const connsOf = (name) => connections.get(name) ?? connections.set(name, new Set()).get(name);
  const openConns = (name) => [...connsOf(name)].filter((c) => !c._closed);
  const queueOf = (name) => queues.get(name) ?? queues.set(name, []).get(name);

  const factory = {
    _log: [],
    _errors: [],

    /**
     * @param {string} name
     * @param {number} [version] an integer ≥ 1; omitted: the current version (1 for a new database)
     * @returns {IdbOpenRequest}
     */
    open(name, version) {
      const n = String(name);
      if (version !== undefined && (!Number.isSafeInteger(version) || version < 1)) {
        throw new TypeError(`Failed to execute 'open' on 'IDBFactory': The optional version provided (${version}) is not a valid integer version.`);
      }
      const req = new IdbOpenRequest();
      factory._log.push(`open ${n} ${version ?? ''}`.trim());
      enqueue(n, { kind: 'open', req, version, waiting: null });
      return req;
    },
    /** @param {string} name @returns {IdbOpenRequest} */
    deleteDatabase(name) {
      const n = String(name);
      const req = new IdbOpenRequest();
      factory._log.push('deleteDatabase ' + n);
      enqueue(n, { kind: 'delete', req, waiting: null });
      return req;
    },
    cmp(a, b) {
      if (!validKey(a) || !validKey(b)) throw dom('DataError', MSG.invalidKey);
      return cmp(a, b);
    },
    databases() {
      return Promise.resolve([...dbs.values()].map((d) => ({ name: d.name, version: d.version })));
    },

    /** What Clear-Site-Data does: aborts live transactions, closes every connection, fires close, deletes the database. */
    _forceClose(name) {
      const n = String(name);
      factory._log.push('forceClose ' + n);
      for (const c of [...connsOf(n)]) {
        if (c._closed) continue;
        c._closePending = true;
        for (const t of [...c._live]) if (!t._finished) t._abort(dom('AbortError', 'The connection was closed.'));
        c._closed = true;
        dispatch(factory, [c], 'close');
      }
      if (dbs.delete(n)) factory._log.push('deleted ' + n);
      factory._recheck(n);
    },
    /** Connections of `name` that are not closed yet (a closing one counts until its transactions finish). */
    _connectionCount: (name) => openConns(String(name)).length,
    _exists: (name) => dbs.has(String(name)),
    _version: (name) => dbs.get(String(name))?.version ?? 0,
    /** {store: [[key, value], …]}: committed data, in key order, cloned. */
    _dump(name) {
      const data = dbs.get(String(name));
      if (!data) return {};
      return Object.fromEntries([...data.stores.keys()].sort().map((s) => [s,
        sortedRows(data.stores.get(s).rows).map((r) => [structuredClone(r.key), structuredClone(r.value)])]));
    },

    // internal: a connection closed, or a transaction finished
    _recheck(name) {
      const op = queueOf(name)[0];
      if (op?.waiting && openConns(name).length === 0) {
        const go = op.waiting;
        op.waiting = null;
        later(go);
      }
    },
    _settled(data) {
      for (const t of [...data.live]) t._pump();
    },
  };

  function enqueue(name, op) {
    const q = queueOf(name);
    q.push(op);
    if (q.length === 1) later(() => runHead(name));
  }
  function finishHead(name) {
    const q = queueOf(name);
    q.shift();
    if (q.length > 0) later(() => runHead(name));
  }
  function runHead(name) {
    const op = queueOf(name)[0];
    if (op) (op.kind === 'open' ? runOpen : runDelete)(name, op);
  }
  function connect(data) {
    const conn = new Connection(factory, data);
    connsOf(data.name).add(conn);
    return conn;
  }
  /** versionchange at every open connection; then blocked at the request while any stays open; then go(). */
  function afterOthersClose(name, op, oldVersion, newVersion, go) {
    for (const c of openConns(name)) {
      if (c._closePending) continue;
      factory._log.push('versionchange ' + name);
      dispatch(factory, [c], 'versionchange', { oldVersion, newVersion });
    }
    if (openConns(name).length === 0) {
      later(go);
      return;
    }
    op.waiting = go;
    later(() => {
      factory._log.push('blocked ' + name);
      dispatch(factory, [op.req], 'blocked', { oldVersion, newVersion });
    });
  }
  function fail(name, req, error) {
    req.readyState = 'done';
    req.result = undefined;
    req.error = error;
    dispatch(factory, [req], 'error', { bubbles: true, cancelable: true });
    finishHead(name);
  }
  function runOpen(name, op) {
    const data = dbs.get(name);
    const old = data?.version ?? 0;
    const version = op.version ?? (old > 0 ? old : 1);
    if (version < old) {
      fail(name, op.req, dom('VersionError', `The requested version (${version}) is less than the existing version (${old}).`));
      return;
    }
    if (version === old) {
      op.req.readyState = 'done';
      op.req.result = connect(data);
      dispatch(factory, [op.req], 'success');
      finishHead(name);
      return;
    }
    afterOthersClose(name, op, old, version, () => runUpgrade(name, op, old, version));
  }
  function runUpgrade(name, op, old, version) {
    let data = dbs.get(name);
    const created = data === undefined;
    if (created) {
      data = { name, version: 0, stores: new Map(), live: [] };
      dbs.set(name, data);
    }
    const saved = new Map([...data.stores].map(([n, s]) => [n, { ...s, indexes: new Map(s.indexes) }]));
    data.version = version;
    const conn = connect(data);
    const tx = new Transaction(factory, conn, [], 'versionchange');
    conn._upgrade = tx;
    tx._revert = () => {
      data.stores = saved;
      data.version = old;
      conn._version = old;
      if (created && dbs.get(name) === data) dbs.delete(name);
    };
    tx._onDone = (how) => {
      conn._upgrade = null;
      op.req.transaction = null;
      if (how === 'complete') {
        later(() => { dispatch(factory, [op.req], 'success'); finishHead(name); });
        return;
      }
      conn._closePending = true;
      conn._closed = true;
      later(() => fail(name, op.req, dom('AbortError', 'The version change transaction was aborted.')));
    };
    op.req.readyState = 'done';
    op.req.result = conn;
    op.req.transaction = tx;
    factory._log.push(`upgrade ${name} ${old} ${version}`);
    const { threw } = dispatch(factory, [op.req], 'upgradeneeded', { oldVersion: old, newVersion: version });
    if (threw !== null && !tx._finished) tx._abort(dom('AbortError', 'The upgradeneeded handler threw an exception.'));
  }
  function runDelete(name, op) {
    const data = dbs.get(name);
    const done = (oldVersion) => {
      op.req.readyState = 'done';
      op.req.result = undefined;
      dispatch(factory, [op.req], 'success', { oldVersion, newVersion: null });
      finishHead(name);
    };
    if (data === undefined) {
      done(0);
      return;
    }
    afterOthersClose(name, op, data.version, null, () => {
      const oldVersion = dbs.get(name)?.version ?? 0;
      if (dbs.delete(name)) factory._log.push('deleted ' + name);
      done(oldVersion);
    });
  }

  return factory;
}
