<?php
declare(strict_types=1);

namespace Pfpms\Device;

use Pfpms\Audit\Audit;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Http\HttpException;
use Pfpms\Http\Request;
use Pfpms\Notify\Notifications;
use Pfpms\Security\RateLimit;

/**
 * Authenticates the Station tablet behind a request (40-design §14.2; 50-design §5.1 step 4, X-5). The
 * credential travels only as "Authorization: PFPMS-Device pfd1_…"; it is looked up by its SHA-256 and
 * trusted only by IN_SERVICE_SQL at an active site. Refusals are thrown as HttpException (the JSON
 * envelope). No transaction is open here: the audit rows and alerts commit at once, and never wait on
 * anything (D-51).
 */
final class DeviceGuard
{
    public const IN_SERVICE = 'in_service';
    public const KNOWN = 'known';
    public const FORMAT = '/^pfd1_[A-Za-z0-9_-]{43}$/D';

    /**
     * @param string $mode IN_SERVICE: only a tablet in service at an active site; KNOWN: also a revoked one (it
     *   gets its directive), never a wiped one (410)
     * @param string $endpoint Request::scriptPath(), what the proof signs
     * @return array the tablet (DeviceRepository::byCredentialHash) plus 'proof' => 'valid'|'stale'|'invalid'|'missing'|'none'
     * @throws HttpException 401 | 403 | 410 | 429
     */
    public static function authenticate(#[\SensitiveParameter] ?string $authorization, string $mode, string $ip, string $endpoint, bool $proofRequired = false): array
    {
        $credential = self::credential($authorization);
        if ($credential === null) {
            if ($authorization === null && Request::header('X-PFPMS-Client') === 'station') {
                self::noteMissingHeader($ip, $endpoint);
            }
            throw new HttpException(401, "The server did not receive this tablet's key.", 'device_credential_missing');
        }
        $d = preg_match(self::FORMAT, $credential) === 1 ? DeviceRepository::byCredentialHash(Tokens::hash($credential)) : null;
        if ($d === null) {
            if (!RateLimit::hit('device_auth:ip:' . $ip, 20, 900)) {
                throw new HttpException(429, '', 'rate_limited', [], ['Retry-After' => '900']);
            }
            throw new HttpException(401, 'The server does not recognise this tablet.', 'device_unknown');
        }
        $id = (int) $d['device_id'];
        if ($d['wiped_at'] !== null) {
            throw new HttpException(410, 'This tablet has erased itself and is no longer registered.', 'wiped', ['status' => 'wiped'],
                ['Clear-Site-Data' => '"cache", "storage"']);
        }
        Audit::setActor(null, null, $d['site_id'] === null ? null : (int) $d['site_id'], $id);
        if ($d['revoked_at'] !== null) {
            self::revokedContact($d, $endpoint);
        }
        if ($mode === self::IN_SERVICE && !self::trusted($d)) {
            [$code, $message] = match (true) {
                $d['revoked_at'] !== null => ['device_revoked', 'This tablet has been taken out of service.'],
                (int) $d['in_service'] === 1 => ['device_site_inactive', "This tablet's site is not active. Ask a Coordinator."],
                default => ['device_not_registered', 'This tablet is not registered for use.'],
            };
            throw new HttpException(403, $message, $code, ['directive' => self::directive($d)]);
        }
        $d['proof'] = (int) $d['has_proof_key'] === 1
            ? DeviceProof::check($id, Request::header(DeviceProof::HEADER), Request::method(), $endpoint, Request::bodySha256(), Clock::now())
            : 'none';
        if ($proofRequired && !in_array($d['proof'], ['valid', 'none'], true)) {
            if ($d['proof'] === 'stale') {
                throw new HttpException(401, "The tablet's clock is too far from the server's. Try again.", 'device_proof_stale',
                    ['server_time' => Clock::dbMillis()]);
            }
            if (!RateLimit::hit('device_proof:device:' . $id, 10, 900)) {
                throw new HttpException(429, '', 'rate_limited', [], ['Retry-After' => '900']);
            }
            throw new HttpException(401, 'This tablet needs to be registered again. Ask a Coordinator.', 'device_proof_invalid');
        }
        if (!$proofRequired && in_array($d['proof'], ['missing', 'invalid'], true)) {
            self::noteUnproven($d, $endpoint, 'proof_' . $d['proof']);
        }
        return $d;
    }

    /** The credential in "PFPMS-Device <credential>" (the scheme in any case, one space), or null for a missing header or another scheme. */
    public static function credential(#[\SensitiveParameter] ?string $authorization): ?string
    {
        if ($authorization === null || !preg_match('/^PFPMS-Device (\S+)$/iD', $authorization, $m)) {
            return null;
        }
        return $m[1];
    }

    /** In service and at an active site: the only trust test (40-design §3.2). */
    public static function trusted(array $device): bool
    {
        return (int) ($device['in_service'] ?? 0) === 1 && (int) ($device['site_active'] ?? 0) === 1;
    }

    /** @return ?array{wipe: string} what a revoked tablet must do */
    public static function directive(array $device): ?array
    {
        return $device['revoked_at'] !== null ? ['wipe' => (string) $device['wipe_mode']] : null;
    }

    /** Clone signal (X-5): one audit row and one Administrator alert per tablet per hour. Never refuses. */
    public static function noteUnproven(array $device, string $endpoint, string $signal): void
    {
        $id = (int) $device['device_id'];
        if (!RateLimit::hit('device_unproven:' . $id, 1, 3600)) {
            return;
        }
        Audit::record('device_unproven', 'device', $id, 'Denied', "A request used this tablet's credential without the tablet's own key",
            ['endpoint' => $endpoint, 'signal' => $signal]);
        Notifications::toRoleOnce('Administrator', null, 'device_clone_suspected', self::name($device)
            . ": a request used this tablet's credential without the tablet's own key, or reported fewer records than before. Someone may"
            . ' have copied its registration. If so, retire it as lost or stolen.', 'device', $id);
    }

    /** A tablet taken out of service contacted the server: recorded and told to Administrators once an hour. */
    private static function revokedContact(array $device, string $endpoint): void
    {
        $id = (int) $device['device_id'];
        if (!RateLimit::hit('device_revoked_contact:' . $id, 1, 3600)) {
            return;
        }
        Audit::record('device_revoked_contact', 'device', $id, 'Denied', 'A tablet taken out of service contacted the server',
            ['endpoint' => $endpoint, 'wipe_mode' => $device['wipe_mode']]);
        Notifications::toRoleOnce('Administrator', null, 'device_revoked_contact', self::name($device)
            . ' contacted the server after it was taken out of service. It has been told to erase itself.', 'device', $id);
    }

    /**
     * A request marked as the Station's arrived without Authorization: the web host may be removing it (50-design §5.4).
     * Anyone can send such a request, so Administrators are alerted only when no tablet has authenticated in the last
     * 15 minutes (a host that strips the header makes that impossible); the audit row is written either way.
     */
    private static function noteMissingHeader(string $ip, string $endpoint): void
    {
        if (!RateLimit::hit('device_header_missing:all', 1, 3600)) {
            return;
        }
        Audit::record('device_header_missing', 'device', null, 'Failed', 'A tablet request reached the server without its Authorization header',
            ['ip' => $ip, 'endpoint' => $endpoint]);
        if (DeviceRepository::anySeenSince(Clock::db(Clock::now()->modify('-15 minutes')))) {
            return;
        }
        Notifications::toRoleOnce('Administrator', null, 'device_header_missing', 'Tablets may be reaching the server without their key: the'
            . ' web host may be removing the Authorization header, and then tablets cannot upload. Run bin/station-smoke.php to check'
            . ' (see the hosting notes).', 'device', 0);
    }

    private static function name(array $device): string
    {
        return $device['label'] . ' (' . ($device['site_name'] ?? 'no site') . ')';
    }
}
