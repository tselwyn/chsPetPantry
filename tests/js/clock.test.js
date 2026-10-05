// public/station/js/clock.js (50-design §7.4): the server offset from the midpoint, the high-water mark, backward
// jumps re-based (and only backward ones: a forward jump cannot be told from sleep), rollbacks while closed, and
// persistence of meta.clock. Driven by fakeEnv's virtual clocks over the real db.js and the memory IndexedDB.
import test from 'node:test';
import assert from 'node:assert/strict';
import { JUMP_MS, PERSIST_MS, ROLLBACK_MS, createClock } from '../../public/station/js/clock.js';
import { DbClosed } from '../../public/station/js/db.js';
import { formatDb } from '../../public/station/js/canonical.js';
import { DEFAULT_NOW, fakeEnv, flush } from './support/fake-env.js';
import { openMemoryDb } from './support/memory-db.js';

/** A started clock: fakeEnv, the memory database (optionally seeded with meta.clock), and load() done. */
async function setup({ saved = undefined, env = fakeEnv(), db = null } = {}) {
  const database = db ?? (await openMemoryDb()).db;
  if (saved !== undefined) await database.put('meta', saved, 'clock');
  const clock = createClock({ env, db: database });
  const loaded = await clock.load();
  return { env, db: database, clock, loaded };
}

/** db with every write recorded: ['put', store, key] and ['tx', stores, mode, [puts inside]]. */
function recording(db) {
  const writes = [];
  return {
    writes,
    db: {
      ...db,
      put: (s, v, k) => { writes.push(['put', s, k]); return db.put(s, v, k); },
      tx: (stores, mode, fn) => {
        const inside = [];
        writes.push(['tx', stores, mode, inside]);
        return db.tx(stores, mode, (t) => fn({ ...t, put: (s, v, k) => { inside.push([s, k]); return t.put(s, v, k); } }));
      },
    },
  };
}

/** The clock learns that the server is `offset` ms ahead, with a symmetric round trip of `rtt` ms. */
async function learnOffset(env, clock, offset, rtt = 0) {
  const sent = env.now();
  await env.advance(rtt);
  const received = env.now();
  clock.learn(formatDb(Math.round((sent + received) / 2) + offset), sent, received);
}

test('learn sets the offset from the midpoint and trusts it', async () => {
  const { env, db, clock } = await setup();
  assert.equal(clock.trusted(), false);
  assert.equal(clock.offsetMs(), null);
  assert.equal(clock.serverNow(), env.now(), 'no offset yet: the tablet clock');
  const sent = env.now();
  await env.advance(400);
  const received = env.now();
  // The server's clock read 90 s ahead of the tablet's at the midpoint of the round trip.
  clock.learn(formatDb(sent + 200 + 90000), sent, received);
  assert.equal(clock.trusted(), true);
  assert.equal(clock.offsetMs(), 90000);
  assert.equal(clock.serverNow(), env.now() + 90000);
  assert.deepEqual(clock.snapshot(), { offset_ms: 90000, trusted: true, high_water_ms: env.now(), measured_at: env.now() });
  await flush(20);
  assert.deepEqual(await db.get('meta', 'clock'), clock.snapshot(), 'persisted at once');
  // A tablet ahead of the server gives a negative offset; an odd midpoint rounds.
  clock.learn(formatDb(env.now() - 5000), env.now(), env.now() + 1);
  assert.equal(clock.offsetMs(), -5000);
  assert.ok(Number.isInteger(clock.snapshot().offset_ms));
});

test('learn ignores unparsable text and reversed times', async () => {
  const { env, db, clock } = await setup();
  const now = env.now();
  const good = formatDb(now + 1000);
  for (const [text, sent, received] of [
    ['not a time', now, now], [null, now, now], [undefined, now, now], [12345, now, now],
    ['2026-10-01T12:00:01.000Z', now, now], ['2026-02-30 12:00:00.000', now, now], ['2026-10-01 12:00:01', now, now],
    [good, now, now - 1], [good, Number.NaN, now], [good, now, Infinity], [good, String(now), now], [good, now, undefined],
  ]) {
    clock.learn(text, sent, received);
    assert.equal(clock.trusted(), false, String(text));
    assert.equal(clock.offsetMs(), null);
  }
  assert.deepEqual(clock.snapshot(), { offset_ms: null, trusted: false, high_water_ms: now, measured_at: null });
  await flush(20);
  assert.equal(await db.get('meta', 'clock'), undefined, 'nothing written');
});

test('learn ignores a server time no proof can carry (13 digits of ms: 2001-09-09 to 2286-11-20)', async () => {
  // Learned and saved, such a time would make every device call fail before it is sent, so no answer could correct it.
  const { env, db, clock } = await setup();
  const now = env.now();
  assert.equal(formatDb(1e12 - 1), '2001-09-09 01:46:39.999');
  assert.equal(formatDb(1e13), '2286-11-20 17:46:40.000');
  // A host clock reset to 2000, a proxy's first or last instant, and one ms either side of the range.
  for (const text of ['2000-01-01 00:00:00.000', '0000-01-01 00:00:00.000', '9999-12-31 23:59:59.999', formatDb(1e12 - 1), formatDb(1e13)]) {
    clock.learn(text, now, now);
    assert.equal(clock.trusted(), false, text);
    assert.equal(clock.offsetMs(), null, text);
  }
  assert.deepEqual(clock.snapshot(), { offset_ms: null, trusted: false, high_water_ms: now, measured_at: null });
  assert.equal(clock.serverNow(), now, 'the tablet clock still');
  await flush(20);
  assert.equal(await db.get('meta', 'clock'), undefined, 'nothing written');
  // The first and the last instant a proof can carry are learned; a later time out of range leaves the offset alone.
  clock.learn(formatDb(1e12), now, now);
  assert.equal(clock.offsetMs(), 1e12 - now);
  clock.learn(formatDb(1e13 - 1), now, now);
  assert.equal(clock.offsetMs(), 1e13 - 1 - now);
  assert.equal(clock.serverNow(), 1e13 - 1);
  clock.learn('2000-01-01 00:00:00.000', now, now);
  assert.equal(clock.offsetMs(), 1e13 - 1 - now);
  await flush(20);
  assert.equal((await db.get('meta', 'clock')).offset_ms, 1e13 - 1 - now, 'what was saved is the last time in range');
});

test('serverNow is max(now, high-water) plus the offset', async () => {
  // A high-water mark 2 minutes ahead of the tablet clock (set back by less than ROLLBACK_MS while closed).
  const env = fakeEnv();
  const now = env.now();
  const { clock, loaded } = await setup({ env, saved: { offset_ms: 5000, trusted: true, high_water_ms: now + 120000, measured_at: now - 1000 } });
  assert.equal(loaded.rolledBack, false);
  assert.equal(clock.serverNow(), now + 120000 + 5000, 'the high-water mark holds');
  await env.advance(119000);
  assert.equal(clock.serverNow(), now + 120000 + 5000, 'still held');
  await env.advance(1000 + 2500);
  assert.equal(clock.serverNow(), env.now() + 5000, 'the tablet clock again, once past the mark');
  assert.equal(clock.offsetMs(), 5000);
  // Untrusted without an offset: max(now, high-water) alone.
  const fresh = await setup();
  assert.equal(fresh.clock.serverNow(), fresh.env.now());
  // Untrusted with an offset (after a rollback): the offset still applies, but offsetMs() is null.
  const rolled = await setup({ saved: { offset_ms: 0, trusted: true, high_water_ms: DEFAULT_NOW + ROLLBACK_MS + 1000, measured_at: null } });
  assert.equal(rolled.clock.trusted(), false);
  assert.equal(rolled.clock.offsetMs(), null);
  assert.equal(rolled.clock.serverNow(), rolled.env.now() + rolled.clock.snapshot().offset_ms);
});

test('a backward jump beyond 60 s is re-based and serverNow stays continuous', async () => {
  assert.equal(JUMP_MS, 60000);
  const { env, db, clock } = await setup();
  await learnOffset(env, clock, 2000);
  await env.advance(10000);
  const before = clock.serverNow();
  env.jumpWall(-3600000); // someone sets the clock back an hour
  assert.equal(clock.serverNow(), before, 'held by the high-water mark until the next tick');
  await env.advance(5000);
  assert.equal(clock.serverNow(), before, 'still held');
  clock.tick();
  assert.equal(clock.serverNow(), before + 5000, 'continuous: the 5 s that passed, no more, no less');
  assert.equal(clock.offsetMs(), 2000 + 3600000);
  assert.equal(clock.trusted(), true);
  await env.advance(15000);
  clock.tick();
  assert.equal(clock.serverNow(), before + 20000);
  await flush(20);
  assert.equal((await db.get('meta', 'clock')).offset_ms, 2000 + 3600000, 'the re-base is saved at once');
  // Without an offset yet, the jump becomes the offset.
  const fresh = await setup();
  const t0 = fresh.clock.serverNow();
  fresh.env.jumpWall(-120000);
  fresh.clock.tick();
  assert.equal(fresh.clock.snapshot().offset_ms, 120000);
  assert.equal(fresh.clock.trusted(), false);
  assert.equal(fresh.clock.serverNow(), t0);
});

test('a backward jump under 60 s is held by the high-water mark', async () => {
  const { env, clock } = await setup();
  await learnOffset(env, clock, 1000);
  const before = clock.serverNow();
  env.jumpWall(-30000);
  clock.tick();
  assert.equal(clock.offsetMs(), 1000, 'not re-based');
  assert.equal(clock.serverNow(), before, 'held');
  await env.advance(29000);
  clock.tick();
  assert.equal(clock.serverNow(), before, 'still held while the tablet clock catches up');
  await env.advance(1000);
  assert.equal(clock.serverNow(), before);
  await env.advance(1000);
  assert.equal(clock.serverNow(), before + 1000, 'moving again');
  // A jump of exactly JUMP_MS is not re-based either.
  env.jumpWall(-JUMP_MS);
  clock.tick();
  assert.equal(clock.offsetMs(), 1000);
});

test('a forward jump changes nothing', async () => {
  const { env, clock } = await setup();
  await learnOffset(env, clock, 1000);
  const offset = clock.snapshot().offset_ms;
  env.jumpWall(3600000);
  clock.tick();
  assert.equal(clock.snapshot().offset_ms, offset, 'not re-based: it cannot be told apart from sleep');
  assert.equal(clock.offsetMs(), 1000);
  assert.equal(clock.trusted(), true);
  assert.equal(clock.serverNow(), env.now() + 1000, 'it runs ahead until the next answer corrects it');
  assert.equal(clock.snapshot().high_water_ms, env.now());
  await learnOffset(env, clock, 1000 - 3600000);
  assert.equal(clock.serverNow(), env.now() + 1000 - 3600000, 'learn() corrects it');
});

test('a sleep (mono paused, wall +2 h) changes nothing and serverNow advances 2 h', async () => {
  const { env, clock } = await setup();
  await learnOffset(env, clock, 1500);
  clock.tick();
  const before = clock.serverNow();
  const snap = clock.snapshot();
  const mono = env.mono();
  await env.sleep(2 * 3600000);
  assert.equal(env.mono(), mono, 'the monotonic clock paused');
  clock.tick();
  assert.equal(clock.snapshot().offset_ms, snap.offset_ms);
  assert.equal(clock.trusted(), true);
  assert.equal(clock.serverNow(), before + 2 * 3600000);
});

test('after a sleep the next start reports no rollback', async () => {
  const { env, db, clock } = await setup();
  await learnOffset(env, clock, 1234);
  await env.sleep(2 * 3600000);
  clock.tick();
  await clock.flush();
  const next = createClock({ env, db });
  assert.deepEqual(await next.load(), { rolledBack: false });
  assert.equal(next.rolledBack(), false);
  assert.equal(next.trusted(), true);
  assert.equal(next.offsetMs(), 1234);
  assert.equal(await db.get('meta', 'clock_rollback_pending'), undefined);
});

test('a clock set back more than 5 minutes while closed is a rollback', async () => {
  assert.equal(ROLLBACK_MS, 300000);
  const { env, db, clock } = await setup();
  await learnOffset(env, clock, 1234);
  const lastServerNow = clock.serverNow();
  const hw = clock.snapshot().high_water_ms;
  await clock.flush();
  // The app is closed, and the tablet clock set back by 5 minutes and 1 ms.
  env.jumpWall(-(ROLLBACK_MS + 1));
  const next = createClock({ env, db });
  assert.deepEqual(await next.load(), { rolledBack: true });
  assert.deepEqual(await db.get('meta', 'clock'), next.snapshot(), 'the re-based state is saved');
  assert.equal(next.snapshot().high_water_ms, env.now(), 'the high-water mark is re-based to the tablet clock');
  assert.equal(next.rolledBack(), true);
  assert.equal(next.trusted(), false);
  assert.equal(next.offsetMs(), null);
  assert.equal(await db.get('meta', 'clock_rollback_pending'), true);
  assert.equal(next.serverNow(), hw + 1234, 'serverNow() continues from the high-water mark');
  assert.equal(next.serverNow(), lastServerNow);
  await env.advance(1000);
  assert.equal(next.serverNow(), lastServerNow + 1000);
  // A later learn() trusts again; the pending flag stays for the heartbeat to report.
  await learnOffset(env, next, 1234);
  assert.equal(next.trusted(), true);
  assert.equal(next.rolledBack(), true, 'this run still found a rollback');
  assert.equal(await db.get('meta', 'clock_rollback_pending'), true);
});

test('a clock set back less than 5 minutes is not a rollback', async () => {
  const { env, db, clock } = await setup();
  await learnOffset(env, clock, 1234);
  const lastServerNow = clock.serverNow();
  await clock.flush();
  env.jumpWall(-ROLLBACK_MS);
  const next = createClock({ env, db });
  assert.deepEqual(await next.load(), { rolledBack: false });
  assert.equal(next.trusted(), true);
  assert.equal(next.offsetMs(), 1234);
  assert.equal(next.serverNow(), lastServerNow, 'held by the high-water mark');
  assert.equal(await db.get('meta', 'clock_rollback_pending'), undefined);
});

test('the rollback writes meta.clock and clock_rollback_pending in one readwrite transaction', async () => {
  const { db } = await openMemoryDb();
  const env = fakeEnv();
  await db.put('meta', { offset_ms: 50, trusted: true, high_water_ms: env.now() + ROLLBACK_MS + 60000, measured_at: env.now() }, 'clock');
  const rec = recording(db);
  const clock = createClock({ env, db: rec.db });
  assert.deepEqual(await clock.load(), { rolledBack: true });
  assert.deepEqual(rec.writes, [['tx', ['meta'], 'readwrite', [['meta', 'clock'], ['meta', 'clock_rollback_pending']]]]);
  assert.deepEqual(await db.get('meta', 'clock'), {
    offset_ms: 50 + ROLLBACK_MS + 60000, trusted: false, high_water_ms: env.now(), measured_at: env.now(),
  });
  // No rollback: load() writes nothing.
  const quiet = recording(db);
  const again = createClock({ env, db: quiet.db });
  assert.deepEqual(await again.load(), { rolledBack: false });
  assert.deepEqual(quiet.writes, []);
});

test('serverNow never goes backwards across ticks, learn and jumps', async () => {
  const { env, clock } = await setup();
  // True time τ (the server's clock); the tablet's wall clock starts 3 s behind it.
  let tau = env.now() + 3000;
  const advance = async (ms) => { tau += ms; await env.advance(ms); };
  const learn = () => { const at = env.now(); clock.learn(formatDb(tau), at, at); };
  const seen = [];
  const look = (label) => seen.push([label, clock.serverNow(), tau]);
  const steps = [
    ['start', () => {}], ['learn', learn], ['tick', () => clock.tick()], ['15 s', () => advance(15000)], ['tick', () => clock.tick()],
    ['back 30 s', () => env.jumpWall(-30000)], ['10 s', () => advance(10000)], ['tick', () => clock.tick()],
    ['learn', learn], ['15 s', () => advance(15000)], ['tick', () => clock.tick()],
    ['back 2 h', () => env.jumpWall(-7200000)], ['5 s', () => advance(5000)], ['tick', () => clock.tick()], ['15 s', () => advance(15000)],
    ['learn', learn], ['sleep 2 h', async () => { tau += 7200000; await env.sleep(7200000); }], ['tick', () => clock.tick()],
    ['back 10 min', () => env.jumpWall(-600000)], ['tick', () => clock.tick()], ['learn', learn], ['15 s', () => advance(15000)],
    ['back 59 s', () => env.jumpWall(-59000)], ['tick', () => clock.tick()], ['20 s', () => advance(20000)], ['tick', () => clock.tick()],
    ['learn', learn], ['forward 1 h', () => env.jumpWall(3600000)], ['tick', () => clock.tick()], ['15 s', () => advance(15000)],
    ['tick', () => clock.tick()],
  ];
  for (const [label, step] of steps) {
    await step();
    look(label);
  }
  for (let i = 1; i < seen.length; i++) {
    assert.ok(seen[i][1] >= seen[i - 1][1], `${seen[i][0]} went backwards: ${JSON.stringify(seen.slice(i - 1, i + 1))}`);
  }
  // Right after each learn(), serverNow() is the server's time.
  for (const [label, at, t] of seen) if (label === 'learn') assert.equal(at, t);
});

test('the high-water mark is written at most every 60 s and flush writes now', async () => {
  assert.equal(PERSIST_MS, 60000);
  const { db } = await openMemoryDb();
  const env = fakeEnv();
  const rec = recording(db);
  const clock = createClock({ env, db: rec.db });
  await clock.load();
  const clockWrites = () => rec.writes.filter((w) => w[0] === 'put' && w[2] === 'clock').length;
  assert.equal(clockWrites(), 0);
  const writtenAt = [];
  for (let s = 15; s <= 300; s += 15) {
    await env.advance(15000);
    const n = clockWrites();
    clock.tick();
    if (clockWrites() > n) writtenAt.push(s);
  }
  assert.deepEqual(writtenAt, [15, 75, 135, 195, 255]);
  await flush(20);
  assert.equal((await db.get('meta', 'clock')).high_water_ms, env.now() - 45000, 'the mark of the last write');
  await clock.flush();
  assert.equal(clockWrites(), 6);
  assert.deepEqual(await db.get('meta', 'clock'), clock.snapshot(), 'flush() wrote the current state, awaited');
  // learn() writes at once and restarts the 60 s.
  await env.advance(1000);
  await learnOffset(env, clock, 10);
  assert.equal(clockWrites(), 7);
  await env.advance(15000);
  clock.tick();
  assert.equal(clockWrites(), 7);
});

test('a DbClosed while persisting is ignored', async () => {
  const { env, db, clock } = await setup();
  db.close();
  await assert.rejects(db.put('meta', 1, 'x'), DbClosed);
  await learnOffset(env, clock, 500);
  assert.equal(clock.offsetMs(), 500, 'the clock still learns');
  clock.tick();
  await env.advance(PERSIST_MS);
  clock.tick();
  await clock.flush();
  await flush(20);
  assert.deepEqual(env.logs, [], 'nothing logged for an expected DbClosed');
  // A rollback found while the database is closed (a wipe in progress) is not thrown either.
  const stub = {
    get: async () => ({ offset_ms: 0, trusted: true, high_water_ms: env.now() + ROLLBACK_MS + 1, measured_at: null }),
    put: async () => { throw new DbClosed(); },
    tx: async () => { throw new DbClosed(); },
  };
  const rolled = createClock({ env, db: stub });
  assert.deepEqual(await rolled.load(), { rolledBack: true });
  await rolled.flush();
  assert.deepEqual(env.logs, []);
});
