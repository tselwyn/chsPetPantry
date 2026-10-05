// The allotment arithmetic (D-57), the twin of src/Allotment/AllotmentCalculator.php: both give exactly the answers of
// tests/fixtures/allotment_calculator.json, key order included. Amounts are decimal strings ("4.50") and the sums are
// done in whole hundredths of a pound, never in floating point. Used by P4; precached but not imported in S2.

/** The largest total a distribution can record: 9999.99 lb, in hundredths. */
export const MAX_TOTAL_HUNDREDTHS = 999999;
const FORM_ORDER = { Any: 0, Dry: 1, Wet: 2 };

/** A pet's species and size band has no rule in the version in force (code `missing_rule`). */
export class MissingAllotmentRule extends Error {
  /**
   * @param {number} speciesId
   * @param {number} sizeBandId
   */
  constructor(speciesId, sizeBandId) {
    super(`No allotment rule covers species ${speciesId}, size band ${sizeBandId}. Publish an allotment version that includes this size band.`);
    this.name = 'MissingAllotmentRule'; this.code = 'missing_rule'; this.speciesId = speciesId; this.sizeBandId = sizeBandId;
  }
}

/** The household's total is more than MAX_TOTAL_HUNDREDTHS (code `overflow`; PHP throws an OverflowException). */
export class AllotmentOverflow extends Error {
  constructor() { super('The allotment for this household is more than 9999.99 lb, which cannot be recorded.'); this.name = 'AllotmentOverflow'; this.code = 'overflow'; }
}

const int = (v) => Math.trunc(Number(v)) || 0; // PHP (int) of an int or a digit string

/**
 * The published version in force on a Y-m-d date (latest start on or before it; the higher number on a tie; 0 never).
 * @param {Array<{rule_version: number|string, effective_from: string}>} published
 * @param {string} date Y-m-d
 * @returns {number|null}
 */
export function versionOn(published, date) {
  let best = null;
  for (const v of published) {
    const version = int(v.rule_version);
    if (version < 1 || v.effective_from > date) continue;
    if (best === null || v.effective_from > best[1] || (v.effective_from === best[1] && version > best[0])) best = [version, v.effective_from];
  }
  return best === null ? null : best[0];
}

function hundredths(lbs) {
  const [whole, fraction = ''] = String(lbs).split('.', 2);
  return int(whole) * 100 + int(fraction.slice(0, 2).padEnd(2, '0'));
}

/**
 * Validator::fromUnits(): whole units of 10^-scale → a decimal string ("450" at scale 2 → "4.50").
 * @param {number} units an integer
 * @param {number} [scale=2]
 * @returns {string}
 */
export function fromUnits(units, scale = 2) {
  if (scale === 0) return String(units);
  const sign = units < 0 ? '-' : '';
  const a = Math.abs(units);
  const p = 10 ** scale;
  return sign + Math.trunc(a / p) + '.' + String(a % p).padStart(scale, '0');
}

const byForm = (a, b) => FORM_ORDER[a] - FORM_ORDER[b];

/**
 * {total, buckets: [{species_id, food_form, lbs}], per_pet: [{lbs, forms: {Any|Dry|Wet: lbs}}]}; throws MissingAllotmentRule / AllotmentOverflow.
 * @param {Array<{species_id: number|string, size_band_id: number|string, food_form: 'Any'|'Dry'|'Wet', lbs_per_distribution: string|null}>} rules the version in force
 * @param {Array<{species_id: number|string, size_band_id: number|string}>} pets the household's active pets
 * @returns {{total: string, buckets: Array<{species_id: number, food_form: string, lbs: string}>, per_pet: Array<{lbs: string, forms: Object<string, string>}>}}
 */
export function entitlement(rules, pets) {
  const byBand = new Map();
  for (const r of rules) {
    if (r.lbs_per_distribution === null) continue; // an empty draft cell is not a rule
    const key = int(r.species_id) + ':' + int(r.size_band_id);
    if (!byBand.has(key)) byBand.set(key, new Map());
    byBand.get(key).set(r.food_form, hundredths(r.lbs_per_distribution));
  }
  const buckets = new Map();
  const perPet = [];
  let total = 0;
  for (const pet of pets) {
    const speciesId = int(pet.species_id);
    const forms = byBand.get(speciesId + ':' + int(pet.size_band_id));
    if (forms === undefined) throw new MissingAllotmentRule(speciesId, int(pet.size_band_id));
    let petTotal = 0;
    const petForms = [];
    for (const [form, units] of forms) {
      if (!buckets.has(speciesId)) buckets.set(speciesId, new Map());
      const b = buckets.get(speciesId);
      b.set(form, (b.get(form) ?? 0) + units);
      petForms.push([form, units]);
      petTotal += units;
    }
    petForms.sort((a, b) => byForm(a[0], b[0]));
    perPet.push({ lbs: fromUnits(petTotal), forms: Object.fromEntries(petForms.map(([f, u]) => [f, fromUnits(u)])) });
    total += petTotal;
  }
  if (total > MAX_TOTAL_HUNDREDTHS) throw new AllotmentOverflow();
  const list = [];
  for (const speciesId of [...buckets.keys()].sort((a, b) => a - b)) {
    for (const [form, units] of [...buckets.get(speciesId)].sort((a, b) => byForm(a[0], b[0]))) {
      list.push({ species_id: speciesId, food_form: form, lbs: fromUnits(units) });
    }
  }
  return { total: fromUnits(total), buckets: list, per_pet: perPet };
}
