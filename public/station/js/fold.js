// Accent fold v1 and phonetic key v1 (docs/design/50-design-station.md §11.3), the twins of src/Text/Fold.php
// (Fold::fold() and Fold::phonetic()): tests/fixtures/accent_fold.json and phonetic_key.json hold the cases both
// must give. Lower-casing is per code point and ς is then mapped to σ, so PHP 8.2, PHP 8.3 and JavaScript agree.
// Parity holds within the Unicode version both engines share, and only there: \p{L}, \p{N}, \p{Mn}, NFD and
// lower-casing come from each engine's own tables (PHP 8.2.4 here: PCRE2 10.40 and ICU 71, Unicode 14; Node 24 and
// current browsers: Unicode 15-17). A letter added later (U+A7CC, U+1C89, U+10D50) is kept and lower-cased here but
// is a space in PHP, and a character whose category changed (U+1171E: Mn in Unicode 14, Mc in 17) is removed in PHP
// but splits the word here. Folding every code point (alone, and between two letters), PHP 8.2.4 and Node 24 differ
// on 14 159 that Unicode 14 leaves unassigned (added in 15-17) and on U+1171E, and on nothing else. So the fixtures
// stay within Unicode 14 (unicode_version), and P3 must not compare a key made on the tablet with one made on the
// server for other text: either the server recomputes keys from the text, or both sides pin Unicode 14 tables.
// Used by P3 search; precached but not imported by anything in S2.

const MAP = new Map([
  ['ß', 'ss'], ['ẞ', 'ss'], ['æ', 'ae'], ['Æ', 'ae'], ['ø', 'o'], ['Ø', 'o'], ['œ', 'oe'], ['Œ', 'oe'],
  ['ł', 'l'], ['Ł', 'l'], ['đ', 'd'], ['Đ', 'd'], ['ð', 'd'], ['Ð', 'd'], ['þ', 'th'], ['Þ', 'th'],
  ['ı', 'i'], ['ħ', 'h'], ['Ħ', 'h'], ['ŋ', 'n'], ['Ŋ', 'n'], ['ſ', 's'],
]);
const APOSTROPHES = /['\u2019\u02BC`\u00B4]/gu;

/**
 * Accent fold v1: NFC then NFD, marks removed, the 22-letter map, lower-cased per code point, ς → σ, apostrophes
 * removed, every run of characters that are neither letters nor digits → one space, trimmed.
 * @param {unknown} text
 * @returns {string} '' for a non-string or a string that is not well-formed UTF-16
 */
export function accentFold(text) {
  if (typeof text !== 'string' || !text.isWellFormed()) return '';
  let s = text.normalize('NFC').normalize('NFD');
  s = s.replace(/\p{Mn}/gu, '');
  s = [...s].map((c) => MAP.get(c) ?? c).join('');
  s = [...s].map((c) => c.toLowerCase()).join('');   // per code point: never final-sigma casing
  s = s.replace(/\u03C2/gu, '\u03C3');                // ς → σ
  s = s.replace(APOSTROPHES, '');
  return s.replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
}

const CODES = { b: 1, f: 1, p: 1, v: 1, c: 2, g: 2, j: 2, k: 2, q: 2, s: 2, x: 2, z: 2, d: 3, t: 3, l: 4, m: 5, n: 5, r: 6,
  a: 0, e: 0, i: 0, o: 0, u: 0, y: 0 }; // h and w are absent: they do not separate equal codes

function soundex(word) {
  const w = word.replace(/[^a-z]/g, '');
  if (w === '') return '';
  let out = w[0].toUpperCase();
  let last = CODES[w[0]] ?? 0;
  for (const ch of w.slice(1)) {
    const code = CODES[ch];
    if (code === undefined) continue; // h, w
    if (code !== 0 && code !== last) out += code;
    last = code;
    if (out.length === 4) break;
  }
  return out.padEnd(4, '0');
}

/**
 * American Soundex per word of accentFold(text); words with no a-z letter are dropped; joined by one space.
 * @param {unknown} text
 * @returns {string}
 */
export function phoneticKey(text) {
  return accentFold(text).split(' ').map(soundex).filter((x) => x !== '').join(' ');
}
