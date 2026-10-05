// Phonetic key v1 (50-design §11.3: American Soundex per word of the accent fold) against
// tests/fixtures/phonetic_key.json, which Fold::phonetic() also passes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { phoneticKey } from '../../public/station/js/fold.js';
import { fixture } from './support/fixtures.js';

const f = fixture('phonetic_key.json');

test('phoneticKey gives the fixture output', () => {
  assert.equal(f.cases.length, 28);
  for (const c of f.cases) {
    assert.equal(phoneticKey(c.in), c.out, JSON.stringify(c.in));
  }
});

test('h and w do not separate equal codes', () => {
  assert.equal(phoneticKey('Ashcraft'), 'A261');
  assert.equal(phoneticKey('Bhb'), 'B000', 'b, h, b: the second b repeats the first');
  assert.equal(phoneticKey('Bwb'), 'B000');
});

test('vowels separate equal codes', () => {
  assert.equal(phoneticKey('Tymczak'), 'T522');
  assert.equal(phoneticKey('Bab'), 'B100');
});

test('the first letter suppresses an equal code', () => {
  assert.equal(phoneticKey('Pfister'), 'P236');
  assert.equal(phoneticKey('Bb'), 'B000');
});

test('words without a letter are dropped', () => {
  assert.equal(phoneticKey('123'), '');
  assert.equal(phoneticKey('Ana 123 Lopez'), 'A500 L120');
  assert.equal(phoneticKey('\u03a3\u0391\u03a3 Lee'), 'L000', 'a word with no a-z letter after the fold');
  assert.equal(phoneticKey(''), '');
  assert.equal(phoneticKey(null), '');
  assert.equal(phoneticKey('\ud800'), '', 'not well-formed');
});
