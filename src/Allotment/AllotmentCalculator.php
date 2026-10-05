<?php
declare(strict_types=1);

namespace Pfpms\Allotment;

use Pfpms\Validation\Validator;

/**
 * The allotment arithmetic (UC-05 §4.1, UC-06 steps 3 and 6), with no database access so the
 * offline Station can port it exactly: tests/fixtures/allotment_calculator.json holds the cases
 * both sides must agree on. Pounds are handled in whole hundredths, never floats.
 *
 * - versionOn(): the version in force on a site-local date is the published version with the
 *   latest start date on or before it (the same rule as policy texts).
 * - entitlement(): each pet adds the pounds of the rule for its species and size band. A
 *   species uses either one figure per band for dry or wet food ('Any'), or separate Dry and
 *   Wet figures; the result is split into buckets per species and form so the distribution
 *   screen can check what is issued against the right one.
 *
 * Callers pass only the household's Active pets. A pet whose band has no rule is an error,
 * never zero pounds (plan P3: "a missing rule is a hard error").
 */
final class AllotmentCalculator
{
    /** The largest total a distribution can record: entitled_lbs and current_allotment_lbs are DECIMAL(6,2). */
    public const MAX_TOTAL_HUNDREDTHS = 999999;

    private const FORM_ORDER = ['Any' => 0, 'Dry' => 1, 'Wet' => 2];

    /**
     * @param list<array{rule_version: int|string, effective_from: string}> $published published versions
     * @return ?int the version in force on $date (Y-m-d), or null when none has started yet
     */
    public static function versionOn(array $published, string $date): ?int
    {
        $best = null;
        foreach ($published as $v) {
            $version = (int) $v['rule_version'];
            if ($version < 1 || $v['effective_from'] > $date) {
                continue; // 0 is reserved for imported legacy distributions
            }
            if ($best === null || $v['effective_from'] > $best[1] || ($v['effective_from'] === $best[1] && $version > $best[0])) {
                $best = [$version, $v['effective_from']];
            }
        }
        return $best[0] ?? null;
    }

    /**
     * @param list<array{species_id: int|string, size_band_id: int|string, food_form: string, lbs_per_distribution: ?string}> $rules one version's rules
     * @param list<array{species_id: int|string, size_band_id: int|string}> $pets the household's Active pets
     * @return array{total: string, buckets: list<array{species_id: int, food_form: string, lbs: string}>, per_pet: list<array{lbs: string, forms: array<string, string>}>}
     * @throws MissingAllotmentRule
     * @throws \OverflowException when the total cannot be recorded
     */
    public static function entitlement(array $rules, array $pets): array
    {
        $byBand = [];
        foreach ($rules as $r) {
            if ($r['lbs_per_distribution'] === null) {
                continue; // an empty draft cell is not a rule
            }
            $byBand[(int) $r['species_id'] . ':' . (int) $r['size_band_id']][$r['food_form']] = self::hundredths($r['lbs_per_distribution']);
        }
        $buckets = [];
        $perPet = [];
        $total = 0;
        foreach ($pets as $pet) {
            $speciesId = (int) $pet['species_id'];
            $forms = $byBand[$speciesId . ':' . (int) $pet['size_band_id']] ?? throw new MissingAllotmentRule($speciesId, (int) $pet['size_band_id']);
            $petTotal = 0;
            $petForms = [];
            foreach ($forms as $form => $units) {
                $buckets[$speciesId][$form] = ($buckets[$speciesId][$form] ?? 0) + $units;
                $petForms[$form] = Validator::fromUnits($units, 2);
                $petTotal += $units;
            }
            uksort($petForms, fn($a, $b) => self::FORM_ORDER[$a] <=> self::FORM_ORDER[$b]);
            $perPet[] = ['lbs' => Validator::fromUnits($petTotal, 2), 'forms' => $petForms];
            $total += $petTotal;
        }
        if ($total > self::MAX_TOTAL_HUNDREDTHS) {
            throw new \OverflowException('The allotment for this household is more than 9999.99 lb, which cannot be recorded.');
        }
        ksort($buckets);
        $list = [];
        foreach ($buckets as $speciesId => $forms) {
            uksort($forms, fn($a, $b) => self::FORM_ORDER[$a] <=> self::FORM_ORDER[$b]);
            foreach ($forms as $form => $units) {
                $list[] = ['species_id' => $speciesId, 'food_form' => $form, 'lbs' => Validator::fromUnits($units, 2)];
            }
        }
        return ['total' => Validator::fromUnits($total, 2), 'buckets' => $list, 'per_pet' => $perPet];
    }

    /** '4.50' → 450. The rules come from DECIMAL(5,2) columns or Validator::decimal, so the form is fixed. */
    private static function hundredths(string $lbs): int
    {
        [$whole, $fraction] = array_pad(explode('.', $lbs, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
