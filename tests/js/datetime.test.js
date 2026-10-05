// The datetime text "YYYY-MM-DD HH:MM:SS.mmm" (UTC) and dayStartUtc against tests/fixtures/datetime.json, which
// Clock::dbMillis(), Clock::fromClient() and Clock::orgDayStartUtc() also pass (DatetimeFixtureTest).
import test from 'node:test';
import assert from 'node:assert/strict';
import { formatDb, parseDb, dayStartUtc } from '../../public/station/js/canonical.js';
import { fixture } from './support/fixtures.js';

const f = fixture('datetime.json');

test('formatDb gives the fixture text', () => {
  assert.equal(f.format.length, 9);
  for (const c of f.format) {
    assert.equal(formatDb(c.ms), c.text, String(c.ms));
    assert.equal(parseDb(c.text), c.ms, c.text + ' round-trips');
  }
});

test('parseDb gives the fixture ms', () => {
  assert.equal(f.parse_valid.length, 7);
  for (const c of f.parse_valid) {
    assert.equal(parseDb(c.text), c.ms, c.text);
    assert.equal(formatDb(c.ms), c.text, c.text + ' round-trips');
  }
});

test('parseDb refuses every parse_invalid text', () => {
  assert.equal(f.parse_invalid.length, 27);
  for (const text of f.parse_invalid) {
    assert.equal(parseDb(text), null, JSON.stringify(text));
  }
  for (const value of [null, undefined, 1790856000123, new Date(0), ['2026-10-01 12:00:00.000'], { text: '2026-10-01 12:00:00.000' }]) {
    assert.equal(parseDb(value), null, 'a ' + typeof value + ' is never a time');
  }
});

test('parseDb gives null, never a throw, for texts that roll out of years 0000-9999 (as Clock::fromClient() does)', () => {
  // parse_invalid's texts of years 0000 and 9999, which DatetimeFixtureTest also passes: each matches the pattern, and
  // Date rolls it into year -1 or 10000; PHP's createFromFormat round trip refuses it.
  const rolling = f.parse_invalid.filter((text) => /^(0000|9999)-/.test(text));
  assert.deepEqual(rolling, ['0000-00-00 00:00:00.000', '0000-01-00 00:00:00.000', '0000-00-01 00:00:00.000',
    '9999-12-31 24:00:00.000', '9999-12-32 00:00:00.000', '9999-13-01 00:00:00.000', '9999-12-31 23:60:00.000']);
  for (const text of rolling) {
    assert.equal(parseDb(text), null, text);
  }
  assert.equal(parseDb('0000-01-01 00:00:00.000'), -62167219200000, 'the first instant still parses');
  assert.equal(parseDb('9999-12-31 23:59:59.999'), 253402300799999, 'and the last');
});

test('dayStartUtc gives the fixture instant for every zone and date', () => {
  assert.equal(f.org_day_start.length, 12);
  for (const c of f.org_day_start) {
    assert.equal(formatDb(dayStartUtc(c.zone, c.date)), c.utc, c.zone + ' ' + c.date);
  }
  assert.throws(() => dayStartUtc('UTC', '2026-10-01 00:00'), RangeError);
  assert.throws(() => dayStartUtc('UTC', '2026-1-01'), RangeError);
  assert.throws(() => dayStartUtc('Mars/Olympus_Mons', '2026-10-01'), RangeError);
});

test('formatDb refuses fractions and years outside 0000-9999', () => {
  for (const ms of [1.5, 0.1, -0.5, NaN, Infinity, -Infinity, 2 ** 53, '0', null, undefined]) {
    assert.throws(() => formatDb(ms), RangeError, String(ms));
  }
  assert.throws(() => formatDb(-62167219200001), RangeError, 'the last millisecond of year -1');
  assert.throws(() => formatDb(253402300800000), RangeError, 'the first millisecond of year 10000');
  assert.throws(() => formatDb(8.64e15), RangeError, 'the last instant a Date can hold');
  assert.throws(() => formatDb(8.64e15 + 1), RangeError, 'beyond what a Date can hold');
  assert.equal(formatDb(-62167219200000), '0000-01-01 00:00:00.000');
  assert.equal(formatDb(253402300799999), '9999-12-31 23:59:59.999');
});
