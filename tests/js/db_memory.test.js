// public/station/js/db.js (50-design §7.3, D-10) over the memory IndexedDB (tests/js/support/memory-idb.js): the
// schema, cloning, keys and indexes, atomic transactions, the auto-commit trap (strict here on purpose: every await
// that is not a request ends the transaction), closing, versionchange, blocked opens and deletes, and forced closes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { DB_NAME, DB_VERSION, STORES, DbClosed, DbTxInactive, openDb, upgrade } from '../../public/station/js/db.js';
import { createMemoryIndexedDB } from './support/memory-idb.js';
import { openMemoryDb } from './support/memory-db.js';
import { fakeEnv, flush } from './support/fake-env.js';
import { fakeFetch } from './support/fake-fetch.js';

const STORE_NAMES = ['meta', 'keyring', 'vault_users', 'sessions', 'outbox', 'drafts', 'pack'];

/** A raw connection through the fake factory: an older page (no versionchange handler), or a look at the schema. */
function rawOpen(idb, version = undefined, name = DB_NAME) {
  return new Promise((resolve, reject) => {
    const r = version === undefined ? idb.open(name) : idb.open(name, version);
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
}
/** A raw request as a promise (preventDefault on error, as db.js does). */
const req = (r) => new Promise((resolve, reject) => {
  r.onsuccess = () => resolve(r.result);
  r.onerror = (e) => { e.preventDefault(); reject(r.error); };
});
const ended = (t) => new Promise((resolve) => { t.oncomplete = () => resolve('complete'); t.onabort = () => resolve('abort'); });
const outboxRecord = (uuid, seq, state = 'queued') => ({ client_uuid: uuid, seq, state, kind: 'station_check', created_at: 1790856000000 });

test('schema v1 creates the seven stores with their key paths and indexes', async () => {
  assert.equal(DB_NAME, 'pfpms');
  assert.equal(DB_VERSION, 1);
  assert.deepEqual(Object.keys(STORES), STORE_NAMES);
  assert.ok(Object.isFrozen(STORES));
  const { idb, db } = await openMemoryDb();
  for (const s of STORE_NAMES) assert.equal(await db.count(s), 0, s);
  db.close();

  const raw = await rawOpen(idb);
  assert.equal(raw.version, 1);
  assert.deepEqual([...raw.objectStoreNames], [...STORE_NAMES].sort());
  const t = raw.transaction(STORE_NAMES, 'readonly');
  const shape = Object.fromEntries(STORE_NAMES.map((name) => {
    const s = t.objectStore(name);
    const indexes = [...s.indexNames].map((i) => {
      const x = s.index(i);
      return { name: x.name, keyPath: x.keyPath, unique: x.unique, multiEntry: x.multiEntry };
    });
    return [name, { keyPath: s.keyPath, indexes }];
  }));
  assert.deepEqual(shape, {
    meta: { keyPath: null, indexes: [] },
    keyring: { keyPath: 'user_id', indexes: [{ name: 'lookup', keyPath: 'lookup', unique: false, multiEntry: true }] },
    vault_users: { keyPath: 'k', indexes: [] },
    sessions: { keyPath: 'k', indexes: [] },
    outbox: { keyPath: 'client_uuid', indexes: [
      { name: 'kind', keyPath: 'kind', unique: false, multiEntry: false },
      { name: 'seq', keyPath: 'seq', unique: true, multiEntry: false },
      { name: 'state', keyPath: 'state', unique: false, multiEntry: false },
    ] },
    drafts: { keyPath: 'k', indexes: [] },
    pack: { keyPath: 'k', indexes: [] },
  });
  raw.close();

  // upgrade() is additive: a database already at version 1 is left alone.
  const calls = [];
  const stub = {
    createObjectStore: (name, options) => {
      calls.push(['store', name, options]);
      return { createIndex: (...a) => calls.push(['index', name, ...a]) };
    },
  };
  upgrade(stub, 1);
  assert.deepEqual(calls, []);
  upgrade(stub, 0);
  assert.deepEqual(calls.filter((c) => c[0] === 'store').map((c) => c[1]), STORE_NAMES);
  assert.deepEqual(calls.find((c) => c[1] === 'meta'), ['store', 'meta', undefined], 'meta has out-of-line keys');
});

test('put and get clone values', async () => {
  const { db } = await openMemoryDb();
  const value = { label: 'Front desk', nested: { list: [1, 2] } };
  await db.put('meta', value, 'thing');
  value.label = 'changed after put';
  value.nested.list.push(3);
  const back = await db.get('meta', 'thing');
  assert.deepEqual(back, { label: 'Front desk', nested: { list: [1, 2] } });
  back.label = 'changed after get';
  assert.equal((await db.get('meta', 'thing')).label, 'Front desk');
  assert.notEqual(await db.get('meta', 'thing'), await db.get('meta', 'thing'), 'every get is a new copy');
  // Mutate a returned object and put it back.
  back.nested.list.push(9);
  await db.put('meta', back, 'thing');
  assert.deepEqual((await db.get('meta', 'thing')).nested.list, [1, 2, 9]);
  // all() and byIndex() clone too.
  await db.put('outbox', outboxRecord('u1', 1));
  const [rec] = await db.all('outbox');
  rec.state = 'held';
  assert.equal((await db.byIndex('outbox', 'state', 'queued')).length, 1);
  // A value that cannot be cloned is refused at once.
  await assert.rejects(db.put('meta', { f: () => 1 }, 'fn'), { name: 'DataCloneError' });
  assert.equal(await db.get('meta', 'missing'), undefined);
});

test('meta takes out-of-line keys and a keyPath store refuses one', async () => {
  const { db } = await openMemoryDb();
  assert.equal(await db.put('meta', { next: 1 }, 'seq'), 'seq');
  assert.equal(await db.put('meta', true, 'clock_rollback_pending'), 'clock_rollback_pending');
  await assert.rejects(db.put('meta', { next: 1 }), { name: 'DataError' }, 'meta needs its key');
  await assert.rejects(db.put('meta', 1, null), { name: 'DataError' }, 'null is not a key');
  await assert.rejects(db.put('meta', 1, { not: 'a key' }), { name: 'DataError' });
  assert.equal(await db.put('keyring', { user_id: 7, lookup: [] }), 7);
  await assert.rejects(db.put('keyring', { user_id: 8, lookup: [] }, 8), { name: 'DataError' }, 'an in-line store refuses a key');
  await assert.rejects(db.put('keyring', { lookup: [] }), { name: 'DataError' }, 'the key path must yield a key');
  await assert.rejects(db.put('outbox', { client_uuid: null, seq: 1 }), { name: 'DataError' });
  await assert.rejects(db.get('meta', undefined), { name: 'DataError' });
  // Key order: numbers before strings.
  await db.put('pack', { k: 'b' });
  await db.put('pack', { k: 10 });
  await db.put('pack', { k: 'a' });
  await db.put('pack', { k: 2 });
  assert.deepEqual(await db.keys('pack'), [2, 10, 'a', 'b']);
  assert.deepEqual((await db.all('pack')).map((r) => r.k), [2, 10, 'a', 'b']);
  assert.deepEqual(await db.keys('meta'), ['clock_rollback_pending', 'seq']);
  await db.delete('pack', 10);
  assert.deepEqual(await db.keys('pack'), [2, 'a', 'b']);
  await db.clear('pack');
  assert.equal(await db.count('pack'), 0);
});

test('a non-extractable CryptoKey survives and still signs', async () => {
  const { idb, db } = await openMemoryDb();
  const raw = crypto.getRandomValues(new Uint8Array(32));
  const key = await crypto.subtle.importKey('raw', raw, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const msg = new TextEncoder().encode('pfpms/v1/proof');
  const before = new Uint8Array(await crypto.subtle.sign('HMAC', key, msg));
  await db.put('meta', { credential: 'pfd1_x', proof: key }, 'device');
  const back = await db.get('meta', 'device');
  assert.ok(back.proof instanceof CryptoKey);
  assert.notEqual(back.proof, key, 'a clone');
  assert.equal(back.proof.extractable, false);
  assert.deepEqual(back.proof.usages, ['sign']);
  await assert.rejects(crypto.subtle.exportKey('raw', back.proof), { name: 'InvalidAccessError' });
  assert.deepEqual(new Uint8Array(await crypto.subtle.sign('HMAC', back.proof, msg)), before);
  // It survives a reopen too (the stored value is what a later page reads).
  db.close();
  const { db: again } = await openMemoryDb({ idb });
  const later = await again.get('meta', 'device');
  assert.deepEqual(new Uint8Array(await crypto.subtle.sign('HMAC', later.proof, msg)), before);
});

test('the multiEntry lookup index finds a record by any element', async () => {
  const { db } = await openMemoryDb();
  await db.put('keyring', { user_id: 13, lookup: ['b', 'c'] });
  await db.put('keyring', { user_id: 12, lookup: ['a', 'b', 'b'] });
  await db.put('keyring', { user_id: 14, lookup: ['d'] });
  await db.put('keyring', { user_id: 15 }); // no lookup: not in the index
  await db.put('keyring', { user_id: 16, lookup: 'e' }); // a single key, not an array
  assert.deepEqual((await db.byIndex('keyring', 'lookup', 'b')).map((r) => r.user_id), [12, 13], 'primary key order');
  assert.deepEqual((await db.byIndex('keyring', 'lookup', 'a')).map((r) => r.user_id), [12]);
  assert.deepEqual((await db.byIndex('keyring', 'lookup', 'e')).map((r) => r.user_id), [16]);
  assert.deepEqual(await db.byIndex('keyring', 'lookup', 'zz'), []);
  assert.equal(await db.count('keyring', 'lookup', 'b'), 2, 'a repeated element counts once');
  assert.equal(await db.count('keyring'), 5);
  // Rewriting a record moves it in the index.
  await db.put('keyring', { user_id: 12, lookup: ['z'] });
  assert.deepEqual((await db.byIndex('keyring', 'lookup', 'b')).map((r) => r.user_id), [13]);
  // The outbox indexes the way device.js counts it.
  await db.put('outbox', outboxRecord('u2', 2, 'conflict'));
  await db.put('outbox', outboxRecord('u1', 1, 'queued'));
  await db.put('outbox', outboxRecord('u3', 3, 'queued'));
  assert.equal(await db.count('outbox', 'state', 'queued'), 2);
  assert.equal(await db.count('outbox', 'state', 'conflict'), 1);
  assert.equal(await db.count('outbox', 'state', 'invalid'), 0);
  assert.deepEqual((await db.byIndex('outbox', 'kind', 'station_check')).map((r) => r.seq), [1, 2, 3]);
});

test('the unique seq index refuses a duplicate and the transaction is aborted', async () => {
  const { idb, db } = await openMemoryDb();
  await db.put('outbox', outboxRecord('u1', 1));
  await assert.rejects(db.put('outbox', outboxRecord('u2', 1)), { name: 'ConstraintError' });
  const mark = idb._log.length;
  await assert.rejects(db.tx(['meta', 'outbox'], 'readwrite', async (t) => {
    await t.put('meta', { next: 3 }, 'seq');
    await t.put('outbox', outboxRecord('u3', 3));
    await t.put('outbox', outboxRecord('u4', 1)); // seq 1 is taken
  }), { name: 'ConstraintError' });
  assert.ok(idb._log.slice(mark).includes('abort pfpms meta,outbox'), JSON.stringify(idb._log.slice(mark)));
  assert.equal(await db.get('meta', 'seq'), undefined, 'the transaction was aborted: nothing it wrote remains');
  assert.deepEqual((await db.all('outbox')).map((r) => r.client_uuid), ['u1']);
  // The same record may keep its own seq.
  await db.put('outbox', { ...outboxRecord('u1', 1), state: 'conflict' });
  assert.equal(await db.count('outbox', 'state', 'conflict'), 1);
});

test('tx is atomic: a throw rolls back every store it touched', async () => {
  const { idb, db } = await openMemoryDb();
  await db.put('meta', { next: 1 }, 'seq');
  await db.put('drafts', { k: 'station:open-entry', data: 'kept' });
  const boom = new Error('boom');
  await assert.rejects(db.tx(['meta', 'outbox', 'drafts'], 'readwrite', async (t) => {
    await t.put('outbox', outboxRecord('u1', 1));
    await t.put('meta', { next: 2 }, 'seq');
    await t.delete('drafts', 'station:open-entry');
    await t.clear('drafts');
    throw boom;
  }), (e) => e === boom);
  assert.deepEqual(await db.get('meta', 'seq'), { next: 1 });
  assert.equal(await db.count('outbox'), 0);
  assert.deepEqual(await db.get('drafts', 'station:open-entry'), { k: 'station:open-entry', data: 'kept' });
  // A synchronous throw and a rejected request roll back the same way (a request fn did not await is aborted).
  let unawaited = null;
  await assert.rejects(db.tx(['meta'], 'readwrite', (t) => {
    unawaited = t.put('meta', 9, 'x');
    unawaited.catch(() => {});
    throw new RangeError('sync');
  }), RangeError);
  await assert.rejects(unawaited, { name: 'AbortError' });
  await assert.rejects(db.tx(['meta'], 'readwrite', async (t) => {
    await t.put('meta', 9, 'x');
    await t.put('meta', 1); // DataError: no key
  }), { name: 'DataError' });
  assert.equal(await db.get('meta', 'x'), undefined);
  assert.deepEqual(idb._dump(DB_NAME).meta, [['seq', { next: 1 }]]);
});

test('tx resolves after complete with fn\'s value', async () => {
  const { idb, db } = await openMemoryDb();
  const mark = idb._log.length;
  const value = await db.tx(['meta'], 'readwrite', async (t) => {
    await t.put('meta', 'v', 'k');
    return { answer: 42 };
  }).then((v) => {
    assert.ok(idb._log.slice(mark).includes('complete pfpms meta'), 'complete fired before tx resolved');
    assert.deepEqual(idb._dump(DB_NAME).meta, [['k', 'v']], 'committed');
    return v;
  });
  assert.deepEqual(value, { answer: 42 });
  assert.equal(await db.tx(['meta'], 'readonly', (t) => t.get('meta', 'k')), 'v');
  assert.equal(await db.tx(['meta'], 'readwrite', () => 'no request at all'), 'no request at all');
  assert.equal(await db.tx(['meta'], 'readonly', async () => undefined), undefined);
});

test('awaiting crypto inside tx is DbTxInactive, and what fn wrote before the await stays committed', async () => {
  const { db } = await openMemoryDb();
  const fetch = fakeFetch().json({ url: 'api/ping.php' }, 200, { ok: true });
  const hmac = await crypto.subtle.importKey('raw', new Uint8Array(32), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const aes = await crypto.subtle.importKey('raw', new Uint8Array(32), 'AES-GCM', false, ['encrypt']);
  const pbkdf2 = await crypto.subtle.importKey('raw', new Uint8Array(8), 'PBKDF2', false, ['deriveBits']);
  // (Node's importKey and exportKey settle within the task, so no fake can see them; Chromium lets them pass too.)
  const waits = [
    ['digest', () => crypto.subtle.digest('SHA-256', new Uint8Array(8))],
    ['hmac generateKey', () => crypto.subtle.generateKey({ name: 'HMAC', hash: 'SHA-256', length: 256 }, false, ['sign'])],
    ['hmac sign', () => crypto.subtle.sign('HMAC', hmac, new Uint8Array(8))],
    ['aes-gcm encrypt', () => crypto.subtle.encrypt({ name: 'AES-GCM', iv: new Uint8Array(12) }, aes, new Uint8Array(8))],
    ['pbkdf2 deriveBits', () => crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: new Uint8Array(16), iterations: 1000 }, pbkdf2, 256)],
    ['setTimeout 0', () => new Promise((resolve) => setTimeout(resolve, 0))],
    ['setImmediate', () => new Promise((resolve) => setImmediate(resolve))],
    ['fetch', () => fetch('../api/ping.php')],
  ];
  for (const [label, wait] of waits) {
    await assert.rejects(db.tx(['meta'], 'readwrite', async (t) => {
      await t.put('meta', 1, `${label}:before`);
      await wait();
      await t.put('meta', 2, `${label}:after`);
    }), (e) => e instanceof DbTxInactive && /^Only IndexedDB requests may be awaited inside db\.tx\(\)/.test(e.message), label);
    assert.equal(await db.get('meta', `${label}:before`), 1, `${label}: the write before the await is committed`);
    assert.equal(await db.get('meta', `${label}:after`), undefined, label);
  }
  // An await before the first request trips it too: the transaction committed with nothing in it.
  await assert.rejects(db.tx(['meta'], 'readwrite', async (t) => {
    await crypto.subtle.digest('SHA-256', new Uint8Array(1));
    await t.put('meta', 3, 'late');
  }), DbTxInactive);
  assert.equal(await db.get('meta', 'late'), undefined);
  // Awaits that settle within the same task (microtasks) do not end it, in browsers either.
  await db.tx(['meta'], 'readwrite', async (t) => {
    await t.put('meta', 1, 'micro');
    await Promise.resolve();
    await null;
    await t.put('meta', 2, 'micro');
  });
  assert.equal(await db.get('meta', 'micro'), 2);
  assert.equal(db.closed(), false);
});

test('a request on a finished transaction is DbTxInactive while the connection is open', async () => {
  const { idb, db } = await openMemoryDb();
  let kept = null;
  await db.tx(['meta'], 'readwrite', async (t) => {
    kept = t;
    await t.put('meta', 1, 'a');
  });
  await assert.rejects(kept.get('meta', 'a'), DbTxInactive);
  await assert.rejects(kept.put('meta', 2, 'a'), DbTxInactive);
  await assert.rejects(kept.count('meta'), DbTxInactive);
  assert.equal(db.closed(), false);
  assert.equal(await db.get('meta', 'a'), 1);

  // The memory IndexedDB gives Chromium's two errors after a commit ...
  const raw = await rawOpen(idb);
  const t = raw.transaction(['meta'], 'readwrite');
  const store = t.objectStore('meta');
  await req(store.put(1, 'x'));
  assert.equal(await ended(t), 'complete');
  assert.throws(() => t.objectStore('meta'), { name: 'InvalidStateError', message: 'The transaction has finished.' });
  assert.throws(() => store.put(2, 'x'), { name: 'TransactionInactiveError' });
  assert.throws(() => t.abort(), { name: 'InvalidStateError' });
  // ... and TransactionInactiveError for a request from another task while one is still pending.
  const t2 = raw.transaction(['meta'], 'readwrite');
  const s2 = t2.objectStore('meta');
  const first = req(s2.put(1, 'y'));
  const fromOtherTask = await new Promise((resolve) => setImmediate(() => {
    try { s2.put(2, 'z'); resolve('accepted'); } catch (e) { resolve(e.name); }
  }));
  assert.equal(fromOtherTask, 'TransactionInactiveError');
  assert.equal(await first, 'y');
  assert.equal(await ended(t2), 'complete');
  raw.close();
});

test('a forced close (_forceClose) during a transaction is DbClosed, never DbTxInactive', async () => {
  // Between two requests (Clear-Site-Data arrives while fn runs): the next t.* call finds the transaction finished.
  const whys = [];
  const { idb, db } = await openMemoryDb({ onClosed: (why) => whys.push(why) });
  await db.put('meta', 'old', 'kept');
  await assert.rejects(db.tx(['meta'], 'readwrite', async (t) => {
    await t.put('meta', 2, 'a');
    idb._forceClose(DB_NAME);
    await t.put('meta', 3, 'b');
  }), (e) => e instanceof DbClosed && !(e instanceof DbTxInactive));
  assert.deepEqual(whys, ['closed']);
  assert.equal(db.closed(), true);
  assert.equal(idb._exists(DB_NAME), false, 'the database is gone');
  assert.equal(idb._connectionCount(DB_NAME), 0);
  await assert.rejects(db.get('meta', 'kept'), DbClosed);

  // While a request is pending: its AbortError arrives after the close event, so it is DbClosed too.
  const second = await openMemoryDb({ idb, onClosed: (why) => whys.push(why) });
  await second.db.put('meta', 1, 'x');
  const running = second.db.tx(['meta'], 'readwrite', async (t) => {
    await t.get('meta', 'x');
    await t.put('meta', 2, 'x');
  });
  idb._forceClose(DB_NAME);
  await assert.rejects(running, (e) => e instanceof DbClosed);
  assert.deepEqual(whys, ['closed', 'closed']);

  // A forced close also ends a transaction that only reads, and a connection is refused new transactions at once.
  const third = await openMemoryDb({ idb });
  const raw = await rawOpen(idb);
  const t = raw.transaction(['meta'], 'readonly');
  const end = ended(t);
  const pending = req(t.objectStore('meta').get('nothing'));
  idb._forceClose(DB_NAME);
  await assert.rejects(pending, { name: 'AbortError' });
  assert.equal(await end, 'abort');
  assert.throws(() => raw.transaction(['meta'], 'readonly'), { name: 'InvalidStateError' });
  assert.equal(third.db.closed(), true);
});

test('awaiting only requests inside tx works (meta.seq read-modify-write)', async () => {
  const { db } = await openMemoryDb();
  await db.put('meta', { next: 1 }, 'seq');
  const take = () => db.tx(['meta', 'outbox'], 'readwrite', async (t) => {
    const seq = await t.get('meta', 'seq');
    await t.put('outbox', outboxRecord(`u${seq.next}`, seq.next));
    await t.put('meta', { next: seq.next + 1 }, 'seq');
    return seq.next;
  });
  // Three at once: transactions on the same stores run one after the other, so the numbers never repeat.
  assert.deepEqual(await Promise.all([take(), take(), take()]), [1, 2, 3]);
  assert.deepEqual(await db.get('meta', 'seq'), { next: 4 });
  assert.deepEqual((await db.all('outbox')).map((r) => r.seq), [1, 2, 3]);
  // Several requests in flight at once inside one transaction.
  const n = await db.tx(['meta', 'outbox'], 'readwrite', async (t) => {
    await Promise.all([t.put('outbox', outboxRecord('u7', 7)), t.put('outbox', outboxRecord('u8', 8))]);
    const count = await t.count('outbox');
    await t.put('meta', count, 'outbox_count');
    return count;
  });
  assert.equal(n, 5);
  assert.equal(await db.get('meta', 'outbox_count'), 5);
  // A readonly reader sees only committed data.
  const [inside, outside] = await Promise.all([
    db.tx(['meta'], 'readwrite', async (t) => { await t.put('meta', 'new', 'r'); return t.get('meta', 'r'); }),
    db.get('meta', 'r'),
  ]);
  assert.equal(inside, 'new');
  assert.equal(outside, 'new', 'the later reader waited for the writer');
});

test('a write in a readonly transaction is ReadOnlyError and tx without a mode is a TypeError', async () => {
  const { db } = await openMemoryDb();
  await db.put('meta', 'v', 'x');
  await assert.rejects(db.tx(['meta'], 'readonly', (t) => t.put('meta', 1, 'x')), { name: 'ReadOnlyError' });
  await assert.rejects(db.tx(['meta'], 'readonly', (t) => t.delete('meta', 'x')), { name: 'ReadOnlyError' });
  await assert.rejects(db.tx(['meta'], 'readonly', (t) => t.clear('meta')), { name: 'ReadOnlyError' });
  await assert.rejects(db.tx(['meta'], 'readonly', async (t) => {
    await t.get('meta', 'x');
    await t.put('meta', 2, 'x');
  }), { name: 'ReadOnlyError' });
  assert.equal(await db.get('meta', 'x'), 'v');
  for (const mode of [undefined, null, 'versionchange', 'readWrite']) {
    await assert.rejects(db.tx(['meta'], mode, (t) => t.get('meta', 'x')), TypeError, String(mode));
  }
  await assert.rejects(db.tx(['nope'], 'readonly', (t) => t.get('nope', 1)), { name: 'NotFoundError' });
  assert.equal(db.closed(), false);
});

test('a closed adapter throws DbClosed from every method', async () => {
  const { db } = await openMemoryDb();
  await db.put('meta', 'v', 'k');
  db.close();
  assert.equal(db.closed(), true);
  const calls = {
    get: () => db.get('meta', 'k'),
    put: () => db.put('meta', 'w', 'k'),
    delete: () => db.delete('meta', 'k'),
    all: () => db.all('meta'),
    keys: () => db.keys('meta'),
    byIndex: () => db.byIndex('keyring', 'lookup', 'a'),
    count: () => db.count('outbox', 'state', 'queued'),
    clear: () => db.clear('meta'),
    tx: () => db.tx(['meta'], 'readonly', (t) => t.get('meta', 'k')),
  };
  for (const [name, call] of Object.entries(calls)) await assert.rejects(call(), DbClosed, name);
  db.close(); // idempotent
  assert.equal(db.closed(), true);
});

test('versionchange from another connection closes this one (onClosed versionchange)', async () => {
  const env = fakeEnv();
  const idb = createMemoryIndexedDB();
  const whys = [];
  const a = await openDb(idb, { onClosed: (why) => whys.push('a:' + why) });
  const b = await openDb(idb, { onClosed: (why) => whys.push('b:' + why) });
  await a.put('meta', 'v', 'k');
  let blocked = 0;
  let deleted = false;
  const deleting = b.destroy({ onBlocked: () => { blocked += 1; }, timers: env }).then(() => { deleted = true; });
  await flush(20);
  assert.equal(blocked, 0, 'the other connection closed itself: nothing blocks');
  assert.deepEqual(whys, ['b:close', 'a:versionchange']);
  assert.equal(deleted, true);
  await deleting;
  assert.equal(a.closed(), true);
  await assert.rejects(a.get('meta', 'k'), DbClosed);
  assert.equal(idb._exists(DB_NAME), false);
  assert.equal(idb._connectionCount(DB_NAME), 0);
});

test('close() and destroy() report onClosed(\'close\')', async () => {
  const env = fakeEnv();
  const whys = [];
  const { idb, db } = await openMemoryDb({ onClosed: (why) => whys.push(why) });
  db.close();
  db.close();
  assert.deepEqual(whys, ['close'], 'once');
  assert.equal(idb._connectionCount(DB_NAME), 0);
  assert.equal(idb._exists(DB_NAME), true, 'close() keeps the data');

  const again = await openMemoryDb({ idb, onClosed: (why) => whys.push(why) });
  await again.db.destroy({ timers: env });
  assert.deepEqual(whys, ['close', 'close']);
  assert.equal(again.db.closed(), true);
  assert.equal(idb._exists(DB_NAME), false);
  // destroy() on an adapter that is already closed still deletes, and reports nothing more.
  const third = await openMemoryDb({ idb, onClosed: (why) => whys.push(why) });
  third.db.close();
  await third.db.destroy({ timers: env });
  assert.deepEqual(whys, ['close', 'close', 'close']);
  assert.equal(idb._exists(DB_NAME), false);
});

test('openDb at a higher version while an old connection stays open calls onBlocked, and opens once it closes', async () => {
  const idb = createMemoryIndexedDB();
  const whys = [];
  const current = await openDb(idb, { onClosed: (why) => whys.push(why) });
  await current.put('meta', { next: 4 }, 'seq');
  // An older page's connection, without db.js's versionchange handler: it hears the event but stays open.
  const old = await rawOpen(idb, 1);
  const heard = [];
  old.onversionchange = (e) => heard.push([e.oldVersion, e.newVersion]);
  // P3's openDb at version 2, seen through a factory that asks for one version more.
  const next = { open: (name, version) => idb.open(name, version + 1), deleteDatabase: (name) => idb.deleteDatabase(name) };
  let blocked = 0;
  let opened = null;
  const opening = openDb(next, { onBlocked: () => { blocked += 1; } }).then((db) => { opened = db; return db; });
  await flush(20);
  assert.deepEqual(whys, ['versionchange'], 'a db.js connection closes itself on versionchange');
  assert.deepEqual(heard, [[1, 2]]);
  assert.equal(blocked, 1);
  assert.equal(opened, null, 'the open waits for the old connection');
  assert.equal(idb._connectionCount(DB_NAME), 1);
  assert.ok(idb._log.includes('blocked pfpms'));
  old.close();
  const v2 = await opening;
  assert.equal(idb._version(DB_NAME), 2);
  assert.deepEqual(await v2.get('meta', 'seq'), { next: 4 }, 'upgrade() is additive: the data stays');
  assert.equal(await v2.count('outbox'), 0);
  // A lower version than the database's is refused.
  await assert.rejects(openDb(idb), { name: 'VersionError' });
});

test('destroy() closes its own connection before it asks for the delete', async () => {
  const env = fakeEnv();
  const whys = [];
  const { idb, db } = await openMemoryDb({ onClosed: (why) => whys.push(why) });
  await db.put('meta', 'v', 'k');
  const mark = idb._log.length;
  let blocked = 0;
  await db.destroy({ onBlocked: () => { blocked += 1; }, timers: env });
  const log = idb._log.slice(mark);
  assert.ok(log.indexOf('close pfpms') >= 0, JSON.stringify(log));
  assert.ok(log.indexOf('close pfpms') < log.indexOf('deleteDatabase pfpms'), JSON.stringify(log));
  assert.ok(!log.includes('versionchange pfpms'), 'it never had to be told to close');
  assert.ok(log.includes('deleted pfpms'));
  assert.deepEqual(whys, ['close']);
  assert.equal(blocked, 0);
  assert.equal(idb._exists(DB_NAME), false);
});

test('a connection with no versionchange handler blocks the delete; onBlocked runs and the retry completes after it closes', async () => {
  const env = fakeEnv();
  const { idb, db } = await openMemoryDb();
  const other = await rawOpen(idb, 1); // another window of an older build: no handler
  let blocked = 0;
  let done = false;
  const destroying = db.destroy({ onBlocked: () => { blocked += 1; }, retryMs: 2000, timers: env }).then(() => { done = true; });
  await flush(20);
  assert.equal(blocked, 1);
  assert.equal(done, false);
  assert.equal(env.pendingTimers(), 1, 'a retry is scheduled');
  await env.advance(2000);
  assert.equal(idb._log.filter((l) => l === 'deleteDatabase pfpms').length, 2, 'asked again after 2 s');
  assert.equal(done, false);
  assert.equal(idb._exists(DB_NAME), true);
  other.close();
  await destroying;
  assert.equal(idb._exists(DB_NAME), false);
  assert.equal(env.pendingTimers(), 0, 'no retry left');
  await flush(20); // the queued second request finds nothing to delete
  assert.equal(idb._exists(DB_NAME), false);
});

test('reopening an existing v1 database keeps its data', async () => {
  const { idb, db } = await openMemoryDb();
  await db.put('meta', { next: 5 }, 'seq');
  await db.put('outbox', outboxRecord('u1', 1));
  await db.put('keyring', { user_id: 3, lookup: ['x'] });
  db.close();
  const mark = idb._log.length;
  const { db: again } = await openMemoryDb({ idb });
  assert.ok(!idb._log.slice(mark).some((l) => l.startsWith('upgrade')), 'no upgrade on a reopen');
  assert.deepEqual(await again.get('meta', 'seq'), { next: 5 });
  assert.equal(await again.count('outbox', 'state', 'queued'), 1);
  assert.deepEqual((await again.byIndex('keyring', 'lookup', 'x')).map((r) => r.user_id), [3]);
  assert.deepEqual(Object.keys(idb._dump(DB_NAME)), [...STORE_NAMES].sort());
  assert.deepEqual(idb._dump(DB_NAME).meta, [['seq', { next: 5 }]]);
});
