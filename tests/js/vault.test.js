// The Station's crypto core (50-design §3, D-27, D-28, D-29) against tests/fixtures/station_crypto.json, which
// tests/Integration/Station/StationCryptoFixtureTest.php opens with the server's own Crypto.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  VaultError, b64url, b64urlDecode, hex, sha256Hex, aad, deriveKek, calibrate, roundsFor, wrapDvk, unwrapDvk,
  openVault, seal, open, pinVerifier, sign, lookupText, lookup, keySelfTest,
} from '../../public/station/js/vault.js';
import { canonical } from '../../public/station/js/canonical.js';
import { fixture, fromHex, toHex } from './support/fixtures.js';

const f = fixture('station_crypto.json');
const c = globalThis.crypto;
const enc = new TextEncoder();
const dec = new TextDecoder();
const itemBytes = () => enc.encode(f.item.canonical);
const outboxRecord = () => ({ k: f.outbox_seal.key, iv: f.outbox_seal.iv, ct: f.outbox_seal.ct });
const keyringRecord = () => ({ iv: f.keyring_wrap.iv, wrapped_dvk: f.keyring_wrap.wrapped_dvk });
const fixtureKek = () => deriveKek(c, f.kek.password, fromHex(f.kek.salt_hex), f.kek.iterations);
const vault = () => openVault(c, fromHex(f.dvk_hex));
const isVaultError = (code) => (e) => e instanceof VaultError && e.name === 'VaultError' && e.code === code && e.message === code;

/** The b64url text with one byte changed (bit 0 of byte `at`; negative counts from the end). */
function flip(text, at) {
  const b = b64urlDecode(text);
  b[at < 0 ? b.length + at : at] ^= 1;
  return b64url(b);
}

test('openVault seals the fixture outbox record byte for byte', async () => {
  const { rec } = await vault();
  assert.equal(f.outbox_seal.plaintext_is, 'item.canonical');
  assert.equal(f.outbox_seal.store, 'outbox');
  assert.equal(dec.decode(aad('outbox', f.outbox_seal.key)), f.outbox_seal.aad);
  assert.equal(f.outbox_seal.iv, b64url(fromHex(f.outbox_seal.iv_hex)));
  const sealed = await seal(c, rec, 'outbox', f.outbox_seal.key, itemBytes(), fromHex(f.outbox_seal.iv_hex));
  assert.deepEqual(sealed, outboxRecord());
  const random = await seal(c, rec, 'outbox', f.outbox_seal.key, itemBytes());
  assert.notEqual(random.iv, f.outbox_seal.iv, 'a random IV by default');
  assert.equal(b64urlDecode(random.iv, 12)?.length, 12);
});

test('open returns the canonical bytes', async () => {
  const { rec } = await vault();
  assert.equal(canonical(f.item.value), f.item.canonical);
  assert.equal(toHex(itemBytes()), f.item.canonical_hex);
  const bytes = await open(c, rec, 'outbox', f.outbox_seal.key, outboxRecord());
  assert.ok(bytes instanceof Uint8Array);
  assert.equal(dec.decode(bytes), f.item.canonical);
  assert.equal(await sha256Hex(c, bytes), f.item.sha256_hex);
});

test('another store or key in the AAD is refused as damaged', async () => {
  const { rec } = await vault();
  const r = outboxRecord();
  await assert.rejects(open(c, rec, 'vault_users', r.k, r), isVaultError('damaged'));
  await assert.rejects(open(c, rec, 'outbox', '123e4567-e89b-42d3-a456-426614174001', r), isVaultError('damaged'));
  // The record's own k changed to match: the AAD still binds the key it was sealed under.
  const moved = { ...r, k: '123e4567-e89b-42d3-a456-426614174001' };
  await assert.rejects(open(c, rec, 'outbox', moved.k, moved), isVaultError('damaged'));
  // The vault_users record moved into the outbox.
  const vu = { k: f.outbox_seal.key, iv: f.vault_users_seal.iv, ct: f.vault_users_seal.ct };
  await assert.rejects(open(c, rec, 'outbox', vu.k, vu), isVaultError('damaged'));
});

test('a changed iv, ct or tag is refused as damaged', async () => {
  const { rec } = await vault();
  const r = outboxRecord();
  const bad = [
    { ...r, iv: flip(r.iv, 0) },
    { ...r, ct: flip(r.ct, 0) },
    { ...r, ct: flip(r.ct, -1) },
    { ...r, ct: b64url(b64urlDecode(r.ct).slice(0, -1)) },
    { ...r, ct: b64url(new Uint8Array(15)) },
    { ...r, iv: b64url(new Uint8Array(11)) },
    { ...r, iv: r.iv + '==' },
    { ...r, ct: undefined },
    { k: r.k, iv: r.iv },
    null,
    undefined,
  ];
  for (const record of bad) await assert.rejects(open(c, rec, 'outbox', r.k, record), isVaultError('damaged'), JSON.stringify(record));
  const other = await openVault(c, fromHex(f.proof.key_hex));
  await assert.rejects(open(c, other.rec, 'outbox', r.k, r), isVaultError('damaged'), 'another DVK');
});

test('openVault zeroes the DVK bytes it is given', async () => {
  const dvk = fromHex(f.dvk_hex);
  assert.notDeepEqual([...dvk], new Array(32).fill(0));
  await openVault(c, dvk);
  assert.deepEqual([...dvk], new Array(32).fill(0));
});

test('the sub-keys are non-extractable', async () => {
  const { rec, pin } = await vault();
  assert.equal(rec.extractable, false);
  assert.equal(pin.extractable, false);
  assert.equal(rec.algorithm.name, 'AES-GCM');
  assert.equal(rec.algorithm.length, 256);
  assert.deepEqual([...rec.usages].sort(), ['decrypt', 'encrypt']);
  assert.equal(pin.algorithm.name, 'HMAC');
  assert.equal(pin.algorithm.length, 256);
  assert.deepEqual([...pin.usages], ['sign']);
  await assert.rejects(c.subtle.exportKey('raw', rec));
  await assert.rejects(c.subtle.exportKey('raw', pin));
  await assert.rejects(c.subtle.exportKey('jwk', rec));
});

test('the PIN verifier matches the fixture', async () => {
  const { pin } = await vault();
  const p = f.pin_verifier;
  assert.equal(p.message, `pfpms/v1/pin|${p.user_id}|${p.pin}`);
  assert.equal(await pinVerifier(c, pin, p.user_id, p.pin), p.verifier);
  assert.equal(await pinVerifier(c, pin, String(p.user_id), p.pin), p.verifier);
  assert.equal(p.verifier.length, 43);
  // The same message under K_pin's raw bytes (hkdf.pin_hex), straight through WebCrypto.
  const kPin = await c.subtle.importKey('raw', fromHex(f.hkdf.pin_hex), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  assert.equal(b64url(await c.subtle.sign('HMAC', kPin, enc.encode(p.message))), p.verifier);
  assert.notEqual(await pinVerifier(c, pin, 13, p.pin), p.verifier);
  assert.notEqual(await pinVerifier(c, pin, p.user_id, '4822'), p.verifier);
});

test('deriveKek gives the fixture KEK', async () => {
  const kek = await fixtureKek();
  assert.equal(kek.extractable, false);
  assert.equal(kek.algorithm.name, 'AES-GCM');
  assert.equal(kek.algorithm.length, 256);
  await assert.rejects(c.subtle.exportKey('raw', kek));
  assert.equal(f.kek.salt, b64url(fromHex(f.kek.salt_hex)));
  const w = f.keyring_wrap;
  assert.equal(dec.decode(aad('keyring', String(w.device_id), String(w.user_id))), w.aad);
  assert.deepEqual(await wrapDvk(c, kek, fromHex(f.dvk_hex), w.device_id, w.user_id, fromHex(w.iv_hex)), keyringRecord());
  const base = await c.subtle.importKey('raw', enc.encode(f.kek.password), 'PBKDF2', false, ['deriveBits']);
  const bits = await c.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: fromHex(f.kek.salt_hex), iterations: f.kek.iterations }, base, 256);
  assert.equal(hex(bits), f.kek.kek_hex);
});

test('unwrapDvk returns the DVK', async () => {
  const kek = await fixtureKek();
  const dvk = await unwrapDvk(c, kek, keyringRecord(), f.keyring_wrap.device_id, f.keyring_wrap.user_id);
  assert.ok(dvk instanceof Uint8Array);
  assert.equal(toHex(dvk), f.dvk_hex);
  const fresh = await wrapDvk(c, kek, fromHex(f.dvk_hex), 7, 12);
  assert.equal(toHex(await unwrapDvk(c, kek, fresh, '7', '12')), f.dvk_hex, 'a random-IV wrap round-trips; ids as strings');
});

test('a wrong password or another user id is wrong_password', async () => {
  const kek = await fixtureKek();
  const r = keyringRecord();
  const wrongKek = (password) => deriveKek(c, password, fromHex(f.kek.salt_hex), f.kek.iterations);
  await assert.rejects(unwrapDvk(c, await wrongKek('Correct-Horse-Battery-8'), r, 7, 12), isVaultError('wrong_password'));
  await assert.rejects(unwrapDvk(c, await wrongKek('Correct-Horse-Battery-9 '), r, 7, 12), isVaultError('wrong_password'), 'the password is used exactly as typed');
  await assert.rejects(unwrapDvk(c, await wrongKek('correct-horse-battery-9'), r, 7, 12), isVaultError('wrong_password'));
  await assert.rejects(unwrapDvk(c, kek, r, 7, 13), isVaultError('wrong_password'));
  await assert.rejects(unwrapDvk(c, kek, r, 8, 12), isVaultError('wrong_password'));
  await assert.rejects(unwrapDvk(c, kek, { ...r, wrapped_dvk: flip(r.wrapped_dvk, -1) }, 7, 12), isVaultError('wrong_password'));
});

test('a malformed keyring record is damaged', async () => {
  const kek = await fixtureKek();
  const r = keyringRecord();
  const bad = [
    null,
    undefined,
    {},
    { iv: r.iv },
    { wrapped_dvk: r.wrapped_dvk },
    { ...r, iv: b64url(new Uint8Array(11)) },
    { ...r, iv: b64url(new Uint8Array(13)) },
    { ...r, iv: r.iv + '==' },
    { ...r, iv: '+' + r.iv.slice(1) },
    { ...r, wrapped_dvk: b64url(b64urlDecode(r.wrapped_dvk).slice(0, 47)) },
    { ...r, wrapped_dvk: b64url(new Uint8Array(49)) },
    { ...r, wrapped_dvk: '+' + r.wrapped_dvk.slice(1) },
    { ...r, iv: 12 },
  ];
  for (const record of bad) await assert.rejects(unwrapDvk(c, kek, record, 7, 12), isVaultError('damaged'), JSON.stringify(record));
});

test('the vault_users seal matches the fixture', async () => {
  const { rec } = await vault();
  const v = f.vault_users_seal;
  assert.equal(v.store, 'vault_users');
  assert.equal(dec.decode(aad(v.store, v.key)), v.aad);
  const sealed = await seal(c, rec, v.store, v.key, enc.encode(v.plaintext), fromHex(v.iv_hex));
  assert.deepEqual(sealed, { k: v.key, iv: v.iv, ct: v.ct });
  assert.equal(dec.decode(await open(c, rec, v.store, v.key, sealed)), v.plaintext);
});

test('sign gives the item mac and the empty-message mac', async () => {
  const grant = b64url(fromHex(f.item.grant_key_hex));
  assert.equal(grant.length, 43);
  assert.equal(await sign(c, grant, itemBytes()), f.item.mac);
  assert.equal(f.hmac_empty.key_hex, f.item.grant_key_hex);
  assert.equal(await sign(c, grant, new Uint8Array(0)), f.hmac_empty.mac);
  for (const badKey of [b64url(new Uint8Array(31)), b64url(new Uint8Array(33)), grant + '=', grant.slice(0, 42) + '+', '', null]) {
    await assert.rejects(sign(c, badKey, itemBytes()), isVaultError('damaged'), JSON.stringify(badKey));
  }
});

test('lookup gives every fixture hash', async () => {
  assert.equal(f.lookup.device_id, 7);
  assert.equal(f.lookup.cases.length, 11);
  for (const cs of f.lookup.cases) {
    assert.equal(lookupText(cs.in), cs.text, JSON.stringify(cs.in));
    assert.equal(await lookup(c, f.lookup.device_id, cs.in), cs.hash, JSON.stringify(cs.in));
    assert.equal(await lookup(c, String(f.lookup.device_id), cs.in), cs.hash);
  }
  assert.notEqual(await lookup(c, 8, 'JDoe'), f.lookup.cases[0].hash, 'the device id is part of the hash');
});

test('trim, NFC and per-code-point lower-casing: " JDoe ", "JDoe<TAB>" and "jdoe" equal JDoe, NBSP does not', async () => {
  const cases = f.lookup.cases;
  const by = (input) => cases.find((x) => x.in === input);
  for (const input of [' JDoe ', 'JDoe\t', 'jdoe']) assert.equal(by(input).hash, by('JDoe').hash, JSON.stringify(input));
  assert.equal(by('\u00a0JDoe').text, '\u00a0jdoe', 'NBSP is not in PHP trim()\'s set');
  assert.notEqual(by('\u00a0JDoe').hash, by('JDoe').hash);
  assert.equal(by('jo.doe@example.test ').hash, by('Jo.Doe@Example.test').hash);
  assert.equal(by('Mu\u0301noz').text, 'm\u00fanoz', 'NFC composes u + U+0301');
  assert.equal(by('Mu\u00f1oz').text, 'mu\u00f1oz');
  assert.equal(by('\u0130pek').text, 'i\u0307pek', 'per code point: U+0130 lower-cases to i + U+0307');
  assert.equal(by('\u03a3\u0391\u03a3').text, '\u03c3\u03b1\u03c3', 'per code point: no final sigma');
  assert.equal(lookupText('\u0000\u000b\r\n JDoe\t\u0000'), 'jdoe', 'the whole PHP trim() set');
  assert.equal(lookupText('J Doe'), 'j doe', 'inner spaces stay');
  assert.ok((await sha256Hex(c, 'pfpms/v1/user|7|jdoe ')).startsWith('9bf0ccdf'), 'the untrimmed value differs');
});

test('b64urlDecode accepts the valid list and refuses the invalid list', () => {
  assert.equal(f.b64url.valid.length, 6);
  assert.equal(f.b64url.invalid.length, 9);
  for (const x of f.b64url.valid) {
    const out = b64urlDecode(x.text);
    assert.ok(out instanceof Uint8Array, x.text);
    assert.equal(toHex(out), x.hex, x.text);
    assert.equal(toHex(b64urlDecode(x.text, x.hex.length / 2)), x.hex, x.text + ' with its length');
    assert.equal(b64urlDecode(x.text, x.hex.length / 2 + 1), null, x.text + ' with another length');
    assert.equal(b64url(fromHex(x.hex)), x.text);
  }
  for (const t of f.b64url.invalid) assert.equal(b64urlDecode(t), null, JSON.stringify(t));
  for (const t of [null, undefined, 0, ['AA'], { text: 'AA' }]) assert.equal(b64urlDecode(t), null, String(t));
  assert.equal(f.proof.key, f.b64url.valid[5].text);
});

test('b64url round-trips every length from 0 to 64', () => {
  for (let n = 0; n <= 64; n++) {
    const bytes = c.getRandomValues(new Uint8Array(n));
    const text = b64url(bytes);
    assert.equal(text, Buffer.from(bytes).toString('base64url'), 'length ' + n);
    assert.equal(text.length, Math.ceil((n * 4) / 3));
    assert.deepEqual(b64urlDecode(text, n), bytes, 'length ' + n);
    assert.equal(b64url(bytes.buffer), text, 'an ArrayBuffer encodes the same');
  }
});

test('roundsFor clamps and rounds', () => {
  assert.equal(roundsFor(50), 2000000);
  assert.equal(roundsFor(1000), 100000);
  assert.equal(roundsFor(200), 500000);
  assert.equal(roundsFor(165), 600000);
  assert.equal(roundsFor(0), 2000000);
  assert.equal(roundsFor(10000), 100000);
  assert.equal(roundsFor(-5), 2000000);
  for (let ms = 0; ms <= 3000; ms += 0.75) {
    const r = roundsFor(ms);
    assert.equal(r % 10000, 0, 'a multiple of 10 000 at ' + ms);
    assert.ok(r >= 100000 && r <= 2000000, 'clamped at ' + ms);
  }
});

test('calibrate times with env.mono()', async () => {
  const marks = [0, 200];
  const randoms = [];
  const env = {
    crypto: c,
    random: (n) => { const b = c.getRandomValues(new Uint8Array(n)); randoms.push(n); return b; },
    mono: () => marks.shift(),
  };
  assert.equal(await calibrate(env), 500000);
  assert.equal(marks.length, 0, 'mono() read once before and once after');
  assert.deepEqual(randoms, [16, 16], 'a random password and a random salt');
});

test('keySelfTest passes over the memory IndexedDB', async () => {
  // The real db.js over the fake IDBFactory (WP-C, tests/js/support/memory-db.js).
  const { openMemoryDb } = await import('./support/memory-db.js');
  const { db } = await openMemoryDb();
  assert.equal(await keySelfTest(c, db), true);
  assert.equal(await db.get('meta', 'selftest'), undefined, 'the test key is deleted at once');
  db.close();
});

test('keySelfTest fails when the stored key comes back missing or extractable', async () => {
  const stub = (back) => {
    const calls = [];
    return {
      calls,
      put: async (store, value, key) => { calls.push(['put', store, key, value.key?.extractable]); },
      get: async (store, key) => { calls.push(['get', store, key]); return back(); },
      delete: async (store, key) => { calls.push(['delete', store, key]); },
    };
  };
  const extractable = await c.subtle.generateKey({ name: 'HMAC', hash: 'SHA-256', length: 256 }, true, ['sign']);
  const another = await c.subtle.generateKey({ name: 'HMAC', hash: 'SHA-256', length: 256 }, false, ['sign']);
  for (const [why, back] of [['missing', () => undefined], ['no key', () => ({})], ['extractable', () => ({ key: extractable })],
    ['another key', () => ({ key: another })]]) {
    const db = stub(back);
    assert.equal(await keySelfTest(c, db), false, why);
    assert.deepEqual(db.calls, [['put', 'meta', 'selftest', false], ['get', 'meta', 'selftest'], ['delete', 'meta', 'selftest']], why);
  }
  const throwing = { put: async () => { throw new Error('quota'); }, get: async () => undefined, delete: async () => {} };
  assert.equal(await keySelfTest(c, throwing), false, 'a failing write is a failed self-test, never a throw');
});

test('the shift_wrap vector opens with its fixed key', async () => {
  const s = f.shift_wrap;
  assert.equal(dec.decode(aad('shift', String(s.device_id), s.expires_at)), s.aad);
  assert.equal(toHex(b64urlDecode(s.iv, 12)), s.iv_hex);
  const key = await c.subtle.importKey('raw', fromHex(s.key_hex), 'AES-GCM', false, ['decrypt']);
  const dvk = await c.subtle.decrypt({ name: 'AES-GCM', iv: b64urlDecode(s.iv, 12), additionalData: aad('shift', String(s.device_id), s.expires_at), tagLength: 128 },
    key, b64urlDecode(s.wrapped, 48));
  assert.equal(hex(dvk), f.dvk_hex);
});
