// The allotment arithmetic (D-57) against tests/fixtures/allotment_calculator.json, which AllotmentCalculator passes.
import test from 'node:test';
import assert from 'node:assert/strict';
import { versionOn, entitlement, fromUnits, MissingAllotmentRule, AllotmentOverflow, MAX_TOTAL_HUNDREDTHS } from '../../public/station/js/calc.js';
import { fixture } from './support/fixtures.js';

const f = fixture('allotment_calculator.json');

test('versionOn gives the fixture version', () => {
  assert.ok(f.version_on.length > 0);
  for (const c of f.version_on) {
    assert.equal(versionOn(c.published, c.date), c.expected, c.name);
  }
});

test('entitlement gives the fixture result, key order included', () => {
  const cases = f.entitlement.filter((c) => c.error === undefined);
  assert.ok(cases.length > 0);
  for (const c of cases) {
    const result = entitlement(c.rules, c.pets);
    assert.deepStrictEqual(result, c.expected, c.name);
    assert.equal(JSON.stringify(result), JSON.stringify(c.expected), c.name + ' (key order)');
  }
});

test('errors are MissingAllotmentRule with species and band, or AllotmentOverflow', () => {
  const cases = f.entitlement.filter((c) => c.error !== undefined);
  assert.ok(cases.some((c) => c.error === 'missing_rule') && cases.some((c) => c.error === 'overflow'), 'the fixture has both errors');
  for (const c of cases) {
    assert.throws(() => entitlement(c.rules, c.pets), (e) => {
      if (c.error === 'missing_rule') {
        assert.ok(e instanceof MissingAllotmentRule, c.name);
        assert.equal(e.code, 'missing_rule');
        assert.deepEqual([e.speciesId, e.sizeBandId], [c.species_id, c.size_band_id], c.name);
        assert.equal(e.message, `No allotment rule covers species ${c.species_id}, size band ${c.size_band_id}. Publish an allotment version that includes this size band.`);
      } else {
        assert.equal(c.error, 'overflow', c.name);
        assert.ok(e instanceof AllotmentOverflow, c.name);
        assert.equal(e.code, 'overflow');
        assert.equal(e.message, 'The allotment for this household is more than 9999.99 lb, which cannot be recorded.');
      }
      return true;
    }, c.name);
  }
  assert.equal(MAX_TOTAL_HUNDREDTHS, 999999);
});

test('fromUnits is Validator::fromUnits', () => {
  assert.equal(fromUnits(450), '4.50');
  assert.equal(fromUnits(5), '0.05');
  assert.equal(fromUnits(0), '0.00');
  assert.equal(fromUnits(-5), '-0.05');
  assert.equal(fromUnits(999999), '9999.99');
  assert.equal(fromUnits(12, 0), '12');
  assert.equal(fromUnits(1234, 3), '1.234');
});
