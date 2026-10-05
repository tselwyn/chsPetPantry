// The device proof (50-design X-1, §3.2) against tests/fixtures/station_crypto.json (proof), which
// DeviceProof::message() and DeviceProof::parse() also pass (StationCryptoFixtureTest).
import test from 'node:test';
import assert from 'node:assert/strict';
import { createProofKey, importProofKey, proofMessage, proofHeader } from '../../public/station/js/proof.js';
import { b64url, b64urlDecode } from '../../public/station/js/vault.js';
import { fixture, fromHex } from './support/fixtures.js';

const f = fixture('station_crypto.json');
const p = f.proof;
const c = globalThis.crypto;
const enc = new TextEncoder();
const HEADER = /^v1 (\d{13}) ([A-Za-z0-9_-]{43})$/; // DeviceProof::parse()'s pattern

/** b64url(HMAC-SHA256(raw, message)) straight through WebCrypto. */
async function macOf(raw, message) {
  const key = await c.subtle.importKey('raw', raw, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  return b64url(await c.subtle.sign('HMAC', key, enc.encode(message)));
}

test('the POST proof header matches the fixture', async () => {
  const key = await importProofKey(c, fromHex(p.key_hex));
  const post = p.post;
  assert.equal(proofMessage(post.method, post.endpoint, p.ts_ms, post.body_sha256), post.message);
  const header = await proofHeader(c, key, 'POST', post.endpoint, p.ts_ms, post.body);
  assert.equal(header, post.header);
  assert.equal(header, `v1 ${p.ts_ms} ${post.mac}`);
  assert.match(header, HEADER);
  assert.equal(await proofHeader(c, key, 'post', post.endpoint, p.ts_ms, post.body), post.header, 'the method is upper-cased');
  assert.equal(await proofHeader(c, key, 'POST', post.endpoint, p.ts_ms + 0.9, post.body), post.header, 'the timestamp is truncated');
  assert.notEqual(await proofHeader(c, key, 'POST', post.endpoint, p.ts_ms, post.body + ' '), post.header, 'the exact body string is signed');
  assert.notEqual(await proofHeader(c, key, 'POST', 'api/device/register.php', p.ts_ms, post.body), post.header);
});

test('the GET proof signs the empty body', async () => {
  const key = await importProofKey(c, fromHex(p.key_hex));
  const get = p.get;
  assert.equal(get.body, '');
  assert.equal(get.body_sha256, 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
  assert.equal(await proofHeader(c, key, 'GET', get.endpoint, p.ts_ms, ''), get.header);
  assert.equal(await proofHeader(c, key, 'GET', get.endpoint, p.ts_ms, undefined), get.header, 'no body is the empty body');
  assert.equal(await proofHeader(c, key, 'GET', get.endpoint, p.ts_ms, null), get.header);
  assert.equal(await proofHeader(c, key, 'GET', get.endpoint, p.ts_ms), get.header);
});

test('proofMessage layout is exact', () => {
  assert.equal(proofMessage('post', 'api/x.php', 1790856000000, 'ab'), 'pfpms/v1/proof\nPOST\napi/x.php\n1790856000000\nab');
  for (const m of [p.post, p.get]) {
    assert.equal(proofMessage(m.method, m.endpoint, p.ts_ms, m.body_sha256), m.message);
    assert.equal(m.message.split('\n').length, 5);
  }
});

test('createProofKey exports exactly 32 bytes (43 b64url characters)', async () => {
  const { raw, key } = await createProofKey(c);
  assert.ok(raw instanceof Uint8Array);
  assert.equal(raw.length, 32);
  assert.equal(b64url(raw).length, 43);
  assert.deepEqual(b64urlDecode(b64url(raw), 32), raw);
  assert.equal(key.algorithm.name, 'HMAC');
  assert.equal(key.algorithm.length, 256);
  assert.equal(key.algorithm.hash.name, 'SHA-256');
  assert.deepEqual([...key.usages], ['sign']);
  const msg = proofMessage('GET', 'api/sync/status.php', p.ts_ms, p.get.body_sha256);
  assert.equal(b64url(await c.subtle.sign('HMAC', key, enc.encode(msg))), await macOf(raw, msg), 'the exported bytes are the key');
  const second = await createProofKey(c);
  assert.notDeepEqual(second.raw, raw, 'a fresh key every time');
});

test('importProofKey makes a non-extractable key that signs like the raw key', async () => {
  const raw = fromHex(p.key_hex);
  const key = await importProofKey(c, raw);
  assert.equal(key.extractable, false);
  assert.equal(key.algorithm.name, 'HMAC');
  assert.deepEqual([...key.usages], ['sign']);
  await assert.rejects(c.subtle.exportKey('raw', key));
  await assert.rejects(c.subtle.exportKey('jwk', key));
  for (const m of [p.post, p.get]) {
    assert.equal(b64url(await c.subtle.sign('HMAC', key, enc.encode(m.message))), await macOf(raw, m.message));
    assert.equal(await macOf(raw, m.message), m.mac);
  }
  const made = await createProofKey(c);
  const kept = await importProofKey(c, made.raw);
  assert.equal(await proofHeader(c, kept, 'POST', p.post.endpoint, p.ts_ms, p.post.body),
    await proofHeader(c, made.key, 'POST', p.post.endpoint, p.ts_ms, p.post.body));
});

test('proofHeader refuses a timestamp that is not 13 digits', async () => {
  const key = await importProofKey(c, fromHex(p.key_hex));
  for (const ts of [999999999999, 10000000000000, -1790856000000, 0, NaN, Infinity, -Infinity, 2 ** 60]) {
    await assert.rejects(proofHeader(c, key, 'POST', p.post.endpoint, ts, p.post.body), RangeError, String(ts));
  }
  assert.match(await proofHeader(c, key, 'POST', p.post.endpoint, 1000000000000, ''), HEADER, 'the smallest 13-digit time');
  assert.match(await proofHeader(c, key, 'POST', p.post.endpoint, 9999999999999, ''), HEADER, 'the largest 13-digit time');
});
