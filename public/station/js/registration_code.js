// The tablet registration code (plan P2A admin_devices), the twin of src/Device/RegistrationCode.php: both give
// exactly the answers of tests/fixtures/registration_code.json. Only ASCII letters change case and only space, tab,
// CR, LF and hyphen are removed, as PHP strtoupper and str_replace do; any other character makes the code invalid.

/** Crockford Base32: no I, L, O or U. */
export const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
/** 16 data symbols (80 bits), then one check symbol. */
export const DATA_SYMBOLS = 16;
/** What the QR code holds before the code: not a web address, so a camera app opens no browser tab. */
export const QR_PREFIX = 'PFPMS-DEVICE:1:';
const enc = new TextEncoder();

/**
 * 10 bytes read 5 bits at a time, most significant first, then the check symbol.
 * @param {Uint8Array} bytes exactly 10 bytes
 * @returns {string} 17 canonical symbols
 */
export function fromBytes(bytes) {
  if (!(bytes instanceof Uint8Array) || bytes.length !== 10) throw new RangeError('A registration code is made from 10 bytes.');
  const bits = [...bytes].map((b) => b.toString(2).padStart(8, '0')).join('');
  let data = '';
  for (let i = 0; i < 80; i += 5) data += ALPHABET[parseInt(bits.slice(i, i + 5), 2)];
  return data + checkSymbol(data);
}

/**
 * Luhn mod 32 over ALPHABET: from the rightmost data symbol leftwards the factor is 2, 1, 2, 1 …
 * @param {string} data symbols of ALPHABET
 * @returns {string} the check symbol
 * @throws {RangeError} for a symbol outside ALPHABET
 */
export function checkSymbol(data) {
  let sum = 0;
  let factor = 2;
  for (let i = data.length - 1; i >= 0; i--) {
    const value = ALPHABET.indexOf(data[i]);
    if (value < 0) throw new RangeError('Not a registration code symbol.');
    const addend = factor * value;
    sum += Math.floor(addend / 32) + (addend % 32);
    factor = factor === 2 ? 1 : 2;
  }
  return ALPHABET[(32 - (sum % 32)) % 32];
}

/**
 * What was typed or scanned → the canonical 17 symbols, or null (RegistrationCode::normalise exactly).
 * @param {unknown} typed
 * @returns {string|null}
 */
export function normalise(typed) {
  if (typeof typed !== 'string' || enc.encode(typed).length > 100) return null; // PHP strlen counts bytes
  let s = typed.replace(/[a-z]/g, (c) => String.fromCharCode(c.charCodeAt(0) - 32)); // ASCII letters only, like strtoupper
  s = s.replace(/[ \t\r\n]/g, '');
  if (s.startsWith(QR_PREFIX)) s = s.slice(QR_PREFIX.length);
  s = s.replace(/-/g, '').replace(/[OIL]/g, (c) => (c === 'O' ? '0' : '1'));
  if (!/^[0-9A-HJKMNP-TV-Z]{17}$/.test(s)) return null;
  return checkSymbol(s.slice(0, DATA_SYMBOLS)) === s[DATA_SYMBOLS] ? s : null;
}

/**
 * XXXX-XXXX-XXXX-XXXX-C, as printed on the registration sheet.
 * @param {string} canonical 17 canonical symbols
 * @returns {string}
 */
export function format(canonical) {
  return canonical.slice(0, 16).match(/.{4}/g).join('-') + '-' + canonical.slice(16);
}

/**
 * What the QR code holds.
 * @param {string} canonical 17 canonical symbols
 * @returns {string}
 */
export function qrPayload(canonical) {
  return QR_PREFIX + canonical;
}

/**
 * RESERVED (as in PHP): six symbols from the first 30 bits of SHA-256('pfpms-pair:' + tokenHashHex). Async (WebCrypto).
 * @param {Crypto} crypto a WebCrypto implementation
 * @param {string} tokenHashHex the credential's stored SHA-256, as hex
 * @returns {Promise<string>}
 */
export async function pairingCheck(crypto, tokenHashHex) {
  const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode('pfpms-pair:' + tokenHashHex)));
  const bits = [...digest.slice(0, 4)].map((b) => b.toString(2).padStart(8, '0')).join('').slice(0, 30);
  let out = '';
  for (let i = 0; i < 30; i += 5) out += ALPHABET[parseInt(bits.slice(i, i + 5), 2)];
  return out;
}
