<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Settings;

/**
 * Server-side sessions in user_session (UC-01 §4.2, US-02, UC-11 §4.4).
 *
 * user_session.session_id is a random id generated here and kept in $_SESSION. It is not
 * PHP's session id: audit_log rows reference it, so it never changes, while PHP's session
 * id is regenerated freely underneath it (login, PIN switch, site switch, role change).
 *
 * Every request validates the row: 30 minutes idle and 12 hours absolute (both settings),
 * the account still usable, and the session not ended remotely or by a permission change.
 */
final class SessionStore
{
    public const COLUMNS = 'u.user_id, u.username, u.email, u.first_name, u.last_name, u.display_name, u.phone, u.role,
        u.status, u.must_change_password, u.password_changed_at, u.locked_until, u.start_date, u.expiry_date, u.deactivation_effective_date,
        u.onboarding_completed_at, u.can_extract_identifiable, u.notification_prefs, u.row_version';

    /** Don't rewrite last_activity_at more often than this (seconds). */
    private const TOUCH_INTERVAL = 60;

    public static function create(int $userId, ?int $siteId, string $authMethod = 'Password', ?int $deviceId = null): string
    {
        $sessionId = bin2hex(random_bytes(32));
        $now = Clock::db();
        Db::pdo()->prepare(
            'INSERT INTO user_session (session_id, user_id, device_id, site_id, auth_method, started_at, last_activity_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$sessionId, $userId, $deviceId, $siteId, $authMethod, $now, $now]);
        return $sessionId;
    }

    /**
     * Check a session and record activity.
     * @return array{user: array, session: array}|array{ended: string} ended is one of
     *         'missing', 'ended', 'timeout', 'account'
     */
    public static function validate(string $sessionId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT s.session_id, s.site_id, s.device_id, s.auth_method, s.started_at, s.last_activity_at, s.ended_at, ' . self::COLUMNS . '
               FROM user_session s JOIN user_account u ON u.user_id = s.user_id
              WHERE s.session_id = ?'
        );
        $st->execute([$sessionId]);
        $row = $st->fetch();
        if (!$row) {
            return ['ended' => 'missing'];
        }
        if ($row['ended_at'] !== null) {
            return ['ended' => 'ended'];
        }
        $now = Clock::now();
        $idle = max(1, Settings::int('session_idle_minutes', 30));
        $absolute = max(1, Settings::int('session_absolute_hours', 12));
        $last = Clock::fromDb($row['last_activity_at']);
        if ($last->modify("+$idle minutes") <= $now || Clock::fromDb($row['started_at'])->modify("+$absolute hours") <= $now) {
            self::end($sessionId, 'Timeout');
            return ['ended' => 'timeout'];
        }
        if (!AccountRules::canHoldSession($row)) {
            self::end($sessionId, 'Deactivated');
            return ['ended' => 'account'];
        }
        if ($now->getTimestamp() - $last->getTimestamp() >= self::TOUCH_INTERVAL) {
            Db::pdo()->prepare('UPDATE user_session SET last_activity_at = ? WHERE session_id = ? AND ended_at IS NULL')
                ->execute([Clock::db($now), $sessionId]);
        }
        $session = array_intersect_key($row, array_flip(['session_id', 'site_id', 'device_id', 'auth_method', 'started_at', 'last_activity_at']));
        $user = array_diff_key($row, $session + ['ended_at' => null]);
        return ['user' => $user, 'session' => $session];
    }

    public static function end(string $sessionId, string $reason, ?int $endedBy = null): void
    {
        Db::pdo()->prepare('UPDATE user_session SET ended_at = ?, end_reason = ?, ended_by = ? WHERE session_id = ? AND ended_at IS NULL')
            ->execute([Clock::db(), $reason, $endedBy, $sessionId]);
    }

    /** End every open session of a user (password reset, permission change, deactivation). Returns how many. */
    public static function endAllForUser(int $userId, string $reason, ?int $endedBy = null, ?string $exceptSessionId = null): int
    {
        $st = Db::pdo()->prepare(
            'UPDATE user_session SET ended_at = ?, end_reason = ?, ended_by = ?
              WHERE user_id = ? AND ended_at IS NULL AND session_id <> ?'
        );
        $st->execute([Clock::db(), $reason, $endedBy, $userId, $exceptSessionId ?? '']);
        return $st->rowCount();
    }

    public static function setSite(string $sessionId, ?int $siteId): void
    {
        Db::pdo()->prepare('UPDATE user_session SET site_id = ? WHERE session_id = ?')->execute([$siteId, $sessionId]);
    }
}
