// The Station's crypto core (docs/design/50-design-station.md §3, D-27, D-28, D-29): strict base64url, the KEK and
// its calibration, the keyring wrap of the DVK, the HKDF sub-keys, record seal/open, the PIN verifier, the item mac,
// the keyring lookup and the registration key self-test. Every function takes its WebCrypto `crypto` (or `env`) as an
// argument. tests/fixtures/station_crypto.json pins every value, and PHP's Crypto opens what this seals.
// The shift-key functions (writeShift, openShift, dropShift) arrive in S4.
import { FORMAT } from './canonical.js';

/** A vault failure: code 'wrong_password' (the KEK does not open the keyring record) or 'damaged' (anything else). */
export class VaultError extends Error {
  /** @param {'wrong_password'|'damaged'} code */
  constructor(code) { super(code); this.name = 'VaultError'; this.code = code; }
}

const ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
const REV = new Map([...ALPHA].map((c, i) => [c, i]));
const enc = new TextEncoder();

/**
 * RFC 4648 §5 without padding (Crypto::b64url).
 * @param {Uint8Array|ArrayBuffer} bytes
 * @returns {string}
 */
export function b64url(bytes) {
  const b = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  let out = '';
  let i = 0;
  for (; i + 2 < b.length; i += 3) {
    const n = (b[i] << 16) | (b[i + 1] << 8) | b[i + 2];
    out += ALPHA[n >> 18] + ALPHA[(n >> 12) & 63] + ALPHA[(n >> 6) & 63] + ALPHA[n & 63];
  }
  if (b.length - i === 1) {
    const n = b[i] << 16;
    out += ALPHA[n >> 18] + ALPHA[(n >> 12) & 63];
  } else if (b.length - i === 2) {
    const n = (b[i] << 16) | (b[i + 1] << 8);
    out += ALPHA[n >> 18] + ALPHA[(n >> 12) & 63] + ALPHA[(n >> 6) & 63];
  }
  return out;
}

/**
 * Strict, like Crypto::unb64urlStrict(): null unless the alphabet, a possible length, the canonical final character,
 * and exactly `bytes` bytes when given.
 * @param {unknown} text
 * @param {number|null} [bytes] the required decoded length
 * @returns {Uint8Array|null}
 */
export function b64urlDecode(text, bytes = null) {
  if (typeof text !== 'string' || !/^[A-Za-z0-9_-]*$/.test(text) || text.length % 4 === 1) return null;
  const out = new Uint8Array(Math.floor((text.length * 3) / 4));
  let o = 0;
  for (let i = 0; i < text.length; i += 4) {
    const c = [...text.slice(i, i + 4)].map((ch) => REV.get(ch));
    const n = (c[0] << 18) | (c[1] << 12) | ((c[2] ?? 0) << 6) | (c[3] ?? 0);
    out[o++] = (n >> 16) & 255;
    if (c.length > 2) out[o++] = (n >> 8) & 255;
    if (c.length > 3) out[o++] = n & 255;
  }
  if (b64url(out) !== text) return null; // "AB" decodes like "AA": refused
  if (bytes !== null && out.length !== bytes) return null;
  return out;
}

/**
 * Lower-case hex of bytes.
 * @param {Uint8Array|ArrayBuffer} bytes
 * @returns {string}
 */
export function hex(bytes) {
  return [...new Uint8Array(bytes)].map((x) => x.toString(16).padStart(2, '0')).join('');
}

/**
 * Lower-case hex SHA-256 of a string's UTF-8 bytes, or of the bytes given.
 * @param {Crypto} crypto
 * @param {string|BufferSource} data
 * @returns {Promise<string>}
 */
export async function sha256Hex(crypto, data) {
  return hex(await crypto.subtle.digest('SHA-256', typeof data === 'string' ? enc.encode(data) : data));
}

/**
 * UTF-8 of "pfpms/v1|<part>|<part>…" (record AADs, 50 §3.3; keyring AAD "pfpms/v1|keyring|<device_id>|<user_id>").
 * @param {...string} parts
 * @returns {Uint8Array}
 */
export function aad(...parts) {
  return enc.encode([FORMAT, ...parts].join('|'));
}

/**
 * KEK_u = PBKDF2-SHA256(UTF-8 password exactly as typed, salt, rounds) as a non-extractable AES-256-GCM key.
 * @param {Crypto} crypto
 * @param {string} password exactly as typed (never trimmed or normalised)
 * @param {BufferSource} salt
 * @param {number} rounds
 * @returns {Promise<CryptoKey>}
 */
export async function deriveKek(crypto, password, salt, rounds) {
  const base = await crypto.subtle.importKey('raw', enc.encode(password), 'PBKDF2', false, ['deriveKey']);
  return crypto.subtle.deriveKey({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations: rounds }, base,
    { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}

/**
 * D-27: 100 000 rounds on a random password and salt, timed with env.mono(); about 1 s per unlock on this tablet.
 * @param {{crypto: Crypto, random: (n: number) => Uint8Array, mono: () => number}} env
 * @returns {Promise<number>} the rounds for this tablet (roundsFor of the measured time)
 */
export async function calibrate(env) {
  const base = await env.crypto.subtle.importKey('raw', env.random(16), 'PBKDF2', false, ['deriveBits']);
  const t0 = env.mono();
  await env.crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: env.random(16), iterations: 100000 }, base, 256);
  return roundsFor(env.mono() - t0);
}

/**
 * min(2 000 000, max(100 000, floor(100 000 × 1000 / max(ms, 1) / 10 000) × 10 000))
 * @param {number} ms the time 100 000 rounds took
 * @returns {number} a multiple of 10 000 in 100 000..2 000 000
 */
export function roundsFor(ms) {
  return Math.min(2000000, Math.max(100000, Math.floor(100000 * 1000 / Math.max(ms, 1) / 10000) * 10000));
}

async function aesSeal(crypto, key, iv, data, ad) {
  return new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: ad, tagLength: 128 }, key, data));
}
async function aesOpen(crypto, key, iv, data, ad) {
  return new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv, additionalData: ad, tagLength: 128 }, key, data));
}

/**
 * {iv, wrapped_dvk} for a keyring record; `iv` is a parameter only so tests and the fixture tool can fix it.
 * @param {Crypto} crypto
 * @param {CryptoKey} kek from deriveKek()
 * @param {Uint8Array} dvkBytes the 32-byte DVK
 * @param {number|string} deviceId
 * @param {number|string} userId
 * @param {Uint8Array} [iv] 12 random bytes by default
 * @returns {Promise<{iv: string, wrapped_dvk: string}>} both b64url; wrapped_dvk is ciphertext‖tag (48 bytes)
 */
export async function wrapDvk(crypto, kek, dvkBytes, deviceId, userId, iv = crypto.getRandomValues(new Uint8Array(12))) {
  const ct = await aesSeal(crypto, kek, iv, dvkBytes, aad('keyring', String(deviceId), String(userId)));
  return { iv: b64url(iv), wrapped_dvk: b64url(ct) };
}

/**
 * The raw DVK (32 bytes) or VaultError('wrong_password'); VaultError('damaged') for a malformed record.
 * @param {Crypto} crypto
 * @param {CryptoKey} kek
 * @param {{iv: string, wrapped_dvk: string}|null|undefined} rec
 * @param {number|string} deviceId
 * @param {number|string} userId
 * @returns {Promise<Uint8Array>}
 * @throws {VaultError}
 */
export async function unwrapDvk(crypto, kek, rec, deviceId, userId) {
  const iv = b64urlDecode(rec?.iv, 12);
  const ct = b64urlDecode(rec?.wrapped_dvk, 48);
  if (iv === null || ct === null) throw new VaultError('damaged');
  try {
    return await aesOpen(crypto, kek, iv, ct, aad('keyring', String(deviceId), String(userId)));
  } catch {
    throw new VaultError('wrong_password');
  }
}

async function hkdfKey(crypto, base, info, alg, usages) {
  return crypto.subtle.deriveKey({ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0), info: enc.encode(info) }, base, alg, false, usages);
}

/**
 * {rec: AES-GCM K_rec, pin: HMAC K_pin}, both non-extractable. Zeroes dvkBytes, so a caller with a wrap still to do
 * passes a copy (50 §2.3).
 * @param {Crypto} crypto
 * @param {Uint8Array} dvkBytes the 32-byte DVK; filled with zeros before this returns or throws
 * @returns {Promise<{rec: CryptoKey, pin: CryptoKey}>}
 */
export async function openVault(crypto, dvkBytes) {
  try {
    const base = await crypto.subtle.importKey('raw', dvkBytes, 'HKDF', false, ['deriveKey']);
    const rec = await hkdfKey(crypto, base, 'pfpms/v1/record', { name: 'AES-GCM', length: 256 }, ['encrypt', 'decrypt']);
    const pin = await hkdfKey(crypto, base, 'pfpms/v1/pin', { name: 'HMAC', hash: 'SHA-256', length: 256 }, ['sign']);
    return { rec, pin };
  } finally {
    dvkBytes.fill(0);
  }
}

/**
 * A sealed record {k, iv, ct} (50 §3.3); AAD "pfpms/v1|<store>|<key>".
 * @param {Crypto} crypto
 * @param {CryptoKey} recKey K_rec from openVault()
 * @param {string} store
 * @param {string} key the record's key in that store
 * @param {Uint8Array} bytes the plaintext
 * @param {Uint8Array} [iv] 12 random bytes by default
 * @returns {Promise<{k: string, iv: string, ct: string}>} iv and ct (ciphertext‖tag) as b64url
 */
export async function seal(crypto, recKey, store, key, bytes, iv = crypto.getRandomValues(new Uint8Array(12))) {
  const ct = await aesSeal(crypto, recKey, iv, bytes, aad(store, key));
  return { k: key, iv: b64url(iv), ct: b64url(ct) };
}

/**
 * The plaintext bytes, or VaultError('damaged') (wrong key, moved record, changed bytes).
 * @param {Crypto} crypto
 * @param {CryptoKey} recKey
 * @param {string} store
 * @param {string} key
 * @param {{k: string, iv: string, ct: string}|null|undefined} rec
 * @returns {Promise<Uint8Array>}
 * @throws {VaultError}
 */
export async function open(crypto, recKey, store, key, rec) {
  const iv = b64urlDecode(rec?.iv, 12);
  const ct = b64urlDecode(rec?.ct);
  if (iv === null || ct === null || ct.length < 16 || rec.k !== key) throw new VaultError('damaged');
  try {
    return await aesOpen(crypto, recKey, iv, ct, aad(store, key));
  } catch {
    throw new VaultError('damaged');
  }
}

/**
 * b64url(HMAC(K_pin, "pfpms/v1/pin|<user_id>|<pin digits>")), 43 chars.
 * @param {Crypto} crypto
 * @param {CryptoKey} pinKey K_pin from openVault()
 * @param {number|string} userId
 * @param {string} pin
 * @returns {Promise<string>}
 */
export async function pinVerifier(crypto, pinKey, userId, pin) {
  return b64url(await crypto.subtle.sign('HMAC', pinKey, enc.encode(`pfpms/v1/pin|${userId}|${pin}`)));
}

/**
 * The item mac: b64url(HMAC-SHA256(grant key, bytes)); the grant key arrives as 43-char b64url.
 * @param {Crypto} crypto
 * @param {string} grantKeyB64 the 32-byte grant key, b64url
 * @param {BufferSource} bytes the item's canonical bytes
 * @returns {Promise<string>}
 * @throws {VaultError} 'damaged' when the grant key is not 32 bytes of strict b64url
 */
export async function sign(crypto, grantKeyB64, bytes) {
  const raw = b64urlDecode(grantKeyB64, 32);
  if (raw === null) throw new VaultError('damaged');
  const key = await crypto.subtle.importKey('raw', raw, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  raw.fill(0);
  return b64url(await crypto.subtle.sign('HMAC', key, bytes));
}

const PHP_TRIM = /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g;

/**
 * lower(NFC(trim(x))): PHP trim()'s set (Auth.php:42), then NFC, then toLowerCase per code point (D-29).
 * @param {unknown} text a username or email as typed
 * @returns {string}
 */
export function lookupText(text) {
  return [...String(text).replace(PHP_TRIM, '').normalize('NFC')].map((c) => c.toLowerCase()).join('');
}

/**
 * hex(SHA-256("pfpms/v1/user|<device_id>|<lookupText(x)>"))
 * @param {Crypto} crypto
 * @param {number|string} deviceId
 * @param {unknown} text
 * @returns {Promise<string>}
 */
export async function lookup(crypto, deviceId, text) {
  return sha256Hex(crypto, `pfpms/v1/user|${deviceId}|${lookupText(text)}`);
}

/**
 * The registration self-test (50 §2.3): a non-extractable CryptoKey must survive an IndexedDB round trip and still
 * sign the same. It stores the key at meta.selftest and deletes it at once.
 * @param {Crypto} crypto
 * @param {{put: Function, get: Function, delete: Function}} db the db.js adapter
 * @returns {Promise<boolean>} false on any failure (never throws)
 */
export async function keySelfTest(crypto, db) {
  try {
    const key = await crypto.subtle.generateKey({ name: 'HMAC', hash: 'SHA-256', length: 256 }, false, ['sign']);
    const msg = enc.encode('pfpms/v1/selftest');
    const a = new Uint8Array(await crypto.subtle.sign('HMAC', key, msg));
    await db.put('meta', { key }, 'selftest');
    const back = await db.get('meta', 'selftest');
    await db.delete('meta', 'selftest');
    if (!back?.key || back.key.extractable !== false) return false;
    const b = new Uint8Array(await crypto.subtle.sign('HMAC', back.key, msg));
    return a.length === 32 && b.length === 32 && a.every((x, i) => x === b[i]);
  } catch {
    return false;
  }
}
