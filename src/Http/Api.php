<?php
declare(strict_types=1);

namespace Pfpms\Http;

use LogicException;
use Pfpms\Audit\Audit;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Policy;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\SiteAccess;
use Pfpms\Auth\WebSession;
use Pfpms\Config;
use Pfpms\Device\DeviceGuard;
use Pfpms\Security\Csrf;

/**
 * The guard for JSON endpoints under public/api/ (plan §4; 50-design §5.1). Every refusal is thrown as an
 * HttpException, which ErrorHandler renders as the JSON error envelope. Order: maintenance → method → tablet
 * (optional) → session → gates → capability → site → CSRF. Station endpoints authenticate the tablet with
 * 'device' (40-design §14.2) and may require its proof signature with 'proof'; device-only endpoints use
 * 'session' => false and never start a PHP session. CSRF is verified for every non-GET request that uses a
 * session, anonymous included.
 */
final class Api
{
    /** The tablet authenticated in this request (DeviceGuard::authenticate), or null. */
    private static ?array $device = null;

    /**
     * @param array{
     *   method?: string|list<string>,
     *   device?: 'in_service'|'known',
     *   proof?: bool,
     *   session?: bool,
     *   public?: bool,
     *   touch?: bool,
     *   capability?: ?string,
     *   site?: bool,
     *   password_change?: bool,
     *   policy_ack?: bool
     * } $options
     *   method          allowed methods; default 'GET' (405 method_not_allowed with Allow otherwise)
     *   device          read "Authorization: PFPMS-Device <credential>" first (DeviceGuard); 'in_service' trusts only a
     *                   tablet in service at an active site, 'known' also answers a revoked one (with its directive)
     *   proof           require the tablet's PFPMS-Proof signature (when the tablet has a proof key)
     *   session         default true; false: no PHP session at all (needs device or public)
     *   public          no sign-in needed: null when anonymous, the Context (before the gates) when signed in
     *   touch           default true; false: validating does not extend the idle timer (polling endpoints)
     *   capability      required capability; site: one site must be selected
     *   password_change / policy_ack  this endpoint is allowed while that gate is pending
     */
    public static function start(array $options = []): ?Context
    {
        $session = ($options['session'] ?? true) !== false;
        if (!$session && empty($options['device']) && empty($options['public'])) {
            throw new LogicException("Api::start(['session' => false]) needs 'device' or 'public'");
        }
        if (Config::get('app.maintenance') === true) {
            throw new HttpException(503, 'The system is being updated. Please try again in a few minutes.', 'maintenance', [], ['Retry-After' => '120']);
        }
        $method = Request::method();
        $allowed = (array) ($options['method'] ?? 'GET');
        if (!in_array($method, $allowed, true)) {
            throw new HttpException(405, '', 'method_not_allowed', [], ['Allow' => implode(', ', $allowed)]);
        }
        Audit::setActor(null);
        self::$device = null;
        if (!empty($options['device'])) {
            self::$device = DeviceGuard::authenticate(Request::authorization(), (string) $options['device'], Request::ip(),
                Request::scriptPath(), !empty($options['proof']));
        }
        if (!$session) {
            return null;
        }

        WebSession::start(self::$device !== null || Request::header('X-PFPMS-Client') === 'station');
        $checked = Page::session($options['touch'] ?? true, $ended);
        $decision = self::contextFor($checked, $ended, self::$device, [SiteAccess::class, 'canUseSite']);
        $ended = $decision['ended'];
        if ($decision['end_session'] && $checked !== null) {
            SessionStore::end((string) $checked['session']['session_id'], 'Permission Change');
            WebSession::restart();
        }
        $ctx = null;
        if ($checked !== null && $decision['kind'] !== 'absent') {
            $user = $checked['user'];
            $row = $checked['session'];
            $deviceId = $row['device_id'] === null ? null : (int) $row['device_id'];
            if ($decision['kind'] === 'tablet') {
                // A tablet's session keeps the tablet's site; it is never re-picked (50-design §5.1 step 9).
                $siteId = (int) $row['site_id'];
                $sites = SiteAccess::sitesFor($user);
            } else {
                [$siteId, $sites] = Page::pickSite($user, $row);
            }
            $ctx = new Context($user, (string) $row['session_id'], $siteId, $sites, $deviceId, (string) $row['auth_method'], self::$device);
        }

        if ($ctx === null) {
            if (!empty($options['public'])) {
                self::csrfIfNeeded($options, $method);
                return null;
            }
            throw new HttpException(401, self::messageFor($ended), self::reasonCode($ended));
        }
        Audit::setActor($ctx->userId(), $ctx->sessionId, $ctx->siteId, $ctx->deviceId);
        if (!empty($options['public'])) {
            self::csrfIfNeeded($options, $method);
            return $ctx;
        }
        if (empty($options['password_change']) && AccountRules::mustChangePassword($ctx->user)) {
            throw new HttpException(403, 'You must choose a new password first.', 'password_change_required');
        }
        if (empty($options['password_change']) && empty($options['policy_ack']) && Policy::acknowledgementRequired($ctx->user)) {
            throw new HttpException(403, 'Please read and accept the agreement first.', 'policy_ack_required');
        }
        $capability = $options['capability'] ?? null;
        if ($capability !== null && !$ctx->can($capability)) {
            // No transaction is open here, so the row commits at once. Not durable(): with a tablet as the actor its
            // device FK would have to see rows the main connection may hold (50-design D-51).
            Audit::record('access_denied', 'api', null, 'Denied', "Missing capability $capability", ['endpoint' => Request::scriptPath()]);
            throw new HttpException(403, '', 'forbidden');
        }
        if (!empty($options['site']) && $ctx->siteId === null) {
            throw new HttpException(409, 'Choose a site first.', 'site_required');
        }
        self::csrfIfNeeded($options, $method);
        return $ctx;
    }

    /**
     * The tablet authenticated in this request by start(['device' => …]): the DeviceRepository::byCredentialHash()
     * row (no secrets) plus 'proof'.
     */
    public static function device(): array
    {
        if (self::$device === null) {
            throw new LogicException('Api::device() needs Api::start([\'device\' => …]) first');
        }
        return self::$device;
    }

    /** start() verifies CSRF for every non-GET request that uses a session, anonymous included. */
    public static function needsCsrf(array $options, string $method): bool
    {
        return ($options['session'] ?? true) !== false && !in_array($method, ['GET', 'HEAD'], true);
    }

    /**
     * Whose session this is for this request (steps 8-9), as a pure decision.
     * @param ?array{user: array, session: array} $checked what Page::session() returned (null: no session)
     * @param ?string $ended its reason when null
     * @param ?array $device the tablet authenticated in this request
     * @param callable(array $user, int $siteId): bool $canUseSite
     * @return array{kind: 'tablet'|'web'|'absent', ended: ?string, end_session: bool}
     */
    public static function contextFor(?array $checked, ?string $ended, ?array $device, callable $canUseSite): array
    {
        if ($checked === null) {
            return ['kind' => 'absent', 'ended' => $ended, 'end_session' => false];
        }
        $sessionDevice = $checked['session']['device_id'] ?? null;
        $sessionDevice = $sessionDevice === null ? null : (int) $sessionDevice;
        if ($device !== null && $sessionDevice !== (int) $device['device_id']) {
            // A web session, or another tablet's, never counts as this Station's.
            return ['kind' => 'absent', 'ended' => 'ended', 'end_session' => false];
        }
        if ($sessionDevice !== null) {
            $siteId = $checked['session']['site_id'] ?? null;
            if ($siteId === null || !$canUseSite($checked['user'], (int) $siteId)) {
                return ['kind' => 'absent', 'ended' => 'ended', 'end_session' => true];
            }
            return ['kind' => 'tablet', 'ended' => null, 'end_session' => false];
        }
        return ['kind' => 'web', 'ended' => null, 'end_session' => false];
    }

    /** The 401 code for an ended reason (50-design §5.2). */
    public static function reasonCode(?string $ended): string
    {
        return match ($ended) {
            'ended' => 'session_ended',
            'timeout' => 'session_timeout',
            'account' => 'account_blocked',
            'device' => 'device_revoked',
            default => 'not_signed_in',
        };
    }

    private static function messageFor(?string $ended): string
    {
        return match ($ended) {
            'ended' => 'Your session has ended. Please sign in again.',
            'timeout' => 'You were signed out after a period of inactivity. Please sign in again.',
            'account' => 'Your account can no longer be used. Please contact an Administrator.',
            'device' => 'This tablet was taken out of service, so you were signed out.',
            default => HttpException::defaultMessage(401),
        };
    }

    private static function csrfIfNeeded(array $options, string $method): void
    {
        if (self::needsCsrf($options, $method)) {
            Csrf::verify();
        }
    }
}
