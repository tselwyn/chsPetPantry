// Directives and wipes (50-design §7.6, X-4; S2 spec §3.16): meta.wipe is written before any deletion and re-read
// before every stage; Push Then Wipe never clears the outbox before confirming and never confirms before it is empty;
// the confirmation is retried with backoff, always with credentials same-origin; deleting closes, deletes (retrying
// while blocked), removes the pfpms- caches and this scope's workers; a 410 erases locally; Repair pings first.
import test from 'node:test';
import assert from 'node:assert/strict';
import { fakeEnv, flush } from './support/fake-env.js';
import { fakeFetch } from './support/fake-fetch.js';
import { openMemoryDb } from './support/memory-db.js';
import { fakeCaches, fakeContainer, fakeRegistration } from './support/fake-sw.js';
import { fakeDocument, text } from './support/fake-dom.js';
import { fakeLockManager } from './support/fake-locks.js';
import { createClock } from '../../public/station/js/clock.js';
import { createApi } from '../../public/station/js/api.js';
import { DbClosed } from '../../public/station/js/db.js';
import { importProofKey, proofMessage } from '../../public/station/js/proof.js';
import { b64url, sha256Hex } from '../../public/station/js/vault.js';
import { STUCK_RETRY_MS, TRIGGER_GAP_MS, WIPE_BACKOFF_MS, backoffMs, createDevice } from '../../public/station/js/device.js';
import { HEARTBEAT_MS, start } from '../../public/station/js/app.js';
import { COPY } from '../../public/station/js/copy.js';

const CRED = 'pfd1_' + 'W'.repeat(43);
const RAW = Uint8Array.from({ length: 32 }, (_, i) => 200 - i);
const HEARTBEAT = 'api/device/heartbeat.php';
const SERVER_TIME = '2026-10-01 12:00:00.000';
const SCOPE = 'http://localhost:8088/station/';
const json = (status, body, headers = {}) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
const okReply = (over = {}) => ({ status: 'ok', offline_enabled: true, label: 'E2E 1', site: { site_id: 3, name: 'Dev Site North' }, directive: null,
  revoked_grants: [], config: {}, server_time: SERVER_TIME, build: 'test+0000000000', ...over });
const directiveReply = (mode) => okReply({ status: 'revoked', offline_enabled: false, directive: { wipe: mode } });
const WIPED = { status: 'wiped', server_time: SERVER_TIME, build: 'test+0000000000' };
const SEEDED = { pack: [{ k: 'pack:1', v: 1 }], keyring: [{ user_id: 1, lookup: ['abc', 'def'], v: 1 }], vault_users: [{ k: 'user:1', v: 1 }],
  sessions: [{ k: 'session:1', v: 1 }], drafts: [{ k: 'intake:1', v: 1 }] };
const rec = (uuid, seq, state) => ({ client_uuid: uuid, seq, state, kind: 'intake', created_at: 1790855000000 + seq });

async function until(pred, ms = 10000) {
  const end = Date.now() + ms;
  for (;;) {
    if (await pred()) return;
    if (Date.now() > end) throw new Error('until: the condition never held');
    await new Promise((resolve) => setImmediate(resolve));
  }
}
const settle = () => flush(60);

const OPS = ['get', 'put', 'delete', 'all', 'keys', 'byIndex', 'count', 'clear'];
/** db.js's adapter with every call recorded, inside tx() too ('t.<op>'); put records [op, store, value, key]. */
function recordingDb(db) {
  const calls = [];
  const wrapOps = (o, prefix) => {
    const w = {};
    for (const m of OPS) w[m] = (s, ...a) => { calls.push([prefix + m, s, ...a]); return o[m](s, ...a); };
    return w;
  };
  const wrapped = { ...db, ...wrapOps(db, '') };
  wrapped.tx = (stores, mode, fn) => { calls.push(['tx', stores.join(','), mode]); return db.tx(stores, mode, (t) => fn(wrapOps(t, 't.'))); };
  wrapped.destroy = (o) => { calls.push(['destroy']); return db.destroy(o); };
  return { db: wrapped, calls };
}
const wipeStages = (calls) => calls.filter((c) => (c[0] === 'put' || c[0] === 't.put') && c[1] === 'meta' && c[3] === 'wipe').map((c) => c[2].stage);

/** A registered tablet with data in every store, and a device over the real modules. */
async function setup({ outbox = [], meta = {}, hooks: moreHooks = {}, caches = null, serviceWorker = null, seed = true } = {}) {
  const f = fakeFetch();
  const env = fakeEnv({ fetch: f, caches, serviceWorker });
  const { idb, db } = await openMemoryDb({ idb: env.indexedDB });
  const proof = await importProofKey(globalThis.crypto, RAW);
  await db.put('meta', { device_id: 7, credential: CRED, proof, site_id: 3, site_name: 'Dev Site North', label: 'E2E 1', iterations: 600000,
    registered_at: SERVER_TIME }, 'device');
  await db.put('meta', { next: 5 }, 'seq');
  await db.put('meta', { k: 'shift' }, 'shift');
  await db.put('meta', { at: SERVER_TIME }, 'pending_shift_end');
  for (const [k, v] of Object.entries(meta)) await db.put('meta', v, k);
  if (seed) for (const [store, rows] of Object.entries(SEEDED)) for (const r of rows) await db.put(store, r);
  for (const r of outbox) await db.put('outbox', r);
  const recorded = recordingDb(db);
  const clock = createClock({ env, db });
  await clock.load();
  let device = null;
  const api = createApi({ env, clock, device: () => device?.credentials() ?? null });
  const log = [];
  let repairs = 0;
  const ui = { wipe: (m) => log.push(['wipe', m.phase, m.n ?? null]), erased: (m) => log.push(['erased', m.final]), banner: (k, on) => log.push(['banner', k, on]) };
  const hooks = { onKeysDropped: (r) => log.push(['keys', r]), ...moreHooks };
  device = createDevice({ env, db: recorded.db, api, clock, ui, hooks, inflight: { begin() {}, end() {} },
    bootRepair: async () => { repairs += 1; return 'repaired'; } });
  await device.load();
  return { f, env, idb, db, calls: recorded.calls, clock, api, device, log, repairs: () => repairs };
}
const beats = (f) => f.requests.filter((r) => r.url.endsWith(HEARTBEAT));
const confirmations = (f) => beats(f).filter((r) => JSON.parse(r.body).wiped === true);
const erased = (t) => t.log.find((x) => x[0] === 'erased')?.[1] ?? null;
const phases = (t) => t.log.filter((x) => x[0] === 'wipe').map((x) => x[1]);
const storeRows = (t, store) => (t.idb._dump('pfpms')[store] ?? []);

test('Push Then Wipe runs start, pushing, confirming, deleting, writing meta.wipe before any deletion', async () => {
  const t = await setup();
  let atConfirm = null;
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Push Then Wipe'));
  t.f.on({ url: HEARTBEAT }, () => { atConfirm = t.idb._dump('pfpms'); return json(200, WIPED, { 'Clear-Site-Data': '"cache", "storage"' }); });
  await t.device.heartbeat();
  await until(() => erased(t) !== null);
  assert.deepEqual(wipeStages(t.calls), ['start', 'pushing', 'confirming', 'deleting']);
  const firstPut = t.calls.findIndex((c) => c[0] === 'put' && c[3] === 'wipe');
  const firstDeletion = t.calls.findIndex((c) => ['t.clear', 't.delete', 'clear', 'delete'].includes(c[0]));
  assert.ok(firstPut !== -1 && firstPut < firstDeletion, 'meta.wipe {stage: start} is written before any deletion');
  assert.equal(t.calls[firstPut][2].mode, 'Push Then Wipe');
  assert.equal(t.calls[firstPut][2].items_pushed, 0);
  assert.equal(t.calls[firstPut][2].started_at, SERVER_TIME);
  assert.deepEqual(Object.fromEntries(atConfirm.meta)['wipe'].stage, 'confirming');
  assert.deepEqual(phases(t), ['retiring', 'retiring', 'confirming']);
  assert.equal(erased(t), 'uploaded');
  assert.deepEqual(t.log.filter((x) => x[0] === 'keys'), [['keys', 'directive']]);
  assert.equal(t.idb._exists('pfpms'), false, 'the database is gone');
  assert.equal(t.device.registered(), false, 'the credentials are forgotten');
  assert.equal(t.env.reloads, 0);
});

test('start clears pack, keyring, vault_users, sessions, drafts and meta.shift in one transaction and keeps the outbox', async () => {
  const t = await setup({ outbox: [rec('q1', 1, 'queued')] });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => phases(t).includes('stuck'));
  const txIndex = t.calls.findIndex((c) => c[0] === 'tx' && c[2] === 'readwrite' && c[1].includes('pack'));
  assert.deepEqual(t.calls[txIndex].slice(1), ['pack,keyring,vault_users,sessions,drafts,meta', 'readwrite']);
  const inside = t.calls.slice(txIndex + 1, txIndex + 9).map((c) => [c[0], c[1], c[0] === 't.put' ? c[3] : c[2]]);
  assert.deepEqual(inside, [['t.clear', 'pack', undefined], ['t.clear', 'keyring', undefined], ['t.clear', 'vault_users', undefined],
    ['t.clear', 'sessions', undefined], ['t.clear', 'drafts', undefined], ['t.delete', 'meta', 'shift'], ['t.delete', 'meta', 'pending_shift_end'],
    ['t.put', 'meta', 'wipe']]);
  for (const store of ['pack', 'keyring', 'vault_users', 'sessions', 'drafts']) assert.deepEqual(storeRows(t, store), [], store);
  const meta = Object.fromEntries(storeRows(t, 'meta'));
  assert.equal(meta.shift, undefined);
  assert.equal(meta.pending_shift_end, undefined);
  assert.equal(meta.device.credential, CRED, 'meta.device stays until deleting');
  assert.deepEqual(meta.seq, { next: 5 });
  assert.equal(meta.wipe.stage, 'pushing');
  assert.deepEqual(storeRows(t, 'outbox').map(([k]) => k), ['q1'], 'the outbox is kept');
  assert.equal(confirmations(t.f).length, 0);
});

test('Wipe Now clears the outbox at start and goes to confirming', async () => {
  const t = await setup({ outbox: [rec('q1', 1, 'queued'), rec('c1', 2, 'conflict')] });
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Wipe Now'));
  t.f.json({ url: HEARTBEAT }, 200, WIPED);
  await t.device.heartbeat();
  await until(() => erased(t) !== null);
  const startTx = t.calls.find((c) => c[0] === 'tx' && c[2] === 'readwrite' && c[1].includes('pack'));
  assert.equal(startTx[1], 'pack,keyring,vault_users,sessions,drafts,meta,outbox');
  assert.ok(t.calls.some((c) => c[0] === 't.clear' && c[1] === 'outbox'));
  assert.deepEqual(wipeStages(t.calls), ['start', 'confirming', 'deleting'], 'no pushing stage');
  assert.deepEqual(phases(t), ['erasing', 'confirming']);
  assert.equal(erased(t), 'not_uploaded');
  assert.equal(JSON.parse(confirmations(t.f)[0].body).pending_count, 0);
});

test('a wipe resumes at every stage after a simulated reload', async () => {
  for (const mode of ['Push Then Wipe', 'Wipe Now']) {
    for (const stage of ['start', 'pushing', 'confirming', 'deleting']) {
      const t = await setup({ meta: { wipe: { mode, stage, started_at: SERVER_TIME, items_pushed: 0 } } });
      t.f.json({ url: HEARTBEAT }, 200, WIPED);
      assert.equal(t.device.wiping(), false, 'nothing runs before resumeWipe()');
      assert.equal(await t.device.resumeWipe(), true, `${mode} ${stage}`);
      assert.equal(t.device.wiping(), true);
      await until(() => erased(t) !== null);
      assert.equal(erased(t), mode === 'Wipe Now' ? 'not_uploaded' : 'uploaded', `${mode} ${stage}`);
      assert.equal(t.idb._exists('pfpms'), false);
      assert.equal(confirmations(t.f).length, stage === 'deleting' ? 0 : 1, `${mode} ${stage}: confirmations`);
      assert.deepEqual(t.log.filter((x) => x[0] === 'keys'), [], 'resuming drops no keys again (they were dropped at the directive)');
    }
  }
  const none = await setup();
  assert.equal(await none.device.resumeWipe(), false);
  assert.equal(none.device.wiping(), false);
  assert.equal(none.f.requests.length, 0);
});

test('records in conflict or invalid stop Push Then Wipe at pushing, retried hourly', async () => {
  assert.equal(STUCK_RETRY_MS, 3600000);
  const t = await setup({ outbox: [rec('c1', 1, 'conflict'), rec('i1', 2, 'invalid')] });
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Push Then Wipe'), {}, { times: Infinity });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => phases(t).includes('stuck'));
  assert.deepEqual(t.log.find((x) => x[1] === 'stuck'), ['wipe', 'stuck', 2]);
  // No heartbeat brought this wipe (a resumed wipe at a restart is the same): it asks the server at once, since only
  // a heartbeat brings an Administrator's Erase now.
  await until(() => beats(t.f).length === 1);
  await until(() => t.log.filter((x) => x[1] === 'stuck').length === 2);
  await settle();
  const hb = JSON.parse(beats(t.f)[0].body);
  assert.equal(hb.wiped, false);
  assert.equal(hb.pending_count, 2);
  assert.equal(hb.attention_count, 2);
  await t.env.advance(STUCK_RETRY_MS - 1);
  await settle();
  assert.equal(beats(t.f).length, 1, 'then not before the hour');
  await t.env.advance(1);
  await until(() => beats(t.f).length === 2);
  await until(() => t.log.filter((x) => x[1] === 'stuck').length === 3);
  await t.env.advance(STUCK_RETRY_MS);
  await until(() => beats(t.f).length === 3);
  await settle();
  assert.equal(confirmations(t.f).length, 0, 'never confirmed while records are stuck');
  assert.deepEqual(storeRows(t, 'outbox').map(([k]) => k), ['c1', 'i1'], 'the outbox is untouched');
  assert.equal(Object.fromEntries(storeRows(t, 'meta')).wipe.stage, 'pushing');
  assert.equal(erased(t), null);
});

test('queued records with no sync also stop it', async () => {
  const t = await setup({ outbox: [rec('q1', 1, 'queued')] });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => phases(t).includes('stuck'));
  assert.deepEqual(t.log.find((x) => x[1] === 'stuck'), ['wipe', 'stuck', 1]);
  await settle();
  assert.equal(confirmations(t.f).length, 0);
  assert.deepEqual(storeRows(t, 'outbox').map(([k]) => k), ['q1']);
});

test('an escalation to Wipe Now then clears the outbox and confirms', async () => {
  const t = await setup({ outbox: [rec('c1', 1, 'conflict'), rec('q1', 2, 'queued')] });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => phases(t).includes('stuck'));
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Wipe Now')); // the Administrator pressed Erase now
  let outboxAtConfirm = null;
  t.f.on({ url: HEARTBEAT }, () => { outboxAtConfirm = storeRows(t, 'outbox'); return json(200, WIPED); });
  await t.env.advance(STUCK_RETRY_MS);
  await until(() => erased(t) !== null);
  assert.deepEqual(outboxAtConfirm, [], 'the outbox was cleared before the confirmation');
  assert.equal(erased(t), 'not_uploaded');
  assert.ok(t.calls.some((c) => c[0] === 'tx' && c[1] === 'outbox,meta' && c[2] === 'readwrite'), 'one transaction clears the outbox and sets confirming');
  assert.deepEqual(wipeStages(t.calls), ['start', 'pushing', 'pushing', 'confirming', 'deleting'], 'the escalation rewrote meta.wipe at pushing');
  const written = t.calls.filter((c) => (c[0] === 'put' || c[0] === 't.put') && c[3] === 'wipe').map((c) => c[2].mode);
  assert.deepEqual(written, ['Push Then Wipe', 'Push Then Wipe', 'Wipe Now', 'Wipe Now', 'Wipe Now']);
});

test('the confirmation is sent only after the outbox is empty, with wiped true, items_pushed and a proof', async () => {
  let drained = 0;
  const t = await setup({ hooks: { drainForWipe: async () => { drained += 1; return { queued: 0, attention: 0, pushed: 4 }; } } });
  let outboxAtConfirm = null;
  t.f.on({ url: HEARTBEAT }, () => { outboxAtConfirm = storeRows(t, 'outbox'); return json(200, WIPED); });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => erased(t) !== null);
  assert.equal(drained, 1, 'S5\'s rescue push runs at pushing');
  assert.deepEqual(outboxAtConfirm, []);
  const [c] = confirmations(t.f);
  const body = JSON.parse(c.body);
  assert.equal(body.wiped, true);
  assert.equal(body.items_pushed, 4);
  assert.equal(body.pending_count, 0);
  assert.equal(body.attention_count, 0);
  assert.equal(body.max_seq, 4, 'meta.seq read into memory');
  assert.equal(c.headers['content-type'], 'application/json');
  assert.equal(c.headers.authorization, 'PFPMS-Device ' + CRED);
  const m = /^v1 (\d{13}) ([A-Za-z0-9_-]{43})$/.exec(c.headers['pfpms-proof']);
  assert.ok(m);
  const key = await globalThis.crypto.subtle.importKey('raw', RAW, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const msg = proofMessage('POST', HEARTBEAT, Number(m[1]), await sha256Hex(globalThis.crypto, c.body));
  assert.equal(b64url(await globalThis.crypto.subtle.sign('HMAC', key, new TextEncoder().encode(msg))), m[2]);
});

test('every device call during a wipe uses credentials same-origin', async () => {
  const t = await setup({ outbox: [rec('c1', 1, 'conflict')] });
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Push Then Wipe'));    // the directive (not yet wiping: omit)
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Wipe Now'));          // the hourly retry while stuck
  t.f.offline({ url: HEARTBEAT });                                        // a confirmation that got no answer
  t.f.json({ url: HEARTBEAT }, 200, WIPED);
  await t.device.heartbeat();
  await until(() => phases(t).includes('stuck'));
  await t.env.advance(STUCK_RETRY_MS);
  await until(() => confirmations(t.f).length === 1);
  await settle();
  await t.env.advance(WIPE_BACKOFF_MS[0]);
  await until(() => erased(t) !== null);
  const all = beats(t.f);
  assert.equal(all.length, 4);
  assert.equal(all[0].credentials, 'omit', 'before the directive');
  assert.deepEqual(all.slice(1).map((r) => r.credentials), ['same-origin', 'same-origin', 'same-origin']);
  // And the heartbeat refuses every other reason while wiping.
  assert.equal(await t.device.heartbeat({ reason: 'timer' }), null);
});

test('the confirmation is retried with backoff until 200 wiped or 410', async () => {
  assert.deepEqual(WIPE_BACKOFF_MS, [5000, 15000, 30000, 60000, 300000]);
  assert.deepEqual([0, 1, 2, 3, 4, 5, 9].map(backoffMs), [5000, 15000, 30000, 60000, 300000, 300000, 300000]);
  const t = await setup({ seed: false });
  t.f.offline({ url: HEARTBEAT });
  t.f.json({ url: HEARTBEAT }, 503, { error: 'maintenance', message: 'x' });
  t.f.json({ url: HEARTBEAT }, 429, { error: 'rate_limited', message: 'x' }, { 'Retry-After': '900' });
  t.f.json({ url: HEARTBEAT }, 500, { error: 'server_error', message: 'x', incident: 'I' });
  t.f.json({ url: HEARTBEAT }, 200, WIPED);
  await t.device.wipe({ wipe: 'Wipe Now' });
  await until(() => confirmations(t.f).length === 1);
  for (const [i, wait] of [[1, 5000], [2, 15000], [3, 30000], [4, 60000]]) {
    await settle();
    await t.env.advance(wait - 1);
    await settle();
    assert.equal(confirmations(t.f).length, i, `try ${i + 1} waits ${wait} ms`);
    await t.env.advance(1);
    await until(() => confirmations(t.f).length === i + 1);
  }
  await until(() => erased(t) !== null);
  assert.equal(confirmations(t.f).length, 5);
  assert.equal(new Set(confirmations(t.f).map((r) => JSON.parse(r.body).wiped)).size, 1);
  // A 410 ends it too.
  const u = await setup({ seed: false });
  u.f.offline({ url: HEARTBEAT });
  u.f.json({ url: HEARTBEAT }, 410, { error: 'wiped', message: 'x', status: 'wiped' });
  await u.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => confirmations(u.f).length === 1);
  await settle();
  await u.env.advance(5000);
  await until(() => erased(u) !== null);
  assert.equal(confirmations(u.f).length, 2);
  assert.equal(erased(u), 'uploaded');
});

test('a 200 ok (claim ignored) keeps retrying', async () => {
  const t = await setup({ seed: false });
  t.f.json({ url: HEARTBEAT }, 200, okReply());
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Wipe Now'));
  t.f.json({ url: HEARTBEAT }, 200, WIPED);
  await t.device.wipe({ wipe: 'Wipe Now' });
  await until(() => confirmations(t.f).length === 1);
  await settle();
  assert.equal(erased(t), null);
  await t.env.advance(5000);
  await until(() => confirmations(t.f).length === 2);
  await settle();
  assert.equal(erased(t), null, 'a revoked 200 is not the end either');
  await t.env.advance(15000);
  await until(() => erased(t) !== null);
  assert.equal(confirmations(t.f).length, 3);
});

test('a second device_proof_stale waits and retries', async () => {
  const t = await setup({ seed: false });
  const stale = { error: 'device_proof_stale', message: 'x', server_time: '2026-10-01 12:00:02.000' };
  t.f.json({ url: HEARTBEAT }, 401, stale, {}, { times: 2 });
  t.f.json({ url: HEARTBEAT }, 200, WIPED);
  await t.device.wipe({ wipe: 'Wipe Now' });
  await until(() => confirmations(t.f).length === 2);
  await settle();
  assert.equal(erased(t), null, 'api.js retried once with a fresh proof; the second stale waits');
  const [a, b] = confirmations(t.f).map((r) => r.headers['pfpms-proof'].split(' ')[1]);
  assert.notEqual(a, b, 'the retry carried a new timestamp');
  await t.env.advance(5000);
  await until(() => erased(t) !== null);
  assert.equal(confirmations(t.f).length, 3);
});

test('a DbClosed after the 200 goes to the final screen', async () => {
  const t = await setup();
  t.f.on({ url: HEARTBEAT }, () => { t.idb._forceClose('pfpms'); return json(200, WIPED, { 'Clear-Site-Data': '"cache", "storage"' }); });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => erased(t) !== null);
  assert.equal(erased(t), 'uploaded');
  assert.equal(t.idb._exists('pfpms'), false);
  assert.equal(t.device.registered(), false);
});

test('deleting closes the connection, deletes the database (retrying while blocked, with the close-other-window message), deletes only pfpms caches and unregisters only this scope', async () => {
  const caches = fakeCaches();
  for (const name of ['pfpms-shell-a', 'pfpms-shell-b', 'pfpms-other', 'another-app']) await caches.open(name);
  const ours = fakeRegistration({ scope: SCOPE });
  const theirs = fakeRegistration({ scope: 'http://localhost:8088/other/' });
  const serviceWorker = fakeContainer({ registrations: [ours, theirs] });
  const t = await setup({ caches, serviceWorker });
  // Another window's connection that does not close itself on versionchange.
  const other = await new Promise((resolve, reject) => { const r = t.idb.open('pfpms', 1); r.onsuccess = () => resolve(r.result); r.onerror = () => reject(r.error); });
  other.onversionchange = null;
  t.f.json({ url: HEARTBEAT }, 200, WIPED);
  await t.device.wipe({ wipe: 'Wipe Now' });
  await until(() => phases(t).includes('blocked'));
  const deletes = () => t.idb._log.filter((x) => x === 'deleteDatabase pfpms').length;
  assert.equal(deletes(), 1);
  await settle();
  await t.env.advance(1999);
  await settle();
  assert.equal(deletes(), 1, 'the retry waits 2 s');
  await t.env.advance(1);
  await until(() => deletes() === 2);
  await t.env.advance(10000);
  await settle();
  assert.equal(erased(t), null, 'still waiting for the other window');
  assert.equal(t.idb._exists('pfpms'), true);
  other.close();
  await until(() => erased(t) !== null);
  const log = t.idb._log;
  assert.ok(log.indexOf('close pfpms') !== -1 && log.indexOf('close pfpms') < log.indexOf('deleteDatabase pfpms'), 'its own connection closes first');
  assert.ok(log.filter((x) => x === 'deleteDatabase pfpms').length >= 2, 'asked again while blocked');
  assert.equal(t.idb._exists('pfpms'), false);
  assert.deepEqual(await caches.keys(), ['another-app']);
  assert.equal(ours.unregistered, true);
  assert.equal(theirs.unregistered, false);
  assert.equal(erased(t), 'not_uploaded');
});

test('the final screen depends on the mode', async () => {
  for (const [mode, final] of [['Push Then Wipe', 'uploaded'], ['Wipe Now', 'not_uploaded']]) {
    const t = await setup({ seed: false });
    t.f.json({ url: HEARTBEAT }, 200, WIPED);
    await t.device.wipe({ wipe: mode });
    await until(() => erased(t) !== null);
    assert.equal(erased(t), final, mode);
  }
  // Anything but 'Wipe Now' is a Push Then Wipe.
  const odd = await setup({ seed: false });
  odd.f.json({ url: HEARTBEAT }, 200, WIPED);
  await odd.device.wipe({ wipe: 'something' });
  await until(() => erased(odd) !== null);
  assert.equal(erased(odd), 'uploaded');
  // A 410 outside a wipe: it is not known whether the records were uploaded.
  const t = await setup();
  t.f.json({ url: HEARTBEAT }, 410, { error: 'wiped', message: 'x', status: 'wiped' });
  assert.equal(await t.device.heartbeat(), null);
  assert.equal(erased(t), 'unknown');
  assert.deepEqual(phases(t), ['erasing']);
  assert.deepEqual(t.log.filter((x) => x[0] === 'keys'), [['keys', 'erased']]);
  assert.equal(t.idb._exists('pfpms'), false);
  assert.equal(confirmations(t.f).length, 0, 'no confirmation: the server already holds it as erased');
});

/** app.js over a registered tablet in a fake page; heartbeat replies as given. */
async function appSetup(replies) {
  const doc = fakeDocument();
  const f = fakeFetch();
  f.json({ url: 'api/session.php' }, 200, { csrf: 'c', organisation_name: 'CHS Pet Pantry', server_time: SERVER_TIME, build: 'test+0000000000', dev_relax: false },
    {}, { times: Infinity });
  for (const r of replies) r(f);
  const env = fakeEnv({ document: doc, fetch: f, locks: fakeLockManager() });
  const { db } = await openMemoryDb({ idb: env.indexedDB });
  await db.put('meta', { device_id: 7, credential: CRED, proof: await importProofKey(globalThis.crypto, RAW), site_id: 3, site_name: 'Dev Site North',
    label: 'E2E 1', iterations: 600000, registered_at: SERVER_TIME }, 'device');
  await db.put('meta', { next: 1 }, 'seq');
  db.close();
  return { doc, f, env, root: doc.getElementById('app') };
}

test('a 410 at start-up or on any device call erases locally, and the page does not reload', async () => {
  const gone = (f) => f.json({ url: HEARTBEAT }, 410, { error: 'wiped', message: 'x', status: 'wiped' });
  // At start-up.
  let a = await appSetup([gone]);
  const app = await start({ registration: null, controlledAtLoad: false, started: () => true, repair: async () => 'repaired' }, { env: a.env });
  await until(() => app.state() === 'ERASED');
  assert.equal(text(a.root.querySelector('h1')), COPY.erased_unknown);
  assert.equal(a.env.indexedDB._exists('pfpms'), false);
  assert.equal(a.env.reloads, 0, 'its own destroy() never reloads the page');
  await a.env.advance(HEARTBEAT_MS * 2);
  assert.equal(a.env.reloads, 0);
  assert.equal(app.state(), 'ERASED');
  const button = a.root.querySelector('button');
  assert.equal(text(button), COPY.register_again);
  button.dispatch('click');
  assert.equal(a.env.reloads, 1, 'Register this tablet reloads');
  // On the 5-minute heartbeat.
  a = await appSetup([(f) => f.json({ url: HEARTBEAT }, 200, okReply()), gone]);
  const app2 = await start({ registration: null, controlledAtLoad: false, started: () => true, repair: async () => 'repaired' }, { env: a.env });
  assert.equal(app2.state(), 'REGISTERED');
  await a.env.advance(HEARTBEAT_MS);
  await until(() => app2.state() === 'ERASED');
  assert.equal(text(a.root.querySelector('h1')), COPY.erased_unknown);
  assert.equal(a.env.reloads, 0);
});

test('repair pings first: offline removes nothing and never calls bootRepair, online calls it once', async () => {
  const t = await setup();
  t.f.offline({ url: 'api/ping.php' });
  assert.equal(await t.device.repair(), 'offline');
  assert.equal(t.repairs(), 0);
  t.f.json({ url: 'api/ping.php' }, 503, { error: 'maintenance', message: 'x' });
  assert.equal(await t.device.repair(), 'offline', 'maintenance is offline too');
  assert.equal(t.repairs(), 0);
  t.f.json({ url: 'api/ping.php' }, 200, { ok: true, server_time: SERVER_TIME, build: 'b', authorization_received: false, script_path: 'api/ping.php' });
  assert.equal(await t.device.repair(), 'repaired');
  assert.equal(t.repairs(), 1);
  const pings = t.f.requests.filter((r) => r.url.endsWith('api/ping.php'));
  assert.equal(pings.length, 3);
  assert.equal('authorization' in pings[2].headers, false);
  assert.equal(pings[2].credentials, 'omit');
  assert.equal((await t.db.get('meta', 'device')).credential, CRED, 'nothing of the tablet is removed');
});

test('a directive during a running wipe only escalates the mode', async () => {
  const t = await setup({ outbox: [rec('c1', 1, 'conflict')] });
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Push Then Wipe'));
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => phases(t).includes('stuck'));
  await until(() => beats(t.f).length === 1); // the stuck wipe asked the server at once
  await settle();
  const before = Object.fromEntries(storeRows(t, 'meta')).wipe;
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  assert.deepEqual(Object.fromEntries(storeRows(t, 'meta')).wipe, before, 'the same directive changes nothing');
  t.f.offline({ url: HEARTBEAT }); // the confirmation after the escalation gets no answer the first time
  await t.device.wipe({ wipe: 'Wipe Now' });
  const escalated = Object.fromEntries(storeRows(t, 'meta')).wipe;
  assert.deepEqual(escalated, { ...before, mode: 'Wipe Now' }, 'only the mode changes; started_at and the stage stay');
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  assert.equal(Object.fromEntries(storeRows(t, 'meta')).wipe.mode, 'Wipe Now', 'never back down');
  assert.deepEqual(t.log.filter((x) => x[0] === 'keys'), [['keys', 'directive']], 'the keys were dropped once');
  // The escalation ends the stuck wait at once (no hour): the outbox is cleared, then the confirmation is sent.
  await until(() => confirmations(t.f).length === 1);
  assert.deepEqual(storeRows(t, 'outbox'), []);
  assert.equal(beats(t.f).length, 2, 'no stuck retry in between');
  t.f.json({ url: HEARTBEAT }, 200, WIPED);
  await settle();
  await t.env.advance(WIPE_BACKOFF_MS[0]);
  await until(() => erased(t) !== null);
  assert.equal(erased(t), 'not_uploaded');
  // A directive while confirming changes nothing.
  const c = await setup({ seed: false });
  c.f.offline({ url: HEARTBEAT });
  c.f.json({ url: HEARTBEAT }, 200, WIPED);
  await c.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => confirmations(c.f).length === 1);
  await settle();
  await c.device.wipe({ wipe: 'Wipe Now' });
  assert.equal(Object.fromEntries(storeRows(c, 'meta')).wipe.mode, 'Push Then Wipe');
  await c.env.advance(5000);
  await until(() => erased(c) !== null);
  assert.equal(erased(c), 'uploaded');
});

test('the heartbeat refuses every other reason while wiping; online and visible only make a stuck wipe ask now', async () => {
  const t = await setup({ outbox: [rec('c1', 1, 'conflict')] });
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Push Then Wipe'), {}, { times: Infinity });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => beats(t.f).length === 1); // the stuck wipe's own question, at once
  await until(() => t.log.filter((x) => x[1] === 'stuck').length === 2); // then it waits
  await settle();
  // While stuck: only the wipe sends.
  for (const reason of ['timer', 'startup', 'registered']) assert.equal(await t.device.heartbeat({ reason }), null, reason);
  await settle();
  assert.equal(beats(t.f).length, 1, 'no heartbeat but the wipe\'s own');
  assert.equal(await t.device.heartbeat({ reason: 'online' }), null);
  await settle();
  assert.equal(beats(t.f).length, 1, 'online within 20 s of the last heartbeat: nothing');
  // Waking the tablet or its network later makes the wipe ask now, not at the end of the hour (rate-limited).
  assert.equal(t.env.pendingTimers(), 1, 'the stuck wait\'s hour');
  await t.env.advance(TRIGGER_GAP_MS);
  assert.equal(await t.device.heartbeat({ reason: 'visible' }), null, 'heartbeat() itself sends nothing');
  await until(() => beats(t.f).length === 2);
  await until(() => t.log.filter((x) => x[1] === 'stuck').length === 3);
  await settle();
  assert.equal(t.env.pendingTimers(), 1, 'the nudged wait\'s timer is cleared: only the new wait\'s hour is pending');
  assert.equal(JSON.parse(beats(t.f)[1].body).wiped, false);
  assert.equal(beats(t.f)[1].credentials, 'same-origin', 'the wipe\'s own heartbeat (clearSite)');
  assert.equal(await t.device.heartbeat({ reason: 'online' }), null);
  await settle();
  assert.equal(beats(t.f).length, 2, 'again at most every 20 s');
  assert.equal(confirmations(t.f).length, 0);

  // While confirming: nothing but the confirmation, whatever the reason.
  const c = await setup({ seed: false });
  c.f.offline({ url: HEARTBEAT });
  c.f.json({ url: HEARTBEAT }, 200, WIPED);
  await c.device.wipe({ wipe: 'Wipe Now' });
  await until(() => confirmations(c.f).length === 1);
  await settle();
  for (const reason of ['timer', 'online', 'visible', 'startup']) assert.equal(await c.device.heartbeat({ reason }), null, reason);
  await settle();
  assert.equal(beats(c.f).length, 1, 'no heartbeat besides the confirmation');
  await c.env.advance(WIPE_BACKOFF_MS[0]);
  await until(() => erased(c) !== null);
  assert.equal(beats(c.f).length, 2);
});

test('confirming clears every record still in the outbox, in states that do not block the wipe too', async () => {
  // S5's 'held' and 'refused' records do not stop Push Then Wipe (only queued, conflict and invalid do); the
  // confirmation says the tablet holds nothing, so they are cleared before it is sent.
  const t = await setup({ outbox: [rec('h1', 1, 'held'), rec('r1', 2, 'refused')] });
  let outboxAtConfirm = null;
  t.f.on({ url: HEARTBEAT }, () => { outboxAtConfirm = storeRows(t, 'outbox').map(([k]) => k); return json(200, WIPED); });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => erased(t) !== null);
  assert.deepEqual(outboxAtConfirm, [], 'cleared before the confirmation');
  assert.equal(JSON.parse(confirmations(t.f)[0].body).pending_count, 0);
  assert.equal(erased(t), 'uploaded');
});

test('a 410 while a Retire wipe is stuck erases the tablet by itself, with or without Clear-Site-Data', async () => {
  for (const clearSite of [false, true]) {
    const t = await setup({ outbox: [rec('c1', 1, 'conflict')] });
    t.f.json({ url: HEARTBEAT }, 200, directiveReply('Push Then Wipe')); // the stuck wipe's first question
    t.f.on({ url: HEARTBEAT }, () => {
      if (clearSite) t.idb._forceClose('pfpms'); // what Chromium does with the 410's Clear-Site-Data
      return json(410, { error: 'wiped', message: 'x', status: 'wiped' }, { 'Clear-Site-Data': '"cache", "storage"' });
    });
    await t.device.wipe({ wipe: 'Push Then Wipe' });
    await until(() => beats(t.f).length === 1);
    await settle();
    await t.env.advance(STUCK_RETRY_MS); // the hourly retry: the server already holds the tablet as erased
    await until(() => erased(t) !== null);
    assert.equal(erased(t), 'unknown', `${clearSite}: it is not known whether the records were uploaded`);
    assert.equal(t.idb._exists('pfpms'), false, `${clearSite}: the database is gone`);
    assert.equal(confirmations(t.f).length, 0, `${clearSite}: nothing to confirm`);
    assert.equal(t.device.registered(), false);
    const n = beats(t.f).length;
    await t.env.advance(STUCK_RETRY_MS * 2);
    await settle();
    assert.equal(beats(t.f).length, n, `${clearSite}: no further heartbeat`);
  }
});

test('a closed database while a Retire wipe is stuck is never read as an empty outbox', async () => {
  // Only the server says the tablet is erased (a 410 or a confirmed wipe). A connection that closed under the page
  // (here: this page's own close) must not let the wipe confirm and delete records it never uploaded.
  const lost = [];
  const t = await setup({ outbox: [rec('c1', 1, 'conflict'), rec('i1', 2, 'invalid')], hooks: { onStorageLost: () => lost.push('lost') } });
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Push Then Wipe'), {}, { times: Infinity });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => t.log.filter((x) => x[1] === 'stuck').length === 2);
  await settle();
  t.db.close();
  await t.env.advance(STUCK_RETRY_MS);
  await until(() => t.env.logs.some((l) => l.startsWith('wipe stopped')));
  await settle();
  assert.ok(t.env.logs.includes('wipe stopped: DbClosed'), JSON.stringify(t.env.logs));
  assert.equal(confirmations(t.f).length, 0, 'never confirmed');
  assert.equal(erased(t), null);
  assert.equal(t.idb._exists('pfpms'), true);
  assert.deepEqual(storeRows(t, 'outbox').map(([k]) => k), ['c1', 'i1'], 'the records are still there for the next start');
  // Nothing is left here to ask the server again (wiping() stays true, so no other heartbeat goes out): the page
  // asks for a fresh start once, instead of saying "uploading" for ever.
  assert.deepEqual(lost, ['lost'], 'onStorageLost: the next start decides');
  await t.env.advance(STUCK_RETRY_MS * 3);
  await settle();
  assert.deepEqual(lost, ['lost'], 'once');
  assert.equal(t.env.pendingTimers(), 0);
});

test('a DbClosed from the rescue push ends the run with a fresh start, but a stopped window logs and asks for nothing', async () => {
  // S5's drainForWipe meets the closed storage (cleared under the page): the next start decides.
  const lost = [];
  const t = await setup({ hooks: { drainForWipe: async () => { throw new DbClosed(); }, onStorageLost: () => lost.push('t') } });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => lost.length > 0);
  await settle();
  assert.deepEqual(lost, ['t']);
  assert.ok(t.env.logs.includes('wipe stopped: DbClosed'));
  assert.equal(confirmations(t.f).length, 0);
  // The window was replaced while the drain ran (onLost: stop(), then its own db.close()): the drain's DbClosed is
  // the replaced window's own doing, and the new primary window resumes the wipe.
  let fail;
  const u = await setup({ hooks: { drainForWipe: () => new Promise((resolve, reject) => { fail = reject; }), onStorageLost: () => lost.push('u') } });
  await u.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => fail !== undefined);
  u.device.stop();
  u.db.close();
  fail(new DbClosed());
  await settle();
  assert.deepEqual(lost, ['t'], 'no fresh start asked by a replaced window');
  assert.deepEqual(u.env.logs, [], 'and no "wipe stopped" line');
});

/** The device's own transactions (t.db.tx, under its recording wrapper) reject `errors` in turn, one per transaction
 *  that `match(stores, mode)` accepts; every other transaction runs as before. Patches compose. */
function failTransactions(t, match, errors) {
  const tx = t.db.tx;
  const left = [...errors];
  t.db.tx = (stores, mode, fn) => (left.length > 0 && match(stores.join(','), mode) ? Promise.reject(left.shift()) : tx(stores, mode, fn));
}
const startTx = (stores, mode) => mode === 'readwrite' && stores.startsWith('pack,'); // the start stage's one transaction
const statsTx = (stores, mode) => mode === 'readonly' && stores === 'outbox';         // outboxStats() at pushing
const startWipe = (mode) => ({ mode, stage: 'start', started_at: SERVER_TIME, items_pushed: 0 });

test('a wipe run that fails on anything but a closed storage runs again after the backoff: no fresh start, no tight loop', async () => {
  // A TypeError at start, once: logged, then the same wipe again after backoffMs(0), to its end.
  const lost = [];
  const t = await setup({ hooks: { onStorageLost: () => lost.push('t') } });
  t.f.json({ url: HEARTBEAT }, 200, WIPED);
  failTransactions(t, startTx, [new TypeError('x')]);
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => t.env.logs.length > 0);
  await settle();
  assert.deepEqual(t.env.logs, ['wipe stopped: TypeError']);
  assert.equal(t.device.wiping(), true, 'still wiping, so no other heartbeat goes out meanwhile');
  assert.equal(t.env.pendingTimers(), 1, 'the backoff');
  const seen = { calls: t.calls.length, requests: t.f.requests.length };
  await t.env.advance(backoffMs(0) - 1);
  await settle();
  assert.deepEqual([t.calls.length, t.f.requests.length], [seen.calls, seen.requests], 'nothing before the backoff is over');
  await t.env.advance(1);
  await until(() => erased(t) !== null);
  assert.equal(erased(t), 'uploaded');
  assert.deepEqual(wipeStages(t.calls), ['start', 'pushing', 'confirming', 'deleting']);
  assert.equal(confirmations(t.f).length, 1);
  assert.deepEqual(t.env.logs, ['wipe stopped: TypeError'], 'one line for the one failure');
  assert.deepEqual(t.log.filter((x) => x[0] === 'keys'), [['keys', 'directive']], 'the keys were dropped once');
  assert.deepEqual(lost, [], 'only a closed storage asks for a fresh start');
  assert.equal(t.env.reloads, 0);

  // A QuotaExceededError at start, then Chromium's UnknownError at pushing: the waits grow as the confirmation's do.
  const q = await setup({ hooks: { onStorageLost: () => lost.push('q') } });
  q.f.json({ url: HEARTBEAT }, 200, WIPED);
  failTransactions(q, startTx, [new DOMException('The quota has been exceeded.', 'QuotaExceededError')]);
  failTransactions(q, statsTx, [new DOMException('Internal error.', 'UnknownError')]);
  await q.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => q.env.logs.length === 1);
  await settle();
  await q.env.advance(backoffMs(0));
  await until(() => q.env.logs.length === 2);
  await settle();
  assert.deepEqual(q.env.logs, ['wipe stopped: QuotaExceededError', 'wipe stopped: UnknownError']);
  assert.deepEqual(storeRows(q, 'outbox'), []);
  assert.equal(Object.fromEntries(storeRows(q, 'meta')).wipe.stage, 'pushing', 'the start stage was done by the second run');
  const calls = q.calls.length;
  await q.env.advance(backoffMs(1) - 1);
  await settle();
  assert.equal(q.calls.length, calls, `the second failure in a row waits ${backoffMs(1)} ms`);
  await q.env.advance(1);
  await until(() => erased(q) !== null);
  assert.equal(erased(q), 'uploaded');
  assert.equal(confirmations(q.f).length, 1);
  assert.deepEqual(lost, []);
  assert.equal(q.env.reloads, 0);

  // stop() during the backoff ends it: no request, database call, screen change or log line from then on.
  const s = await setup({ hooks: { onStorageLost: () => lost.push('s') } });
  s.f.json({ url: HEARTBEAT }, 200, WIPED, {}, { times: Infinity });
  failTransactions(s, startTx, [new TypeError('x')]);
  await s.device.wipe({ wipe: 'Wipe Now' });
  await until(() => s.env.logs.length > 0);
  await settle();
  assert.equal(s.env.pendingTimers(), 1);
  const before = { calls: s.calls.length, requests: s.f.requests.length, log: s.log.length, logs: s.env.logs.length };
  s.device.stop();
  assert.equal(s.env.pendingTimers(), 0, 'the backoff is cleared');
  await s.env.advance(STUCK_RETRY_MS);
  await settle();
  assert.deepEqual({ calls: s.calls.length, requests: s.f.requests.length, log: s.log.length, logs: s.env.logs.length }, before);
  assert.equal(Object.fromEntries(storeRows(s, 'meta')).wipe.stage, 'start', 'meta.wipe is left for the window that resumes it');
  assert.equal(s.idb._exists('pfpms'), true);
  assert.deepEqual(lost, []);
});

test('the retried run is still the one wipe runner, and a later runner counts its failures from the first backoff again', async () => {
  // While the retried run waits for the confirmation's answer, a resume or a directive starts no second run.
  const t = await setup({ seed: false });
  let answer;
  t.f.on({ url: HEARTBEAT }, () => new Promise((resolve) => { answer = resolve; }));
  failTransactions(t, startTx, [new TypeError('x')]);
  await t.device.wipe({ wipe: 'Wipe Now' });
  await until(() => t.env.logs.length > 0);
  await settle();
  assert.equal(await t.device.resumeWipe(), true);
  await t.device.wipe({ wipe: 'Wipe Now' });
  await settle();
  assert.equal(t.f.requests.length, 0, 'during the backoff: it is not cut short, and nothing runs beside it');
  await t.env.advance(backoffMs(0));
  await until(() => answer !== undefined);
  assert.equal(await t.device.resumeWipe(), true);
  await t.device.wipe({ wipe: 'Wipe Now' });
  await settle();
  assert.equal(confirmations(t.f).length, 1, 'one run, so one confirmation in flight');
  answer(json(200, WIPED));
  await until(() => erased(t) !== null);
  await settle();
  assert.equal(confirmations(t.f).length, 1);
  assert.equal(erased(t), 'not_uploaded');

  // A run that ends normally (meta.wipe is gone at the next try) ends its runner; the next runner's first failure
  // waits backoffMs(0) again, not the third wait in a row.
  const r = await setup({ seed: false, meta: { wipe: startWipe('Wipe Now') } });
  failTransactions(r, startTx, [new TypeError('a'), new TypeError('b')]);
  assert.equal(await r.device.resumeWipe(), true);
  await until(() => r.env.logs.length === 1);
  await settle();
  await r.env.advance(backoffMs(0));
  await until(() => r.env.logs.length === 2);
  await settle();
  await r.db.delete('meta', 'wipe');
  await r.env.advance(backoffMs(1));
  await settle();
  assert.equal(r.env.pendingTimers(), 0, 'nothing is left to retry');
  assert.equal(r.f.requests.length, 0);
  await r.db.put('meta', startWipe('Wipe Now'), 'wipe');
  failTransactions(r, startTx, [new TypeError('c')]);
  r.f.json({ url: HEARTBEAT }, 200, WIPED);
  assert.equal(await r.device.resumeWipe(), true);
  await until(() => r.env.logs.length === 3);
  await settle();
  await r.env.advance(backoffMs(0) - 1);
  await settle();
  assert.equal(confirmations(r.f).length, 0);
  await r.env.advance(1);
  await until(() => confirmations(r.f).length === 1, 3000);
  await until(() => erased(r) !== null);
  assert.deepEqual(r.env.logs, ['wipe stopped: TypeError', 'wipe stopped: TypeError', 'wipe stopped: TypeError']);
});

test('stop() while deleting: nothing after the step that was running (no cache, worker or erased screen)', async () => {
  const make = async () => {
    const caches = fakeCaches();
    await caches.open('pfpms-shell-a');
    const ours = fakeRegistration({ scope: SCOPE });
    const serviceWorker = fakeContainer({ registrations: [ours] });
    const t = await setup({ caches, serviceWorker, seed: false });
    t.f.json({ url: HEARTBEAT }, 200, WIPED);
    return { t, caches, ours, serviceWorker };
  };
  const nothingAfter = async ({ t, caches, ours }, what) => {
    await t.env.advance(10000);
    await settle();
    assert.equal(erased(t), null, `${what}: no erased screen`);
    assert.equal(ours.unregistered, false, `${what}: the worker the new primary window runs on stays`);
    return caches;
  };

  // 1. The database delete is blocked by another window's connection when this window is replaced.
  const a = await make();
  const other = await new Promise((resolve, reject) => { const r = a.t.idb.open('pfpms', 1); r.onsuccess = () => resolve(r.result); r.onerror = () => reject(r.error); });
  other.onversionchange = null;
  await a.t.device.wipe({ wipe: 'Wipe Now' });
  await until(() => phases(a.t).includes('blocked'));
  a.t.device.stop();
  other.close(); // the delete goes on now
  await until(() => !a.t.idb._exists('pfpms'));
  assert.deepEqual(await (await nothingAfter(a, 'blocked delete')).keys(), ['pfpms-shell-a'], 'the shared pfpms- caches stay');

  // 2. Replaced while the caches are listed.
  const b = await make();
  let listed;
  const keys = b.caches.keys.bind(b.caches);
  b.caches.keys = () => new Promise((resolve) => { listed = () => resolve(keys()); });
  await b.t.device.wipe({ wipe: 'Wipe Now' });
  await until(() => listed !== undefined);
  b.t.device.stop();
  listed();
  await nothingAfter(b, 'caches');

  // 3. Replaced while the workers are listed.
  const c = await make();
  let found;
  const regs = c.serviceWorker.getRegistrations.bind(c.serviceWorker);
  c.serviceWorker.getRegistrations = () => new Promise((resolve) => { found = () => resolve(regs()); });
  await c.t.device.wipe({ wipe: 'Wipe Now' });
  await until(() => found !== undefined);
  c.t.device.stop();
  found();
  await settle();
  assert.equal(erased(c.t), null, 'workers: no erased screen');
});

test('a wall clock set back after a heartbeat never holds the next online/visible one off, and the gap still holds after it', async () => {
  // A stuck Retire wipe: the tablet waking asks the server at once even though the clock now reads an hour earlier.
  const t = await setup({ outbox: [rec('c1', 1, 'conflict')] });
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Push Then Wipe'), {}, { times: Infinity });
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => beats(t.f).length === 1);
  await until(() => t.log.filter((x) => x[1] === 'stuck').length === 2);
  await settle();
  t.env.jumpWall(-60 * 60 * 1000); // an OS time sync
  await t.env.advance(TRIGGER_GAP_MS);
  await t.device.heartbeat({ reason: 'visible' });
  await until(() => beats(t.f).length === 2);
  await until(() => t.log.filter((x) => x[1] === 'stuck').length === 3);
  await settle();
  await t.device.heartbeat({ reason: 'visible' });
  await settle();
  assert.equal(beats(t.f).length, 2, 'then again at most every TRIGGER_GAP_MS');
  // A registered tablet: the same for its own online/visible heartbeats.
  const r = await setup();
  r.f.json({ url: HEARTBEAT }, 200, okReply(), {}, { times: Infinity });
  await r.device.heartbeat({ reason: 'timer' });
  r.env.jumpWall(-60 * 60 * 1000);
  await r.env.advance(1000);
  assert.notEqual(await r.device.heartbeat({ reason: 'online' }), null, 'not held off for the hour the clock went back');
  assert.equal(await r.device.heartbeat({ reason: 'visible' }), null, 'the 20-s gap holds from the new time');
  assert.equal(beats(r.f).length, 2);
});

test('stop() ends a running wipe: no request, write, deletion, screen change, log line or timer from then on', async () => {
  // Stuck at pushing.
  const t = await setup({ outbox: [rec('c1', 1, 'conflict'), rec('i1', 2, 'invalid')] });
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Push Then Wipe'));
  await t.device.wipe({ wipe: 'Push Then Wipe' });
  await until(() => t.log.filter((x) => x[1] === 'stuck').length === 2);
  await settle();
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Wipe Now'), {}, { times: Infinity }); // what it would hear next
  const seen = { log: t.log.length, requests: t.f.requests.length, calls: t.calls.length, logs: t.env.logs.length };
  t.device.stop();
  assert.equal(t.env.pendingTimers(), 0, 'the stuck wait is cleared');
  assert.equal(t.device.registered(), false, 'the credentials are forgotten');
  for (const reason of ['online', 'visible', 'timer', 'wipe']) assert.equal(await t.device.heartbeat({ reason }), null, reason);
  await t.device.wipe({ wipe: 'Wipe Now' });
  assert.equal(await t.device.resumeWipe(), false);
  await t.device.eraseLocally('unknown');
  await t.env.advance(STUCK_RETRY_MS * 3);
  await settle();
  assert.equal(t.f.requests.length, seen.requests, 'no request');
  assert.equal(t.calls.length, seen.calls, 'no database call');
  assert.equal(t.log.length, seen.log, 'no screen change');
  assert.equal(t.env.logs.length, seen.logs, 'no log line');
  assert.equal(t.idb._exists('pfpms'), true);
  assert.deepEqual(storeRows(t, 'outbox').map(([k]) => k), ['c1', 'i1']);
  assert.equal(Object.fromEntries(storeRows(t, 'meta')).wipe.stage, 'pushing', 'meta.wipe is left for the window that resumes it');

  // Confirming, the confirmation offline.
  const c = await setup({ seed: false });
  c.f.offline({ url: HEARTBEAT }, { times: Infinity });
  await c.device.wipe({ wipe: 'Wipe Now' });
  await until(() => confirmations(c.f).length === 1);
  await settle();
  const before = { requests: c.f.requests.length, log: c.log.length, logs: c.env.logs.length };
  c.device.stop();
  assert.equal(c.env.pendingTimers(), 0, 'the backoff is cleared');
  await c.env.advance(30 * 60 * 1000);
  await settle();
  assert.equal(c.f.requests.length, before.requests, 'no further confirmation');
  assert.equal(c.log.length, before.log);
  assert.equal(c.env.logs.length, before.logs);
  assert.equal(c.idb._exists('pfpms'), true, 'nothing deleted');

  // A heartbeat answer that arrives after stop() (here a directive) is dropped.
  const h = await setup();
  let answer;
  h.f.on({ url: HEARTBEAT }, () => new Promise((resolve) => { answer = resolve; }));
  const beat = h.device.heartbeat({ reason: 'timer' });
  await until(() => answer !== undefined);
  h.device.stop();
  answer(json(200, directiveReply('Wipe Now')));
  assert.equal(await beat, null);
  await settle();
  assert.equal(h.device.wiping(), false, 'no wipe starts');
  assert.deepEqual(h.log, [], 'no screen, no banner, no keys dropped');
  assert.equal(Object.fromEntries(storeRows(h, 'meta')).wipe, undefined);
  assert.equal(Object.fromEntries(storeRows(h, 'meta')).last_heartbeat_at, undefined, 'nothing saved');
});

test('a resumed stuck wipe asks the server at once, so Erase now arrives within one heartbeat of a start', async () => {
  const t = await setup({ outbox: [rec('c1', 1, 'conflict')], meta: { wipe: { mode: 'Push Then Wipe', stage: 'pushing', started_at: SERVER_TIME, items_pushed: 0 } } });
  t.f.json({ url: HEARTBEAT }, 200, directiveReply('Wipe Now')); // an Administrator pressed Erase now while the tablet was off
  t.f.json({ url: HEARTBEAT }, 200, WIPED);
  assert.equal(await t.device.resumeWipe(), true);
  await until(() => erased(t) !== null);
  assert.equal(erased(t), 'not_uploaded');
  assert.deepEqual(beats(t.f).map((r) => JSON.parse(r.body).wiped), [false, true], 'one heartbeat, then the confirmation');
});
