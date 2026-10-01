// Accent fold v1 (50-design §11.3) against tests/fixtures/accent_fold.json, which Fold::fold() also passes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { accentFold } from '../../public/station/js/fold.js';
import { fixture } from './support/fixtures.js';

const f = fixture('accent_fold.json');

test('accentFold gives the fixture output', () => {
  assert.equal(f.cases.length, 42);
  for (const c of f.cases) {
    assert.equal(accentFold(c.in), c.out, JSON.stringify(c.in));
  }
});

test('the fold fixtures declare the Unicode version their cases stay within (FoldTest checks every character)', () => {
  // Parity with Fold::fold() holds only for characters both engines know with the same category (fold.js header).
  for (const name of ['accent_fold.json', 'phonetic_key.json']) {
    const data = fixture(name);
    assert.equal(data.unicode_version, '14.0', name);
    assert.match(data._comment, /only characters assigned in Unicode unicode_version or earlier/, name);
    for (const c of data.cases) assert.match(c.in + c.out, /^\p{Assigned}*$/u, `${name}: ${JSON.stringify(c.in)}`);
  }
  const [major] = process.versions.unicode.split('.').map(Number);
  assert.ok(major >= 14, `this engine knows Unicode 14 (it has ${process.versions.unicode})`);
});

test('ΣΑΣ and σας both fold to σασ', () => {
  assert.equal('\u03a3\u0391\u03a3'.toLowerCase(), '\u03c3\u03b1\u03c2', 'JavaScript lower-cases a whole string with final-sigma casing');
  assert.equal(accentFold('\u03a3\u0391\u03a3'), '\u03c3\u03b1\u03c3');
  assert.equal(accentFold('\u03c3\u03b1\u03c2'), '\u03c3\u03b1\u03c3');
  assert.equal(accentFold('\u03a3\u0391\u03a3 \u03a3\u0391\u03a3'), '\u03c3\u03b1\u03c3 \u03c3\u03b1\u03c3', 'at the end of every word too');
});

test('a string that is not well-formed folds to the empty string', () => {
  assert.equal(accentFold('\ud800'), '');
  assert.equal(accentFold('Mu\u00f1oz \ud800'), '', 'one lone surrogate spoils the whole string');
  assert.equal(accentFold('\udc00abc'), '', 'a lone low surrogate');
  assert.equal(accentFold('\ud83d\ude00'), '', 'a well-formed pair is not refused (it is not a letter)');
});

test('non-strings fold to the empty string', () => {
  for (const value of [null, undefined, 0, 42, true, {}, ['Mu\u00f1oz'], new String('Mu\u00f1oz')]) {
    assert.equal(accentFold(value), '', 'a ' + typeof value);
  }
});
