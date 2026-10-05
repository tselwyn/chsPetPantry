<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Closure;
use Pfpms\Account\AccountRepository;
use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Http\Context;
use Pfpms\Http\ErrorHandler;
use Pfpms\Http\HttpException;
use Pfpms\Security\Crypto;
use Pfpms\Security\RateLimit;
use Pfpms\Settings;
use Pfpms\Station\OfflineGrants;
use Pfpms\Station\StationGate;
use RuntimeException;

/**
 * Station PINs (50-design D-22, D-25): set on the Station, online, behind the person's password; stored as
 * "p1.<key id>.<Argon2id(b64url(HMAC(pepper, '<user_id>|<pin>')))>", the pepper being HKDF(crypto.keys[kid], 'pfpms/v1/pin-pepper'),
 * so a database-only copy cannot try the 10^4-10^6 PINs without the config key. Never Tokens::hash (plain SHA-256).
 */
final class Pin
{
    public const PEPPER_INFO = 'pfpms/v1/pin-pepper';

    /** Tests only (honoured when Config::env() === 'test'): runs between the reservation and Pin::verify(), with ($userId, $count). */
    public static ?Closure $afterReserve = null;

    /**
     * Tests only (honoured when Config::env() === 'test'): runs first in verify(), with ($userId), wherever verify() is called
     * from; a throw stands for a request that dies while its PIN is being checked.
     */
    public static ?Closure $beforeVerify = null;

    /** @return array{0: int, 1: int} the allowed PIN length, the settings clamped to 4..6 (US-01; the registry caps them at 6) */
    public static function digitsRange(): array
    {
        $min = max(4, min(6, Settings::int('pin_min_digits', 4)));
        $max = max($min, min(6, Settings::int('pin_max_digits', 6)));
        return [$min, $max];
    }

    /** @return array<string, string> ['pin' => the first problem] or []: digits and length, then guessable, then the repeat */
    public static function problems(#[\SensitiveParameter] string $pin, #[\SensitiveParameter] string $confirm): array
    {
        [$min, $max] = self::digitsRange();
        if (!preg_match('/^\d+$/D', $pin) || strlen($pin) < $min || strlen($pin) > $max) {
            return ['pin' => $min === $max ? "Use $min digits." : "Use $min to $max digits."];
        }
        if (self::guessable($pin)) {
            return ['pin' => 'Choose a PIN that is harder to guess than 1234 or 0000.'];
        }
        if (!hash_equals($pin, $confirm)) {
            return ['pin' => 'The two PINs are different.'];
        }
        return [];
    }

    /** All one digit, or every step +1, or every step −1 (no wrap-around: 8901 is allowed). */
    public static function guessable(#[\SensitiveParameter] string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }
        $up = $down = true;
        for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
            $step = ord($pin[$i]) - ord($pin[$i - 1]);
            $up = $up && $step === 1;
            $down = $down && $step === -1;
        }
        return $up || $down;
    }

    /** The account (AccountRepository::COLUMNS) plus pin_hash and pin_failed_count; null for an unknown or the system account. */
    public static function target(int $userId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . AccountRepository::COLUMNS . ", pin_hash, pin_failed_count FROM user_account WHERE user_id = ? AND username <> 'system'");
        $st->execute([$userId]);
        return $st->fetch() ?: null;
    }

    public static function hasPin(int $userId): bool
    {
        $st = Db::pdo()->prepare('SELECT (pin_hash IS NOT NULL) FROM user_account WHERE user_id = ?');
        $st->execute([$userId]);
        return (int) $st->fetchColumn() === 1;
    }

    /**
     * Count one online attempt BEFORE the PIN is checked (D-25), in its own short transaction: the conditional increment is
     * atomic, so parallel requests cannot all pass the limit while the ≈0.5 s verify runs. A request that dies after this
     * leaves the attempt counted (it fails closed). @return ?int the count after the reservation; null at the limit
     */
    public static function reserveAttempt(int $userId, int $max): ?int
    {
        return Db::transaction(static function () use ($userId, $max): ?int {
            $st = Db::pdo()->prepare('UPDATE user_account SET pin_failed_count = pin_failed_count + 1 WHERE user_id = ? AND pin_failed_count < ?');
            $st->execute([$userId, $max]);
            if ($st->rowCount() !== 1) {
                return null;
            }
            $count = Db::pdo()->prepare('SELECT pin_failed_count FROM user_account WHERE user_id = ?');
            $count->execute([$userId]);
            return (int) $count->fetchColumn();
        });
    }

    public static function hash(int $userId, #[\SensitiveParameter] string $pin): string
    {
        [$kid, $pepper] = Crypto::derivedKey(self::PEPPER_INFO);
        return 'p1.' . $kid . '.' . PasswordPolicy::hash(Crypto::b64url(Crypto::hmac($pepper, $userId . '|' . $pin)));
    }

    /** False for another format or a key id no longer in crypto.keys (logged: the person sets the PIN again). */
    public static function verify(int $userId, #[\SensitiveParameter] string $pin, #[\SensitiveParameter] string $stored): bool
    {
        if (self::$beforeVerify !== null && Config::env() === 'test') {
            (self::$beforeVerify)($userId);
        }
        if (!preg_match('/^p1\.([A-Za-z0-9_-]{1,20})\.(.+)$/sD', $stored, $m)) {
            return false;
        }
        try {
            [, $pepper] = Crypto::derivedKey(self::PEPPER_INFO, $m[1]);
        } catch (RuntimeException $e) {
            ErrorHandler::log(strtoupper(bin2hex(random_bytes(4))), $e);
            return false;
        }
        return password_verify(Crypto::b64url(Crypto::hmac($pepper, $userId . '|' . $pin)), $m[2]);
    }

    /**
     * POST api/auth/pin_set.php (50-design §6.7). The first PIN revokes nothing; a change revokes the person's grants on OTHER
     * tablets (their verifiers there go stale); this tablet then stores the new verifier. @throws HttpException 403 device_*
     * (lockDevice: the tablet was taken out of service during the request; the PIN is unchanged) | 401 session_ended |
     * 422 account_unusable (Auth::lockVerified: a reset, a deactivation or a password change committed during the request)
     * @return array{ok: true, revoked_grants: list<int>, server_time: string}
     */
    public static function set(Context $ctx, array $device, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $pin,
        #[\SensitiveParameter] string $confirm): array
    {
        $uid = $ctx->userId();
        if (!RateLimit::hit("pin_set:user:$uid", 5, 900)) {
            throw new HttpException(429, '', 'rate_limited', [], ['Retry-After' => '900']);
        }
        $verified = Auth::verifiedHash($uid, $password);
        if ($verified === null) {
            Audit::record('pin_set', 'user_account', $uid, 'Failed', 'Wrong password');
            throw new HttpException(422, 'That password is not correct.', 'login_failed');
        }
        $errors = self::problems($pin, $confirm);
        if ($errors !== []) {
            throw new HttpException(422, (string) reset($errors), 'pin_rules', ['errors' => $errors]);
        }
        $hash = self::hash($uid, $pin); // ≈0.4 s: before the transaction, which holds the account's lock
        $deviceId = (int) $device['device_id'];
        Db::transaction(static function () use ($uid, $hash, $pin, $device, $deviceId, $ctx, $verified): void {
            StationGate::lockDevice($device); // device S first (§2.1: the audit row's FK); a tablet retired meanwhile is refused
            // Then the account X and the session: a reset committed since the verify (it clears the PIN) wins, 401.
            $changed = Auth::lockVerified($uid, $ctx->sessionId, $verified)['pin_hash'] !== null;
            Db::pdo()->prepare('UPDATE user_account SET pin_hash = ?, pin_failed_count = 0 WHERE user_id = ?')->execute([$hash, $uid]);
            if ($changed) {
                OfflineGrants::revokeForUser($uid, 'pin_change', $deviceId);
            }
            Audit::record('pin_set', 'user_account', $uid, 'Success', null, ['changed' => $changed, 'digits' => strlen($pin)]);
        });
        return ['ok' => true, 'revoked_grants' => Tokens::revokedGrantIds($deviceId), 'server_time' => Clock::dbMillis()];
    }
}
