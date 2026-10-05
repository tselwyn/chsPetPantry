<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * The clinic directory (plan P2A): partner and low-cost clinics, with the address, hours and
 * directions printed on vouchers (US-21), the voucher rate and capacity used by spay/neuter
 * referrals (UC-08), and whether the clinic offers low-cost vaccination (US-14).
 * A clinic is suspended rather than deleted, because referrals and pets point at it.
 * Species rules and the portal access code arrive with the spay/neuter work (plan P5).
 */
final class ClinicService
{
    public const FIELDS = ['name', 'is_partner', 'street_address', 'city', 'state', 'postal_code', 'phone', 'hours_text', 'directions_url',
        'voucher_rate', 'period_capacity', 'current_wait_days', 'offers_low_cost_vaccination'];

    /** @throws ValidationException */
    public static function create(array $input): int
    {
        $values = self::validate($input);
        return Db::transaction(function () use ($values): int {
            $id = ClinicRepository::insert($values);
            Audit::record('clinic_create', 'clinic', $id, details: ['name' => $values['name']]);
            return $id;
        });
    }

    /** @throws ValidationException */
    public static function update(int $clinicId, array $input): void
    {
        $before = ClinicRepository::find($clinicId) ?? throw ValidationException::one('_form', 'That clinic no longer exists.');
        $values = self::validate($input);
        $changes = Audit::diff($before, $values, self::FIELDS);
        if (!$changes) {
            return;
        }
        Db::transaction(function () use ($clinicId, $values, $changes): void {
            ClinicRepository::update($clinicId, $values);
            Audit::record('clinic_update', 'clinic', $clinicId, changes: $changes);
        });
    }

    /** Suspend (no new referrals) or reactivate a clinic. @throws ValidationException */
    public static function setSuspended(int $clinicId, bool $suspended): void
    {
        $clinic = ClinicRepository::find($clinicId) ?? throw ValidationException::one('_form', 'That clinic no longer exists.');
        $status = $suspended ? 'Suspended' : 'Active';
        if ($clinic['status'] === $status) {
            return;
        }
        Db::transaction(function () use ($clinicId, $clinic, $status, $suspended): void {
            ClinicRepository::setStatus($clinicId, $status);
            Audit::record($suspended ? 'clinic_suspend' : 'clinic_reactivate', 'clinic', $clinicId, changes: ['status' => [$clinic['status'], $status]]);
        });
    }

    /** "8435551234" => "(843) 555-1234", for display. */
    public static function formatPhone(?string $digits): string
    {
        return $digits !== null && preg_match('/^(\d{3})(\d{3})(\d{4})$/', $digits, $m) ? "($m[1]) $m[2]-$m[3]" : (string) $digits;
    }

    /**
     * "1,250.50" => "1250.50". A comma is accepted only as a thousands separator, so "75,50"
     * is refused instead of being read as 7550. Returns null when the commas are misplaced.
     */
    private static function withoutThousands(string $value): ?string
    {
        if (str_contains($value, ',') && !preg_match('/^\d{1,3}(,\d{3})+(\.\d*)?$/', $value)) {
            return null;
        }
        return str_replace(',', '', $value);
    }

    /** @return array<string, mixed> normalised values */
    private static function validate(array $input): array
    {
        $errors = [];
        $raw = static fn(string $field): string => trim((string) (is_scalar($input[$field] ?? null) ? $input[$field] : ''));

        $name = Validator::text($raw('name'), 100);
        if ($name === null) {
            $errors['name'] = 'Enter the clinic name (up to 100 characters).';
        }
        $optional = static function (string $field, int $max, string $label) use ($raw, &$errors): ?string {
            if ($raw($field) === '') {
                return null;
            }
            $value = Validator::text($raw($field), $max);
            if ($value === null) {
                $errors[$field] = "$label can be at most $max characters.";
            }
            return $value;
        };
        $street = $optional('street_address', 100, 'The street address');
        $city = $optional('city', 50, 'The city');
        $hours = $optional('hours_text', 255, 'The opening hours');

        $state = strtoupper($raw('state'));
        if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
            $errors['state'] = 'Use the two-letter state code, e.g. SC.';
        }
        $zip = $raw('postal_code') === '' ? null : Validator::zip($raw('postal_code'));
        if ($raw('postal_code') !== '' && $zip === null) {
            $errors['postal_code'] = 'Enter a 5-digit ZIP code (ZIP+4 is fine).';
        }
        $phone = $raw('phone') === '' ? null : Validator::phone($raw('phone'));
        if ($raw('phone') !== '' && $phone === null) {
            $errors['phone'] = 'Enter a 10-digit phone number, e.g. (843) 555-1234.';
        }
        $url = $raw('directions_url') === '' ? null : Validator::url($raw('directions_url'));
        if ($raw('directions_url') !== '' && ($url === null || mb_strlen($url) > 255)) {
            $errors['directions_url'] = 'Enter a web address starting with http:// or https:// (up to 255 characters).';
            $url = null;
        }

        $rate = null;
        $rateRaw = str_replace(['$', ' '], '', $raw('voucher_rate'));
        if ($rateRaw !== '') {
            $plain = self::withoutThousands($rateRaw);
            if ($plain !== null && preg_match('/^\d{1,5}(\.\d{1,2})?$/', $plain)) {
                $rate = number_format((float) $plain, 2, '.', '');
            } else {
                $errors['voucher_rate'] = 'Enter the voucher amount in dollars, e.g. 75 or 75.50 (up to 99,999.99).';
            }
        }
        $whole = static function (string $field, string $message) use ($raw, &$errors): ?int {
            if ($raw($field) === '') {
                return null;
            }
            $value = self::withoutThousands($raw($field));
            if ($value === null || !preg_match('/^\d{1,5}$/', $value) || (int) $value > 32767) {
                $errors[$field] = $message;
                return null;
            }
            return (int) $value;
        };
        $capacity = $whole('period_capacity', 'Enter a whole number of referrals from 0 to 32767, or leave it blank.');
        $wait = $whole('current_wait_days', 'Enter a whole number of days from 0 to 32767, or leave it blank.');

        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['name' => $name, 'is_partner' => empty($input['is_partner']) ? 0 : 1, 'street_address' => $street, 'city' => $city,
            'state' => $state !== '' ? $state : null, 'postal_code' => $zip, 'phone' => $phone, 'hours_text' => $hours, 'directions_url' => $url,
            'voucher_rate' => $rate, 'period_capacity' => $capacity, 'current_wait_days' => $wait,
            'offers_low_cost_vaccination' => empty($input['offers_low_cost_vaccination']) ? 0 : 1];
    }
}
