<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Pfpms\Clock;
use Pfpms\Tests\TestCase;

/**
 * The datetime text "YYYY-MM-DD HH:MM:SS.mmm" (UTC) against tests/fixtures/datetime.json, the cases the Station's
 * js/canonical.js (formatDb, parseDb, dayStartUtc) also passes. An integration test because org_day_start runs
 * through Clock::orgDayStartUtc() with the organisation_time_zone setting changed.
 */
final class DatetimeFixtureTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/datetime.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The instant $ms milliseconds after the epoch (negative ones too). */
    private static function instant(int $ms): DateTimeImmutable
    {
        $milli = (($ms % 1000) + 1000) % 1000;
        $seconds = intdiv($ms - $milli, 1000);
        return (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'))->modify("+$milli milliseconds");
    }

    /** ms since the epoch; never format('Uv'), which concatenates for negative times (-1 and 999 → -1999). */
    private static function millis(DateTimeImmutable $at): int
    {
        return (int) $at->format('U') * 1000 + (int) $at->format('v');
    }

    public function testFormat(): void
    {
        $cases = self::fixture()['format'];
        $this->assertCount(9, $cases);
        foreach ($cases as $case) {
            $at = self::instant($case['ms']);
            $this->assertSame($case['ms'], self::millis($at), 'the test helper itself');
            $this->assertSame($case['text'], Clock::dbMillis($at), (string) $case['ms']);
        }
    }

    public function testParseValid(): void
    {
        $cases = self::fixture()['parse_valid'];
        $this->assertCount(7, $cases);
        foreach ($cases as $case) {
            $at = Clock::fromClient($case['text']);
            $this->assertNotNull($at, $case['text']);
            $this->assertSame($case['ms'], self::millis($at), $case['text']);
            $this->assertSame($case['text'], Clock::dbMillis($at), $case['text'] . ' round-trips');
        }
    }

    public function testParseInvalid(): void
    {
        $cases = self::fixture()['parse_invalid'];
        $this->assertCount(27, $cases, 'the last seven roll out of years 0000-9999 (month or day 00, hour 24, day 32, month 13, minute 60)');
        foreach ($cases as $text) {
            $this->assertNull(Clock::fromClient($text), json_encode($text, JSON_THROW_ON_ERROR));
        }
        $this->assertNull(Clock::fromClient(null));
    }

    public function testOrgDayStart(): void
    {
        $cases = self::fixture()['org_day_start'];
        $this->assertCount(12, $cases);
        foreach ($cases as $case) {
            $this->setSetting('organisation_time_zone', $case['zone']);
            $this->assertSame($case['utc'], Clock::dbMillis(Clock::orgDayStartUtc($case['date'])), "{$case['zone']} {$case['date']}");
        }
    }
}
