// Test support (S2 spec §6.1): the REAL public/station/js/db.js over the memory IndexedDB, so every suite exercises
// the real wrapper. Not a suite (no .test.js suffix).
import { createMemoryIndexedDB } from './memory-idb.js';
import { openDb } from '../../../public/station/js/db.js';

/**
 * Opens the Station database through db.js's openDb() on a memory IndexedDB.
 * @param {{idb?: object, name?: string, onBlocked?: () => void, onClosed?: (why: string) => void}} [opts]
 *   idb: an existing memory IndexedDB (a second window on the same tablet); the rest goes to openDb()
 * @returns {Promise<{idb: object, db: import('../../../public/station/js/db.js').Db}>}
 */
export async function openMemoryDb(opts = {}) {
  const { idb = createMemoryIndexedDB(), ...rest } = opts;
  const db = await openDb(idb, rest);
  return { idb, db };
}
