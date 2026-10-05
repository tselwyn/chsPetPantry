<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Clock;

/**
 * Times a tablet sends (50-design §6.4): only "YYYY-MM-DD HH:MM:SS.mmm" UTC, and never a date PHP would roll over.
 * Clock::orgDayStartUtc() needs the organisation_time_zone setting, so it is in tests/Integration/ClockZoneTest.php.
 */
final class ClockTest extends TestCase
{
    public function testFromClientAcceptsOnlyColumnPrecisionUtc(): void
    {
        $at = Clock::fromClient('2026-10-01 12:00:00.123');
        $this->assertNotNull($at);
        $this->assertSame(['2026-10-01 12:00:00.123', 'UTC', '1790856000123'], [$at->format('Y-m-d H:i:s.v'), $at->getTimezone()->getName(), $at->format('Uv')]);
        $this->assertSame('2026-10-01 12:00:00.123', Clock::dbMillis($at), 'it round-trips through the server\'s own format');

        $refused = [
            '2026-10-01 12:00:00',          // seconds only
            '2026-10-01 12:00:00.12',       // too few digits
            '2026-10-01 12:00:00.1234',     // too many
            '2026-10-01T12:00:00.123Z',     // ISO 8601, as Date.toISOString() sends it
            '2026-10-01T12:00:00.123',
            '2026-10-01 12:00:00.123Z',
            '2026-10-01 12:00:00.123+02:00',
            ' 2026-10-01 12:00:00.123',
            '2026-10-01 12:00:00.123 ',
            "2026-10-01 12:00:00.123\n",    // PCRE's $ would allow a final newline
            '2026-10-01  12:00:00.123',
            '2026-1-01 12:00:00.123',
            '+2026-10-01 12:00:00.123',
            '1790856000123',                // epoch milliseconds
            'now',
            '',
        ];
        foreach ($refused as $value) {
            $this->assertNull(Clock::fromClient($value), json_encode($value));
        }
        $this->assertNull(Clock::fromClient(null));
    }

    public function testFromClientRefusesImpossibleDates(): void
    {
        foreach (['2026-02-30 10:00:00.000', '2025-02-29 00:00:00.000', '2026-13-01 00:00:00.000', '2026-00-10 00:00:00.000', '2026-10-00 00:00:00.000',
            '2026-10-01 24:00:00.000', '2026-10-01 12:60:00.000', '2026-10-01 12:00:60.000', '0000-00-00 00:00:00.000'] as $value) {
            $this->assertNull(Clock::fromClient($value), "$value would roll over");
        }
        $this->assertSame('2028-02-29 23:59:59.999', Clock::fromClient('2028-02-29 23:59:59.999')?->format('Y-m-d H:i:s.v'), 'a leap day is a real date');
    }

    public function testFromClientIgnoresTheFrozenClockAndTheDefaultZone(): void
    {
        $zone = date_default_timezone_get();
        Clock::freeze('2030-01-01 00:00:00');
        date_default_timezone_set('America/New_York');
        try {
            $this->assertSame('2026-10-01 00:00:00.000 UTC', Clock::fromClient('2026-10-01 00:00:00.000')?->format('Y-m-d H:i:s.v e'),
                'every field comes from the value (the ! format), and it is read as UTC');
        } finally {
            date_default_timezone_set($zone);
            Clock::freeze(null);
        }
    }
}
