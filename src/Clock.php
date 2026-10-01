<?php
declare(strict_types=1);

namespace Pfpms;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The application's notion of "now". Always UTC; the database stores UTC too.
 * Tests freeze and advance it instead of sleeping.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function freeze(DateTimeImmutable|string|null $at = 'now'): void
    {
        self::$frozen = $at === null ? null
            : ($at instanceof DateTimeImmutable ? $at->setTimezone(new DateTimeZone('UTC'))
                : new DateTimeImmutable($at, new DateTimeZone('UTC')));
    }

    public static function advance(string $modifier): void
    {
        self::$frozen = self::now()->modify($modifier);
    }

    /** UTC timestamp in the format DATETIME columns take (no offset, whole seconds). */
    public static function db(?DateTimeImmutable $at = null): string
    {
        return ($at ?? self::now())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC timestamp for DATETIME(3) columns. */
    public static function dbMillis(?DateTimeImmutable $at = null): string
    {
        return ($at ?? self::now())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    public static function fromDb(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /**
     * A time sent by a tablet: exactly "YYYY-MM-DD HH:MM:SS.mmm" in UTC (the column-precision format both PHP and the
     * Station write), else null. Impossible dates (Feb 30) are refused by the round trip.
     */
    public static function fromClient(?string $value): ?DateTimeImmutable
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/D', $value)) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
        return $at !== false && $at->format('Y-m-d H:i:s.v') === $value ? $at : null;
    }

    /** 00:00 of an organisation date (Y-m-d), as a UTC instant: when an account's date-based rule starts to apply. */
    public static function orgDayStartUtc(string $date): DateTimeImmutable
    {
        return (new DateTimeImmutable("$date 00:00:00", new DateTimeZone(self::orgTimeZone())))->setTimezone(new DateTimeZone('UTC'));
    }

    /** Today's date (Y-m-d) in a site's local time zone, e.g. for distribution.local_date. */
    public static function localDate(string $timeZone, ?DateTimeImmutable $at = null): string
    {
        return ($at ?? self::now())->setTimezone(new DateTimeZone($timeZone))->format('Y-m-d');
    }

    /**
     * Today's date where the organisation is (organisation_time_zone), for dates that belong to
     * no one site: account start, end and deactivation dates, policy start dates and re-acceptance
     * due dates. In the evening in the Americas the UTC date is already tomorrow.
     */
    public static function orgToday(?DateTimeImmutable $at = null): string
    {
        return self::localDate(self::orgTimeZone(), $at);
    }

    /**
     * An organisation date relative to today, e.g. orgDate('+365 days') for a yearly due date.
     * The arithmetic is on the calendar date, so it never drifts across a daylight-saving change.
     */
    public static function orgDate(string $modifier): string
    {
        return (new DateTimeImmutable(self::orgToday(), new DateTimeZone('UTC')))->modify($modifier)->format('Y-m-d');
    }

    /** The organisation_time_zone setting, or America/New_York if it is not a known zone. */
    private static function orgTimeZone(): string
    {
        static $known = [];
        $zone = Settings::string('organisation_time_zone', 'America/New_York');
        return ($known[$zone] ??= in_array($zone, DateTimeZone::listIdentifiers(), true)) ? $zone : 'America/New_York';
    }
}
