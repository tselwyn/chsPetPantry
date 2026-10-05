<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Pfpms\Account\AccountRepository;
use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\Context;
use Pfpms\Http\HttpException;
use Pfpms\Notify\Notifications;
use Pfpms\Security\RateLimit;
use Pfpms\Settings;
use Pfpms\Station\OfflineGrants;
use Pfpms\Station\StationGate;

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
    public static function attempt(string $identifier, #[\SensitiveParameter] string $password, string $ip, ?int $siteId = null): array
    {
        $r = self::core($identifier, $password, ['ip' => $ip], ["login:ip:$ip", 30, 900], null, null,
            static function (array $user, int $userId, mixed $before) use ($ip, $siteId): string {
                $sessionId = SessionStore::create($userId, $siteId);
                Audit::record('login', 'user_account', $userId, 'Success', null, ['ip' => $ip],
                    actor: ['user_id' => $userId, 'session_id' => $sessionId, 'site_id' => $siteId]);
                return $sessionId;
            });
        return $r['ok'] ? ['ok' => true, 'user' => $r['user'], 'sessionId' => $r['result']] : $r;
    }

    /**
     * The Station's sign-in (50-design D-16): the shared core with the tablet's bucket instead of the IP's. $access(user) returns
     * 'role'|'site' to refuse before any write; $beforeUpdate(user) runs first in the success transaction (the tablet's row lock)
     * and returns a value; $afterUpdate(user, userId, that value) runs after the account UPDATE in the same transaction and
     * returns what the caller needs.
     * @return array{ok: true, user: array, result: mixed}|array{ok: false, code: string, reason?: string}
     *   code: 'invalid' | 'locked' | 'inactive' | 'expired' | 'not_started' | 'rate_limited' | 'no_access' (reason 'role'|'site')
     */
    public static function attemptStation(string $identifier, #[\SensitiveParameter] string $password, string $ip, int $deviceId,
        callable $access, callable $beforeUpdate, callable $afterUpdate): array
    {
        return self::core($identifier, $password, ['ip' => $ip, 'station' => true], ["login:device:$deviceId", 30, 900],
            $access, $beforeUpdate, $afterUpdate);
    }

    /**
     * One audited, rate-limited, dummy-hash sign-in for the web and the Station. $firstBucket [name, limit, window] is hit first,
     * then 'login:id:<lower identifier>' 10/900 (only when the first passes, as today). On success the account UPDATE also
     * resets pin_failed_count (D-22: any password sign-in clears online PIN failures). The returned user never holds password_hash.
     * @param array{0: string, 1: int, 2: int} $firstBucket
     * @return array{ok: true, user: array, result: mixed}|array{ok: false, code: string, reason?: string}
     */
    private static function core(string $identifier, #[\SensitiveParameter] string $password, array $details, array $firstBucket,
        ?callable $access, ?callable $beforeUpdate, callable $afterUpdate): array
    {
        $identifier = trim($identifier);
        [$bucket, $limit, $window] = $firstBucket;
        if (!RateLimit::hit($bucket, $limit, $window) || !RateLimit::hit('login:id:' . mb_strtolower($identifier), 10, 900)) {
            Audit::record('login', 'user_account', null, 'Denied', 'Rate limited', $details + ['identifier' => mb_substr($identifier, 0, 100)], actor: ['user_id' => null, 'session_id' => null]);
            return ['ok' => false, 'code' => 'rate_limited'];
        }

        $st = Db::pdo()->prepare(
            'SELECT user_id, username, email, first_name, last_name, role, status, password_hash, failed_login_count, locked_until,
                    must_change_password, password_changed_at, start_date, expiry_date, deactivation_effective_date
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
        if ($access !== null && ($why = $access($user)) !== null) {
            Audit::record('login', 'user_account', $userId, 'Denied', "No access to this tablet's site or the Station",
                $details + ['reason_code' => $why], actor: $actor);
            return ['ok' => false, 'code' => 'no_access', 'reason' => $why];
        }

        $result = Db::transaction(function () use ($user, $userId, $password, $beforeUpdate, $afterUpdate): mixed {
            $before = $beforeUpdate !== null ? $beforeUpdate($user) : null;
            $sets = ['failed_login_count = 0', 'locked_until = NULL', 'pin_failed_count = 0', 'last_login_at = ?'];
            $args = [Clock::db()];
            if (PasswordPolicy::needsRehash($user['password_hash'])) {
                $sets[] = 'password_hash = ?';
                $args[] = PasswordPolicy::hash($password);
            }
            Db::pdo()->prepare('UPDATE user_account SET ' . implode(', ', $sets) . ' WHERE user_id = ?')->execute([...$args, $userId]);
            return $afterUpdate($user, $userId, $before);
        });
        unset($user['password_hash']);
        return ['ok' => true, 'user' => $user, 'result' => $result];
    }

    /**
     * Set a new password (forced change, voluntary change or reset). Clears the forced-change
     * flag, activates a Pending account (UC-01 §3.2.2), revokes outstanding reset links and ends
     * the user's other sessions. Call inside a transaction when combined with other work.
     */
    public static function setPassword(int $userId, #[\SensitiveParameter] string $newPassword, string $endReason, ?string $keepSessionId = null): void
    {
        self::setPasswordHash($userId, PasswordPolicy::hash($newPassword), $endReason, $keepSessionId);
    }

    /**
     * setPassword() with the new password already hashed by PasswordPolicy::hash(). A change re-checked under the account
     * lock (lockVerified()) hashes before its transaction, as Pin::set() does, and writes here: no Argon2 runs while the
     * account row (and the tablet's) is locked.
     * @throws \InvalidArgumentException when $hash is not a current PasswordPolicy::hash() (never a plain password)
     */
    public static function setPasswordHash(int $userId, #[\SensitiveParameter] string $hash, string $endReason, ?string $keepSessionId = null): void
    {
        if (PasswordPolicy::needsRehash($hash)) {
            throw new \InvalidArgumentException('setPasswordHash() takes a PasswordPolicy::hash()');
        }
        Db::pdo()->prepare(
            "UPDATE user_account SET password_hash = ?, password_changed_at = ?, must_change_password = 0,
                    failed_login_count = 0, locked_until = NULL, status = IF(status = 'Pending', 'Active', status)
              WHERE user_id = ?"
        )->execute([$hash, Clock::db(), $userId]);
        // Every outstanding link that could set the password is now stale: self-service reset
        // links and Administrator-issued invitations, reset links and printed sheets alike.
        Tokens::revokeAll($userId, Tokens::PASSWORD_RESET);
        Tokens::revokeAll($userId, Tokens::TEMPORARY_CREDENTIAL);
        Tokens::revokeAll($userId, Tokens::DEVICE_REGISTRATION); // tablet codes they created (reprint after a password change)
        OfflineGrants::revokeForUser($userId, 'password'); // grants before sessions (50-design §12.3; lock order auth_token → user_session)
        SessionStore::endAllForUser($userId, $endReason, $userId, $keepSessionId);
    }

    public static function verifyPassword(int $userId, #[\SensitiveParameter] string $password): bool
    {
        return self::verifiedHash($userId, $password) !== null;
    }

    /**
     * The stored password hash when $password verifies against it, else null. A change made behind the current password keeps
     * it for lockVerified(), which refuses the change when the hash is no longer this one.
     */
    public static function verifiedHash(int $userId, #[\SensitiveParameter] string $password): ?string
    {
        $st = Db::pdo()->prepare('SELECT password_hash FROM user_account WHERE user_id = ?');
        $st->execute([$userId]);
        $hash = $st->fetchColumn();
        return is_string($hash) && password_verify($password, $hash) ? $hash : null;
    }

    /**
     * The re-check of a change the person proved with their password before its transaction (a password change on the web or
     * the Station, a PIN set). Call it inside that transaction before any write: first after the tablet's lockDevice(), when
     * there is one. It locks the account row (FOR UPDATE), then reads the session: that is the transaction's first plain read,
     * so a reset, a deactivation or another password change committed after the request's own verify is seen here, and wins.
     * $verifiedHash: what verifiedHash() returned for this request.
     * @return array the locked account row: AccountRepository::COLUMNS, password_hash and pin_hash
     * @throws HttpException 401 session_ended (the session ended, or the password is no longer the one verified) |
     *   422 account_unusable
     */
    public static function lockVerified(int $userId, string $sessionId, #[\SensitiveParameter] string $verifiedHash): array
    {
        $st = Db::pdo()->prepare('SELECT ' . AccountRepository::COLUMNS
            . ", password_hash, pin_hash FROM user_account WHERE user_id = ? AND username <> 'system' FOR UPDATE");
        $st->execute([$userId]);
        $account = $st->fetch() ?: null;
        $session = SessionStore::row($sessionId);
        if ($account === null || $session === null || $session['ended_at'] !== null || (int) $session['user_id'] !== $userId
            || !hash_equals($verifiedHash, (string) $account['password_hash'])) {
            throw new HttpException(401, 'Your session has ended. Please sign in again.', 'session_ended');
        }
        if (!AccountRules::canHoldSession($account)) {
            throw new HttpException(422, StationGate::UNUSABLE, 'account_unusable');
        }
        return $account;
    }

    /**
     * The person's own change on the web (change_password.php, UC-01 §3.2.2): the current password, the repeat, a password
     * different from the current one and the rules; the new hash (before the transaction, which holds the account's lock);
     * then one transaction: lockVerified(), setPasswordHash() keeping this session, and the audit row.
     * @return ?list<string> the problems to show ([]: the password was changed); null: a reset, a deactivation or another
     *   change committed during the request won (lockVerified()'s 401 session_ended or 422 account_unusable; nothing was
     *   changed), so the page reloads and Page::start() signs the person out and says why
     */
    public static function changeOwnPassword(Context $ctx, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new,
        #[\SensitiveParameter] string $confirm): ?array
    {
        $uid = $ctx->userId();
        $verified = self::verifiedHash($uid, $current);
        if ($verified === null) {
            Audit::record('password_change', 'user_account', $uid, 'Failed', 'Wrong current password');
            return ['The current password is not correct.'];
        }
        if ($new !== $confirm) {
            return ['The new passwords do not match.'];
        }
        if (hash_equals($current, $new)) {
            return ['Choose a password different from your current one.'];
        }
        $problems = PasswordPolicy::check($new, $ctx->user);
        if ($problems !== []) {
            return $problems;
        }
        $hash = PasswordPolicy::hash($new); // ≈0.4 s: before the transaction, which holds the account's lock
        try {
            Db::transaction(static function () use ($ctx, $uid, $hash, $verified): void {
                $account = self::lockVerified($uid, $ctx->sessionId, $verified);
                self::setPasswordHash($uid, $hash, 'Password Reset', $ctx->sessionId);
                Audit::record('password_change', 'user_account', $uid, 'Success', AccountRules::mustChangePassword($account) ? 'Forced change' : null);
            });
        } catch (HttpException $e) {
            if (!in_array($e->code(), ['session_ended', 'account_unusable'], true)) {
                throw $e;
            }
            return null;
        }
        return [];
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
