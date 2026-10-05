// The tablet's clock (docs/design/50-design-station.md §7.4): the server offset learned from every answer, a
// high-water mark in the tablet's own frame, and backward-jump detection against the monotonic clock. serverNow() is
// what proofs, expiries and (S4) items use; it never moves earlier than the last time the Station ran.
import { parseDb } from './canonical.js';

/** A backward wall-clock jump larger than this (between two ticks) is re-based. */
export const JUMP_MS = 60000;
/** At start-up, now < high_water − this is a clock set back while the app was closed: a rollback. */
export const ROLLBACK_MS = 300000;
/** The high-water mark is written at most this often (learn(), a re-base and flush() write at once). */
export const PERSIST_MS = 60000;

/** The server times a proof can carry: DeviceProof::parse() takes 13 digits of ms, 2001-09-09 to 2286-11-20. */
const PROOF_FIRST_MS = 1e12;
const PROOF_END_MS = 1e13;

const finite = (v) => typeof v === 'number' && Number.isFinite(v);

/**
 * @typedef {object} Clock
 * @property {() => Promise<{rolledBack: boolean}>} load reads meta.clock and detects a rollback
 * @property {() => number} serverNow max(env.now(), high-water) + (offset ?? 0); that max becomes the high-water
 *   mark, so it never goes backwards while the app runs (only learn() may correct it)
 * @property {() => number|null} offsetMs the offset while trusted, else null (what items sign and About shows)
 * @property {() => boolean} trusted
 * @property {(serverTimeText: string, sentAt: number, receivedAt: number) => void} learn from an answer's
 *   server_time; sentAt and receivedAt are env.now() around the fetch; a time a proof cannot carry is ignored
 * @property {() => void} tick every 15 s and on becoming visible
 * @property {() => boolean} rolledBack this run found a rollback at start-up
 * @property {() => {offset_ms: number|null, trusted: boolean, high_water_ms: number, measured_at: number|null}} snapshot
 * @property {() => Promise<void>} flush writes meta.clock now (on hidden)
 */

/**
 * @param {{env: import('./env.js').Env, db: import('./db.js').Db}} deps
 * @returns {Clock}
 */
export function createClock({ env, db }) {
  let offset = null;      // ms to add to the tablet's clock to get the server's, or null before any answer
  let trusted = false;
  let hw = 0;             // the high-water mark, tablet frame
  let measuredAt = null;  // env.now() of the last learn()
  let rolled = false;
  let base = { wall: env.now(), mono: env.mono() }; // the last tick (memory only)
  let lastPersistAt = -Infinity;

  const snapshot = () => ({ offset_ms: offset, trusted, high_water_ms: hw, measured_at: measuredAt });
  const failed = (e) => { if (e?.name !== 'DbClosed') env.log(`clock not saved: ${e?.name ?? 'error'}`); };
  /** db.put of the snapshot; never rejects (a DbClosed during a wipe is expected). */
  const persist = async () => {
    lastPersistAt = env.now();
    try { await db.put('meta', snapshot(), 'clock'); } catch (e) { failed(e); }
  };

  return {
    async load() {
      const saved = await db.get('meta', 'clock');
      offset = finite(saved?.offset_ms) ? saved.offset_ms : null;
      trusted = saved?.trusted === true && offset !== null;
      hw = finite(saved?.high_water_ms) ? saved.high_water_ms : 0;
      measuredAt = finite(saved?.measured_at) ? saved.measured_at : null;
      rolled = false;
      const now = env.now();
      if (hw > 0 && now < hw - ROLLBACK_MS) {
        // Set back while closed: untrusted, and re-based so serverNow() continues from the high-water mark.
        rolled = true;
        trusted = false;
        offset = (offset ?? 0) + (hw - now);
        hw = now;
        const state = snapshot();
        lastPersistAt = now;
        try {
          await db.tx(['meta'], 'readwrite', async (t) => {
            await t.put('meta', state, 'clock');
            await t.put('meta', true, 'clock_rollback_pending');
          });
        } catch (e) { failed(e); }
      }
      hw = Math.max(hw, now);
      base = { wall: now, mono: env.mono() };
      return { rolledBack: rolled };
    },
    serverNow() {
      const now = env.now();
      if (now > hw) hw = now;
      return hw + (offset ?? 0);
    },
    offsetMs: () => (trusted && offset !== null ? Math.round(offset) : null),
    trusted: () => trusted,
    learn(serverTimeText, sentAt, receivedAt) {
      const server = parseDb(serverTimeText);
      if (server === null || !Number.isFinite(sentAt) || !Number.isFinite(receivedAt) || receivedAt < sentAt) return;
      // A time no proof can carry (a host clock reset to 2000, a proxy's 0000 or 9999) would be learned, saved and then
      // refuse every device call, so no device answer could ever correct it.
      if (server < PROOF_FIRST_MS || server >= PROOF_END_MS) return;
      offset = Math.round(server - (sentAt + receivedAt) / 2);
      trusted = true;
      const now = env.now();
      hw = now;
      measuredAt = now;
      base = { wall: now, mono: env.mono() };
      void persist();
    },
    tick() {
      const wall = env.now();
      const mono = env.mono();
      const jump = (wall - base.wall) - (mono - base.mono);
      // Only a backward jump is re-based: a forward one cannot be told apart from sleep (performance.now() pauses).
      const rebased = jump < -JUMP_MS;
      if (rebased) {
        offset = (offset ?? 0) - jump;
        hw += jump;
      }
      hw = Math.max(hw, wall);
      base = { wall, mono };
      if (rebased || wall - lastPersistAt >= PERSIST_MS) void persist();
    },
    rolledBack: () => rolled,
    snapshot,
    flush: () => persist(),
  };
}
