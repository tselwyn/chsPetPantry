// Test support: the shared fixtures (tests/fixtures/) and the Station's files. Not a suite (no .test.js suffix).
import { readFileSync } from 'node:fs';

/** The Station's web directory, public/station/, as a file: URL ending in a slash. */
export const STATION_DIR = new URL('../../../public/station/', import.meta.url);

/**
 * The parsed tests/fixtures/<name>.
 * @param {string} name a file name such as 'canonical_json.json'
 * @returns {any}
 */
export function fixture(name) {
  return JSON.parse(readFileSync(new URL('../../fixtures/' + name, import.meta.url), 'utf8'));
}

/**
 * Hex text → bytes; throws on an odd length or a character that is not hex.
 * @param {string} hex
 * @returns {Uint8Array}
 */
export function fromHex(hex) {
  if (typeof hex !== 'string' || hex.length % 2 !== 0 || !/^[0-9a-fA-F]*$/.test(hex)) throw new RangeError('fromHex needs an even number of hex digits');
  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < out.length; i++) out[i] = parseInt(hex.slice(2 * i, 2 * i + 2), 16);
  return out;
}

/**
 * Bytes → lower-case hex text.
 * @param {Uint8Array|ArrayBuffer} bytes
 * @returns {string}
 */
export function toHex(bytes) {
  const view = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  return [...view].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * The UTF-8 text of a Station file.
 * @param {string} path relative to public/station/, e.g. 'js/canonical.js'
 * @returns {string}
 */
export function readStation(path) {
  return readFileSync(new URL(path, STATION_DIR), 'utf8');
}
