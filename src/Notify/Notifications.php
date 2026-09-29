<?php
declare(strict_types=1);

namespace Pfpms\Notify;

use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\Context;

/**
 * The in-app work queue (notification table): lockout alerts, sync failures, requests
 * referred to an Administrator. A notification goes to one user, or to everyone with a
 * role (optionally only at one site).
 */
final class Notifications
{
    public static function toUser(int $userId, string $kind, string $message, ?string $entityType = null, ?int $entityId = null, ?int $participantId = null): void
    {
        self::insert($userId, null, null, $kind, $message, $entityType, $entityId, $participantId);
    }

    public static function toRole(string $role, ?int $siteId, string $kind, string $message, ?string $entityType = null, ?int $entityId = null, ?int $participantId = null): void
    {
        self::insert(null, $role, $siteId, $kind, $message, $entityType, $entityId, $participantId);
    }

    /**
     * Like toRole(), but only if no open notification of this kind exists for the same record,
     * so a repeated event (an attacker re-locking an account every 15 minutes) cannot flood the queue.
     */
    public static function toRoleOnce(string $role, ?int $siteId, string $kind, string $message, string $entityType, int $entityId): void
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM notification WHERE recipient_role = ? AND kind = ? AND entity_type = ? AND entity_id = ? AND resolved_at IS NULL');
        $st->execute([$role, $kind, $entityType, $entityId]);
        if ((int) $st->fetchColumn() === 0) {
            self::insert(null, $role, $siteId, $kind, $message, $entityType, $entityId, null);
        }
    }

    /** Unresolved notifications this user should see: addressed to them, or to their role (at their current site or everywhere). */
    public static function openCountFor(Context $ctx): int
    {
        $st = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM notification
              WHERE resolved_at IS NULL AND read_at IS NULL
                AND (recipient_user_id = ? OR (recipient_user_id IS NULL AND recipient_role = ? AND (site_id IS NULL OR site_id = ?)))'
        );
        $st->execute([$ctx->userId(), $ctx->role(), $ctx->siteId ?? 0]);
        return (int) $st->fetchColumn();
    }

    private static function insert(?int $userId, ?string $role, ?int $siteId, string $kind, string $message, ?string $entityType, ?int $entityId, ?int $participantId): void
    {
        Db::pdo()->prepare(
            'INSERT INTO notification (recipient_user_id, recipient_role, site_id, kind, entity_type, entity_id, participant_id, message, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $role, $siteId, mb_substr($kind, 0, 40), $entityType, $entityId, $participantId, mb_substr($message, 0, 500), Clock::db()]);
    }
}
