<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use Pfpms\Clock;
use Pfpms\Tests\TestCase;

/** 00:00 of an organisation date as a UTC instant, in organisation_time_zone (50-design §12.1 Clock; the ClockTest case that needs Settings). */
final class ClockZoneTest extends TestCase
{
    private static function startOf(string $date): string
    {
        $at = Clock::orgDayStartUtc($date);
        return $at->format('Y-m-d H:i:s e');
    }

    public function testOrgDayStartUtcFollowsTheOrganisationZone(): void
    {
        $this->setSetting('organisation_time_zone', 'America/New_York');
        $this->assertSame('2026-10-01 04:00:00 UTC', self::startOf('2026-10-01'), 'EDT is UTC-4');
        $this->assertSame('2026-11-01 04:00:00 UTC', self::startOf('2026-11-01'), 'DST ends at 02:00 on Nov 1, so its midnight is still EDT');
        $this->assertSame('2026-11-02 05:00:00 UTC', self::startOf('2026-11-02'), 'EST is UTC-5');
        $this->assertSame('2026-03-08 05:00:00 UTC', self::startOf('2026-03-08'), 'DST starts at 02:00 on Mar 8, so its midnight is still EST');
        $this->assertSame('2026-03-09 04:00:00 UTC', self::startOf('2026-03-09'));
    }

    public function testOrgDayStartUtcFollowsAChangedZone(): void
    {
        $this->setSetting('organisation_time_zone', 'Europe/London');
        $this->assertSame('2026-03-29 00:00:00 UTC', self::startOf('2026-03-29'), 'BST starts at 01:00 UTC that day');
        $this->assertSame('2026-03-29 23:00:00 UTC', self::startOf('2026-03-30'), 'BST is UTC+1: the day starts the evening before in UTC');

        $this->setSetting('organisation_time_zone', 'Pacific/Auckland');
        $this->assertSame('2026-09-26 12:00:00 UTC', self::startOf('2026-09-27'), 'NZST (+12) until 02:00 that day');
        $this->assertSame('2026-09-27 11:00:00 UTC', self::startOf('2026-09-28'), 'NZDT (+13)');
    }

    public function testAnUnknownZoneFallsBackToNewYork(): void
    {
        $this->setSetting('organisation_time_zone', 'Mars/Olympus_Mons');
        $this->assertSame('2026-10-01 04:00:00 UTC', self::startOf('2026-10-01'));
    }

    public function testOrgDayStartUtcIsTheUtcInstantOfOrgToday(): void
    {
        $this->setSetting('organisation_time_zone', 'America/New_York');
        Clock::freeze('2026-10-02 03:59:59'); // 23:59:59 on Oct 1 in New York
        $today = Clock::orgToday();
        $this->assertSame('2026-10-01', $today);
        $this->assertSame('2026-10-01 04:00:00', Clock::db(Clock::orgDayStartUtc($today)), 'the organisation\'s day, not the UTC date (already Oct 2)');
    }
}
