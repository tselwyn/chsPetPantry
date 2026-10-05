// The device proof (docs/design/50-design-station.md X-1, §3.2): at registration the tablet makes a 32-byte HMAC key,
// sends it once and keeps it non-extractable; every device call then carries
//   PFPMS-Proof: v1 <ts_ms> <b64url HMAC-SHA256(key, "pfpms/v1/proof\n<METHOD>\n<endpoint>\n<ts_ms>\n<hex SHA-256(body)>")>
// exactly as src/Device/DeviceProof.php checks it. tests/fixtures/station_crypto.json (proof) pins both sides.
import { b64url, sha256Hex } from './vault.js';

const enc = new TextEncoder();

/**
 * At registration only: a 32-byte HMAC key, extractable for its one export. The default HMAC length is 512 bits,
 * so length is set.
 * @param {Crypto} crypto a WebCrypto Crypto
 * @returns {Promise<{raw: Uint8Array, key: CryptoKey}>} raw: the 32 bytes sent to the server (the caller zeroes them)
 */
export async function createProofKey(crypto) {
  const key = await crypto.subtle.generateKey({ name: 'HMAC', hash: 'SHA-256', length: 256 }, true, ['sign']);
  const raw = new Uint8Array(await crypto.subtle.exportKey('raw', key));
  if (raw.length !== 32) throw new Error('proof key must be 32 bytes');
  return { raw, key };
}

/**
 * The key the tablet keeps: re-imported non-extractable from the raw bytes (the caller then zeroes them).
 * @param {Crypto} crypto
 * @param {Uint8Array} raw the 32 bytes from createProofKey()
 * @returns {Promise<CryptoKey>} an HMAC-SHA256 signing key, extractable false
 */
export async function importProofKey(crypto, raw) {
  return crypto.subtle.importKey('raw', raw, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
}

/**
 * "pfpms/v1/proof\n<METHOD>\n<endpoint>\n<ts_ms>\n<hex SHA-256(body)>" — DeviceProof::message() exactly.
 * @param {string} method upper-cased here
 * @param {string} endpoint the path relative to the app root, e.g. api/device/heartbeat.php
 * @param {number} tsMs
 * @param {string} bodySha256Hex lower-case hex SHA-256 of the exact body text
 * @returns {string}
 */
export function proofMessage(method, endpoint, tsMs, bodySha256Hex) {
  return `pfpms/v1/proof\n${method.toUpperCase()}\n${endpoint}\n${tsMs}\n${bodySha256Hex}`;
}

/**
 * "v1 <13-digit ts_ms> <43-char b64url mac>" (DeviceProof::parse()'s pattern). GET proofs sign the empty body.
 * @param {Crypto} crypto
 * @param {CryptoKey} key the proof key
 * @param {string} method
 * @param {string} endpoint
 * @param {number} tsMs milliseconds since the epoch, truncated to an integer; must have 13 digits
 * @param {string|null|undefined} bodyText the exact string sent as the body (null or undefined: the empty body)
 * @returns {Promise<string>} the PFPMS-Proof header value
 * @throws {RangeError} when the timestamp is not a 13-digit integer
 */
export async function proofHeader(crypto, key, method, endpoint, tsMs, bodyText) {
  const ts = Math.trunc(tsMs);
  if (!Number.isSafeInteger(ts) || String(ts).length !== 13) throw new RangeError('proof timestamp must be 13-digit ms');
  const mac = await crypto.subtle.sign('HMAC', key, enc.encode(proofMessage(method, endpoint, ts, await sha256Hex(crypto, bodyText ?? ''))));
  return `v1 ${ts} ${b64url(mac)}`;
}
