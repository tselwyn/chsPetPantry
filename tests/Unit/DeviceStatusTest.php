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
        ];
    }

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
