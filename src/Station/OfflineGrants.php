<?php
declare(strict_types=1);

namespace Pfpms\Station;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Pfpms\Audit\Audit;
use Pfpms\Auth\Policy;
use Pfpms\Auth\SiteAccess;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Security\Crypto;
use Pfpms\Settings;

/**
 * Offline grants (50-design D-19..D-21, D-24, §3.7): the one "signed in with a password on this tablet" record. An auth_token
 * row (purpose 'Offline Grant') with a server-made 32-byte HMAC secret the tablet signs its records with. A new grant
 * supersedes, never revokes, the person's earlier ones on the tablet (a sign-in whose answer was lost must not turn an offline
 * shift into held records); the account and access hooks, a PIN change and Retire/Erase revoke them. used_at is never set.
 */
final class OfflineGrants
{
    /** Tests only (honoured when Config::env() === 'test'): runs first in issue(), inside the sign-in transaction. */
    public static ?Closure $beforeIssue = null;

    /**
     * Issue a grant (inside the caller's transaction, after the account write and before any session write). Reads only
     * $user's id, role, dates and password_changed_at, and $device's device_id and site_id. Null, writing nothing, when the
     * expiry is not in the future. $at: the issue instant (default now); the sign-in passes the instant its session starts at.
     * @return ?array{grant_id: int, secret: string, issued_at: string, expires_at: string, capped_by: string}
     *   secret: the raw 32 bytes; issued_at and expires_at: the stored DATETIME columns as 'Y-m-d H:i:s.000'
     */
    public static function issue(array $user, array $device, bool $offlineAllowed, ?DateTimeImmutable $at = null): ?array
    {
        if (self::$beforeIssue !== null && Config::env() === 'test') {
            (self::$beforeIssue)($user, $device);
        }
        $now = $at ?? Clock::now();
        $expiry = self::expiry($user, $device, $offlineAllowed, $now);
        $createdAt = Clock::db($now);
        $expiresAt = Clock::db($expiry['at']); // whole seconds, rounded down: never later than the cap
        if ($expiresAt <= $createdAt) {
            return null;
        }
        $userId = (int) $user['user_id'];
        $secret = random_bytes(32);
        $tokenHash = Tokens::hash(random_bytes(32)); // a filler for token_hash NOT NULL UNIQUE; never looked up
        Db::pdo()->prepare(
            "INSERT INTO auth_token (user_id, purpose, token_hash, secret_ciphertext, device_id, expires_at, created_at)
             VALUES (?, 'Offline Grant', ?, ?, ?, ?, ?)"
        )->execute([$userId, $tokenHash, Crypto::encrypt($secret, 'offline_grant:' . $tokenHash), (int) $device['device_id'], $expiresAt, $createdAt]);
        $grantId = (int) Db::pdo()->lastInsertId();
        Audit::record('offline_grant_issue', 'user_account', $userId, 'Success', null,
            ['grant_id' => $grantId, 'expires_at' => $expiresAt, 'capped_by' => $expiry['capped_by'], 'offline_allowed' => $offlineAllowed],
            actor: ['user_id' => $userId, 'session_id' => null]);
        return ['grant_id' => $grantId, 'secret' => $secret, 'issued_at' => $createdAt . '.000', 'expires_at' => $expiresAt . '.000',
            'capped_by' => $expiry['capped_by']];
    }

    /**
     * D-19: the earliest instant from which the server itself would refuse the person, and which rule it is. Ties keep the
     * first of: hours, site_access, account_expiry, deactivation, agreement_due, agreement_new, password_age.
     * agreement_new: 00:00 (organisation time) of the day a later agreement version takes effect (the person has not accepted it).
     * Hours: offline_grant_hours when offline is allowed on this tablet, else session_absolute_hours.
     * @return array{at: DateTimeImmutable, capped_by: string}
     */
    public static function expiry(array $user, array $device, bool $offlineAllowed, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $hours = $offlineAllowed ? max(1, Settings::int('offline_grant_hours', 72)) : max(1, Settings::int('session_absolute_hours', 12));
        $caps = ['hours' => $now->modify("+$hours hours")];
        $accessEnd = SiteAccess::accessEnd($user, (int) $device['site_id']);
        if ($accessEnd !== null) {
            $caps['site_access'] = Clock::fromDb($accessEnd);
        }
        if (!empty($user['expiry_date'])) { // usable through its expiry date (AccountRules): refused from 00:00 the day after
            $next = (new DateTimeImmutable((string) $user['expiry_date'], new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
            $caps['account_expiry'] = Clock::orgDayStartUtc($next);
        }
        if (!empty($user['deactivation_effective_date'])) {
            $caps['deactivation'] = Clock::orgDayStartUtc((string) $user['deactivation_effective_date']);
        }
        $due = Policy::dueAgainOn($user);
        if ($due !== null) {
            $caps['agreement_due'] = Clock::orgDayStartUtc($due);
        }
        $next = Policy::nextVersionFrom();
        if ($next !== null) {
            $caps['agreement_new'] = Clock::orgDayStartUtc($next);
        }
        $maxAge = Settings::int('password_max_age_days', 0);
        $changed = Clock::fromDb($user['password_changed_at'] ?? null);
        if ($maxAge > 0 && $changed !== null) {
            $caps['password_age'] = $changed->modify("+$maxAge days");
        }
        $by = 'hours';
        foreach ($caps as $name => $at) {
            if ($at < $caps[$by]) {
                $by = $name;
            }
        }
        return ['at' => $caps[$by], 'capped_by' => $by];
    }

    /** The grant row whatever its state (revoked, expired, shredded), for S5's push; never filtered on now. */
    public static function find(int $grantId): ?array
    {
        $st = Db::pdo()->prepare(
            "SELECT token_id, user_id, device_id, token_hash, secret_ciphertext, created_at, expires_at, revoked_at
               FROM auth_token WHERE token_id = ? AND purpose = 'Offline Grant'"
        );
        $st->execute([$grantId]);
        return $st->fetch() ?: null;
    }

    /** The grant's raw secret; null once shredded (an erase, the purge). @throws \RuntimeException when it does not decrypt */
    public static function secret(array $grant): ?string
    {
        $sealed = $grant['secret_ciphertext'] ?? null;
        return $sealed === null ? null : Crypto::decrypt((string) $sealed, 'offline_grant:' . $grant['token_hash']);
    }

    /** REQ-69: valid at $t when created_at ≤ t < expires_at and (revoked_at NULL or t ≤ revoked_at). A row made before 0013 (created_at NULL) never is. */
    public static function validAt(array $grant, DateTimeImmutable $t): bool
    {
        $created = Clock::fromDb($grant['created_at'] ?? null);
        $expires = Clock::fromDb($grant['expires_at'] ?? null);
        $revoked = Clock::fromDb($grant['revoked_at'] ?? null);
        return $created !== null && $expires !== null && $created <= $t && $t < $expires && ($revoked === null || $t <= $revoked);
    }

    /**
     * The online PIN window (D-24): the newest live grant for this person on this tablet issued within $hours and after the
     * tablet's last End shift (strictly later; NULL = never). An offline password unlock makes no grant, so it never opens it.
     * @return ?array{token_id: int, created_at: string, expires_at: string}
     */
    public static function pinWindow(int $userId, int $deviceId, int $hours): ?array
    {
        $now = Clock::now();
        $st = Db::pdo()->prepare(
            "SELECT t.token_id, t.created_at, t.expires_at FROM auth_token t JOIN device d ON d.device_id = t.device_id
              WHERE t.purpose = 'Offline Grant' AND t.user_id = ? AND t.device_id = ? AND t.revoked_at IS NULL AND t.expires_at > ?
                AND t.created_at >= ? AND (d.shift_ended_at IS NULL OR t.created_at > d.shift_ended_at)
              ORDER BY t.token_id DESC LIMIT 1"
        );
        $st->execute([$userId, $deviceId, Clock::db($now), Clock::db($now->modify('-' . max(1, $hours) . ' hours'))]);
        $row = $st->fetch();
        return $row ? ['token_id' => (int) $row['token_id'], 'created_at' => (string) $row['created_at'], 'expires_at' => (string) $row['expires_at']] : null;
    }

    /** Whether a grant, whatever its state, was issued to the person on this tablet at or after $since (Clock::db text). */
    public static function issuedSince(int $userId, int $deviceId, string $since): bool
    {
        $st = Db::pdo()->prepare("SELECT 1 FROM auth_token WHERE purpose = 'Offline Grant' AND user_id = ? AND device_id = ? AND created_at >= ? LIMIT 1");
        $st->execute([$userId, $deviceId, $since]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Revoke the person's live grants, on every tablet or all but $exceptDeviceId (a PIN change spares the tablet in hand), in the
     * caller's transaction; audited as offline_grant_revoke {count, cause} when any was revoked. Call before ending sessions.
     * @param 'password'|'access'|'deactivate'|'reset'|'pin_change' $cause
     */
    public static function revokeForUser(int $userId, string $cause, ?int $exceptDeviceId = null): int
    {
        $now = Clock::db();
        $sql = "UPDATE auth_token SET revoked_at = ? WHERE purpose = 'Offline Grant' AND user_id = ? AND revoked_at IS NULL AND expires_at > ?";
        $args = [$now, $userId, $now];
        if ($exceptDeviceId !== null) {
            $sql .= ' AND (device_id IS NULL OR device_id <> ?)';
            $args[] = $exceptDeviceId;
        }
        $st = Db::pdo()->prepare($sql);
        $st->execute($args);
        $count = $st->rowCount();
        if ($count > 0) {
            Audit::record('offline_grant_revoke', 'user_account', $userId, 'Success', null, ['count' => $count, 'cause' => $cause]);
        }
        return $count;
    }
}
