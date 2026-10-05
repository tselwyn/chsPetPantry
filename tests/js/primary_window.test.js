// Two windows on one tablet (50-design X-2; S2 spec §3.17 steps 3 and 12): the 'pfpms-primary' Web Lock. A second
// window shows "open in another window" and never opens the database; "Use this window here" steals the lock; the
// window that lost it closes its database, stops its timers, forgets its credentials, shows "replaced" and never
// reloads. Two app.js instances share one memory IndexedDB and one fake LockManager, each with its own page.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeEnv, flush } from './support/fake-env.js';
import { fakeFetch } from './support/fake-fetch.js';
import { createMemoryIndexedDB } from './support/memory-idb.js';
import { openMemoryDb } from './support/memory-db.js';
import { fakeDocument, text } from './support/fake-dom.js';
import { fakeLockManager } from './support/fake-locks.js';
import { fakeContainer, fakeRegistration, fakeWorker } from './support/fake-sw.js';
import { importProofKey } from '../../public/station/js/proof.js';
import { HEARTBEAT_MS, TICK_MS, claimPrimary, start } from '../../public/station/js/app.js';
import { STUCK_RETRY_MS } from '../../public/station/js/device.js';
import { COPY } from '../../public/station/js/copy.js';

const CRED = 'pfd1_' + 'P'.repeat(43);
const SERVER_TIME = '2026-10-01 12:00:00.000';
const HEARTBEAT = 'api/device/heartbeat.php';
const okHeartbeat = { status: 'ok', offline_enabled: true, label: 'E2E 1', site: { site_id: 3, name: 'Dev Site North' }, directive: null,
  revoked_grants: [], config: {}, server_time: SERVER_TIME, build: 'test+0000000000' };

async function until(pred, ms = 10000) {
  const end = Date.now() + ms;
  for (;;) {
    if (await pred()) return;
    if (Date.now() > end) throw new Error('until: the condition never held');
    await new Promise((resolve) => setImmediate(resolve));
  }
}

async function seedRegistered(idb) {
  const { db } = await openMemoryDb({ idb });
  await db.put('meta', { device_id: 7, credential: CRED, proof: await importProofKey(globalThis.crypto, new Uint8Array(32).fill(9)), site_id: 3,
    site_name: 'Dev Site North', label: 'E2E 1', iterations: 600000, registered_at: SERVER_TIME }, 'device');
  await db.put('meta', { next: 1 }, 'seq');
  db.close();
}

/**
 * One browser window of the tablet: its own page, network log and timers; the tablet's IndexedDB and locks.
 * session(f) / heartbeat(f) set up replies of their own first (heartbeat replaces the default ok answers).
 */
function windowOf({ idb, locks, registration = null, session = null, heartbeat = null }) {
  const doc = fakeDocument();
  const f = fakeFetch();
  session?.(f);
  f.json({ url: 'api/session.php' }, 200, { csrf: 'c', organisation_name: 'CHS Pet Pantry', server_time: SERVER_TIME, build: 'test+0000000000', dev_relax: false },
    {}, { times: Infinity });
  if (heartbeat) heartbeat(f); else f.json({ url: HEARTBEAT }, 200, okHeartbeat, {}, { times: Infinity });
  const serviceWorker = registration ? fakeContainer({ controller: fakeWorker('activated'), registration }) : null;
  const env = fakeEnv({ indexedDB: idb, locks, document: doc, fetch: f, serviceWorker });
  const sessionCalls = [];
  const createSession = () => ({ state: () => 'LOCKED', lock: (...a) => sessionCalls.push(a), onRevokedGrants() {}, onOfflineDisabled() {}, tick() {}, touch() {},
    hasVaultKey: () => false, on() {} });
  const boot = { registration, controlledAtLoad: registration !== null, started: () => true, repair: async () => 'repaired' };
  return { doc, f, env, root: doc.getElementById('app'), boot, createSession, sessionCalls,
    start() { return start(this.boot, { env, createSession }); } };
}

test('the first window holds pfpms-primary and a second is busy', async () => {
  const locks = fakeLockManager();
  assert.equal(await claimPrimary(locks), 'held');
  assert.equal(locks.held('pfpms-primary'), true);
  assert.equal(await claimPrimary(locks), 'busy');
  assert.equal(locks.held('pfpms-primary'), true, 'the first keeps it');

  const idb = createMemoryIndexedDB();
  const tabletLocks = fakeLockManager();
  const a = windowOf({ idb, locks: tabletLocks });
  const b = windowOf({ idb, locks: tabletLocks });
  const appA = await a.start();
  assert.equal(appA.state(), 'UNREGISTERED');
  const appB = await b.start();
  assert.equal(appB.state(), 'ELSEWHERE');
  assert.equal(text(b.root.querySelector('h1')), COPY.elsewhere);
  assert.equal(text(b.root.querySelector('section button')), COPY.use_here);
  assert.equal(b.env.hash(), '#/elsewhere');
  assert.equal(b.root.querySelector('a.link'), null, 'no About link in another window');
  assert.equal(b.root.querySelector('span.chip'), null, 'no connection chip: this window never asks the server');
  assert.equal(text(a.root.querySelector('span.chip')), COPY.online, 'the primary window has one');
});

test('Use this window here steals it and the first window\'s onLost runs', async () => {
  const locks = fakeLockManager();
  const lost = [];
  assert.equal(await claimPrimary(locks, { onLost: () => lost.push('first') }), 'held');
  assert.equal(await claimPrimary(locks, { steal: true, onLost: () => lost.push('second') }), 'held');
  await flush();
  assert.deepEqual(lost, ['first']);
  assert.equal(locks.held('pfpms-primary'), true);
  assert.equal(await claimPrimary(locks), 'busy', 'the stealer holds it now');

  const idb = createMemoryIndexedDB();
  await seedRegistered(idb);
  const tabletLocks = fakeLockManager();
  let releaseSession;
  const sessionHeld = new Promise((resolve) => { releaseSession = resolve; });
  const a = windowOf({ idb, locks: tabletLocks });
  const b = windowOf({ idb, locks: tabletLocks, session: (f) => f.on({ url: 'api/session.php' }, () => sessionHeld.then(() => json(200,
    { csrf: 'c', organisation_name: 'CHS Pet Pantry', server_time: SERVER_TIME, build: 'test+0000000000', dev_relax: false }))) });
  const appA = await a.start();
  assert.equal(appA.state(), 'REGISTERED');
  const appB = await b.start();
  assert.equal(appB.state(), 'ELSEWHERE');
  assert.equal(b.root.querySelector('span.chip'), null);
  b.root.querySelector('section button').dispatch('click');
  await until(() => b.f.requests.some((r) => r.url.endsWith('api/session.php')));
  assert.equal(text(b.root.querySelector('span.chip')), COPY.connecting, 'the window that took over shows Connecting… until its first answer');
  releaseSession();
  await until(() => appB.state() === 'REGISTERED' && b.root.querySelector('button[type="submit"]')?.disabled === false);
  assert.equal(appA.state(), 'REPLACED');
  assert.equal(text(a.root.querySelector('h1')), COPY.replaced);
  assert.equal(a.root.querySelector('span.chip'), null, 'the replaced window shows no chip');
  assert.equal(text(b.root.querySelector('span.chip')), COPY.online, 'the window that took over shows its own');
  const beatsB = b.f.requests.filter((r) => r.url.endsWith(HEARTBEAT));
  assert.equal(beatsB.length, 1, 'one start-up heartbeat in the new window');
  assert.ok(b.f.requests.some((r) => r.url.endsWith('api/session.php')));
});

test('the replaced window closes its database, stops its timers, forgets its credentials and shows replaced, and never reloads', async () => {
  const idb = createMemoryIndexedDB();
  await seedRegistered(idb);
  const locks = fakeLockManager();
  const a = windowOf({ idb, locks });
  const b = windowOf({ idb, locks });
  const appA = await a.start();
  assert.equal(appA.state(), 'REGISTERED');
  assert.equal(idb._connectionCount('pfpms'), 1);
  assert.ok(a.env.pendingTimers() > 0, 'the tick and the heartbeat timer run');
  const appB = await b.start();
  b.root.querySelector('section button').dispatch('click');
  await until(() => appA.state() === 'REPLACED' && appB.state() === 'REGISTERED');
  assert.equal(text(a.root.querySelector('h1')), COPY.replaced);
  assert.equal(a.root.querySelector('section button'), null, 'no buttons');
  assert.equal(idb._connectionCount('pfpms'), 1, 'the first window closed its connection; only the new one is open');
  assert.equal(a.env.pendingTimers(), 0, 'every timer of the first window is cleared');
  assert.deepEqual(a.sessionCalls, [['hard', 'replaced']], 'the keys were dropped');
  const before = a.f.requests.length;
  await a.env.advance(HEARTBEAT_MS * 2);
  a.env.fire('online');
  a.env.fire('visible');
  a.env.fire('hidden');
  await flush(20);
  assert.equal(a.f.requests.length, before, 'no heartbeat, no request: its credentials are forgotten and its listeners gone');
  assert.equal(a.env.reloads, 0, 'it never reloads');
  assert.equal(appA.state(), 'REPLACED');
  // A hash change cannot bring a view back.
  a.env.setHash('#/login');
  await flush();
  assert.equal(text(a.root.querySelector('h1')), COPY.replaced);
});

test('an ELSEWHERE window never opens the database', async () => {
  const idb = createMemoryIndexedDB();
  await seedRegistered(idb);
  const locks = fakeLockManager();
  const a = windowOf({ idb, locks });
  const waiting = fakeWorker('installed');
  const registration = fakeRegistration({ active: fakeWorker('activated'), waiting });
  const b = windowOf({ idb, locks, registration });
  await a.start();
  const opens = idb._log.filter((x) => x.startsWith('open pfpms')).length;
  const appB = await b.start();
  assert.equal(appB.state(), 'ELSEWHERE');
  assert.equal(idb._log.filter((x) => x.startsWith('open pfpms')).length, opens, 'no open from the second window');
  assert.equal(b.f.requests.length, 0, 'no api/session.php, no heartbeat');
  await b.env.advance(HEARTBEAT_MS + TICK_MS * 5);
  b.env.fire('online');
  b.env.fire('visible');
  await flush(20);
  assert.equal(b.f.requests.length, 0);
  assert.deepEqual(waiting.messages, [], 'no update is activated or checked from there');
  assert.equal(registration.updates, 0);
  assert.equal(b.env.pendingTimers(), 0);
});

test('without Web Locks the window runs as primary', async () => {
  assert.equal(await claimPrimary(null), 'unsupported');
  assert.equal(await claimPrimary(undefined), 'unsupported');
  const idb = createMemoryIndexedDB();
  const w = windowOf({ idb, locks: null });
  const app = await w.start();
  assert.equal(app.state(), 'UNREGISTERED');
  assert.ok(w.f.requests.some((r) => r.url.endsWith('api/session.php')));
  await until(() => w.root.querySelector('form') !== null);
  assert.equal(text(w.root.querySelector('h1')), COPY.register_title);
});

// ---- a window replaced in the middle of something: it goes no further (X-2) ----

const beats = (f) => f.requests.filter((r) => r.url.endsWith(HEARTBEAT));
const confirmations = (f) => beats(f).filter((r) => JSON.parse(r.body).wiped === true);
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const revoked = (mode) => ({ ...okHeartbeat, status: 'revoked', offline_enabled: false, directive: { wipe: mode } });
const outboxKeys = (idb) => (idb._dump('pfpms').outbox ?? []).map(([k]) => k);

/** A registered tablet with meta.wipe at `stage` and outbox records (two that block a Retire wipe, by default). */
async function seedWipe(idb, { mode = 'Push Then Wipe', stage = 'pushing', outbox = ['conflict', 'invalid'] } = {}) {
  await seedRegistered(idb);
  const { db } = await openMemoryDb({ idb });
  await db.put('meta', { mode, stage, started_at: SERVER_TIME, items_pushed: 0 }, 'wipe');
  for (const [i, state] of outbox.entries()) await db.put('outbox', { client_uuid: state[0] + (i + 1), seq: i + 1, state, kind: 'intake', created_at: i + 1 });
  db.close();
}

test('a window replaced while its Retire wipe is stuck runs it no further: the database and its records stay', async () => {
  const idb = createMemoryIndexedDB();
  await seedWipe(idb);
  const locks = fakeLockManager();
  const stuckAnswer = (f) => f.json({ url: HEARTBEAT }, 200, revoked('Push Then Wipe'), {}, { times: Infinity });
  const a = windowOf({ idb, locks, heartbeat: stuckAnswer });
  const b = windowOf({ idb, locks, heartbeat: stuckAnswer });
  const appA = await a.start();
  assert.equal(appA.state(), 'WIPING');
  const stuck = COPY.wipe_stuck.replace('{n}', '2');
  await until(() => text(a.root.querySelector('h1')) === stuck);
  await flush(60);
  const appB = await b.start();
  b.root.querySelector('section button').dispatch('click');
  await until(() => appA.state() === 'REPLACED' && appB.state() === 'WIPING' && text(b.root.querySelector('h1')) === stuck);
  await flush(60);
  assert.equal(a.env.pendingTimers(), 0, 'the replaced window\'s stuck wait is cleared');
  const seen = { requests: a.f.requests.length, logs: a.env.logs.length };
  await a.env.advance(STUCK_RETRY_MS * 2); // the replaced window's hours pass
  a.env.fire('online');
  a.env.fire('visible');
  await flush(100);
  assert.equal(appA.state(), 'REPLACED');
  assert.equal(text(a.root.querySelector('h1')), COPY.replaced);
  assert.equal(a.f.requests.length, seen.requests, 'no heartbeat and no confirmation from the replaced window');
  assert.equal(a.env.logs.length, seen.logs);
  assert.equal(idb._exists('pfpms'), true, 'the database the new window holds is not deleted');
  assert.deepEqual(outboxKeys(idb), ['c1', 'i2'], 'the records that stop the wipe are kept');
  assert.equal(Object.fromEntries(idb._dump('pfpms').meta).wipe.stage, 'pushing');
  assert.equal(appB.state(), 'WIPING', 'the new window carries the wipe on');
  assert.equal(text(b.root.querySelector('h1')), stuck);
  assert.equal(confirmations(b.f).length, 0);
  assert.equal(a.env.reloads + b.env.reloads, 0);
});

test('a window replaced while its wipe confirmation gets no answer sends nothing more and keeps no timer', async () => {
  const idb = createMemoryIndexedDB();
  await seedWipe(idb, { mode: 'Wipe Now', stage: 'confirming', outbox: [] });
  const locks = fakeLockManager();
  const offline = (f) => f.offline({ url: HEARTBEAT }, { times: Infinity });
  const a = windowOf({ idb, locks, heartbeat: offline });
  const b = windowOf({ idb, locks, heartbeat: offline });
  const appA = await a.start();
  await until(() => confirmations(a.f).length === 1);
  await flush(60);
  const appB = await b.start();
  b.root.querySelector('section button').dispatch('click');
  await until(() => appA.state() === 'REPLACED' && appB.state() === 'WIPING');
  await flush(60);
  assert.equal(a.env.pendingTimers(), 0, 'the confirmation\'s backoff is cleared');
  const seen = { requests: a.f.requests.length, logs: a.env.logs.length };
  await a.env.advance(30 * 60 * 1000);
  await flush(60);
  assert.equal(a.f.requests.length, seen.requests, 'no further confirmation from the replaced window');
  assert.equal(a.env.logs.length, seen.logs, 'no "wipe confirmation failed" lines, for ever');
  assert.equal(appA.state(), 'REPLACED');
  assert.equal(text(a.root.querySelector('h1')), COPY.replaced);
  assert.equal(idb._exists('pfpms'), true);
  assert.equal(a.env.reloads, 0);
});

test('a heartbeat answer that reaches a replaced window is dropped: no wipe, no deletion, no reload of the new window', async () => {
  const idb = createMemoryIndexedDB();
  await seedRegistered(idb);
  const { db } = await openMemoryDb({ idb });
  await db.put('outbox', { client_uuid: 'q1', seq: 1, state: 'queued', kind: 'intake', created_at: 1 }); // one record still to upload
  db.close();
  const locks = fakeLockManager();
  let release;
  const late = new Promise((resolve) => { release = resolve; });
  const a = windowOf({ idb, locks, heartbeat: (f) => {
    f.json({ url: HEARTBEAT }, 200, okHeartbeat); // the start-up heartbeat
    f.on({ url: HEARTBEAT }, () => late.then(() => json(200, revoked('Push Then Wipe')))); // the 5-minute one answers late
  } });
  const b = windowOf({ idb, locks });
  const appA = await a.start();
  assert.equal(appA.state(), 'REGISTERED');
  await a.env.advance(HEARTBEAT_MS);
  await until(() => beats(a.f).length === 2); // in flight
  const appB = await b.start();
  b.root.querySelector('section button').dispatch('click');
  await until(() => appA.state() === 'REPLACED' && appB.state() === 'REGISTERED');
  release(); // the Retire directive reaches the replaced window
  await flush(100);
  await a.env.advance(STUCK_RETRY_MS);
  await flush(100);
  assert.equal(appA.state(), 'REPLACED');
  assert.equal(text(a.root.querySelector('h1')), COPY.replaced);
  assert.equal(beats(a.f).length, 2, 'nothing sent after it');
  assert.equal(idb._exists('pfpms'), true);
  assert.equal(Object.fromEntries(idb._dump('pfpms').meta).wipe, undefined, 'no wipe written by the replaced window');
  assert.deepEqual(outboxKeys(idb), ['q1']);
  assert.equal(appB.state(), 'REGISTERED');
  assert.equal(b.env.reloads, 0, 'the new window\'s database was never deleted under it');
});

test('a window whose lock is stolen while its database opens stays replaced and keeps no connection', async () => {
  const idb = createMemoryIndexedDB();
  await seedRegistered(idb);
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  let opening = false;
  const slowIdb = {
    deleteDatabase: (n) => idb.deleteDatabase(n),
    open(name, version) {
      opening = true;
      const real = idb.open(name, version);
      const w = { get result() { return real.result; }, get error() { return real.error; } };
      real.onupgradeneeded = (e) => w.onupgradeneeded?.(e);
      real.onblocked = (e) => w.onblocked?.(e);
      real.onerror = (e) => gate.then(() => w.onerror?.(e));
      real.onsuccess = (e) => gate.then(() => w.onsuccess?.(e));
      return w;
    },
  };
  const locks = fakeLockManager();
  const a = windowOf({ idb: slowIdb, locks });
  const b = windowOf({ idb, locks });
  const runningA = a.start();
  await until(() => opening);
  const appB = await b.start();
  b.root.querySelector('section button').dispatch('click');
  await until(() => appB.state() === 'REGISTERED');
  release(); // A's open succeeds after the steal
  const appA = await runningA;
  await flush(60);
  await a.env.advance(HEARTBEAT_MS + TICK_MS * 2);
  await flush(20);
  assert.equal(appA.state(), 'REPLACED');
  assert.equal(text(a.root.querySelector('h1')), COPY.replaced);
  assert.equal(idb._connectionCount('pfpms'), 1, 'only the new window holds a connection');
  assert.equal(a.f.requests.length, 0, 'no api/session.php, no heartbeat from the old window');
  assert.equal(a.env.pendingTimers(), 0);
  assert.equal(appB.state(), 'REGISTERED');
});

test('a window whose lock is stolen while api/session.php is out stays replaced: no screen, timer, heartbeat or update from it', async () => {
  const idb = createMemoryIndexedDB();
  await seedRegistered(idb);
  const locks = fakeLockManager();
  const registration = fakeRegistration({ active: fakeWorker('activated'), waiting: null });
  const a = windowOf({ idb, locks, registration, session: (f) => f.hang({ url: 'api/session.php' }) });
  const b = windowOf({ idb, locks });
  const runningA = a.start();
  await until(() => a.f.requests.some((r) => r.url.endsWith('api/session.php')));
  const appB = await b.start();
  b.root.querySelector('section button').dispatch('click');
  await until(() => appB.state() === 'REGISTERED');
  assert.equal(text(a.root.querySelector('h1')), COPY.replaced);
  const waiting = fakeWorker('installed');
  registration.waiting = waiting; // an update waits
  await a.env.advance(5000); // the old window's api/session.php times out
  const appA = await runningA;
  await flush(60);
  await a.env.advance(TICK_MS * 6 + 60000);
  await flush(20);
  assert.equal(appA.state(), 'REPLACED');
  assert.equal(text(a.root.querySelector('h1')), COPY.replaced, 'never the register view');
  assert.equal(a.env.pendingTimers(), 0);
  assert.equal(beats(a.f).length, 0);
  assert.deepEqual(waiting.messages, [], 'a replaced window never activates an update');
  assert.equal(a.env.reloads, 0);
  assert.equal(idb._connectionCount('pfpms'), 1);
});

/** What `promise` settled to within a few event-loop turns, or 'pending' (so a hang fails the test instead of the run). */
async function settledWithin(promise, turns = 50) {
  let out = 'pending';
  promise.then((v) => { out = v; }, (e) => { out = e; });
  await flush(turns);
  return out;
}

test('claimPrimary settles denied when the browser refuses the Locks API, and a steal can be tried again', async () => {
  const refusing = { request: () => Promise.reject(new DOMException('Access to the Locks API is denied in this context.', 'SecurityError')) };
  assert.equal(await settledWithin(claimPrimary(refusing)), 'denied', 'never left pending (Starting… for ever)');
  assert.equal(await settledWithin(claimPrimary(refusing, { steal: true })), 'denied');
  const throwing = { request: () => { throw new DOMException('denied', 'SecurityError'); } };
  assert.equal(await settledWithin(claimPrimary(throwing)), 'denied');
  // A lock that was held and is then stolen still calls onLost (and only then).
  const locks = fakeLockManager();
  const lost = [];
  assert.equal(await claimPrimary(locks, { onLost: () => lost.push('a') }), 'held');
  assert.deepEqual(lost, []);
  await claimPrimary(locks, { steal: true });
  await flush();
  assert.deepEqual(lost, ['a']);
});
