<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Security\Crypto;

/**
 * Single-use, time-limited tokens in auth_token: password reset links (UC-01 §3.2.1),
 * temporary credentials (UC-11 §4.2), trusted devices, offline grants.
 * Only a SHA-256 hash of the token is stored, so a database leak does not leak live links.
 */
final class Tokens
{
    public const PASSWORD_RESET = 'Password Reset';
    public const TEMPORARY_CREDENTIAL = 'Temporary Credential';

    /** Create a token and return the raw value (shown or sent once, never stored). */
    public static function issue(int $userId, string $purpose, int $ttlMinutes, ?int $deviceId = null): string
    {
        $raw = Crypto::b64url(random_bytes(32));
        Db::pdo()->prepare('INSERT INTO auth_token (user_id, purpose, token_hash, device_id, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $purpose, self::hash($raw), $deviceId, Clock::db(Clock::now()->modify("+$ttlMinutes minutes"))]);
        return $raw;
    }

    /** The token row if it is valid right now (right purpose, unused, unrevoked, unexpired); otherwise null. */
    public static function find(string $raw, string $purpose): ?array
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

    /** Mark a token used. Returns false if it was already used (a concurrent request won). */
    public static function consume(int $tokenId): bool
    {
        $st = Db::pdo()->prepare('UPDATE auth_token SET used_at = ? WHERE token_id = ? AND used_at IS NULL AND revoked_at IS NULL');
        $st->execute([Clock::db(), $tokenId]);
        return $st->rowCount() === 1;
    }

    /** Revoke every live token of one purpose for a user (e.g. all reset links after a password change). */
    public static function revokeAll(int $userId, string $purpose): void
    {
        Db::pdo()->prepare('UPDATE auth_token SET revoked_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND revoked_at IS NULL')
            ->execute([Clock::db(), $userId, $purpose]);
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
