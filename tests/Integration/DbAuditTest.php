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

    public function testRateLimitWindow(): void
    {
        $bucket = 'test:' . bin2hex(random_bytes(4));
        $this->assertTrue(RateLimit::hit($bucket, 2, 60));
        $this->assertTrue(RateLimit::hit($bucket, 2, 60));
        $this->assertFalse(RateLimit::hit($bucket, 2, 60));
        \Pfpms\Clock::advance('+61 seconds');
        $this->assertTrue(RateLimit::hit($bucket, 2, 60), 'a new window starts');
    }
}
