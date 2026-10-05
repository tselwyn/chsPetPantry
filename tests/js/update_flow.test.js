// public/station/js/update.js (50-design §7.7, BH-05, BH-06, X-3): when SKIP_WAITING is posted, when the page reloads,
// and the hourly update throttle after a failed install. The update check never reloads a page that had no
// controller, never one that did not post SKIP_WAITING itself, and never mid-request.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeEnv, flush } from './support/fake-env.js';
import { openMemoryDb } from './support/memory-db.js';
import { fakeContainer, fakeRegistration, fakeWorker } from './support/fake-sw.js';
import { FAILED_UPDATE_RETRY_MS, QUIET_MS, createUpdater } from '../../public/station/js/update.js';

const SKIP = { type: 'SKIP_WAITING' };
/** device.js keeps a sent registration body for a replay this long (the server's window is 15 minutes, §3.16). */
const REPLAY_WINDOW_MS = 14 * 60 * 1000;

/**
 * One page: a registration (with a waiting worker unless waiting is false), env.serviceWorker as the container, the
 * memory database, and conditions the test changes through `state` (draftOpen counts the real drafts store, as
 * app.js's does).
 */
async function setup({ tabletState = 'REGISTERED', sessionState = 'LOCKED', controlledAtLoad = true, waiting = true } = {}) {
  const container = fakeContainer({ controller: controlledAtLoad ? fakeWorker('activated') : null });
  const env = fakeEnv({ serviceWorker: container });
  const worker = waiting ? fakeWorker('installed') : null;
  const registration = fakeRegistration({ active: fakeWorker('activated'), waiting: worker });
  container.registration = registration;
  const { db } = await openMemoryDb();
  const state = { tabletState, sessionState, sync: false, pending: () => false };
  const conditions = {
    tabletState: () => state.tabletState,
    sessionState: () => state.sessionState,
    draftOpen: async () => ((await db.count('drafts').catch(() => 0)) ?? 0) > 0,
    syncInFlight: () => state.sync,
    registrationPending: () => state.pending(),
  };
  const updater = createUpdater({ env, getDb: () => db, registration, controlledAtLoad, conditions });
  updater.start();
  return { env, container, registration, worker, db, state, updater };
}

const failedAt = async (db) => (await db.get('meta', 'sw'))?.update_failed_at ?? null;

test('SKIP_WAITING is posted at the lock screen or device view with no draft, no sync, nothing in flight and 60 s without input', async () => {
  assert.equal(QUIET_MS, 60000);
  for (const [tabletState, sessionState] of [['UNREGISTERED', 'LOCKED'], ['REGISTERED', 'LOCKED'], ['REGISTERED', 'PICKER']]) {
    const t = await setup({ tabletState, sessionState });
    assert.equal(t.updater.waiting(), true);
    await t.env.advance(QUIET_MS - 1);
    assert.equal(await t.updater.check(), false, `${tabletState}/${sessionState}: not yet`);
    assert.deepEqual(t.worker.messages, []);
    await t.env.advance(1);
    assert.equal(await t.updater.canActivate(), true);
    assert.equal(await t.updater.check(), true, `${tabletState}/${sessionState}`);
    assert.deepEqual(t.worker.messages, [SKIP]);
    assert.equal(t.updater.posted(), true);
    assert.equal(t.env.reloads, 0, 'the reload waits for the controllerchange');
  }
  // Someone signed in (S3), or no worker waiting: nothing is posted.
  const signedIn = await setup({ sessionState: 'UNLOCKED' });
  await signedIn.env.advance(QUIET_MS * 10);
  assert.equal(await signedIn.updater.check(), false);
  assert.deepEqual(signedIn.worker.messages, []);
  const none = await setup({ waiting: false });
  await none.env.advance(QUIET_MS);
  assert.equal(await none.updater.check(), false);
  assert.equal(none.updater.waiting(), false);
  assert.equal(none.updater.posted(), false);
});

test('input within 60 s, an open draft or a sync defers it', async () => {
  const input = await setup();
  await input.env.advance(QUIET_MS - 1000);
  input.updater.inputSeen();
  await input.env.advance(QUIET_MS - 1);
  assert.equal(await input.updater.check(), false, 'the quiet time starts again at the last input');
  await input.env.advance(1);
  assert.equal(await input.updater.check(), true);

  const draft = await setup();
  await draft.db.put('drafts', { k: 'intake:1', state: 'open' });
  await draft.env.advance(QUIET_MS * 5);
  assert.equal(await draft.updater.check(), false, 'a draft is open');
  assert.deepEqual(draft.worker.messages, []);
  await draft.db.delete('drafts', 'intake:1');
  assert.equal(await draft.updater.check(), true);

  const sync = await setup();
  sync.state.sync = true;
  await sync.env.advance(QUIET_MS * 5);
  assert.equal(await sync.updater.check(), false, 'a push is running');
  sync.state.sync = false;
  assert.equal(await sync.updater.check(), true);

  const flight = await setup();
  flight.updater.begin('register');
  assert.equal(flight.updater.inFlight(), true);
  await flight.env.advance(QUIET_MS * 5);
  assert.equal(await flight.updater.check(), false, 'a request is in flight');
  flight.updater.end('register');
  assert.equal(flight.updater.inFlight(), false);
  assert.equal(await flight.updater.check(), true);
});

test('WIPING, ERASED and ELSEWHERE never post', async () => {
  for (const tabletState of ['WIPING', 'ERASED', 'ELSEWHERE', 'REPLACED', 'STARTING', 'NO_STORAGE']) {
    const t = await setup({ tabletState });
    await t.env.advance(QUIET_MS * 10);
    assert.equal(await t.updater.canActivate(), false, tabletState);
    assert.equal(await t.updater.check(), false, tabletState);
    if (tabletState === 'WIPING' || tabletState === 'ERASED') assert.equal(await t.updater.updateNow(), false, `${tabletState}: Update now`);
    assert.deepEqual(t.worker.messages, [], tabletState);
    assert.equal(t.updater.posted(), false, tabletState);
  }
});

test('controllerchange reloads only when the page had a controller and posted SKIP_WAITING itself', async () => {
  const t = await setup({ controlledAtLoad: true });
  await t.env.advance(QUIET_MS);
  assert.equal(await t.updater.check(), true);
  t.container.fireControllerChange();
  assert.equal(t.env.reloads, 1);

  const quiet = await setup({ controlledAtLoad: true });
  quiet.container.fireControllerChange();
  assert.equal(quiet.env.reloads, 0, 'it did not post SKIP_WAITING');
});

test('the first claim of an uncontrolled page never reloads', async () => {
  // The first install's clients.claim() (also an iPad Home Screen app's first launch, or after Repair).
  const first = await setup({ controlledAtLoad: false, waiting: false });
  first.container.fireControllerChange();
  assert.equal(first.env.reloads, 0);

  // Even when this uncontrolled page posted SKIP_WAITING itself (the cold-start activation or the quiet-time rule).
  const cold = await setup({ controlledAtLoad: false });
  assert.equal(cold.updater.activateAtColdStart(), true);
  cold.container.fireControllerChange();
  assert.equal(cold.env.reloads, 0);
  const later = await setup({ controlledAtLoad: false });
  await later.env.advance(QUIET_MS);
  assert.equal(await later.updater.check(), true);
  later.container.fireControllerChange();
  assert.equal(later.env.reloads, 0);
});

test("another window's update never reloads this one", async () => {
  const t = await setup({ controlledAtLoad: true });
  t.updater.inputSeen(); // this window is in use, so it does not post
  assert.equal(await t.updater.check(), false);
  assert.deepEqual(t.worker.messages, []);
  // Another Station window posted SKIP_WAITING to the same worker: it activates and claims this window too.
  t.registration.active = t.worker;
  t.registration.waiting = null;
  t.container.fireControllerChange(t.worker);
  assert.equal(t.updater.posted(), false);
  assert.equal(t.env.reloads, 0);
});

test('no reload while a registration request is in flight; it reloads when the request ends', async () => {
  const t = await setup({ controlledAtLoad: true, tabletState: 'UNREGISTERED' });
  await t.env.advance(QUIET_MS);
  assert.equal(await t.updater.check(), true);
  t.updater.begin('register'); // Register pressed after SKIP_WAITING was posted
  t.updater.begin('signin');   // (S3: any request that must not be cut)
  t.container.fireControllerChange();
  assert.equal(t.env.reloads, 0, 'not mid-request');
  t.updater.end('register');
  assert.equal(t.env.reloads, 0, 'another request is still in flight');
  t.updater.end('signin');
  assert.equal(t.env.reloads, 1, 'the reload runs when the last request ends');
  t.updater.begin('register');
  t.updater.end('register');
  assert.equal(t.env.reloads, 1, 'and only once');
});

test('a kept registration attempt defers SKIP_WAITING and Update now until it succeeds, is dropped or is 14 minutes old', async () => {
  // device.registrationPending(): a sent body is kept in memory from the first send until a success, a discard or
  // REPLAY_WINDOW_MS; reloading meanwhile would lose its nonce and proof key and burn the sheet (X-3).
  for (const end of ['succeeds', 'is dropped', 'is 14 minutes old']) {
    const t = await setup({ tabletState: 'UNREGISTERED' });
    let kept = { firstSentAt: t.env.now() };
    t.state.pending = () => kept !== null && t.env.now() - kept.firstSentAt <= REPLAY_WINDOW_MS;
    await t.env.advance(QUIET_MS);
    assert.equal(await t.updater.check(), false, `${end}: the quiet-time rule waits`);
    assert.equal(await t.updater.updateNow(), false, `${end}: Update now waits`);
    assert.deepEqual(t.worker.messages, []);
    if (end === 'is 14 minutes old') {
      await t.env.advance(REPLAY_WINDOW_MS - QUIET_MS);
      assert.equal(await t.updater.check(), false, 'still kept at exactly 14 minutes');
      await t.env.advance(1);
    } else {
      if (end === 'succeeds') t.state.tabletState = 'REGISTERED';
      kept = null;
    }
    assert.equal(await t.updater.check(), true, `${end}: posted`);
    assert.deepEqual(t.worker.messages, [SKIP]);
  }
  // Update now, once the attempt is dropped.
  const now = await setup({ tabletState: 'UNREGISTERED' });
  let pending = true;
  now.state.pending = () => pending;
  assert.equal(await now.updater.updateNow(), false);
  pending = false;
  assert.equal(await now.updater.updateNow(), true);
  assert.deepEqual(now.worker.messages, [SKIP]);
});

test('update() is skipped for an hour after a failed install, then runs', async () => {
  assert.equal(FAILED_UPDATE_RETRY_MS, 3600000);
  const t = await setup({ waiting: false });
  await t.updater.update('boot');
  assert.equal(t.registration.updates, 1, 'no failure yet: the check runs');

  // The new worker fails its install (a stale copy on the host fails the hash check): it goes straight to redundant.
  const failing = fakeWorker('installing');
  t.registration.fireUpdateFound(failing);
  failing.setState('redundant');
  await flush(20); // the meta write goes through the memory IndexedDB
  assert.equal(await failedAt(t.db), t.env.now(), 'meta.sw.update_failed_at is the tablet time of the failure');

  await t.updater.update('build');
  assert.equal(t.registration.updates, 1, 'skipped right after the failure');
  await t.env.advance(FAILED_UPDATE_RETRY_MS - 1);
  await t.updater.update('build');
  assert.equal(t.registration.updates, 1, 'skipped for the hour');
  await t.env.advance(1);
  await t.updater.update('build');
  assert.equal(t.registration.updates, 2, 'runs again after an hour');

  // A rejected check is logged, never thrown.
  t.registration.update = async () => { throw new DOMException('Failed to update a ServiceWorker.', 'InvalidStateError'); };
  await t.updater.update('timer');
  assert.ok(t.env.logs.includes('update check (timer) failed: InvalidStateError'), JSON.stringify(t.env.logs));

  // Before app.js opened the database (getDb() null) and without a registration, nothing throws.
  const env = fakeEnv();
  const registration = fakeRegistration();
  const early = createUpdater({ env, registration, controlledAtLoad: true, conditions: {} });
  await early.update('boot');
  assert.equal(registration.updates, 1);
  const bare = createUpdater({ env, registration: null, controlledAtLoad: false, conditions: {} });
  bare.start();
  await bare.update('boot');
  assert.equal(bare.waiting(), false);
  assert.equal(bare.activateAtColdStart(), false);
});

test('an installed worker clears the failure', async () => {
  const t = await setup({ waiting: false });
  await t.db.put('meta', { update_failed_at: t.env.now() }, 'sw');
  await t.updater.update('boot');
  assert.equal(t.registration.updates, 0, 'throttled');

  // The host's copy is fresh again: the next worker installs.
  const next = fakeWorker('installing');
  t.registration.fireUpdateFound(next);
  next.setState('installed');
  t.registration.waiting = next;
  await flush(20); // the meta write goes through the memory IndexedDB
  assert.equal(await failedAt(t.db), null);
  await t.updater.update('build');
  assert.equal(t.registration.updates, 1);

  // That worker becoming redundant later (replaced by a newer one while waiting) is not a failed install.
  const newer = fakeWorker('installed');
  t.registration.waiting = newer;
  next.setState('redundant');
  await flush(20); // the meta write goes through the memory IndexedDB
  assert.equal(await failedAt(t.db), null);
  await t.updater.update('build');
  assert.equal(t.registration.updates, 2);
});

test('updateNow ignores the 60 s rule but not drafts, requests in flight or a wipe', async () => {
  const t = await setup();
  t.updater.inputSeen(); // About is open and the person just pressed Update now
  assert.equal(await t.updater.check(), false);
  assert.equal(await t.updater.updateNow(), true);
  assert.deepEqual(t.worker.messages, [SKIP]);
  assert.equal(t.updater.posted(), true);

  const draft = await setup();
  await draft.db.put('drafts', { k: 'intake:1' });
  assert.equal(await draft.updater.updateNow(), false, 'a draft is open');
  const flight = await setup({ tabletState: 'UNREGISTERED' });
  flight.updater.begin('register');
  assert.equal(await flight.updater.updateNow(), false, 'a request is in flight');
  const sync = await setup();
  sync.state.sync = true;
  assert.equal(await sync.updater.updateNow(), false, 'a push is running');
  for (const tabletState of ['WIPING', 'ERASED']) {
    const wipe = await setup({ tabletState });
    assert.equal(await wipe.updater.updateNow(), false, tabletState);
    assert.deepEqual(wipe.worker.messages, []);
  }
  const none = await setup({ waiting: false });
  assert.equal(await none.updater.updateNow(), false, 'nothing waits');
});

test('activateAtColdStart posts at once when a worker waits', async () => {
  const t = await setup({ controlledAtLoad: true });
  t.updater.inputSeen();
  assert.equal(t.updater.activateAtColdStart(), true, 'no quiet time at a cold start: no view has shown yet');
  assert.deepEqual(t.worker.messages, [SKIP]);
  t.container.fireControllerChange();
  assert.equal(t.env.reloads, 1, 'the page reloads into the new version');

  const none = await setup({ waiting: false });
  assert.equal(none.updater.activateAtColdStart(), false);
  const busy = await setup();
  busy.updater.begin('register');
  assert.equal(busy.updater.activateAtColdStart(), false);
  assert.deepEqual(busy.worker.messages, []);
});

test('a reload that waited for the register request also waits while the body is kept, then runs once it is saved, dropped or 14 minutes old', async () => {
  // The flight alone ended too early: a lost answer keeps the body (the next press replays it), and a 200 is only safe
  // once meta.device is written. update.js asks registrationPending() before it reloads, at end() and on every check().
  for (const end of ['saved', 'dropped', 'is 14 minutes old']) {
    const t = await setup({ controlledAtLoad: true, tabletState: 'UNREGISTERED' });
    let kept = null;
    t.state.pending = () => kept !== null && t.env.mono() - kept.firstSentMono <= REPLAY_WINDOW_MS;
    await t.env.advance(QUIET_MS);
    assert.equal(await t.updater.check(), true, `${end}: SKIP_WAITING posted before the press`);
    t.updater.begin('register'); // Register pressed
    kept = { firstSentMono: t.env.mono() };
    t.container.fireControllerChange(); // the new worker took control mid-request
    t.updater.end('register'); // three lost answers: reg_no_answer, the body is kept
    assert.equal(t.env.reloads, 0, `${end}: not while the body is kept`);
    assert.equal(await t.updater.check(), false);
    assert.equal(t.env.reloads, 0);
    if (end === 'saved') {
      t.updater.begin('register'); // the next press replays it; meta.device is written, then the flight ends
      kept = null;
      t.updater.end('register');
    } else if (end === 'dropped') {
      kept = null; // another code: the old body is dropped (the tick's check() runs the reload)
      assert.equal(await t.updater.check(), false, 'check() only reloads while a reload waits');
    } else {
      await t.env.advance(REPLAY_WINDOW_MS);
      assert.equal(await t.updater.check(), false);
      assert.equal(t.env.reloads, 0, 'still inside the replay window at exactly 14 minutes');
      await t.env.advance(1);
      assert.equal(await t.updater.check(), false);
    }
    assert.equal(t.env.reloads, 1, `${end}: the reload runs`);
    t.updater.begin('register');
    t.updater.end('register');
    await t.updater.check();
    assert.equal(t.env.reloads, 1, `${end}: once`);
  }
});

test('a failure time later than now (the wall clock was set back since) does not hold update() off', async () => {
  const t = await setup({ waiting: false });
  await t.db.put('meta', { update_failed_at: t.env.now() + 30 * 60 * 1000 }, 'sw');
  await t.updater.update('boot');
  assert.equal(t.registration.updates, 1, 'a failure "in the future" is not within the hour');
  await t.db.put('meta', { update_failed_at: t.env.now() - 1000 }, 'sw');
  await t.updater.update('boot');
  assert.equal(t.registration.updates, 1, 'one a second ago still is');
});

test('a registration adopted after the start runs the same rule: update(), the install-failure throttle and controllerchange', async () => {
  // boot.js's register() was refused (sw.php's 503 under maintenance on a first start); app.js registered again later.
  const container = fakeContainer({ controller: null });
  const env = fakeEnv({ serviceWorker: container });
  const { db } = await openMemoryDb();
  const conditions = { tabletState: () => 'REGISTERED', sessionState: () => 'LOCKED', draftOpen: async () => false, syncInFlight: () => false,
    registrationPending: () => false };
  const updater = createUpdater({ env, getDb: () => db, registration: null, controlledAtLoad: false, conditions });
  updater.start();
  assert.equal(updater.registration(), null);
  await updater.update('build');
  assert.equal(container.listenerCount('controllerchange'), 0, 'nothing to listen to yet');
  const registration = fakeRegistration({ installing: fakeWorker('installing') });
  assert.equal(updater.adopt(registration), true);
  assert.equal(updater.registration(), registration);
  assert.equal(updater.adopt(fakeRegistration()), false, 'the first one stays');
  assert.equal(updater.registration(), registration);
  await updater.update('build');
  assert.equal(registration.updates, 1, 'update() runs on it');
  assert.equal(registration.listenerCount('updatefound'), 1);
  assert.equal(container.listenerCount('controllerchange'), 1);
  // A failed install of its next worker holds update() off for the hour, as for boot.js's registration.
  const failing = fakeWorker('installing');
  registration.fireUpdateFound(failing);
  failing.setState('redundant');
  await flush(20);
  assert.equal(await failedAt(db), env.now());
  await updater.update('build');
  assert.equal(registration.updates, 1, 'throttled');
  // Its first install claims this page (uncontrolled at load): never a reload.
  container.fireControllerChange();
  assert.equal(env.reloads, 0);
  // Adopted before start(): nothing is attached until start() runs.
  const early = createUpdater({ env, getDb: () => db, registration: null, controlledAtLoad: false, conditions });
  const other = fakeRegistration();
  assert.equal(early.adopt(other), true);
  assert.equal(other.listenerCount('updatefound'), 0);
  early.start();
  assert.equal(other.listenerCount('updatefound'), 1);
  // A register() that resolved nothing hands nothing over.
  const none = createUpdater({ env, getDb: () => db, registration: null, controlledAtLoad: false, conditions });
  assert.equal(none.adopt(undefined), false);
  assert.equal(none.adopt(null), false);
  assert.equal(none.registration(), null);
});
