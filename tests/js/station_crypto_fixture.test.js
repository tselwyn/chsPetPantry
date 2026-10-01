// tests/fixtures/station_crypto.json is generated, never edited by hand: the generator is rerun in memory here, so a
// hand edit (or a drift from the design's §3.9 table) fails CI.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { buildFixture, checkExpected, asciiJson, EXPECTED, FIXTURE_URL } from './tools/make-station-crypto-fixture.js';

test('the generator meets the §3.9 table', async () => {
  const built = await buildFixture();
  assert.deepEqual(await checkExpected(built), []);
  assert.equal(Object.keys(EXPECTED).length, 18, 'the 50-design §3.9 values the generator checks');
  // checkExpected notices a changed value and a broken trim case.
  const changed = structuredClone(built);
  changed.item.mac = 'x' + changed.item.mac.slice(1);
  changed.lookup.cases[2].hash = changed.lookup.cases[4].hash;
  assert.deepEqual((await checkExpected(changed)).map((line) => line.split(':')[0]), ['item.mac', 'lookup case 2 must equal JDoe']);
});

test('the committed file is the generator\'s output byte for byte', async () => {
  const committed = readFileSync(FIXTURE_URL, 'utf8');
  assert.equal(committed, asciiJson(await buildFixture()));
  assert.match(committed, /^[\x0a\x20-\x7e]*$/, 'ASCII only, LF only');
  assert.ok(committed.endsWith('}\n'));
});
