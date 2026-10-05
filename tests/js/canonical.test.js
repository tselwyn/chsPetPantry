// Canonical JSON v1 (50-design §3.6, D-33) against tests/fixtures/canonical_json.json, which Canonical.php also passes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { canonical, canonicalBytes, isCanonicalText, CanonicalError, FORMAT, MAX_BYTES, MAX_DEPTH } from '../../public/station/js/canonical.js';
import { fixture, fromHex } from './support/fixtures.js';

const f = fixture('canonical_json.json');

/** Bytes that are not UTF-8 are not canonical; otherwise the text must be. */
function isCanonicalBytes(bytes) {
  let text;
  try { text = new TextDecoder('utf-8', { fatal: true }).decode(bytes); } catch { return false; }
  return isCanonicalText(text);
}

function reasonOf(value) {
  try {
    canonical(value);
  } catch (e) {
    assert.ok(e instanceof CanonicalError, 'a CanonicalError, not ' + e);
    assert.equal(e.name, 'CanonicalError');
    return e.reason;
  }
  return null;
}

const nest = (n) => '['.repeat(n) + '1' + ']'.repeat(n);

test('the constants', () => {
  assert.equal(FORMAT, 'pfpms/v1');
  assert.equal(MAX_BYTES, 65536);
  assert.equal(MAX_DEPTH, 16);
});

test('valid cases encode to their bytes and are canonical', () => {
  assert.equal(f.valid.length, 18);
  for (const c of f.valid) {
    assert.equal(canonical(JSON.parse(c.json)), c.bytes, c.name);
    assert.equal(isCanonicalText(c.bytes), true, c.name + ' (bytes are canonical)');
    assert.equal(isCanonicalBytes(new TextEncoder().encode(c.bytes)), true, c.name + ' (as UTF-8)');
  }
});

test('invalid cases are not canonical', () => {
  assert.equal(f.invalid.length, 26);
  for (const c of f.invalid) {
    if (c.bytes_hex !== undefined) {
      assert.throws(() => new TextDecoder('utf-8', { fatal: true }).decode(fromHex(c.bytes_hex)), TypeError, c.name + ' is not UTF-8');
      assert.equal(isCanonicalBytes(fromHex(c.bytes_hex)), false, c.name);
    } else {
      assert.equal(isCanonicalText(c.bytes), false, c.name);
    }
  }
});

test('exactly 65536 bytes is canonical and 65537 is not', () => {
  assert.equal(f.sized.length, 2);
  for (const c of f.sized) {
    const text = '{"s":"' + 'a'.repeat(c.total_bytes - 8) + '"}';
    assert.equal(new TextEncoder().encode(text).length, c.total_bytes);
    assert.equal(isCanonicalText(text), c.canonical, c.name);
  }
  assert.equal(canonical({ s: 'a'.repeat(MAX_BYTES - 8) }).length, MAX_BYTES);
  assert.equal(reasonOf({ s: 'a'.repeat(MAX_BYTES - 7) }), 'too_large');
  // The limit is in UTF-8 bytes, not UTF-16 code units: ñ is two bytes.
  assert.equal(canonicalBytes({ s: '\u00f1'.repeat((MAX_BYTES - 8) / 2) }).length, MAX_BYTES);
  assert.equal(reasonOf({ s: '\u00f1'.repeat((MAX_BYTES - 8) / 2) + 'a' }), 'too_large');
});

test('refuses undefined, functions, bigint, Map, Date and class instances', () => {
  class Item { constructor() { this.a = 1; } }
  for (const [name, value] of [
    ['undefined', undefined], ['a function', () => 1], ['a bigint', 1n], ['a Map', new Map([['a', 1]])], ['a Date', new Date(0)],
    ['a class instance', new Item()], ['a symbol', Symbol('x')], ['a Set', new Set()], ['a typed array', new Uint8Array(1)],
    ['undefined inside an object', { a: undefined }], ['undefined inside an array', [undefined]], ['a nested Date', { a: [new Date(0)] }],
  ]) {
    assert.equal(reasonOf(value), 'type', name);
  }
  assert.equal(canonical(Object.assign(Object.create(null), { b: 1, a: 2 })), '{"a":2,"b":1}', 'an object without a prototype is plain');
});

test('refuses unsafe integers, floats, NaN and Infinity', () => {
  for (const value of [2 ** 53, -(2 ** 53), 1.5, 0.1, -1.5, NaN, Infinity, -Infinity, 1e300, Number.MAX_VALUE, Number.MIN_VALUE]) {
    assert.equal(reasonOf(value), 'number', String(value));
    assert.equal(reasonOf({ a: value }), 'number', 'inside an object: ' + value);
  }
  assert.equal(canonical(Number.MAX_SAFE_INTEGER), '9007199254740991');
  assert.equal(canonical(Number.MIN_SAFE_INTEGER), '-9007199254740991');
  assert.equal(canonical(1.0), '1', 'a whole number has no fraction once parsed');
});

test('keys, depth and strings name their reason', () => {
  for (const key of ['A', '', '1a', 'a-b', '\u00f1', 'a b', 'a\n', '_a', 'k'.repeat(65)]) {
    assert.equal(reasonOf({ [key]: 1 }), 'key', JSON.stringify(key));
  }
  assert.equal(canonical({ ['k'.repeat(64)]: 1 }), '{"' + 'k'.repeat(64) + '":1}');
  assert.equal(canonical(JSON.parse(nest(MAX_DEPTH))), nest(MAX_DEPTH));
  assert.equal(reasonOf(JSON.parse(nest(MAX_DEPTH + 1))), 'depth');
  let deep = {};
  for (let i = 1; i < MAX_DEPTH; i++) deep = { a: deep };
  assert.equal(canonical(deep).length > 0, true, 'sixteen levels of objects');
  assert.equal(reasonOf({ a: deep }), 'depth', 'seventeen levels of objects');
  assert.equal(reasonOf('\ud800'), 'string', 'a lone high surrogate');
  assert.equal(reasonOf({ s: 'a\udc00' }), 'string', 'a lone low surrogate');
  assert.equal(isCanonicalText(nest(MAX_DEPTH + 1)), false);
  assert.equal(isCanonicalText('not json'), false, 'isCanonicalText never throws');
});

test('canonicalBytes is the UTF-8 of canonical', () => {
  for (const value of [{ s: 'Mu\u00f1oz \u03a3 \ud83d\ude00 \u2028' }, [1, 'a', null], 'x', 0, true, null, {}]) {
    assert.deepEqual(canonicalBytes(value), new TextEncoder().encode(canonical(value)));
  }
  assert.ok(canonicalBytes({}) instanceof Uint8Array);
  assert.equal(canonicalBytes({ s: '\ud83d\ude00' }).length, 12, 'the emoji is four bytes');
});

test('minus zero is emitted as 0', () => {
  assert.equal(canonical(-0), '0');
  assert.equal(canonical({ a: -0 }), '{"a":0}');
  assert.equal(canonical([-0, 0]), '[0,0]');
  assert.equal(isCanonicalText('{"a":-0}'), false);
});
