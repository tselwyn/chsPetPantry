<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Pfpms\Device\DeviceStatus;

/** Tablet states, labels and warnings on admin_devices (plan P2A), worked out from the device row. */
final class DeviceStatusTest extends TestCase
{
    private const NOW = '2026-10-01 12:00:00';

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
    }

    /** An in-service tablet that has never reported, unless overridden. */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'device_id' => 7, 'site_id' => 1, 'label' => 'Front desk 1', 'is_site_registered' => 1, 'has_credential' => 1, 'offline_enabled' => 0,
            'storage_persisted' => 0, 'last_seen_at' => null, 'last_sync_at' => null, 'pending_count' => 0, 'reported_max_seq' => null, 'app_build' => null,
            'revoked_at' => null, 'revoked_by' => null, 'revoked_lost' => 0, 'revoked_max_seq' => null, 'wipe_mode' => 'None', 'erase_requested_at' => null, 'wiped_at' => null,
            'code_expires_at' => null, 'site_active' => 1, 'site_name' => 'Northside',
            'display_mode' => null, 'clock_skew_seconds' => null, 'locked_out_since' => null, 'attention_count' => null, 'received_count' => null,
        ];
    }

    /** An in-service tablet that reported five minutes ago with nothing to warn about, unless overridden. */
    private static function reporting(array $overrides = []): array
    {
        return self::row($overrides + ['last_seen_at' => '2026-10-01 11:55:00', 'storage_persisted' => 1, 'offline_enabled' => 1, 'app_build' => '1.0',
            'display_mode' => 'standalone', 'clock_skew_seconds' => 3, 'attention_count' => 0, 'reported_max_seq' => 10, 'received_count' => 10]);
    }

    /** Retired (Push Then Wipe) after its last report, so no "connected after" warning. */
    private const RETIRING = ['revoked_at' => '2026-10-01 11:00:00', 'wipe_mode' => 'Push Then Wipe', 'last_seen_at' => '2026-10-01 10:55:00'];

    private static function describe(array $row, bool $offline = true, string $build = '1.0'): array
    {
        return DeviceStatus::describe($row, self::now(), 'America/New_York', $offline, $build);
    }

    public function testEveryStateInItsOrder(): void
    {
        $revoked = ['revoked_at' => '2026-09-30 12:00:00', 'is_site_registered' => 0];
        $cases = [
            [DeviceStatus::IN_SERVICE, []],
            [DeviceStatus::NO_CODE, ['has_credential' => 0, 'is_site_registered' => 0]],
            [DeviceStatus::AWAITING, ['has_credential' => 0, 'is_site_registered' => 0, 'code_expires_at' => '2026-10-01 13:00:00']],
            [DeviceStatus::CANCELLED, ['has_credential' => 0] + $revoked],
            [DeviceStatus::RETIRING, ['wipe_mode' => 'Push Then Wipe'] + $revoked],
            [DeviceStatus::ERASING, ['wipe_mode' => 'Wipe Now'] + $revoked],
            [DeviceStatus::ERASED, ['wipe_mode' => 'Wipe Now', 'wiped_at' => '2026-10-01 09:00:00'] + $revoked],
        ];
        foreach ($cases as [$code, $overrides]) {
            $this->assertSame($code, DeviceStatus::code(self::row($overrides)), $code);
            $this->assertSame($code === DeviceStatus::IN_SERVICE, DeviceStatus::inService(self::row($overrides)), "inService for $code");
        }
        $this->assertFalse(DeviceStatus::inService(self::row(['is_site_registered' => 0])), 'a credential alone is not enough');
    }

    public function testLabelsAndBadges(): void
    {
        $retired = self::describe(self::row(['revoked_at' => '2026-09-30 16:00:00', 'wipe_mode' => 'Push Then Wipe', 'revoked_lost' => 1]));
        $this->assertSame(['Retired Sep 30, 12:00 PM', 'badge', 'Reported lost or stolen.'], [$retired['label'], $retired['badge'], $retired['detail']], 'in the site zone');
        $erasing = self::describe(self::row(['revoked_at' => '2026-09-30 16:00:00', 'wipe_mode' => 'Wipe Now', 'erase_requested_at' => '2026-10-01 14:00:00']));
        $this->assertSame(['Erase requested Oct 1, 10:00 AM', 'badge badge-problem'], [$erasing['label'], $erasing['badge']], 'when the erase was chosen, not the retirement');
        $waiting = self::describe(self::row(['has_credential' => 0, 'code_expires_at' => '2026-10-01 13:00:00']));
        $this->assertSame(['Waiting for the tablet', 'Its code works until Oct 1, 9:00 AM.'], [$waiting['label'], $waiting['detail']]);
        $this->assertSame('It has no working code.', self::describe(self::row(['has_credential' => 0]))['detail']);
        $this->assertSame(['Not heard from yet', null], [self::describe(self::row())['label'], self::describe(self::row())['detail']], 'a short badge');
        $online = self::describe(self::row(['last_seen_at' => '2026-10-01 11:55:00', 'storage_persisted' => 1]));
        $this->assertSame(['Works online only', 'Offline is not switched on yet.'], [$online['label'], $online['detail']], 'the reason apart');
        $this->assertNull(self::describe(self::row(['last_seen_at' => '2026-10-01 11:55:00', 'offline_enabled' => 1]))['detail'], 'not repeated when the storage warning says it');
    }

    public function testWhyATabletWorksOnlineOnly(): void
    {
        $heard = ['last_seen_at' => '2026-10-01 11:55:00'];
        $ready = self::row($heard + ['offline_enabled' => 1, 'storage_persisted' => 1]);
        $this->assertSame(['Ready to work offline', null], [DeviceStatus::serviceLabel($ready, true), DeviceStatus::onlineOnlyReason($ready, true)]);
        $this->assertSame(['Works online only', 'Offline working is switched off for all tablets in Settings.'],
            [DeviceStatus::serviceLabel($ready, false), DeviceStatus::onlineOnlyReason($ready, false)]);
        $this->assertSame('Its storage is not kept.', DeviceStatus::onlineOnlyReason(self::row($heard + ['offline_enabled' => 1]), true));
        $this->assertSame('Offline is not switched on yet.', DeviceStatus::onlineOnlyReason(self::row($heard + ['storage_persisted' => 1]), true));
        $this->assertNull(DeviceStatus::onlineOnlyReason(self::row(), false), 'not reported yet: no reason');
    }

    public function testWarnings(): void
    {
        $this->assertSame([], self::describe(self::row())['warnings'], 'an unreported tablet has no warnings (never a misleading 0 or No)');
        $at24 = self::describe(self::row(['last_seen_at' => '2026-09-30 12:00:00', 'pending_count' => 3, 'storage_persisted' => 1, 'app_build' => '1.0']));
        $this->assertSame(['Holds 3 unsynced records, reported 1 day ago. Connect it to the internet so they upload.'], $at24['warnings'], 'from exactly 24 hours');
        $this->assertSame([], self::describe(self::row(['last_seen_at' => '2026-09-30 12:00:01', 'pending_count' => 3, 'storage_persisted' => 1]))['warnings']);
        $all = self::describe(self::row(['last_seen_at' => '2026-10-01 11:00:00', 'app_build' => '0.9', 'site_active' => 0]))['warnings'];
        $this->assertCount(3, $all);
        $this->assertStringStartsWith('It is not keeping its data', $all[0]);
        $this->assertStringContainsString('older version of the Station (0.9)', $all[1]);
        $this->assertStringContainsString('Northside, is deactivated', $all[2]);
        $seen = self::describe(self::row(['revoked_at' => '2026-09-30 12:00:00', 'wipe_mode' => 'Push Then Wipe', 'last_seen_at' => '2026-10-01 10:00:00']))['warnings'];
        $this->assertSame(['Connected after it was taken out of service (Oct 1, 6:00 AM).'], $seen);
        $this->assertSame([], self::describe(self::row(['has_credential' => 0, 'revoked_at' => '2026-09-30 12:00:00', 'site_active' => 0]))['warnings'],
            'a cancelled tablet needs nothing doing');
        $this->assertSame(['Its site, Northside, is deactivated. Cancel this registration.'], self::describe(self::row(['has_credential' => 0, 'site_active' => 0]))['warnings'],
            'a waiting tablet is cancelled, not retired');
        $this->assertSame([], self::describe(self::row(['revoked_at' => '2026-09-30 12:00:00', 'wipe_mode' => 'Push Then Wipe', 'site_active' => 0]))['warnings'],
            'already out of service: nothing to do');
    }

    public function testAReportingTabletWithEveryNewFigureInRangeHasNoWarning(): void
    {
        $this->assertSame([], self::describe(self::reporting())['warnings']);
    }

    public function testSequenceGapWarning(): void
    {
        $this->assertSame(['8 records it numbered have neither reached the server nor been reported as waiting on it. They may be lost; ask who used it.'],
            self::describe(self::reporting(['reported_max_seq' => 41, 'received_count' => 30, 'pending_count' => 3]))['warnings'], '41 numbered - 30 received - 3 waiting');
        $this->assertSame(['1 record it numbered has neither reached the server nor been reported as waiting on it. They may be lost; ask who used it.'],
            self::describe(self::reporting(['reported_max_seq' => 11, 'received_count' => 10]))['warnings']);
        $this->assertSame([], self::describe(self::reporting(['reported_max_seq' => 13, 'received_count' => 10, 'pending_count' => 3]))['warnings'], 'all accounted for');
        $this->assertSame([], self::describe(self::reporting(['reported_max_seq' => 10, 'received_count' => 12]))['warnings'], 'more received than numbered is no gap');
    }

    public function testSequenceGapWarningForARetiringTablet(): void
    {
        $this->assertSame(['2 records it numbered have neither reached the server nor been reported as waiting on it. They may be lost; ask who used it.'],
            self::describe(self::reporting(self::RETIRING + ['reported_max_seq' => 12]))['warnings'], 'it may still upload them');
    }

    public function testNoGapWarningOnceTheTabletIsBeingErased(): void
    {
        $erasing = ['revoked_at' => '2026-10-01 11:00:00', 'wipe_mode' => 'Wipe Now', 'last_seen_at' => '2026-10-01 10:55:00', 'reported_max_seq' => 20];
        $this->assertSame([], self::describe(self::reporting($erasing))['warnings'], 'erasing: in service or retiring only');
        $this->assertSame([], self::describe(self::reporting($erasing + ['wiped_at' => '2026-10-01 11:30:00']))['warnings'], 'erased');
    }

    public function testNoGapWarningBeforeTheTabletReports(): void
    {
        $this->assertSame([], self::describe(self::reporting(['reported_max_seq' => null, 'received_count' => 0]))['warnings'], 'no max_seq reported yet');
        $this->assertSame([], self::describe(self::reporting(['reported_max_seq' => 41, 'received_count' => null]))['warnings'],
            'a row whose query did not count the received records');
    }

    public function testBrowserTabWarning(): void
    {
        $warning = "It is running in a browser tab, not the installed app, so it cannot work offline. Open the Station from the tablet's home screen.";
        foreach (['browser', 'minimal-ui', 'fullscreen', 'other'] as $mode) {
            $this->assertSame([$warning], self::describe(self::reporting(['display_mode' => $mode]))['warnings'], $mode);
        }
        $this->assertSame([], self::describe(self::reporting(['display_mode' => null]))['warnings'], 'not reported');
        $this->assertSame([], self::describe(self::reporting(self::RETIRING + ['display_mode' => 'browser']))['warnings'], 'in service only');
    }

    public function testClockSkewWarningBeyondTheTolerance(): void
    {
        $warning = fn(string $text) => ["Its clock is $text. Records are timed correctly, but set the tablet's clock to automatic."];
        $this->assertSame([], self::describe(self::reporting(['clock_skew_seconds' => 600]))['warnings'], 'exactly the default 10 minutes is tolerated');
        $this->assertSame($warning('10 minutes slow'), self::describe(self::reporting(['clock_skew_seconds' => 601]))['warnings'],
            'server minus tablet > 0: the tablet is behind');
        $this->assertSame($warning('20 minutes fast'), self::describe(self::reporting(['clock_skew_seconds' => -1200]))['warnings']);
        $this->assertSame($warning('1 minute slow'),
            DeviceStatus::describe(self::reporting(['clock_skew_seconds' => 61]), self::now(), 'UTC', true, '1.0', 60)['warnings'],
            'the tolerance comes from the page (sync_clock_skew_minutes)');
        $this->assertSame([], DeviceStatus::describe(self::reporting(['clock_skew_seconds' => -1200]), self::now(), 'UTC', true, '1.0', 1800)['warnings'],
            'within a wider tolerance');
        $this->assertSame([], self::describe(self::reporting(self::RETIRING + ['clock_skew_seconds' => 5000]))['warnings'], 'in service only');
    }

    public function testLockedOutWarning(): void
    {
        $warning = ['It locked itself after too many wrong passwords (Oct 1, 6:30 AM). Its unsynced records are kept; someone must sign in on it with a connection.'];
        $this->assertSame($warning, self::describe(self::reporting(['locked_out_since' => '2026-10-01 10:30:00']))['warnings'], 'in the site zone');
        $this->assertSame($warning, self::describe(self::reporting(self::RETIRING + ['locked_out_since' => '2026-10-01 10:30:00']))['warnings'], 'retiring too');
        $this->assertSame([], self::describe(self::reporting(['locked_out_since' => '2026-10-01 10:30:00', 'revoked_at' => '2026-10-01 11:00:00',
            'wipe_mode' => 'Wipe Now', 'last_seen_at' => '2026-10-01 10:55:00']))['warnings'], 'not once it is being erased');
    }

    public function testAttentionWarning(): void
    {
        $this->assertSame(['2 records on it could not be uploaded. They are kept on the tablet; tell the Administrator.'],
            self::describe(self::reporting(['attention_count' => 2]))['warnings']);
        $this->assertSame(['1 record on it could not be uploaded. They are kept on the tablet; tell the Administrator.'],
            self::describe(self::reporting(['attention_count' => 1]))['warnings']);
        $this->assertSame([], self::describe(self::reporting(['attention_count' => 0]))['warnings']);
        $this->assertSame([], self::describe(self::reporting(['attention_count' => null]))['warnings'], 'not reported');
    }

    public function testRowsWithoutTheNewKeysGiveNoWarning(): void
    {
        $row = self::row(['last_seen_at' => '2026-10-01 11:55:00', 'storage_persisted' => 1, 'offline_enabled' => 1, 'reported_max_seq' => 41]);
        foreach (['display_mode', 'clock_skew_seconds', 'locked_out_since', 'attention_count', 'received_count'] as $key) {
            unset($row[$key]); // shaped as today's list queries and the P2A row() were
        }
        $described = self::describe($row);
        $this->assertSame([], $described['warnings'], 'and no PHP warning, which failOnWarning would turn into a failure');
        $this->assertSame('Ready to work offline', $described['label']);
    }

    public function testAgo(): void
    {
        $cases = [[null, 'never'], ['2026-10-01 11:59:01', 'just now'], ['2026-10-01 11:59:00', '1 minute ago'], ['2026-10-01 11:00:01', '59 minutes ago'],
            ['2026-10-01 11:00:00', '1 hour ago'], ['2026-09-30 12:00:01', '23 hours ago'], ['2026-09-30 12:00:00', '1 day ago'], ['2026-09-28 12:00:00', '3 days ago']];
        foreach ($cases as [$at, $words]) {
            $this->assertSame($words, DeviceStatus::ago($at, self::now()), (string) $at);
        }
    }

    public function testLockedRowsNeedTheirOwnTest(): void
    {
        $locked = self::row(['has_credential' => 0]);
        unset($locked['code_expires_at']); // DeviceRepository::lock() has no live-code columns
        $this->assertTrue(DeviceStatus::waitingForRegistration($locked));
        $this->assertFalse(DeviceStatus::waitingForRegistration(self::row()), 'registered');
        $this->assertFalse(DeviceStatus::waitingForRegistration(self::row(['has_credential' => 0, 'revoked_at' => '2026-09-30 12:00:00'])), 'cancelled');
        $this->expectException(\LogicException::class);
        DeviceStatus::code($locked);
    }
}
