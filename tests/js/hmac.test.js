// HMAC-SHA256 parity (50-design §3.2) against tests/fixtures/hmac.json, which HmacFixtureTest (Crypto::hmac) also passes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { b64url, sign } from '../../public/station/js/vault.js';
import { fixture, fromHex } from './support/fixtures.js';

const f = fixture('hmac.json');
const sc = fixture('station_crypto.json');
const c = globalThis.crypto;
const enc = new TextEncoder();

/** The message bytes of a case: message_utf8, message_hex or repeat {char, count}. */
function messageOf(cs) {
  if (typeof cs.message_utf8 === 'string') return enc.encode(cs.message_utf8);
  if (typeof cs.message_hex === 'string') return fromHex(cs.message_hex);
  assert.equal(cs.repeat.char.length, 1, cs.name);
  return enc.encode(cs.repeat.char.repeat(cs.repeat.count));
}

test('every hmac.json case', async () => {
  assert.equal(f.cases.length, 5);
  let throughSign = 0;
  for (const cs of f.cases) {
    const key = fromHex(cs.key_hex);
    const message = messageOf(cs);
    const k = await c.subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    const mac = b64url(await c.subtle.sign('HMAC', k, message));
    assert.equal(mac, cs.mac, cs.name + ' (crypto.subtle)');
    assert.equal(mac.length, 43);
    if (key.length === 32) {
      assert.equal(await sign(c, b64url(key), message), cs.mac, cs.name + ' (vault.sign)');
      throughSign++;
    }
  }
  assert.equal(throughSign, 4, 'every 32-byte key also goes through vault.sign()');
  const big = f.cases.find((cs) => cs.repeat);
  assert.equal(messageOf(big).length, 1048576);
  // The item case is the station_crypto.json item: the same bytes and the same mac.
  const item = f.cases.find((cs) => cs.message_utf8 === sc.item.canonical);
  assert.ok(item, 'hmac.json holds the §3.9 item');
  assert.equal(item.key_hex, sc.item.grant_key_hex);
  assert.equal(item.mac, sc.item.mac);
  assert.equal(f.cases.find((cs) => cs.message_utf8 === '').mac, sc.hmac_empty.mac);
});
