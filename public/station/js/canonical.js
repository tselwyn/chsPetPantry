// Canonical JSON v1 and the datetime text (docs/design/50-design-station.md §3.6, D-33), byte for byte the same as
// src/Sync/Canonical.php, Clock::fromClient() and Clock::dbMillis(). The item HMAC covers these exact bytes, so the
// server never re-serialises a tablet's item; tests/fixtures/canonical_json.json and datetime.json pin both sides.

/** The record and key-derivation format label (`pfpms/v1`), used in AADs and HKDF info strings. */
export const FORMAT = 'pfpms/v1';
/** The largest canonical text, in UTF-8 bytes. */
export const MAX_BYTES = 65536;
/** Container nesting: the top-level object or array is level 1. */
export const MAX_DEPTH = 16;
const KEY = /^[a-z][a-z0-9_]{0,63}$/;
const DB_TEXT = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\.(\d{3})$/;
/** 0000-01-01 00:00:00.000 and 9999-12-31 23:59:59.999 UTC, in ms: the instants the datetime text can name. */
const FIRST_MS = -62167219200000;
const LAST_MS = 253402300799999;

/** A value that has no canonical JSON v1 text; `reason` is number, string, key, depth, type or too_large. */
export class CanonicalError extends Error {
  /** @param {'number'|'string'|'key'|'depth'|'type'|'too_large'} reason */
  constructor(reason) { super('Not canonical JSON v1: ' + reason); this.name = 'CanonicalError'; this.reason = reason; }
}

/**
 * The canonical text of a value; throws CanonicalError('number'|'string'|'key'|'depth'|'type'|'too_large').
 * @param {unknown} value null, a boolean, a safe integer, a well-formed string, an array or a plain object
 * @returns {string}
 */
export function canonical(value) {
  const text = emit(value, 0);
  if (new TextEncoder().encode(text).length > MAX_BYTES) throw new CanonicalError('too_large');
  return text;
}

/**
 * The UTF-8 bytes of canonical(value): what is sealed, signed and sent.
 * @param {unknown} value
 * @returns {Uint8Array}
 */
export function canonicalBytes(value) {
  return new TextEncoder().encode(canonical(value));
}

/**
 * true when text is exactly canonical(JSON.parse(text)). Never throws.
 * @param {string} text
 * @returns {boolean}
 */
export function isCanonicalText(text) {
  try { return canonical(JSON.parse(text)) === text; } catch { return false; }
}

function emit(v, depth) {
  if (v === null) return 'null';
  if (v === true) return 'true';
  if (v === false) return 'false';
  switch (typeof v) {
    case 'number':
      if (!Number.isSafeInteger(v)) throw new CanonicalError('number');
      return Object.is(v, -0) ? '0' : String(v);
    case 'string':
      if (!v.isWellFormed()) throw new CanonicalError('string');
      return JSON.stringify(v);
    case 'object': {
      if (depth + 1 > MAX_DEPTH) throw new CanonicalError('depth');
      if (Array.isArray(v)) return '[' + v.map((x) => emit(x, depth + 1)).join(',') + ']';
      const proto = Object.getPrototypeOf(v);
      if (proto !== Object.prototype && proto !== null) throw new CanonicalError('type');
      const keys = Object.keys(v);
      for (const k of keys) if (!KEY.test(k)) throw new CanonicalError('key');
      keys.sort(); // ASCII keys: UTF-16 code-unit order = byte order
      return '{' + keys.map((k) => '"' + k + '":' + emit(v[k], depth + 1)).join(',') + '}';
    }
    default:
      throw new CanonicalError('type'); // undefined, functions, bigint, symbols
  }
}

/**
 * ms since the epoch → "YYYY-MM-DD HH:MM:SS.mmm" (UTC), years 0000-9999 (Clock::dbMillis()'s twin).
 * @param {number} ms an integer
 * @returns {string}
 * @throws {RangeError} for a fraction, a value that is not a number, or a year outside 0000-9999
 */
export function formatDb(ms) {
  if (!Number.isSafeInteger(ms)) throw new RangeError('formatDb needs integer milliseconds');
  if (ms < FIRST_MS || ms > LAST_MS) throw new RangeError('formatDb: year outside 0000-9999');
  return new Date(ms).toISOString().slice(0, 23).replace('T', ' ');
}

/**
 * "YYYY-MM-DD HH:MM:SS.mmm" (UTC) → ms, or null: the same rule as Clock::fromClient() (regex, then the round trip).
 * Never throws: a text that rolls out of years 0000-9999 (month or day 00 of year 0000, 24:00 on 9999-12-31) is null.
 * @param {unknown} text
 * @returns {number|null}
 */
export function parseDb(text) {
  if (typeof text !== 'string') return null;
  const m = DB_TEXT.exec(text);
  if (m === null) return null;
  const d = new Date(0);
  d.setUTCFullYear(+m[1], +m[2] - 1, +m[3]); // not Date.UTC: years 0000-0099 stay themselves
  d.setUTCHours(+m[4], +m[5], +m[6], +m[7]);
  const ms = d.getTime();
  if (!Number.isFinite(ms) || ms < FIRST_MS || ms > LAST_MS) return null;
  return formatDb(ms) === text ? ms : null; // Feb 30, 24:00, minute 60 roll over and are refused
}

/**
 * 00:00 of a local date (Y-m-d) in an IANA zone, as ms UTC — Clock::orgDayStartUtc()'s twin (for P3/P4 day rules).
 * Not pinned for a midnight that does not exist (a zone that changes its clocks at 24:00).
 * @param {string} zone an IANA time zone, e.g. America/New_York
 * @param {string} date Y-m-d
 * @returns {number}
 * @throws {RangeError} when date is not Y-m-d or the zone is unknown
 */
export function dayStartUtc(zone, date) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
  if (m === null) throw new RangeError('dayStartUtc needs Y-m-d');
  const fmt = new Intl.DateTimeFormat('en-CA', { timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const offsetAt = (ms) => {
    const p = Object.fromEntries(fmt.formatToParts(new Date(ms)).filter((x) => x.type !== 'literal').map((x) => [x.type, x.value]));
    return Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second) - Math.floor(ms / 1000) * 1000;
  };
  const wall = Date.UTC(+m[1], +m[2] - 1, +m[3]);
  let guess = wall - offsetAt(wall);
  guess = wall - offsetAt(guess); // second pass: the offset in force at that wall time
  return guess;
}
