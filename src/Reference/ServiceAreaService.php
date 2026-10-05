<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * The configurable service area (UC-03 §4.3, plan P2A admin_service_area): the ZIP codes the
 * pantry serves, each optionally assigned to its nearest site (UC-04 §3.2.1). ZIPs are stored
 * as 5 digits; ZIP+4 input is shortened to its first 5 digits everywhere.
 */
final class ServiceAreaService
{
    /** The 5-digit ZIP for a ZIP or ZIP+4 ("29401", "29401-1234", "294011234"), or null. */
    public static function normalise(string $postalCode): ?string
    {
        $zip = Validator::zip($postalCode);
        return $zip === null ? null : substr($zip, 0, 5);
    }

    /**
     * Split pasted text into ZIPs. Entries may be separated by commas, semicolons, spaces or new lines.
     * @return array{valid: list<string>, invalid: list<string>} valid ZIPs (5 digits, no repeats) and the entries that are not ZIPs
     */
    public static function parse(string $text): array
    {
        $valid = [];
        $invalid = [];
        $text = str_replace("\u{00A0}", ' ', $text); // non-breaking spaces pasted from spreadsheets
        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $zip = self::normalise($entry);
            if ($zip === null) {
                $shown = mb_substr($entry, 0, 20);
                $invalid[$shown] = $shown;
            } else {
                $valid[$zip] = $zip;
            }
        }
        return ['valid' => array_values($valid), 'invalid' => array_values($invalid)];
    }

    /**
     * Add ZIPs to the service area. New ZIPs are added, deactivated ones are reactivated, and
     * ZIPs already active are left as they are. $siteId, when given, is assigned to the ZIPs
     * added or reactivated. Entries that are not ZIPs are returned, not saved.
     *
     * @return array{added: list<string>, reactivated: list<string>, existing: list<string>, invalid: list<string>}
     * @throws ValidationException when there is nothing to add or the site cannot be used
     */
    public static function bulkAdd(string $text, ?int $siteId): array
    {
        ['valid' => $valid, 'invalid' => $invalid] = self::parse($text);
        $errors = [];
        if (!$valid && !$invalid) {
            $errors['postal_codes'] = 'Enter at least one ZIP code.';
        }
        if ($siteId !== null && !self::siteIsActive($siteId)) {
            $errors['site_id'] = 'Choose an active site, or no site.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $result = ['added' => [], 'reactivated' => [], 'existing' => [], 'invalid' => $invalid];
        if (!$valid) {
            return $result;
        }
        return Db::transaction(function () use ($valid, $siteId, $result): array {
            foreach ($valid as $zip) {
                $row = ServiceAreaRepository::find($zip);
                if ($row === null) {
                    ServiceAreaRepository::insert($zip, $siteId);
                    $result['added'][] = $zip;
                } elseif (!$row['is_active']) {
                    ServiceAreaRepository::setActive($zip, true);
                    if ($siteId !== null) {
                        ServiceAreaRepository::setSite($zip, $siteId);
                    }
                    $result['reactivated'][] = $zip;
                } else {
                    $result['existing'][] = $zip;
                }
            }
            if ($result['added'] || $result['reactivated']) {
                Audit::record('service_area_add', 'service_area_postal_code', details: [
                    'added' => $result['added'], 'reactivated' => $result['reactivated'], 'invalid' => $result['invalid'], 'site_id' => $siteId,
                ]);
            }
            return $result;
        });
    }

    /** Assign a ZIP to its nearest site, or to no site (null). */
    public static function setSite(string $postalCode, ?int $siteId): void
    {
        [$zip, $row] = self::load($postalCode);
        $current = $row['site_id'] === null ? null : (int) $row['site_id'];
        if ($current === $siteId) {
            return;
        }
        if ($siteId !== null && !self::siteIsActive($siteId)) {
            throw ValidationException::one('_form', 'Choose an active site, or no site.');
        }
        Db::transaction(function () use ($zip, $current, $siteId): void {
            ServiceAreaRepository::setSite($zip, $siteId);
            Audit::record('service_area_site', 'service_area_postal_code', details: ['postal_code' => $zip], changes: ['site_id' => [$current, $siteId]]);
        });
    }

    public static function setActive(string $postalCode, bool $active): void
    {
        [$zip, $row] = self::load($postalCode);
        if ((bool) $row['is_active'] === $active) {
            return;
        }
        Db::transaction(function () use ($zip, $active): void {
            ServiceAreaRepository::setActive($zip, $active);
            Audit::record($active ? 'service_area_activate' : 'service_area_deactivate', 'service_area_postal_code',
                details: ['postal_code' => $zip], changes: ['is_active' => [(int) !$active, (int) $active]]);
        });
    }

    /** True when the ZIP (or ZIP+4) is an active part of the service area (UC-03 §3.3.3). */
    public static function covers(string $postalCode): bool
    {
        $zip = self::normalise($postalCode);
        $row = $zip === null ? null : ServiceAreaRepository::find($zip);
        return $row !== null && (bool) $row['is_active'];
    }

    /** The nearest active site for a covered ZIP (or ZIP+4), or null when none is assigned (UC-04 §3.2.1). */
    public static function siteFor(string $postalCode): ?int
    {
        $zip = self::normalise($postalCode);
        return $zip === null ? null : ServiceAreaRepository::activeSiteFor($zip);
    }

    /** @return array{0: string, 1: array} */
    private static function load(string $postalCode): array
    {
        $zip = self::normalise($postalCode);
        $row = $zip === null ? null : ServiceAreaRepository::find($zip);
        if ($zip === null || $row === null) {
            throw ValidationException::one('_form', 'That ZIP code is not in the service area list.');
        }
        return [$zip, $row];
    }

    private static function siteIsActive(int $siteId): bool
    {
        $site = SiteRepository::find($siteId);
        return $site !== null && (bool) $site['is_active'];
    }
}
