<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Security\Crypto;

/**
 * Single-use, time-limited tokens in auth_token: password reset links (UC-01 §3.2.1),
 * temporary credentials (UC-11 §4.2), tablet registration codes (P2A admin_devices), trusted
 * devices, offline grants. Only a SHA-256 hash of the token is stored, so a database leak does
 * not leak live links. Parameters holding a raw token are #[SensitiveParameter], so stack traces in
 * the error log never show them.
 */
final class Tokens
{
    public const PASSWORD_RESET = 'Password Reset';
    public const TEMPORARY_CREDENTIAL = 'Temporary Credential';
    public const DEVICE_REGISTRATION = 'Device Registration';
    public const OFFLINE_GRANT = 'Offline Grant';
    public const TRUSTED_DEVICE = 'Trusted Device';

    /** Create a token and return the raw value (shown or sent once, never stored). */
    public static function issue(int $userId, string $purpose, int $ttlMinutes, ?int $deviceId = null): string
    {
        $raw = Crypto::b64url(random_bytes(32));
        self::issueValue($userId, $purpose, $raw, $ttlMinutes, $deviceId);
        return $raw;
    }

    /**
     * Store a token whose value the caller made (a registration code in its own format) and return
     * when it expires (UTC). Only its hash is stored.
     */
    public static function issueValue(int $userId, string $purpose, #[\SensitiveParameter] string $raw, int $ttlMinutes, ?int $deviceId = null): string
    {
        $expires = Clock::db(Clock::now()->modify("+$ttlMinutes minutes"));
        Db::pdo()->prepare('INSERT INTO auth_token (user_id, purpose, token_hash, device_id, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $purpose, self::hash($raw), $deviceId, $expires, Clock::db()]);
        return $expires;
    }

    /** The token row if it is valid right now (right purpose, unused, unrevoked, unexpired); otherwise null. */
    public static function find(#[\SensitiveParameter] string $raw, string $purpose): ?array
    {
        if ($raw === '' || strlen($raw) > 100) {
            return null;
        }
        $st = Db::pdo()->prepare(
            'SELECT token_id, user_id, purpose, device_id, expires_at FROM auth_token
              WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > ?'
        );
        $st->execute([self::hash($raw), $purpose, Clock::db()]);
        return $st->fetch() ?: null;
    }

    /**
     * The token row whatever its state (used, revoked or expired), or null: registration replays need the used code
     * (50-design X-3).
     */
    public static function findAny(#[\SensitiveParameter] string $raw, string $purpose): ?array
    {
        if ($raw === '' || strlen($raw) > 100) {
            return null;
        }
        $st = Db::pdo()->prepare(
            'SELECT token_id, user_id, purpose, device_id, expires_at, used_at, revoked_at, created_at FROM auth_token WHERE token_hash = ? AND purpose = ?'
        );
        $st->execute([self::hash($raw), $purpose]);
        return $st->fetch() ?: null;
    }

    /**
     * Ids of this tablet's offline grants revoked in the last 168 hours (the longest offline_grant_hours): the tablet
     * deletes a person's offline sign-in when its current grant is listed (50-design D-21).
     * @return list<int>
     */
    public static function revokedGrantIds(int $deviceId): array
    {
        $st = Db::pdo()->prepare(
            "SELECT token_id FROM auth_token
              WHERE device_id = ? AND purpose = 'Offline Grant' AND revoked_at IS NOT NULL AND revoked_at > ?
              ORDER BY token_id"
        );
        $st->execute([$deviceId, Clock::db(Clock::now()->modify('-168 hours'))]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Mark a token used. Returns false if it was already used (a concurrent request won), revoked
     * meanwhile, or expired since it was found.
     */
    public static function consume(int $tokenId): bool
    {
        $now = Clock::db();
        $st = Db::pdo()->prepare('UPDATE auth_token SET used_at = ? WHERE token_id = ? AND used_at IS NULL AND revoked_at IS NULL AND expires_at > ?');
        $st->execute([$now, $tokenId, $now]);
        return $st->rowCount() === 1;
    }

    /** Revoke every live token of one purpose for a user (e.g. all reset links after a password change). */
    public static function revokeAll(int $userId, string $purpose): void
    {
        Db::pdo()->prepare('UPDATE auth_token SET revoked_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND revoked_at IS NULL')
            ->execute([Clock::db(), $userId, $purpose]);
    }

    /**
     * Revoke the live tokens bound to a tablet (its registration code, and later its offline grants
     * and trusted-device tokens), optionally of one purpose. A registration code already redeemed
     * and expired rows are left as they are. Returns how many were revoked.
     */
    public static function revokeForDevice(int $deviceId, ?string $purpose = null): int
    {
        $now = Clock::db();
        $st = Db::pdo()->prepare(
            "UPDATE auth_token SET revoked_at = ?
              WHERE device_id = ? AND revoked_at IS NULL AND expires_at > ? AND (used_at IS NULL OR purpose <> 'Device Registration')"
            . ($purpose !== null ? ' AND purpose = ?' : '')
        );
        $st->execute($purpose !== null ? [$now, $deviceId, $now, $purpose] : [$now, $deviceId, $now]);
        return $st->rowCount();
    }

    /** SHA-256 hex: the stored form of every token, and of device credentials (P2B). */
    public static function hash(#[\SensitiveParameter] string $raw): string
    {
        return hash('sha256', $raw);
    }
}
