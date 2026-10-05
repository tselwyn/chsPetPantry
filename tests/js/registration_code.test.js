// Tablet registration codes against tests/fixtures/registration_code.json, which RegistrationCode.php also passes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { ALPHABET, DATA_SYMBOLS, QR_PREFIX, fromBytes, checkSymbol, normalise, format, qrPayload, pairingCheck } from '../../public/station/js/registration_code.js';
import { fixture, fromHex, toHex, readStation } from './support/fixtures.js';

const f = fixture('registration_code.json');
const randomCode = () => fromBytes(crypto.getRandomValues(new Uint8Array(10)));

test('checkSymbol matches the fixture', () => {
  assert.ok(f.check.length > 0);
  for (const c of f.check) assert.equal(checkSymbol(c.data), c.check, c.data);
  assert.throws(() => checkSymbol('0000000000000U00'), RangeError, 'U is not a symbol');
});

test('fromBytes matches the fixture', () => {
  assert.ok(f.bytes.length > 0);
  for (const c of f.bytes) assert.equal(fromBytes(fromHex(c.hex)), c.code, c.hex);
  assert.throws(() => fromBytes(new Uint8Array(9)), RangeError);
  assert.throws(() => fromBytes(new Uint8Array(11)), RangeError);
  assert.throws(() => fromBytes([0, 1, 2, 3, 4, 5, 6, 7, 8, 9]), RangeError, 'an array is not bytes');
});

test('normalise gives the fixture answer for all 16 inputs', () => {
  assert.equal(f.normalise.length, 16);
  for (const c of f.normalise) assert.equal(normalise(c.in), c.out, JSON.stringify(c.in));
  assert.equal(normalise(null), null);
  assert.equal(normalise(undefined), null);
  assert.equal(normalise(12345), null);
  assert.equal(normalise(' '.repeat(83) + 'K7QM2XRD9VHPC4TNS'), 'K7QM2XRD9VHPC4TNS', 'exactly 100 bytes');
  assert.equal(normalise(' '.repeat(84) + 'K7QM2XRD9VHPC4TNS'), null, 'more than 100 bytes, as PHP strlen counts');
});

test('format matches the fixture', () => {
  assert.ok(f.format.length > 0);
  for (const c of f.format) assert.equal(format(c.in), c.out, c.in);
});

test('pairingCheck matches the fixture', async () => {
  assert.ok(f.pairing.length > 0);
  for (const c of f.pairing) assert.equal(await pairingCheck(crypto, c.token_hash), c.check, c.token_hash);
  const hash = toHex(await crypto.subtle.digest('SHA-256', new TextEncoder().encode('pfd1_test')));
  assert.equal(f.pairing[0].token_hash, hash, 'the fixture token hash is SHA-256 of pfd1_test');
});

test('generated codes round-trip through format and the QR payload', () => {
  const seen = new Set();
  const symbol = new RegExp('^[' + ALPHABET + ']{17}$');
  for (let i = 0; i < 2000; i++) {
    const code = randomCode();
    assert.match(code, symbol);
    assert.equal(normalise(code), code);
    assert.equal(normalise(format(code)), code);
    assert.equal(normalise(format(code).toLowerCase()), code, 'lower case is accepted');
    assert.equal(normalise(qrPayload(code)), code);
    assert.equal(qrPayload(code), QR_PREFIX + code);
    assert.match(format(code), /^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]$/);
    for (const c of code.slice(0, DATA_SYMBOLS)) seen.add(c);
  }
  assert.equal(seen.size, 32, 'every symbol appears');
});

test('every single-symbol mistake is caught', () => {
  for (let i = 0; i < 50; i++) {
    const code = randomCode();
    for (let pos = 0; pos < 17; pos++) {
      for (const symbol of ALPHABET) {
        if (symbol === code[pos]) continue;
        const wrong = code.slice(0, pos) + symbol + code.slice(pos + 1);
        assert.equal(normalise(wrong), null, `${code} with ${symbol} at ${pos}`);
      }
    }
  }
});

test('the source uses no trim(), toUpperCase() or \\s', () => {
  const source = readStation('js/registration_code.js');
  assert.doesNotMatch(source, /\btrim(?:Start|End|Left|Right)?\s*\(/, 'trim() removes Unicode spaces that PHP keeps');
  assert.doesNotMatch(source, /\bto(?:Locale)?UpperCase\s*\(/, 'toUpperCase() changes letters beyond ASCII (ı → I)');
  assert.equal(source.includes('\\s'), false, '\\s matches Unicode spaces that PHP refuses');
});
