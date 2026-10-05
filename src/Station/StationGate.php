<?php
declare(strict_types=1);

namespace Pfpms\Station;

use DateTimeImmutable;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Policy;
use Pfpms\Auth\Rbac;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\SiteAccess;
use Pfpms\Device\DeviceGuard;
use Pfpms\Device\DeviceRepository;
use Pfpms\Http\ErrorHandler;
use Pfpms\Http\HttpException;
use Pfpms\Reference\SiteRepository;
use Pfpms\Security\Crypto;
use Pfpms\Settings;
use RuntimeException;

/**
 * The Station's gate (50-design D-18, D-59, REQ-30): who may use the Station on a tablet, and what a sign-in may release.
 * release() is the one place the tablet's vault key and an offline grant leave the server; P3's pack.php must call it too
 * (after its own lockDevice, account lock, requireSiteActive and requireSessionOpen).
 */
final class StationGate
{
    public const UNUSABLE = 'This account cannot be used at the moment. Please contact an Administrator.';
    public const ROLE = 'Your role does not use the Station.';

    /** @return list<string> the role's offline.* capabilities, sorted (empty for Board: no Station sign-in, D-18) */
    public static function offlineCapabilities(string $role): array
    {
        $caps = array_values(array_filter(Rbac::capabilities($role), static fn(string $c): bool => str_starts_with($c, 'offline.')));
        sort($caps);
        return $caps;
    }

    /** null when the person may use the Station here; 'role' without an offline.* capability (checked first), 'site' without access to the tablet's site. */
    public static function access(array $user, array $device): ?string
    {
        if (self::offlineCapabilities((string) $user['role']) === []) {
            return 'role';
        }
        if ($device['site_id'] === null || !SiteAccess::canUseSite($user, (int) $device['site_id'])) {
            return 'site';
        }
        return null;
    }

    /**
     * The tablet's row ($device: the guard's row; locked by its device_id) under a shared lock, still in service, plus the guard's
     * site_name (for messages only); otherwise 403 with the directive (the caller's transaction rolls back).
     */
    public static function lockDevice(array $device): array
    {
        $d = DeviceRepository::lockShared((int) $device['device_id']);
        if ($d === null || (int) $d['in_service'] !== 1) {
            $revoked = $d !== null && $d['revoked_at'] !== null;
            throw new HttpException(403, $revoked ? 'This tablet has been taken out of service.' : 'This tablet is not registered for use.',
                $revoked ? 'device_revoked' : 'device_not_registered', ['directive' => $d === null ? null : DeviceGuard::directive($d)]);
        }
        return $d + ['site_name' => $device['site_name'] ?? null];
    }

    /** The tablet's site is active (a plain read: call it after the transaction's account write, §2.1). */
    public static function requireSiteActive(array $device): void
    {
        $site = $device['site_id'] === null ? null : SiteRepository::find((int) $device['site_id']);
        if ($site === null || (int) $site['is_active'] !== 1) {
            throw new HttpException(403, "This tablet's site is not active. Ask a Coordinator.", 'device_site_inactive', ['directive' => null]);
        }
    }

    /**
     * The caller's own session, still open (a plain read: call it after the transaction's account lock, §2.1). 401 once it has
     * ended: an access change, a reset, End shift or another person's sign-in committed after the request's own check.
     * @return array the SessionStore::row()
     */
    public static function requireSessionOpen(string $sessionId): array
    {
        $row = SessionStore::row($sessionId);
        if ($row === null || $row['ended_at'] !== null) {
            throw new HttpException(401, 'Your session has ended. Please sign in again.', 'session_ended');
        }
        return $row;
    }

    /** 403 no_station_access for access()'s reason ('role' or 'site'). */
    public static function noAccess(string $why, array $device): HttpException
    {
        $message = $why === 'role' ? self::ROLE : "You don't have access to " . ($device['site_name'] ?? 'this site') . ', where this tablet is used.';
        return new HttpException(403, $message, 'no_station_access', ['reason' => $why]);
    }

    /**
     * First the account and access re-check (an access change committed after the request's own checks and before this
     * transaction's account lock is seen here, §2.1), then the gates (forced password change first, then the agreement), then
     * the vault key, then the grant. $user is the account row read in this transaction; $device the lockDevice() row; $at the
     * grant's issue instant (default now). Writes the grant (and its audit row) only when it releases.
     * @return array{gate: ?string, release: ?array, unavailable: ?string}
     *   gate 'password_change'|'policy_ack'; unavailable 'no_vault_key'|'no_grant_possible'; release (50 §6.5):
     *   {dvk, grant_id, grant_hmac_key, grant_issued_at, grant_expires_at, pbkdf2_iterations, offline_allowed}
     * @throws HttpException 422 account_unusable | 403 no_station_access
     */
    public static function release(array $user, array $device, ?DateTimeImmutable $at = null): array
    {
        if (!AccountRules::canHoldSession($user)) {
            throw new HttpException(422, self::UNUSABLE, 'account_unusable');
        }
        $why = self::access($user, $device);
        if ($why !== null) {
            throw self::noAccess($why, $device);
        }
        $gate = match (true) {
            AccountRules::mustChangePassword($user) => 'password_change',
            Policy::acknowledgementRequired($user) => 'policy_ack',
            default => null,
        };
        if ($gate !== null) {
            return ['gate' => $gate, 'release' => null, 'unavailable' => null];
        }
        $id = (int) $device['device_id'];
        $sealed = DeviceRepository::vaultKeyCiphertext($id);
        if ($sealed === null) {
            return ['gate' => null, 'release' => null, 'unavailable' => 'no_vault_key']; // a seeded test tablet (D-46)
        }
        try {
            $dvk = Crypto::decrypt($sealed, "device:$id:dvk");
        } catch (RuntimeException $e) {
            ErrorHandler::log(strtoupper(bin2hex(random_bytes(4))), $e); // a key id removed from crypto.keys: online only, and logged
            return ['gate' => null, 'release' => null, 'unavailable' => 'no_vault_key'];
        }
        $offlineAllowed = (int) ($device['offline_enabled'] ?? 0) === 1 && Settings::bool('offline_mode_enabled', true);
        $grant = OfflineGrants::issue($user, $device, $offlineAllowed, $at);
        if ($grant === null) {
            return ['gate' => null, 'release' => null, 'unavailable' => 'no_grant_possible'];
        }
        return ['gate' => null, 'unavailable' => null, 'release' => [
            'dvk' => Crypto::b64url($dvk),
            'grant_id' => $grant['grant_id'],
            'grant_hmac_key' => Crypto::b64url($grant['secret']),
            'grant_issued_at' => $grant['issued_at'],
            'grant_expires_at' => $grant['expires_at'],
            'pbkdf2_iterations' => $device['pbkdf2_iterations'] === null ? Settings::int('offline_pbkdf2_iterations', 600000) : (int) $device['pbkdf2_iterations'],
            'offline_allowed' => $offlineAllowed,
        ]];
    }
}
