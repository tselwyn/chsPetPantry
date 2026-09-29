<?php
declare(strict_types=1);

namespace Pfpms\Notify;

use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\Context;

/**
 * The in-app work queue (notification table): lockout alerts, failed mail, sync failures,
 * requests referred to an Administrator. A notification goes to one user, or to everyone
 * with a role (optionally only at one site). It stays open until someone resolves it; for
 * a role, one person resolving it resolves it for everyone.
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

    public static function openCountFor(Context $ctx): int
    {
        [$where, $args] = self::visibleTo($ctx);
        $st = Db::pdo()->prepare("SELECT COUNT(*) FROM notification n WHERE n.resolved_at IS NULL AND $where");
        $st->execute($args);
        return (int) $st->fetchColumn();
    }

    /** @return list<array> newest first; open ones, or recently resolved ones when $resolved */
    public static function listFor(Context $ctx, bool $resolved = false, int $limit = 100): array
    {
        [$where, $args] = self::visibleTo($ctx);
        $state = $resolved ? 'n.resolved_at IS NOT NULL AND n.resolved_at >= ?' : 'n.resolved_at IS NULL';
        if ($resolved) {
            array_unshift($args, Clock::db(Clock::now()->modify('-30 days')));
        }
        $st = Db::pdo()->prepare(
            "SELECT n.notification_id, n.kind, n.message, n.entity_type, n.entity_id, n.site_id, n.created_at, n.resolved_at,
                    s.name AS site_name, COALESCE(NULLIF(r.display_name, ''), CONCAT(r.first_name, ' ', r.last_name)) AS resolved_by_name
               FROM notification n
               LEFT JOIN site s ON s.site_id = n.site_id
               LEFT JOIN user_account r ON r.user_id = n.resolved_by
              WHERE $state AND $where
              ORDER BY n.created_at DESC, n.notification_id DESC LIMIT " . max(1, min(500, $limit))
        );
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Resolve a notification the user can see. Returns false if it is not theirs or already resolved. */
    public static function resolve(Context $ctx, int $notificationId): bool
    {
        [$where, $args] = self::visibleTo($ctx);
        $st = Db::pdo()->prepare("UPDATE notification n SET n.resolved_at = ?, n.resolved_by = ?, n.read_at = COALESCE(n.read_at, ?)
                                    WHERE n.notification_id = ? AND n.resolved_at IS NULL AND $where");
        $now = Clock::db();
        $st->execute([$now, $ctx->userId(), $now, $notificationId, ...$args]);
        return $st->rowCount() === 1;
    }

    /**
     * Addressed to this user, or to their role at a site they can use now (or at no particular site).
     * @return array{0: string, 1: list<mixed>}
     */
    private static function visibleTo(Context $ctx): array
    {
        $siteIds = $ctx->siteIds();
        $sites = $siteIds ? ' OR n.site_id IN (' . implode(',', array_map('intval', $siteIds)) . ')' : '';
        return ["(n.recipient_user_id = ? OR (n.recipient_user_id IS NULL AND n.recipient_role = ? AND (n.site_id IS NULL$sites)))",
            [$ctx->userId(), $ctx->role()]];
    }

    private static function insert(?int $userId, ?string $role, ?int $siteId, string $kind, string $message, ?string $entityType, ?int $entityId, ?int $participantId): void
    {
        Db::pdo()->prepare(
            'INSERT INTO notification (recipient_user_id, recipient_role, site_id, kind, entity_type, entity_id, participant_id, message, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $role, $siteId, mb_substr($kind, 0, 40), $entityType, $entityId, $participantId, mb_substr($message, 0, 500), Clock::db()]);
    }
}
