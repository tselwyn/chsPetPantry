<?php
declare(strict_types=1);

namespace Pfpms\Account;

use Pfpms\Clock;
use Pfpms\Db;

/** SQL for user_account and user_site_access (UC-11). Never selects password or PIN hashes. */
final class AccountRepository
{
    public const COLUMNS = 'user_id, username, email, first_name, last_name, display_name, phone, role, status, must_change_password,
        password_changed_at, failed_login_count, locked_until, start_date, expiry_date, onboarding_completed_at, can_extract_identifiable,
        last_login_at, deactivated_reason, deactivation_effective_date, created_at, row_version';

    /**
     * Accounts for the list page, with the sites each can use through a standing or current grant.
     * @param array{status?: ?string, role?: ?string, q?: ?string} $filters
     * @return list<array>
     */
    public static function list(array $filters): array
    {
        $where = ["u.username <> 'system'"];
        $args = [Clock::db(), Clock::db()];
        // Locked covers the time-limited lock after failed sign-ins as well as the Locked status.
        if (($filters['status'] ?? null) === 'Locked') {
            $where[] = "(u.status = 'Locked' OR (u.status <> 'Inactive' AND u.locked_until > ?))";
            $args[] = Clock::db();
        } elseif (($filters['status'] ?? null) === 'Active') {
            $where[] = "u.status = 'Active' AND (u.locked_until IS NULL OR u.locked_until <= ?)";
            $args[] = Clock::db();
        } elseif (!empty($filters['status'])) {
            $where[] = 'u.status = ?';
            $args[] = $filters['status'];
        }
        if (!empty($filters['role'])) {
            $where[] = 'u.role = ?';
            $args[] = $filters['role'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $st = Db::pdo()->prepare(
            'SELECT u.user_id, u.username, u.email, u.first_name, u.last_name, u.display_name, u.role, u.status, u.locked_until,
                    u.start_date, u.expiry_date, u.deactivation_effective_date, u.last_login_at,
                    GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR \', \') AS site_names
               FROM user_account u
               LEFT JOIN user_site_access a ON a.user_id = u.user_id AND a.starts_at <= ? AND (a.ends_at IS NULL OR a.ends_at > ?)
               LEFT JOIN site s ON s.site_id = a.site_id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY u.user_id, u.username, u.email, u.first_name, u.last_name, u.display_name, u.role, u.status, u.locked_until,
                       u.start_date, u.expiry_date, u.deactivation_effective_date, u.last_login_at
              ORDER BY u.status = \'Inactive\', u.last_name, u.first_name'
        );
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function find(int $userId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . " FROM user_account WHERE user_id = ? AND username <> 'system'");
        $st->execute([$userId]);
        return $st->fetch() ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM user_account WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    public static function usernameTaken(string $username): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM user_account WHERE username = ?');
        $st->execute([$username]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function emailTaken(string $email, int $exceptUserId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM user_account WHERE email = ? AND user_id <> ?');
        $st->execute([$email, $exceptUserId]);
        return (int) $st->fetchColumn() > 0;
    }

    /**
     * Active Administrators with no end date and no deactivation scheduled, optionally not
     * counting one account. Only these guarantee someone can still sign in next month: an
     * Administrator who is leaving on a date would otherwise let the last lasting one go today.
     */
    public static function lastingAdministratorCount(?int $exceptUserId = null, bool $lock = false): int
    {
        // With $lock (inside a transaction) the rows are read as last committed and locked, so two
        // Administrators removing each other at the same moment cannot both see the other remain.
        $st = Db::pdo()->prepare(
            "SELECT user_id FROM user_account
              WHERE role = 'Administrator' AND status = 'Active' AND username <> 'system' AND user_id <> ?
                AND start_date <= ? AND expiry_date IS NULL AND deactivation_effective_date IS NULL" . ($lock ? ' FOR UPDATE' : '')
        );
        $st->execute([$exceptUserId ?? 0, Clock::orgToday()]);
        return count($st->fetchAll());
    }

    /** @param array<string, mixed> $v */
    public static function insert(array $v): int
    {
        Db::pdo()->prepare(
            "INSERT INTO user_account (username, email, first_name, last_name, phone, role, status, password_hash, must_change_password,
                                       start_date, expiry_date, onboarding_completed_at, can_extract_identifiable, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, 0, ?, ?, ?, ?, ?, ?)"
        )->execute([$v['username'], $v['email'], $v['first_name'], $v['last_name'], $v['phone'], $v['role'], $v['password_hash'],
            $v['start_date'], $v['expiry_date'], $v['onboarding_completed_at'], $v['can_extract_identifiable'], $v['created_by'], Clock::db()]);
        return (int) Db::pdo()->lastInsertId();
    }

    /**
     * Site ids the account can use now through a standing grant (no end) or a current time-limited one.
     * @return list<int>
     */
    public static function currentSiteIds(int $userId): array
    {
        $st = Db::pdo()->prepare('SELECT DISTINCT site_id FROM user_site_access WHERE user_id = ? AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?) ORDER BY site_id');
        $now = Clock::db();
        $st->execute([$userId, $now, $now]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> sites granted without an end date */
    public static function standingSiteIds(int $userId): array
    {
        $st = Db::pdo()->prepare('SELECT DISTINCT site_id FROM user_site_access WHERE user_id = ? AND ends_at IS NULL AND starts_at <= ? ORDER BY site_id');
        $st->execute([$userId, Clock::db()]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array> time-limited grants that have not ended yet (US-28), for display */
    public static function temporaryGrants(int $userId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT a.access_id, a.site_id, s.name AS site_name, a.starts_at, a.ends_at, a.grant_reason
               FROM user_site_access a JOIN site s ON s.site_id = a.site_id
              WHERE a.user_id = ? AND a.ends_at IS NOT NULL AND a.ends_at > ? ORDER BY a.starts_at'
        );
        $st->execute([$userId, Clock::db()]);
        return $st->fetchAll();
    }

    public static function grantSite(int $userId, int $siteId, int $grantedBy, ?string $reason): void
    {
        Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, starts_at, ends_at, grant_reason, granted_by) VALUES (?, ?, ?, NULL, ?, ?)')
            ->execute([$userId, $siteId, Clock::db(), $reason === null ? null : mb_substr($reason, 0, 255), $grantedBy]);
    }

    /** End standing grants for these sites now; the rows stay as history. */
    public static function endStandingGrants(int $userId, array $siteIds): void
    {
        if (!$siteIds) {
            return;
        }
        $marks = implode(',', array_fill(0, count($siteIds), '?'));
        Db::pdo()->prepare("UPDATE user_site_access SET ends_at = ? WHERE user_id = ? AND ends_at IS NULL AND site_id IN ($marks)")
            ->execute([Clock::db(), $userId, ...array_map('intval', $siteIds)]);
    }
}
