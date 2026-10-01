// app.js's start (S2 spec §3.17): Starting… and started() before any network call, a waiting worker activated before
// any view, a wipe resumed before api/session.php, the device view or the lock screen (disabled until the start-up
// heartbeat answers or 5 s pass), the dev-only hooks, the storage messages and reloads, and the timers. The real
// modules over fake-env.js, fake-fetch.js, the memory IndexedDB, fake-dom.js and fake-locks.js.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeEnv, flush } from './support/fake-env.js';
import { fakeFetch } from './support/fake-fetch.js';
import { openMemoryDb } from './support/memory-db.js';
import { fakeDocument, text } from './support/fake-dom.js';
import { fakeLockManager } from './support/fake-locks.js';
import { fakeContainer, fakeRegistration, fakeWorker } from './support/fake-sw.js';
import { importProofKey } from '../../public/station/js/proof.js';
import { formatDb } from '../../public/station/js/canonical.js';
import { HEARTBEAT_MS, TICK_MS, start } from '../../public/station/js/app.js';
import { STUCK_RETRY_MS, TRIGGER_GAP_MS } from '../../public/station/js/device.js';
import { QUIET_MS } from '../../public/station/js/update.js';
import { format, fromBytes } from '../../public/station/js/registration_code.js';
import { COPY } from '../../public/station/js/copy.js';

const CRED = 'pfd1_' + 'S'.repeat(43);
const SERVER_TIME = '2026-10-01 12:00:00.000';
const HEARTBEAT = 'api/device/heartbeat.php';
const SESSION = 'api/session.php';
const STORES = ['meta', 'keyring', 'vault_users', 'sessions', 'outbox', 'drafts', 'pack'];
const okHeartbeat = (over = {}) => ({ status: 'ok', offline_enabled: true, label: 'E2E 1', site: { site_id: 3, name: 'Dev Site North' }, directive: null,
  revoked_grants: [], config: { session_idle_minutes: 15 }, server_time: SERVER_TIME, build: 'test+0000000000', ...over });
const sessionInfo = (over = {}) => ({ csrf: 'csrf-1', organisation_name: 'CHS Pet Pantry', server_time: SERVER_TIME, build: 'test+0000000000', dev_relax: false,
  user: null, session: null, gate: null, ...over });

async function until(pred, ms = 10000) {
  const end = Date.now() + ms;
  for (;;) {
    if (await pred()) return;
    if (Date.now() > end) throw new Error('until: the condition never held');
    await new Promise((resolve) => setImmediate(resolve));
  }
}

/**
 * A page of the Station: a fake document, network, locks and the memory IndexedDB (seeded as a registered tablet when
 * asked). `heartbeat` sets up the heartbeat replies ('ok' answers every time; a function sets up its own).
 */
async function page({ registered = false, dev = false, heartbeat = 'ok', session = 'ok', meta = {}, rows = {}, env: envOptions = {}, boot: bootOver = {},
  deps = {} } = {}) {
  const doc = fakeDocument();
  const f = fakeFetch();
  if (session === 'ok') f.json({ url: SESSION }, 200, sessionInfo({ dev_relax: dev }), {}, { times: Infinity });
  else if (typeof session === 'function') session(f);
  if (heartbeat === 'ok') f.json({ url: HEARTBEAT }, 200, okHeartbeat(), {}, { times: Infinity });
  else if (typeof heartbeat === 'function') heartbeat(f);
  const env = fakeEnv({ document: doc, fetch: f, locks: fakeLockManager(), ...envOptions });
  if (env.indexedDB && typeof env.indexedDB._exists === 'function') {
    const { db } = await openMemoryDb({ idb: env.indexedDB });
    if (registered) {
      await db.put('meta', { device_id: 7, credential: CRED, proof: await importProofKey(globalThis.crypto, new Uint8Array(32).fill(3)), site_id: 3,
        site_name: 'Dev Site North', label: 'E2E 1', iterations: 600000, registered_at: SERVER_TIME }, 'device');
      await db.put('meta', { next: 1 }, 'seq');
    }
    for (const [k, v] of Object.entries(meta)) await db.put('meta', v, k);
    for (const [store, list] of Object.entries(rows)) for (const r of list) await db.put(store, r);
    db.close();
  }
  const calls = { started: [], repair: 0 };
  const root = doc.getElementById('app');
  const boot = {
    registration: null, controlledAtLoad: false,
    started: () => { calls.started.push({ requests: f.requests.length, text: text(root) }); return true; },
    repair: async () => { calls.repair += 1; return 'repaired'; },
    ...bootOver,
  };
  return { doc, f, env, root, boot, calls, run: () => start(boot, { env, ...deps }) };
}
const sectionClass = (root) => root.querySelector('section')?.getAttribute('class') ?? null;
const beats = (f) => f.requests.filter((r) => r.url.endsWith(HEARTBEAT));
const clearHooks = () => { delete globalThis.__pfpms; };

test('Starting… is mounted and started() is called before the first network request', async () => {
  const p = await page();
  let atFirstRequest = null;
  const fetch = p.env.fetch;
  p.env.fetch = (...a) => { atFirstRequest ??= text(p.root); return fetch(...a); };
  const app = await p.run();
  assert.equal(p.calls.started.length, 1);
  assert.equal(p.calls.started[0].requests, 0, 'no request before started()');
  assert.equal(p.calls.started[0].text, '', 'started() comes first: nothing is mounted over what boot.js shows until it says yes');
  assert.ok(atFirstRequest.includes(COPY.starting), 'Starting… is on screen before the first request');
  assert.equal(app.state(), 'UNREGISTERED');
  assert.ok(p.f.requests.length > 0, 'the requests came after');
});

test('started() returning false stops the start', async () => {
  // The watchdog fired before this file arrived: boot.js's failure screen is in #app, and it must stay usable.
  const p = await page({ boot: { started: () => false } });
  const failed = p.doc.createElement('section');
  failed.setAttribute('class', 'boot-failed');
  for (const label of [COPY.try_again, COPY.repair_app]) { const b = p.doc.createElement('button'); b.textContent = label; failed.append(b); }
  p.root.replaceChildren(failed);
  const seeded = p.env.indexedDB._log.length;
  const app = await p.run();
  assert.equal(app.state(), 'STARTING');
  assert.equal(p.f.requests.length, 0);
  assert.equal(p.env.indexedDB._log.length, seeded, 'the database is not opened');
  assert.equal(p.env.pendingTimers(), 0);
  assert.deepEqual(p.root.children, [failed], 'the failure screen stays, untouched');
  assert.deepEqual(p.root.querySelectorAll('section.boot-failed button').map(text), [COPY.try_again, COPY.repair_app]);
  assert.ok(!text(p.root).includes(COPY.starting));
});

test('a waiting worker is activated before any view', async () => {
  const waiting = fakeWorker('installed');
  const registration = fakeRegistration({ active: fakeWorker('activated'), waiting });
  const container = fakeContainer({ controller: fakeWorker('activated'), registration });
  const p = await page({ env: { serviceWorker: container }, boot: { registration, controlledAtLoad: true } });
  let at = null;
  const seeded = p.env.indexedDB._log.length;
  const post = waiting.postMessage;
  waiting.postMessage = (data) => {
    at = { requests: p.f.requests.length, opened: p.env.indexedDB._log.slice(seeded).some((x) => x.startsWith('open')), section: sectionClass(p.root) };
    post(data);
  };
  const run = p.run();
  await until(() => at !== null);
  assert.deepEqual(waiting.messages, [{ type: 'SKIP_WAITING' }]);
  assert.deepEqual(at, { requests: 0, opened: false, section: 'view view-starting' }, 'posted while Starting… shows, before the database or the network');
  container.fireControllerChange(waiting);
  assert.equal(p.env.reloads, 1, 'the page had a controller and posted SKIP_WAITING: it reloads');
  await run;
});

test('meta.wipe resumes before api/session.php, any heartbeat or any view', async () => {
  const seen = [];
  const p = await page({ registered: true, heartbeat: (f) => f.json({ url: HEARTBEAT }, 200, { status: 'wiped', server_time: SERVER_TIME, build: 'b' }),
    meta: { wipe: { mode: 'Push Then Wipe', stage: 'pushing', started_at: SERVER_TIME, items_pushed: 0 } } });
  const append = p.root.append.bind(p.root);
  p.root.append = (...nodes) => { append(...nodes); const s = sectionClass(p.root); if (s && seen.at(-1) !== s) seen.push(s); };
  const app = await p.run();
  assert.equal(app.state(), 'WIPING');
  await until(() => app.state() === 'ERASED');
  assert.deepEqual(seen, ['view view-starting', 'view view-wipe'], 'no device view, no lock screen');
  assert.equal(p.f.requests.some((r) => r.url.endsWith(SESSION)), false, 'no api/session.php');
  assert.equal(beats(p.f).length, 1);
  assert.equal(JSON.parse(beats(p.f)[0].body).wiped, true, 'the only heartbeat is the confirmation');
  assert.equal(text(p.root.querySelector('h1')), COPY.erased_uploaded);
  assert.equal(p.env.reloads, 0);
});

test('an unregistered tablet shows the device view', async () => {
  const p = await page();
  const app = await p.run();
  assert.equal(app.state(), 'UNREGISTERED');
  assert.equal(p.env.hash(), '#/device');
  await until(() => p.root.querySelector('form') !== null);
  assert.equal(text(p.root.querySelector('h1')), COPY.register_title);
  assert.equal(text(p.root.querySelector('span.org')), 'CHS Pet Pantry');
  assert.equal(text(p.root.querySelector('span.chip')), COPY.online);
  assert.equal(p.root.querySelector('span.tablet'), null, 'not registered: no tablet name');
  assert.equal(text(p.root.querySelector('footer')), 'Version test+0000000000 · About this tablet');
  assert.equal(beats(p.f).length, 0, 'no heartbeat while unregistered');
  assert.equal(p.f.requests[0].url, '../' + SESSION);
  assert.equal(p.f.requests[0].credentials, 'same-origin');
  // About is allowed and comes back.
  p.env.setHash('#/about');
  await until(() => sectionClass(p.root) === 'view view-about');
  assert.ok(text(p.root).includes(COPY.not_registered));
  [...p.root.querySelectorAll('button')].find((b) => text(b) === COPY.back).dispatch('click');
  await until(() => p.root.querySelector('form') !== null);
  assert.equal(p.env.hash(), '#/device');
});

test('a registered tablet shows the lock screen disabled until the start-up heartbeat answers or 5 s pass', async () => {
  // The heartbeat never answers: 5 s.
  const p = await page({ registered: true, heartbeat: (f) => f.hang({ url: HEARTBEAT }), env: { persisted: false } });
  let done = false;
  const run = p.run().then((a) => { done = true; return a; });
  await until(() => text(p.root).includes(COPY.checking) && beats(p.f).length === 1);
  assert.equal(sectionClass(p.root), 'view view-login');
  assert.equal(text(p.root.querySelector('h1')), 'CHS Pet Pantry');
  assert.equal(text(p.root.querySelector('section p.tablet')), 'E2E 1 at Dev Site North');
  assert.equal(p.root.querySelector('button[type="submit"]').disabled, true);
  assert.equal(p.env.hash(), '#/login');
  await p.env.advance(4999);
  await flush(20);
  assert.equal(done, false);
  assert.ok(text(p.root).includes(COPY.checking), 'still checking at 4.999 s');
  await p.env.advance(1);
  const app = await run;
  assert.equal(app.state(), 'REGISTERED');
  assert.ok(text(p.root).includes(COPY.signin_not_ready));
  assert.ok(!text(p.root).includes(COPY.checking));
  assert.equal(text(p.root.querySelector('span.chip')), COPY.offline, 'the heartbeat timed out: offline');
  assert.equal(p.env.persistCalls, 1, 'persistence is asked again at a registered start');
  // The heartbeat answers.
  const order = [];
  let qRoot = null;
  const q = await page({ registered: true, heartbeat: (f) => f.on({ url: HEARTBEAT }, () => {
    order.push(text(qRoot.querySelector('p.status')));
    return new Response(JSON.stringify(okHeartbeat()), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }) });
  qRoot = q.root;
  const app2 = await q.run();
  assert.deepEqual(order, [COPY.checking], 'checking while the heartbeat is out');
  assert.equal(app2.state(), 'REGISTERED');
  assert.equal(text(q.root.querySelector('p.status')), COPY.signin_not_ready);
  assert.equal(q.env.persistCalls, 0, 'already persisted');
});

test('dev_relax creates __pfpms.debug and the banner, and its absence creates neither', async () => {
  clearHooks();
  try {
    const off = await page();
    await off.run();
    assert.equal(globalThis.__pfpms, undefined);
    assert.equal(off.root.querySelector('.banner-danger'), null);
    const on = await page({ dev: true, registered: true });
    const app = await on.run();
    assert.equal(text(on.root.querySelector('.banner-danger')), COPY.dev_relax);
    const debug = globalThis.__pfpms?.debug;
    assert.ok(debug);
    assert.deepEqual(Object.keys(debug).sort(), ['draft', 'dump', 'heartbeat', 'record', 'skipDelay', 'state']);
    assert.equal(debug.state(), 'REGISTERED');
    assert.equal(debug.state(), app.state());
    const before = beats(on.f).length;
    const res = await debug.heartbeat();
    assert.equal(res.status, 'ok');
    assert.equal(beats(on.f).length, before + 1, 'heartbeat() sends one now (no 20-s gap)');
    assert.throws(() => debug.skipDelay(), /S4/);
    assert.throws(() => debug.record(), /S5/);
    assert.throws(() => debug.draft(), /S4/);
  } finally {
    clearHooks();
  }
});

test('the dump shows a CryptoKey as {CryptoKey: {…, extractable: false}}', async () => {
  clearHooks();
  try {
    const p = await page({ dev: true, registered: true });
    await p.run();
    const d = await globalThis.__pfpms.debug.dump();
    assert.equal(d.meta.device.credential, CRED);
    assert.deepEqual(d.meta.device.proof, { CryptoKey: { type: 'secret', extractable: false,
      algorithm: { name: 'HMAC', hash: { name: 'SHA-256' }, length: 256 }, usages: ['sign'] } });
    assert.equal(JSON.stringify(d).match(/pfd1_/g).length, 1);
  } finally {
    clearHooks();
  }
});

test('a database that cannot open shows storage_unavailable', async () => {
  const refusing = {
    open() {
      const r = {};
      setImmediate(() => { r.error = new DOMException('The user denied permission to access the database.', 'UnknownError'); r.onerror?.(); });
      return r;
    },
  };
  for (const indexedDB of [refusing, null]) {
    const p = await page({ env: { indexedDB } });
    const app = await p.run();
    assert.equal(app.state(), 'NO_STORAGE');
    assert.equal(text(p.root.querySelector('p.lead')), COPY.storage_unavailable);
    assert.equal(p.f.requests.length, 0, 'nothing else starts');
    assert.equal(p.env.pendingTimers(), 0);
    assert.ok(p.env.logs.some((l) => l.startsWith('the database did not open')));
    assert.equal(p.root.querySelector('span.chip'), null, 'no Connecting… chip for ever: nothing will be asked');
    const retry = p.root.querySelector('section button');
    assert.equal(text(retry), COPY.try_again, 'the message says try again, and the installed app has no reload control');
    retry.dispatch('click');
    assert.equal(p.env.reloads, 1);
  }
});

test('a browser that refuses the Locks API shows storage_unavailable instead of Starting… for ever', async () => {
  const locks = { request: () => Promise.reject(new DOMException('Access to the Locks API is denied in this context.', 'SecurityError')) };
  const p = await page({ env: { locks } });
  const seeded = p.env.indexedDB._log.length;
  let app = null;
  p.run().then((a) => { app = a; });
  await flush(100);
  assert.notEqual(app, null, 'the start ends: it never hangs on Starting…');
  assert.equal(app.state(), 'NO_STORAGE');
  assert.equal(text(p.root.querySelector('p.lead')), COPY.storage_unavailable);
  assert.equal(p.env.indexedDB._log.length, seeded, 'the database is not opened');
  assert.equal(p.f.requests.length, 0);
  assert.equal(p.env.pendingTimers(), 0);
  assert.ok(p.env.logs.includes('the primary-window lock was refused'));
  assert.equal(text(p.root.querySelector('section button')), COPY.try_again);
});

test('the database closing under the page reloads it when no wipe runs', async () => {
  for (const registered of [false, true]) {
    for (const how of ['versionchange', 'closed']) {
      const p = await page({ registered });
      const app = await p.run();
      assert.equal(app.state(), registered ? 'REGISTERED' : 'UNREGISTERED');
      if (how === 'versionchange') {
        await new Promise((resolve, reject) => { const r = p.env.indexedDB.deleteDatabase('pfpms'); r.onsuccess = resolve; r.onerror = () => reject(r.error); });
      } else {
        p.env.indexedDB._forceClose('pfpms'); // Clear-Site-Data, or the browser's storage settings
      }
      await until(() => p.env.reloads === 1);
      await flush(20);
      assert.equal(p.env.reloads, 1, `${registered ? 'registered' : 'unregistered'}, ${how}: one reload`);
    }
  }
});

test('the page\'s own close() and destroy() never reload it', async () => {
  // destroy(): a 410 erases the tablet locally.
  const p = await page({ registered: true, heartbeat: (f) => f.json({ url: HEARTBEAT }, 410, { error: 'wiped', message: 'x', status: 'wiped' }) });
  const app = await p.run();
  await until(() => app.state() === 'ERASED');
  await flush(20);
  assert.equal(p.env.indexedDB._exists('pfpms'), false);
  assert.equal(p.env.reloads, 0);
  // close(): the window lost the primary lock.
  const q = await page({ registered: true });
  const appQ = await q.run();
  const thief = await new Promise((resolve) => q.env.locks.request('pfpms-primary', { steal: true }, () => { resolve(true); return new Promise(() => {}); }));
  assert.equal(thief, true);
  await until(() => appQ.state() === 'REPLACED');
  await flush(20);
  assert.equal(q.env.indexedDB._connectionCount('pfpms'), 0);
  assert.equal(q.env.reloads, 0);
});

test('useDocument(env.document) is called and every screen mounts into #app', async () => {
  const p = await page({ registered: true });
  const inApp = () => {
    assert.deepEqual(p.doc.body.children, [p.root], 'the body holds only #app');
    const all = p.root.querySelectorAll('*');
    assert.ok(all.length > 0);
    for (const node of all) assert.equal(node.ownerDocument, p.doc, 'made by env.document');
    assert.ok(p.root.querySelector('section'));
    assert.ok(p.root.querySelector('header.topbar'));
  };
  const run = p.run();
  inApp(); // Starting…
  await run;
  inApp(); // the lock screen
  assert.equal(sectionClass(p.root), 'view view-login');
  p.f.json({ url: 'api/ping.php' }, 200, { ok: true, server_time: SERVER_TIME, build: 'b', authorization_received: true, script_path: 'api/ping.php' });
  assert.equal(p.root.querySelector('a.link').getAttribute('href'), '#/about');
  p.env.setHash('#/about'); // what following the footer link does (fake-dom does not follow links)
  const factsNow = () => Object.fromEntries(p.root.querySelectorAll('dt').map((dt, i) => [text(dt), text(p.root.querySelectorAll('dd')[i])]));
  await until(() => sectionClass(p.root) === 'view view-about' && factsNow()[COPY.about_key] === COPY.yes);
  inApp();
  const facts = factsNow();
  assert.equal(facts[COPY.about_name], 'E2E 1');
  assert.equal(facts[COPY.about_key], COPY.yes);
  assert.equal(facts[COPY.about_last_contact], new Date(Date.parse(SERVER_TIME.replace(' ', 'T') + 'Z')).toISOString(), 'env.formatTime() of last_heartbeat_at');
  const ping = p.f.requests.find((r) => r.url.endsWith('api/ping.php'));
  assert.equal(ping.headers.authorization, 'PFPMS-Device ' + CRED, 'the ping carries the tablet\'s key');
  assert.equal(p.root.querySelector('footer a'), null, 'About shows Back instead of its own link');
});

test('the dump lists every store as an array and meta as an object', async () => {
  clearHooks();
  try {
    const p = await page({ dev: true, registered: true, rows: {
      keyring: [{ user_id: 2, lookup: ['aa'] }, { user_id: 1, lookup: ['bb'] }],
      outbox: [{ client_uuid: 'u1', seq: 1, state: 'queued', kind: 'intake', created_at: 1, mac: new Uint8Array([1, 2, 255]) }],
      pack: [{ k: 'p', bytes: new Uint8Array([0, 16]).buffer }],
    } });
    await p.run();
    const d = await globalThis.__pfpms.debug.dump();
    assert.deepEqual(Object.keys(d), STORES);
    assert.equal(Array.isArray(d.meta), false);
    assert.equal(typeof d.meta, 'object');
    for (const s of STORES.slice(1)) assert.ok(Array.isArray(d[s]), s);
    assert.deepEqual(d.keyring.map((r) => r.user_id), [1, 2], 'key order');
    assert.equal(d.outbox[0].mac, '0102ff', 'bytes as hex');
    assert.equal(d.pack[0].bytes, '0010');
    assert.deepEqual(d.meta.seq, { next: 1 });
    assert.equal(typeof d.meta.last_heartbeat_at, 'string');
    assert.deepEqual(d.sessions, []);
  } finally {
    clearHooks();
  }
});

test('ticks every 15 s, heartbeats every 5 minutes and on online and visible', async () => {
  assert.equal(TICK_MS, 15000);
  assert.equal(HEARTBEAT_MS, 300000);
  const registration = fakeRegistration({ active: fakeWorker('activated') });
  const container = fakeContainer({ controller: fakeWorker('activated'), registration });
  const answer = { build: 'test+0000000000' };
  const p = await page({ registered: true, env: { serviceWorker: container }, boot: { registration, controlledAtLoad: true },
    heartbeat: (f) => f.on({ url: HEARTBEAT }, () => new Response(JSON.stringify(okHeartbeat(answer)), { status: 200, headers: { 'Content-Type': 'application/json' } }),
      { times: Infinity }) });
  await p.run();
  assert.equal(beats(p.f).length, 1, 'the start-up heartbeat');
  await until(() => registration.updates === 1); // update('boot')
  // The tick: the update check (once 60 s have passed without input) and the update banner.
  const waiting = fakeWorker('installed');
  registration.waiting = waiting;
  await p.env.advance(TICK_MS * 3);
  assert.deepEqual(waiting.messages, [], 'within the quiet time');
  assert.ok(text(p.root).includes(COPY.update_ready), 'the tick shows the update banner');
  await p.env.advance(TICK_MS);
  assert.deepEqual(waiting.messages, [{ type: 'SKIP_WAITING' }], 'the tick at 60 s posts it');
  // The tick runs clock.tick(): a clock set back is re-based and saved.
  p.env.jumpWall(-120000);
  await p.env.advance(TICK_MS);
  await until(async () => {
    const { db } = await openMemoryDb({ idb: p.env.indexedDB });
    const c = await db.get('meta', 'clock');
    db.close();
    return c?.offset_ms > 100000;
  });
  // The 5-minute heartbeat.
  const n = beats(p.f).length;
  await p.env.advance(HEARTBEAT_MS - TICK_MS * 5 - 1);
  assert.equal(beats(p.f).length, n);
  await p.env.advance(1);
  await until(() => beats(p.f).length === n + 1);
  await flush(60);
  // online and visible, at most every 20 s.
  p.env.fire('online');
  await flush(20);
  assert.equal(beats(p.f).length, n + 1, 'within 20 s of the last one');
  await p.env.advance(20000);
  p.env.fire('online');
  await until(() => beats(p.f).length === n + 2);
  await flush(60);
  await p.env.advance(20000);
  p.env.fire('visible');
  await until(() => beats(p.f).length === n + 3);
  await flush(60);
  // A heartbeat whose build differs asks for an update.
  const updates = registration.updates;
  answer.build = '0.1.0-dev+ffffffffff';
  await p.env.advance(20000);
  p.env.fire('visible');
  await until(() => registration.updates === updates + 1);
  // 'hidden' saves the clock.
  p.env.fire('hidden');
  await flush(20);
  const { db } = await openMemoryDb({ idb: p.env.indexedDB });
  assert.equal(typeof (await db.get('meta', 'clock')).high_water_ms, 'number');
  assert.equal(await db.get('meta', 'last_heartbeat_at'), formatDb(Date.parse('2026-10-01T12:00:00.000Z')), 'the server frame (fake answers carry 12:00)');
  db.close();
});

// ---- the registration, the update rule and the chip through app.js (what app.js wires into device.js and update.js) ----

const CODE = fromBytes(new Uint8Array([0x5a, 0x01, 0xc3, 0x77, 0x10, 0xfe, 0x42, 0x99, 0x0b, 0x6d]));
const REGISTER = 'api/device/register.php';
const SKIP = { type: 'SKIP_WAITING' };
const registerReply = (over = {}) => ({ device_id: 7, site: { site_id: 3, name: 'Dev Site North' }, label: 'E2E 1', credential: CRED,
  pbkdf2_iterations: 600000, replayed: false, server_time: SERVER_TIME, build: 'test+0000000000', ...over });
const revoked = (mode) => okHeartbeat({ status: 'revoked', offline_enabled: false, directive: { wipe: mode } });
const json = (status, body) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const registerRequests = (f) => f.requests.filter((r) => r.url.endsWith(REGISTER));
const chipText = (root) => text(root.querySelector('span.chip'));
const typeCode = (root, code) => { const input = root.querySelector('#code'); input.value = code; input.dispatch('input'); };
const pressRegister = (root) => root.querySelector('button[type="submit"]').dispatch('click');
const factsOf = (root) => Object.fromEntries(root.querySelectorAll('dt').map((dt, i) => [text(dt), text(root.querySelectorAll('dd')[i])]));

/** The S3 seam (deps.createSession) gives a test the very updater app.js built. */
function sessionSpy() {
  const seen = {};
  const createSession = ({ updater }) => {
    seen.updater = updater;
    return { state: () => 'LOCKED', lock() {}, onRevokedGrants() {}, onOfflineDisabled() {}, tick() {}, touch() {}, hasVaultKey: () => false, on() {} };
  };
  return { seen, createSession };
}

/** A page with a worker registration whose waiting worker is set after the start (no cold-start activation). */
async function updatablePage(options = {}) {
  const registration = fakeRegistration({ active: fakeWorker('activated'), waiting: null });
  const container = fakeContainer({ controller: fakeWorker('activated'), registration });
  const p = await page({ ...options, env: { serviceWorker: container, ...(options.env ?? {}) }, boot: { registration, controlledAtLoad: true } });
  return { ...p, registration, container };
}

/** Types the code and presses Register with three lost answers: reg_no_answer, and the body is kept for a replay. */
async function pressWithoutAnswer(p) {
  await until(() => p.root.querySelector('form') !== null);
  typeCode(p.root, format(CODE));
  p.f.offline({ url: REGISTER }, { times: 3 });
  pressRegister(p.root);
  await until(() => registerRequests(p.f).length === 1);
  await flush(20);
  await p.env.advance(1000);
  await until(() => registerRequests(p.f).length === 2);
  await flush(20);
  await p.env.advance(3000);
  await until(() => text(p.root).includes(COPY.reg_no_answer));
}

test('a kept registration attempt holds the update back through app.js, until the replay is saved', async () => {
  const p = await updatablePage();
  const app = await p.run();
  assert.equal(app.state(), 'UNREGISTERED');
  await pressWithoutAnswer(p);
  const waiting = fakeWorker('installed');
  p.registration.waiting = waiting; // an update arrives while the body is kept
  await p.env.advance(QUIET_MS + TICK_MS * 2);
  assert.deepEqual(waiting.messages, [], 'no SKIP_WAITING while the nonce and proof key exist only in memory');
  // The next press replays the same body; once it is saved, the quiet-time rule applies again.
  p.f.json({ url: REGISTER }, 200, registerReply({ replayed: true }));
  pressRegister(p.root);
  await until(() => text(p.root).includes('Registered to Dev Site North as E2E 1.'));
  p.root.querySelector('section button').dispatch('click'); // Continue
  assert.equal(app.state(), 'REGISTERED');
  await p.env.advance(TICK_MS);
  assert.deepEqual(waiting.messages.slice(0, 1), [SKIP], 'posted at the next tick');
  assert.equal(new Set(registerRequests(p.f).map((r) => r.body)).size, 1, 'the same body every time');
});

test('a controllerchange during the register POST reloads only once meta.device is saved', async () => {
  const spy = sessionSpy();
  const p = await updatablePage({ deps: { createSession: spy.createSession } });
  await p.run();
  await until(() => p.root.querySelector('form') !== null);
  const waiting = fakeWorker('installed');
  p.registration.waiting = waiting;
  await p.env.advance(QUIET_MS);
  assert.deepEqual(waiting.messages, [SKIP], 'the quiet-time rule posted SKIP_WAITING at the device view');
  const atReload = [];
  p.env.reload = () => { p.env.reloads += 1; atReload.push(Object.fromEntries(p.env.indexedDB._dump('pfpms').meta).device?.credential ?? null); };
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  p.f.on({ url: REGISTER }, () => gate.then(() => json(200, registerReply())));
  typeCode(p.root, format(CODE));
  pressRegister(p.root);
  await until(() => registerRequests(p.f).length === 1);
  assert.equal(spy.seen.updater.inFlight(), true, 'app.js wires device.js\'s register flight into the update rule');
  p.container.fireControllerChange(waiting); // the new worker took control while the POST runs
  await flush(20);
  assert.deepEqual(atReload, [], 'not while the request runs');
  release();
  await until(() => atReload.length > 0);
  assert.deepEqual(atReload, [CRED], 'one reload, after the credential is stored');
  assert.equal(spy.seen.updater.inFlight(), false);
});

test('input through app.js restarts the quiet time, and an open draft holds the update back', async () => {
  const p = await updatablePage({ registered: true });
  await p.run();
  const waiting = fakeWorker('installed');
  p.registration.waiting = waiting;
  await p.env.advance(30000);
  p.env.fire('input'); // someone touches the tablet 30 s in
  await p.env.advance(QUIET_MS - 15000);
  assert.deepEqual(waiting.messages, [], 'the quiet time starts again at the input');
  await p.env.advance(15000);
  assert.deepEqual(waiting.messages, [SKIP], '60 s after the input');

  const d = await updatablePage({ registered: true, rows: { drafts: [{ k: 'intake:1', state: 'open' }] } });
  await d.run();
  const next = fakeWorker('installed');
  d.registration.waiting = next;
  await d.env.advance(QUIET_MS * 3);
  assert.deepEqual(next.messages, [], 'a draft is open');
  const { db } = await openMemoryDb({ idb: d.env.indexedDB });
  await db.delete('drafts', 'intake:1');
  db.close();
  await d.env.advance(TICK_MS);
  assert.deepEqual(next.messages, [SKIP], 'posted at the next tick');
});

test('a controllerchange after this page posted never reloads it while WIPING or REPLACED', async () => {
  // WIPING: a directive arrives after SKIP_WAITING was posted.
  const w = await updatablePage({ registered: true, heartbeat: (f) => {
    f.json({ url: HEARTBEAT }, 200, okHeartbeat());
    f.json({ url: HEARTBEAT }, 200, revoked('Wipe Now'), {}, { times: Infinity }); // the confirmation's claim is ignored: it keeps waiting
  } });
  const app = await w.run();
  const waiting = fakeWorker('installed');
  w.registration.waiting = waiting;
  await w.env.advance(QUIET_MS);
  assert.deepEqual(waiting.messages, [SKIP]);
  w.env.fire('visible'); // a heartbeat: Erase now
  await until(() => app.state() === 'WIPING');
  w.container.fireControllerChange(waiting);
  await flush(20);
  assert.equal(w.env.reloads, 0, 'the wipe owns the page');
  // REPLACED: another window took the tablet after this page posted.
  const r = await updatablePage({ registered: true });
  const appR = await r.run();
  const next = fakeWorker('installed');
  r.registration.waiting = next;
  await r.env.advance(QUIET_MS);
  assert.deepEqual(next.messages, [SKIP]);
  await new Promise((resolve) => r.env.locks.request('pfpms-primary', { steal: true }, () => { resolve(); return new Promise(() => {}); }));
  await until(() => appR.state() === 'REPLACED');
  r.container.fireControllerChange(next);
  await flush(20);
  assert.equal(r.env.reloads, 0, 'a replaced window never reloads (its new page would invite stealing the lock back)');
});

test('Continue after a registration shows the lock screen and starts the 5-minute heartbeat', async () => {
  const p = await page();
  const app = await p.run();
  await until(() => p.root.querySelector('form') !== null);
  typeCode(p.root, format(CODE));
  p.f.json({ url: REGISTER }, 200, registerReply());
  pressRegister(p.root);
  await until(() => text(p.root).includes('Registered to Dev Site North as E2E 1.'));
  await until(() => beats(p.f).length === 1); // the registration's own first heartbeat
  await flush(60);
  assert.equal(app.state(), 'UNREGISTERED', 'the registered view waits for Continue');
  p.root.querySelector('section button').dispatch('click'); // Continue
  assert.equal(app.state(), 'REGISTERED');
  assert.equal(p.env.hash(), '#/login');
  await until(() => sectionClass(p.root) === 'view view-login');
  assert.equal(text(p.root.querySelector('section p.tablet')), 'E2E 1 at Dev Site North');
  assert.equal(text(p.root.querySelector('span.tablet')), 'E2E 1 at Dev Site North', 'the header names the tablet too');
  assert.equal(text(p.root.querySelector('p.status')), COPY.signin_not_ready, 'not "Checking this tablet…": the first heartbeat already ran');
  const n = beats(p.f).length;
  await p.env.advance(HEARTBEAT_MS - 1);
  await flush(20);
  assert.equal(beats(p.f).length, n);
  await p.env.advance(1);
  await until(() => beats(p.f).length === n + 1);
  await flush(20);
  assert.equal(beats(p.f).length, n + 1, 'the 5-minute heartbeat runs without a reload');
});

test('an unregistered tablet that started offline shows Online once the network answers, and the press sets the chip', async () => {
  const p = await page({ session: (f) => { f.offline({ url: SESSION }); f.json({ url: SESSION }, 200, sessionInfo(), {}, { times: Infinity }); } });
  const app = await p.run();
  assert.equal(app.state(), 'UNREGISTERED');
  assert.equal(chipText(p.root), COPY.offline);
  assert.equal(p.root.querySelector('span.org'), null);
  const sessions = () => p.f.requests.filter((r) => r.url.endsWith(SESSION)).length;
  p.env.fire('online');
  await until(() => chipText(p.root) === COPY.online && p.root.querySelector('span.org') !== null);
  assert.equal(text(p.root.querySelector('span.org')), 'CHS Pet Pantry', 'and the organisation name arrives');
  assert.equal(sessions(), 2);
  // 'visible' asks again, at most every 20 s.
  p.env.fire('visible');
  await flush(20);
  assert.equal(sessions(), 2, 'not within 20 s of the start');
  await p.env.advance(TRIGGER_GAP_MS);
  p.env.fire('visible');
  await until(() => sessions() === 3);
  p.env.fire('visible');
  await flush(20);
  assert.equal(sessions(), 3, 'rate-limited');
  // The press: no answer says Offline, any answer says Online.
  await pressWithoutAnswer(p);
  assert.equal(chipText(p.root), COPY.offline);
  p.f.json({ url: REGISTER }, 409, { error: 'busy', message: 'x' });
  pressRegister(p.root);
  await until(() => text(p.root).includes(COPY.reg_busy));
  assert.equal(chipText(p.root), COPY.online);
  assert.equal(beats(p.f).length, 0, 'still no heartbeat while unregistered');
});

test('About says Not known when the key check fails, never Checking… for as long as it shows', async () => {
  const p = await page({ registered: true });
  await p.run();
  p.f.offline({ url: 'api/ping.php' });
  p.env.setHash('#/about');
  await until(() => sectionClass(p.root) === 'view view-about' && factsOf(p.root)[COPY.about_key] !== COPY.checking_short);
  assert.equal(factsOf(p.root)[COPY.about_key], COPY.key_unknown);
  assert.equal(chipText(p.root), COPY.offline);
  await p.env.advance(10 * 60 * 1000);
  await flush(20);
  assert.equal(factsOf(p.root)[COPY.about_key], COPY.key_unknown);
  // A 200 that says false is the only No.
  [...p.root.querySelectorAll('button')].find((b) => text(b) === COPY.back).dispatch('click');
  await until(() => sectionClass(p.root) === 'view view-login');
  p.f.json({ url: 'api/ping.php' }, 200, { ok: true, server_time: SERVER_TIME, build: 'b', authorization_received: false, script_path: 'api/ping.php' });
  p.env.setHash('#/about');
  await until(() => sectionClass(p.root) === 'view view-about' && factsOf(p.root)[COPY.about_key] === COPY.no);
});

test('Repair this app is refused while a sent registration body is kept', async () => {
  const p = await page();
  await p.run();
  await pressWithoutAnswer(p);
  p.f.json({ url: 'api/ping.php' }, 200, { ok: true, server_time: SERVER_TIME, build: 'b', authorization_received: false, script_path: 'api/ping.php' });
  p.env.setHash('#/about');
  await until(() => sectionClass(p.root) === 'view view-about');
  [...p.root.querySelectorAll('button')].find((b) => text(b) === COPY.repair_this_app).dispatch('click');
  await until(() => text(p.root).includes(COPY.repair_registering));
  await flush(20);
  assert.equal(p.calls.repair, 0, 'boot\'s repair (unregister, reload) never ran');
  assert.equal(p.env.reloads, 0);
});

test('a resumed stuck wipe asks the server at the start and when the tablet comes online or wakes, so Erase now arrives', async () => {
  const p = await page({ registered: true, meta: { wipe: { mode: 'Push Then Wipe', stage: 'pushing', started_at: SERVER_TIME, items_pushed: 0 } },
    rows: { outbox: [{ client_uuid: 'c1', seq: 1, state: 'conflict', kind: 'intake', created_at: 1 }] },
    heartbeat: (f) => {
      f.json({ url: HEARTBEAT }, 200, revoked('Push Then Wipe')); // at the start: still Retire
      f.json({ url: HEARTBEAT }, 200, revoked('Push Then Wipe')); // when the network comes back: still Retire
      f.json({ url: HEARTBEAT }, 200, revoked('Wipe Now'));       // later: an Administrator pressed Erase now
      f.json({ url: HEARTBEAT }, 200, { status: 'wiped', server_time: SERVER_TIME, build: 'b' });
    } });
  const app = await p.run();
  assert.equal(app.state(), 'WIPING');
  await until(() => beats(p.f).length === 1);
  await until(() => text(p.root.querySelector('h1')) === COPY.wipe_stuck.replace('{n}', '1'));
  await flush(60);
  await p.env.advance(TRIGGER_GAP_MS);
  p.env.fire('online'); // the hall Wi-Fi comes back long before the hour is over
  await until(() => beats(p.f).length === 2);
  await flush(60);
  assert.equal(app.state(), 'WIPING');
  await p.env.advance(TRIGGER_GAP_MS);
  p.env.fire('visible'); // the tablet wakes
  await until(() => app.state() === 'ERASED');
  assert.equal(text(p.root.querySelector('h1')), COPY.erased_not_uploaded);
  assert.deepEqual(beats(p.f).map((r) => JSON.parse(r.body).wiped), [false, false, false, true]);
  assert.equal(p.f.requests.some((r) => r.url.endsWith(SESSION)), false, 'the wipe owns the start');
});

test('storage cleared under a stuck Retire wipe (no 410) starts the app again instead of saying "uploading" for ever', async () => {
  const p = await page({ registered: true, meta: { wipe: { mode: 'Push Then Wipe', stage: 'pushing', started_at: SERVER_TIME, items_pushed: 0 } },
    rows: { outbox: [{ client_uuid: 'c1', seq: 1, state: 'conflict', kind: 'intake', created_at: 1 }] },
    heartbeat: (f) => f.json({ url: HEARTBEAT }, 200, revoked('Push Then Wipe'), {}, { times: Infinity }) });
  const app = await p.run();
  await until(() => beats(p.f).length === 1);
  await until(() => text(p.root.querySelector('h1')) === COPY.wipe_stuck.replace('{n}', '1'));
  await flush(60);
  p.env.indexedDB._forceClose('pfpms'); // the person or the browser clears the site's data
  await p.env.advance(STUCK_RETRY_MS);   // the hourly question finds the storage gone
  await until(() => p.env.reloads === 1);
  assert.ok(p.env.logs.includes('wipe stopped: DbClosed'));
  assert.equal(beats(p.f).filter((r) => JSON.parse(r.body).wiped === true).length, 0, 'never confirmed: only the server ends a Retire wipe');
  await p.env.advance(STUCK_RETRY_MS * 3);
  p.env.fire('online');
  p.env.fire('visible');
  await flush(60);
  assert.equal(p.env.reloads, 1, 'one reload, no loop');
  assert.equal(app.state(), 'WIPING', 'this page is going away');
  // The fresh start: the storage is gone, so the tablet asks to be registered again.
  const q = await page({ env: { indexedDB: p.env.indexedDB } });
  const next = await q.run();
  assert.equal(next.state(), 'UNREGISTERED');
  await until(() => q.root.querySelector('form') !== null);
  assert.equal(text(q.root.querySelector('h1')), COPY.register_title);
});

// ---- the worker registration boot.js could not make (sw.php refused on a first start, e.g. under maintenance) ----

/** boot.registerWorker that the test settles by hand: `calls` holds {resolve, reject} per call. */
function manualRegisterWorker() {
  const calls = [];
  const registerWorker = () => new Promise((resolve, reject) => { calls.push({ resolve, reject }); });
  return { calls, registerWorker };
}

test('a page that started without a worker registration registers sw.php again after a server answer, one call at a time, until it has one', async () => {
  const w = manualRegisterWorker();
  const answer = { build: 'test+0000000000' };
  const p = await page({ registered: true, boot: { registration: null, registerWorker: w.registerWorker },
    heartbeat: (f) => f.on({ url: HEARTBEAT }, () => json(200, okHeartbeat(answer)), { times: Infinity }) });
  const app = await p.run();
  assert.equal(app.state(), 'REGISTERED');
  assert.equal(beats(p.f).length, 1);
  assert.equal(w.calls.length, 1, 'api/session.php answered: one call; the start-up heartbeat finds it still running');
  w.calls[0].reject(new TypeError('Failed to register a ServiceWorker: A bad HTTP response code (503) was received when fetching the script.'));
  await flush(20);
  assert.ok(p.env.logs.includes('service worker registration failed: Failed to register a ServiceWorker: A bad HTTP response code (503) was received when fetching the script.'));
  await p.env.advance(HEARTBEAT_MS);
  await until(() => w.calls.length === 2); // the next heartbeat's 200 tries again
  await p.env.advance(TRIGGER_GAP_MS);
  p.env.fire('visible');
  await until(() => beats(p.f).length === 3);
  await flush(60);
  assert.equal(w.calls.length, 2, 'not while the last call runs');
  const registration = fakeRegistration({ installing: fakeWorker('installing') });
  w.calls[1].resolve(registration);
  await flush(20);
  // The update rule now runs on it: a heartbeat whose build differs checks for an update through it.
  answer.build = '0.1.0-dev+ffffffffff';
  await p.env.advance(HEARTBEAT_MS);
  await until(() => registration.updates === 1);
  assert.equal(registration.listenerCount('updatefound'), 1, 'the install-failure throttle watches it');
  await p.env.advance(HEARTBEAT_MS * 2);
  await flush(20);
  assert.equal(w.calls.length, 2, 'never again once the page has a registration');
});

test('About\'s key check is a server answer too, and a start that had a registration never registers again', async () => {
  const w = manualRegisterWorker();
  const p = await page({ registered: true, boot: { registration: null, registerWorker: w.registerWorker },
    session: (f) => f.offline({ url: SESSION }, { times: Infinity }), heartbeat: (f) => f.offline({ url: HEARTBEAT }, { times: Infinity }) });
  await p.run();
  assert.equal(w.calls.length, 0, 'no answer yet: nothing is tried');
  p.f.json({ url: 'api/ping.php' }, 200, { ok: true, server_time: SERVER_TIME, build: 'b', authorization_received: true, script_path: 'api/ping.php' });
  p.env.setHash('#/about');
  await until(() => sectionClass(p.root) === 'view view-about' && factsOf(p.root)[COPY.about_key] === COPY.yes);
  await flush(20);
  assert.equal(w.calls.length, 1, 'the ping\'s 200');

  const registration = fakeRegistration({ active: fakeWorker('activated') });
  const q = manualRegisterWorker();
  const r = await page({ registered: true, boot: { registration, controlledAtLoad: false, registerWorker: q.registerWorker } });
  await r.run();
  await r.env.advance(HEARTBEAT_MS);
  await until(() => beats(r.f).length === 2);
  await flush(60);
  assert.equal(q.calls.length, 0, 'boot.js\'s registration is the one');
});

test('no worker registration is tried during a wipe, or from a window that lost the tablet', async () => {
  // A resumed stuck wipe: its heartbeats are answered 200, but the wipe removes this scope's workers at its end.
  const w = manualRegisterWorker();
  const p = await page({ registered: true, boot: { registration: null, registerWorker: w.registerWorker },
    meta: { wipe: { mode: 'Push Then Wipe', stage: 'pushing', started_at: SERVER_TIME, items_pushed: 0 } },
    rows: { outbox: [{ client_uuid: 'c1', seq: 1, state: 'conflict', kind: 'intake', created_at: 1 }] },
    heartbeat: (f) => f.json({ url: HEARTBEAT }, 200, revoked('Push Then Wipe'), {}, { times: Infinity }) });
  await p.run();
  await until(() => beats(p.f).length === 1);
  await flush(60);
  assert.equal(w.calls.length, 0);
  // The start-up heartbeat's 200 brings Erase now: the wipe has begun when the answer is done.
  const d = manualRegisterWorker();
  let answer;
  const q = await page({ registered: true, boot: { registration: null, registerWorker: d.registerWorker },
    heartbeat: (f) => { f.on({ url: HEARTBEAT }, () => new Promise((resolve) => { answer = resolve; })); f.hang({ url: HEARTBEAT }); } });
  const starting = q.run();
  await until(() => answer !== undefined);
  assert.equal(d.calls.length, 1, 'api/session.php\'s answer');
  d.calls[0].reject(new TypeError('refused'));
  await flush(20);
  answer(json(200, revoked('Wipe Now')));
  const app = await starting;
  await until(() => app.state() === 'WIPING');
  await flush(20);
  assert.equal(d.calls.length, 1, 'not after the heartbeat that brought the wipe');
  // api/session.php answers after another window took the tablet.
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  const s = manualRegisterWorker();
  const r = await page({ boot: { registration: null, registerWorker: s.registerWorker },
    session: (f) => f.on({ url: SESSION }, () => gate.then(() => json(200, sessionInfo())), { times: Infinity }) });
  const running = r.run();
  await until(() => r.f.requests.some((x) => x.url.endsWith(SESSION)));
  await new Promise((resolve) => r.env.locks.request('pfpms-primary', { steal: true }, () => { resolve(); return new Promise(() => {}); }));
  release();
  const appR = await running;
  await flush(20);
  assert.equal(appR.state(), 'REPLACED');
  assert.equal(s.calls.length, 0);
});

test('a worker registration that arrives once the tablet wipes or was erased is removed, and one that arrives in a replaced window is left alone', async () => {
  // api/session.php's 200 asks for the registration before the start-up heartbeat; it is still pending when that
  // heartbeat's answer starts a wipe (or erases the tablet), so the erase may already have removed this scope's workers.
  const lateAfter = async (heartbeat, reached) => {
    const w = manualRegisterWorker();
    const p = await page({ registered: true, boot: { registration: null, registerWorker: w.registerWorker }, heartbeat });
    const app = await p.run();
    await until(() => reached(app.state()));
    await flush(20);
    assert.equal(w.calls.length, 1, 'api/session.php\'s answer');
    const registration = fakeRegistration({ installing: fakeWorker('installing') });
    return { p, app, w, registration };
  };
  const cases = [
    ['Erase now, confirmation pending', (f) => { f.json({ url: HEARTBEAT }, 200, revoked('Wipe Now')); f.offline({ url: HEARTBEAT }, { times: Infinity }); },
      (s) => s === 'WIPING'],
    ['Erase now, confirmed', (f) => { f.json({ url: HEARTBEAT }, 200, revoked('Wipe Now')); f.json({ url: HEARTBEAT }, 200, { status: 'wiped', server_time: SERVER_TIME, build: 'b' }); },
      (s) => s === 'ERASED'],
    ['a 410', (f) => f.json({ url: HEARTBEAT }, 410, { error: 'wiped', message: 'x', status: 'wiped' }), (s) => s === 'ERASED'],
  ];
  for (const [name, heartbeat, done] of cases) {
    const { p, w, registration } = await lateAfter(heartbeat, done);
    w.calls[0].resolve(registration);
    await flush(20);
    assert.equal(registration.unregistered, true, `${name}: removed`);
    assert.equal(registration.listenerCount('updatefound'), 0, `${name}: not adopted`);
    await p.env.advance(HEARTBEAT_MS * 2);
    await flush(20);
    assert.equal(w.calls.length, 1, `${name}: never asked again`);
    assert.equal(registration.updates, 0, `${name}: no update check through it`);
  }
  // A removal that fails is logged, never thrown.
  const { p: e, w: we, registration: refusing } = await lateAfter(cases[2][1], cases[2][2]);
  refusing.unregister = async () => { throw new DOMException('x', 'InvalidStateError'); };
  we.calls[0].resolve(refusing);
  await flush(20);
  assert.ok(e.env.logs.includes('service worker not removed: InvalidStateError'), JSON.stringify(e.env.logs));
  assert.equal(refusing.listenerCount('updatefound'), 0);

  // Another window took the tablet while sw.php registered: that window runs on this very registration.
  const s = manualRegisterWorker();
  const r = await page({ boot: { registration: null, registerWorker: s.registerWorker } });
  const app = await r.run();
  assert.equal(app.state(), 'UNREGISTERED');
  assert.equal(s.calls.length, 1);
  await new Promise((resolve) => r.env.locks.request('pfpms-primary', { steal: true }, () => { resolve(); return new Promise(() => {}); }));
  await until(() => app.state() === 'REPLACED');
  const shared = fakeRegistration({ active: fakeWorker('activated') });
  s.calls[0].resolve(shared);
  await flush(20);
  assert.equal(shared.unregistered, false, 'never removed from a replaced window');
  assert.equal(shared.listenerCount('updatefound'), 0, 'not adopted either');
  assert.deepEqual(r.env.logs, []);
});
