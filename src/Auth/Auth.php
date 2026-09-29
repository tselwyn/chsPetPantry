<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Notify\Notifications;
use Pfpms\Security\RateLimit;
use Pfpms\Settings;

/**
 * Sign-in and password changes (UC-01).
 *
 * The failure message never reveals whether an account exists. Only after the correct
 * password has been given does the user learn that the account itself is locked,
 * inactive or expired (UC-01 §3.3.2).
 */
final class Auth
{
    /**
     * When no account matches, a password is still verified against a fixed hash of a random
     * string, made with the same algorithm and cost as real ones (PHP's defaults, see
     * PasswordPolicy::hash). Both paths then cost exactly one verify, so response time does not
     * reveal whether an account exists. Computing a fresh dummy per request would cost a hash
     * plus a verify and make unknown accounts measurably slower.
     */
    private static function dummyHash(): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? '$argon2id$v=19$m=65536,t=4,p=1$cjdHUElGTklKdjJqdHplag$X9if5Xk/37vgCsZD4byGl2c1Eb8EAC9519TzVkSv6bg'
            : '$2y$12$A9ThSsW3oPT9lTxLwVOi..ixRzpBwMUcJFoZ9M1aIsGAzIkjpgqTS';
    }

    /**
     * @return array{ok: true, user: array, sessionId: string}|array{ok: false, code: string}
     *         code: 'invalid' | 'locked' | 'inactive' | 'expired' | 'not_started' | 'rate_limited'
     */
    public static function attempt(string $identifier, string $password, string $ip, ?int $siteId = null): array
    {
        $identifier = trim($identifier);
        $details = ['ip' => $ip];
        if (!RateLimit::hit("login:ip:$ip", 30, 900) || !RateLimit::hit('login:id:' . mb_strtolower($identifier), 10, 900)) {
            Audit::record('login', 'user_account', null, 'Denied', 'Rate limited', $details + ['identifier' => mb_substr($identifier, 0, 100)], actor: ['user_id' => null, 'session_id' => null]);
            return ['ok' => false, 'code' => 'rate_limited'];
        }

        $st = Db::pdo()->prepare(
            'SELECT user_id, username, email, first_name, last_name, role, status, password_hash, failed_login_count, locked_until,
                    must_change_password, password_changed_at, start_date, expiry_date
               FROM user_account WHERE (username = ? OR email = ?) AND username <> \'system\' LIMIT 2'
        );
        $st->execute([$identifier, $identifier]);
        $rows = $st->fetchAll();
        $user = count($rows) === 1 ? $rows[0] : null;

        if ($user === null) {
            password_verify($password, self::dummyHash());
            Audit::record('login', 'user_account', null, 'Failed', 'Unknown account', $details + ['identifier' => mb_substr($identifier, 0, 100)], actor: ['user_id' => null, 'session_id' => null]);
            return ['ok' => false, 'code' => 'invalid'];
        }
        $userId = (int) $user['user_id'];
        $actor = ['user_id' => $userId, 'session_id' => null];

        // An expired temporary lockout starts a fresh count.
        $lockedUntil = Clock::fromDb($user['locked_until']);
        if ($lockedUntil !== null && $lockedUntil <= Clock::now()) {
            Db::pdo()->prepare('UPDATE user_account SET failed_login_count = 0, locked_until = NULL WHERE user_id = ?')->execute([$userId]);
            $user['failed_login_count'] = 0;
            $user['locked_until'] = null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            $locked = self::recordFailure($user);
            Audit::record('login', 'user_account', $userId, 'Failed', $locked ? 'Wrong password; account locked' : 'Wrong password', $details, actor: $actor);
            return ['ok' => false, 'code' => 'invalid'];
        }

        $blocked = AccountRules::blockReason($user);
        if ($blocked !== null) {
            Audit::record('login', 'user_account', $userId, 'Denied', 'Account ' . str_replace('_', ' ', $blocked), $details, actor: $actor);
            return ['ok' => false, 'code' => $blocked];
        }

        $sessionId = Db::transaction(function () use ($user, $userId, $password, $details, $siteId): string {
            $sets = ['failed_login_count = 0', 'locked_until = NULL', 'last_login_at = ?'];
            $args = [Clock::db()];
            if (PasswordPolicy::needsRehash($user['password_hash'])) {
                $sets[] = 'password_hash = ?';
                $args[] = PasswordPolicy::hash($password);
            }
            Db::pdo()->prepare('UPDATE user_account SET ' . implode(', ', $sets) . ' WHERE user_id = ?')->execute([...$args, $userId]);
            $sessionId = SessionStore::create($userId, $siteId);
            Audit::record('login', 'user_account', $userId, 'Success', null, $details, actor: ['user_id' => $userId, 'session_id' => $sessionId, 'site_id' => $siteId]);
            return $sessionId;
        });
        return ['ok' => true, 'user' => $user, 'sessionId' => $sessionId];
    }

    /**
     * Set a new password (forced change, voluntary change or reset). Clears the forced-change
     * flag, activates a Pending account (UC-01 §3.2.2), revokes outstanding reset links and ends
     * the user's other sessions. Call inside a transaction when combined with other work.
     */
    public static function setPassword(int $userId, string $newPassword, string $endReason, ?string $keepSessionId = null): void
    {
        Db::pdo()->prepare(
            "UPDATE user_account SET password_hash = ?, password_changed_at = ?, must_change_password = 0,
                    failed_login_count = 0, locked_until = NULL, status = IF(status = 'Pending', 'Active', status)
              WHERE user_id = ?"
        )->execute([PasswordPolicy::hash($newPassword), Clock::db(), $userId]);
        Tokens::revokeAll($userId, Tokens::PASSWORD_RESET);
        SessionStore::endAllForUser($userId, $endReason, $userId, $keepSessionId);
    }

    public static function verifyPassword(int $userId, string $password): bool
    {
        $st = Db::pdo()->prepare('SELECT password_hash FROM user_account WHERE user_id = ?');
        $st->execute([$userId]);
        $hash = $st->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    }

    /** Count a wrong password; lock the account at max_failed_logins and tell the Administrators. */
    private static function recordFailure(array $user): bool
    {
        $max = max(1, Settings::int('max_failed_logins', 5));
        $count = (int) $user['failed_login_count'] + 1;
        $lock = $count >= $max && $user['locked_until'] === null;
        if ($lock) {
            $minutes = max(1, Settings::int('lockout_minutes', 15));
            Db::pdo()->prepare('UPDATE user_account SET failed_login_count = ?, locked_until = ? WHERE user_id = ?')
                ->execute([$count, Clock::db(Clock::now()->modify("+$minutes minutes")), $user['user_id']]);
            Notifications::toRoleOnce('Administrator', null, 'account_locked',
                "The account {$user['username']} was locked for $minutes minutes after $count failed sign-in attempts.",
                'user_account', (int) $user['user_id']);
        } else {
            Db::pdo()->prepare('UPDATE user_account SET failed_login_count = ? WHERE user_id = ?')->execute([$count, $user['user_id']]);
        }
        return $lock;
    }
}
