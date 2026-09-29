<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use DateTimeZone;
use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Distribution sites (plan P2A). Each site has its own IANA time zone, which decides the
 * local date of its distributions (UC-14 §4.2), so the zone must be a real identifier.
 *
 * This is the reference pattern for admin modules: pages call the Service; the Service
 * validates, writes through the Repository inside one transaction, and audits every change.
 */
final class SiteService
{
    public const FIELDS = ['name', 'street_address', 'city', 'state', 'postal_code', 'time_zone'];

    /** @throws ValidationException */
    public static function create(array $input): int
    {
        $values = self::validate($input, null);
        return Db::transaction(function () use ($values): int {
            $id = SiteRepository::insert($values);
            Audit::record('site_create', 'site', $id, details: ['name' => $values['name']]);
            return $id;
        });
    }

    /** @throws ValidationException */
    public static function update(int $siteId, array $input): void
    {
        $before = SiteRepository::find($siteId) ?? throw ValidationException::one('_form', 'That site no longer exists.');
        $values = self::validate($input, $siteId);
        $changes = Audit::diff($before, $values, self::FIELDS);
        if (!$changes) {
            return;
        }
        Db::transaction(function () use ($siteId, $values, $changes): void {
            SiteRepository::update($siteId, $values);
            Audit::record('site_update', 'site', $siteId, changes: $changes);
        });
    }

    /** @throws ValidationException when deactivating the last active site */
    public static function setActive(int $siteId, bool $active): void
    {
        $site = SiteRepository::find($siteId) ?? throw ValidationException::one('_form', 'That site no longer exists.');
        if ((bool) $site['is_active'] === $active) {
            return;
        }
        if (!$active && SiteRepository::activeCount() <= 1) {
            throw ValidationException::one('_form', 'At least one site must stay active.');
        }
        Db::transaction(function () use ($siteId, $active): void {
            SiteRepository::setActive($siteId, $active);
            Audit::record($active ? 'site_activate' : 'site_deactivate', 'site', $siteId, changes: ['is_active' => [(int) !$active, (int) $active]]);
        });
    }

    /** @return array<string, ?string> normalised values */
    private static function validate(array $input, ?int $siteId): array
    {
        $errors = [];
        $name = Validator::text($input['name'] ?? null, 100);
        if ($name === null) {
            $errors['name'] = 'Enter the site name (up to 100 characters).';
        } elseif (SiteRepository::nameTaken($name, $siteId)) {
            $errors['name'] = 'Another site already has this name.';
        }
        $optional = static function (string $field, int $max, string $label) use ($input, &$errors): ?string {
            $raw = trim((string) ($input[$field] ?? ''));
            if ($raw === '') {
                return null;
            }
            $value = Validator::text($raw, $max);
            if ($value === null) {
                $errors[$field] = "$label can be at most $max characters.";
            }
            return $value;
        };
        $street = $optional('street_address', 100, 'The street address');
        $city = $optional('city', 50, 'The city');
        $stateRaw = strtoupper(trim((string) ($input['state'] ?? '')));
        if ($stateRaw !== '' && !preg_match('/^[A-Z]{2}$/', $stateRaw)) {
            $errors['state'] = 'Use the two-letter state code, e.g. SC.';
        }
        $zipRaw = trim((string) ($input['postal_code'] ?? ''));
        $zip = $zipRaw === '' ? null : Validator::zip($zipRaw);
        if ($zipRaw !== '' && $zip === null) {
            $errors['postal_code'] = 'Enter a 5-digit ZIP code (ZIP+4 is fine).';
        }
        $zone = trim((string) ($input['time_zone'] ?? ''));
        if (!in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            $errors['time_zone'] = 'Choose the time zone the site is in.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['name' => $name, 'street_address' => $street, 'city' => $city, 'state' => $stateRaw !== '' ? $stateRaw : null,
            'postal_code' => $zip, 'time_zone' => $zone];
    }
}
