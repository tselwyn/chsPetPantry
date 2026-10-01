<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use PDO;
use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Security\RateLimit;
use Pfpms\Tests\TestCase;
use RuntimeException;

/** Transactions, optimistic locking, audit trail, rate limiting. */
final class DbAuditTest extends TestCase
{
    public function testNestedFailureRollsBackOnlyTheInnerWork(): void
    {
        $user = $this->makeUser();
        Db::transaction(function () use ($user): void {
            Audit::record('outer', 'user_account', $user['user_id']);
            try {
                Db::transaction(function () use ($user): void {
                    Audit::record('inner', 'user_account', $user['user_id']);
                    throw new RuntimeException('business rule');
                });
            } catch (RuntimeException) {
            }
        });
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'outer' AND entity_id = ?", [$user['user_id']]));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'inner' AND entity_id = ?", [$user['user_id']]));
    }

    public function testOptimisticLocking(): void
    {
        $user = $this->makeUser();
        $version = (int) $this->scalar('SELECT row_version FROM user_account WHERE user_id = ?', [$user['user_id']]);
        $this->assertTrue(Db::updateVersioned('user_account', 'user_id', $user['user_id'], $version, ['phone' => '8435550100']));
        $this->assertFalse(Db::updateVersioned('user_account', 'user_id', $user['user_id'], $version, ['phone' => '8435550199']),
            'a save based on the old version is refused (UC-04 §3.3.2)');
        $this->assertSame('8435550100', $this->scalar('SELECT phone FROM user_account WHERE user_id = ?', [$user['user_id']]));
    }

    public function testUpdateVersionedRejectsBadIdentifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Db::updateVersioned('user_account; DROP TABLE x', 'user_id', 1, 1, ['phone' => '1']);
    }

    public function testFieldLevelAuditAndRedaction(): void
    {
        $user = $this->makeUser();
        Audit::setActor($user['user_id']);
        $changes = Audit::diff(['phone' => null, 'display_name' => 'A', 'pin_hash' => 'old'], ['phone' => '8435550100', 'display_name' => 'A', 'pin_hash' => 'new'], ['phone', 'display_name', 'pin_hash']);
        $this->assertSame(['phone', 'pin_hash'], array_keys($changes));
        $id = Audit::record('profile_update', 'user_account', $user['user_id'], details: ['password' => 'secret', 'note' => 'ok'], changes: $changes);
        $details = json_decode((string) $this->scalar('SELECT details FROM audit_log WHERE audit_id = ?', [$id]), true);
        $this->assertSame('[redacted]', $details['password']);
        $this->assertSame('ok', $details['note']);
        $rows = Db::pdo()->prepare('SELECT field_name, old_value, new_value FROM audit_field_change WHERE audit_id = ? ORDER BY field_name');
        $rows->execute([$id]);
        $this->assertSame([
            ['field_name' => 'phone', 'old_value' => null, 'new_value' => '8435550100'],
            ['field_name' => 'pin_hash', 'old_value' => '[redacted]', 'new_value' => '[redacted]'],
        ], $rows->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame($user['user_id'], (int) $this->scalar('SELECT user_id FROM audit_log WHERE audit_id = ?', [$id]));
    }

    public function testActorOverrideSetsDeviceAndOccurredAt(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $requestTablet = $this->makeDevice($site);
        $reportingTablet = $this->makeDevice($site);
        Audit::setActor($user['user_id'], null, $site, $requestTablet);

        $id = Audit::record('offline_auth_failures', 'device', $reportingTablet, 'Failed',
            actor: ['user_id' => null, 'device_id' => $reportingTablet, 'occurred_at' => '2026-09-30 08:15:30.250']);
        $this->assertSame(['occurred_at' => '2026-09-30 08:15:30.250', 'user_id' => null, 'device_id' => $reportingTablet, 'site_id' => $site],
            $this->auditRow(Db::pdo(), $id), 'the tablet and the time it reported, the rest from the actor');

        $plain = Audit::record('device_state', 'device', $requestTablet);
        $this->assertSame(['occurred_at' => '2026-10-01 12:00:00.000', 'user_id' => $user['user_id'], 'device_id' => $requestTablet, 'site_id' => $site],
            $this->auditRow(Db::pdo(), $plain), 'without an override: the request tablet and the frozen clock, in milliseconds');
    }

    public function testADeviceOverrideOfNullClearsTheRequestsTablet(): void
    {
        $site = $this->makeSite('Test North');
        $tablet = $this->makeDevice($site);
        Audit::setActor(null, null, $site, $tablet);
        $id = Audit::record('device_vault_key_cleared', 'device', $tablet, actor: ['device_id' => null]);
        $this->assertNull($this->auditRow(Db::pdo(), $id)['device_id'], 'array_key_exists, not ??: an explicit null wins');
    }

    public function testDurableTakesTheSameOverrides(): void
    {
        Audit::setActor(null);
        $id = Audit::durable('test_durable_override', 'device', null, 'Denied', 'probe', ['k' => 1],
            actor: ['device_id' => null, 'occurred_at' => '2026-09-29 23:59:59.999']);
        $this->assertSame(['occurred_at' => '2026-09-29 23:59:59.999', 'user_id' => null, 'device_id' => null, 'site_id' => null],
            $this->auditRow(Db::durable(), $id), 'on the autocommit connection');
    }

    public function testRateLimitWindow(): void
    {
        $bucket = 'test:' . bin2hex(random_bytes(4));
        $this->assertTrue(RateLimit::hit($bucket, 2, 60));
        $this->assertTrue(RateLimit::hit($bucket, 2, 60));
        $this->assertFalse(RateLimit::hit($bucket, 2, 60));
        \Pfpms\Clock::advance('+61 seconds');
        $this->assertTrue(RateLimit::hit($bucket, 2, 60), 'a new window starts');
    }

    /** @return array{occurred_at: string, user_id: ?int, device_id: ?int, site_id: ?int} */
    private function auditRow(PDO $pdo, int $auditId): array
    {
        $st = $pdo->prepare('SELECT occurred_at, user_id, device_id, site_id FROM audit_log WHERE audit_id = ?');
        $st->execute([$auditId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "audit row $auditId");
        foreach (['user_id', 'device_id', 'site_id'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        return $row;
    }
}
