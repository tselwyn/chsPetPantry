<?php
declare(strict_types=1);

namespace Pfpms\Validation;

use DateTimeImmutable;

/**
 * Input validators, ported from legacy include/input-validation.php with the review fixes
 * (plan §7). Each returns the normalised value, or null when the input is not valid.
 * The same rules are exported to the offline Station so both sides agree.
 */
final class Validator
{
    /** Trim and collapse internal whitespace; unique fields must be trimmed (the collation is PAD SPACE). */
    public static function text(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return $value === '' || mb_strlen($value) > $maxLength ? null : $value;
    }

    /** Legacy validateDate: a real calendar date in Y-m-d. */
    public static function date(?string $value): ?string
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }

    /** Legacy validate24hTime, fixed: the pattern is anchored. Returns H:i. */
    public static function time24h(?string $value): ?string
    {
        return $value !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
    }

    /** Legacy validate24hTimeRange, fixed: checks both ends (the original checked $start twice). */
    public static function time24hRange(?string $start, ?string $end): bool
    {
        $s = self::time24h($start);
        $e = self::time24h($end);
        return $s !== null && $e !== null && $s < $e;
    }

    /** Legacy validateEmail, plus a length cap (user_account.email is VARCHAR(100)). */
    public static function email(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value !== null && mb_strlen($value) <= 100 && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    /**
     * Legacy validateAndFilterPhoneNumber, fixed: a leading US country code "1" is accepted.
     * Returns the 10 digits.
     */
    public static function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $value);
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        return strlen($digits) === 10 ? $digits : null;
    }

    /** Legacy validateZipcode, fixed: ZIP+4 is accepted and normalised to "12345-6789". */
    public static function zip(?string $value): ?string
    {
        if ($value === null || !preg_match('/^\s*(\d{5})(?:[-\s]?(\d{4}))?\s*$/', $value, $m)) {
            return null;
        }
        return isset($m[2]) && $m[2] !== '' ? "$m[1]-$m[2]" : $m[1];
    }

    /** Legacy validateURL, fixed: only http and https (no javascript: or data: URLs). */
    public static function url(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value !== null && filter_var($value, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $value) ? $value : null;
    }

    /** Legacy valueConstrainedTo, fixed: strict comparison. */
    public static function oneOf(?string $value, array $allowed): ?string
    {
        return $value !== null && in_array($value, $allowed, true) ? $value : null;
    }
}
