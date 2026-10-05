<?php
declare(strict_types=1);

namespace Pfpms\Station;

use Pfpms\Account\AccountRepository;
use Pfpms\Audit\Audit;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Auth;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Pin;
use Pfpms\Auth\Policy;
use Pfpms\Auth\Rbac;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Device\DeviceRepository;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\Context;
use Pfpms\Http\HttpException;
use Pfpms\Security\RateLimit;
use Pfpms\Settings;

/**
 * The Station's session services (50-design §6.5-§6.10, D-16, D-17, D-24, D-54): password sign-in, PIN switch, End shift and
 * logout, the in-app acknowledgement and forced password change. The endpoints call WebSession (login/regenerate/restart)
 * after these return. The DVK and a grant are released only by StationGate::release(), and only to a request the guard
 * proved came from the tablet (proof => true, single-use, §2.5a).
 */
final class StationAuth
{
    /**
     * An acceptance releases only this soon after its password sign-in: the tablet's own bound (session.js keeps the KEK
     * GATE_KEK_MS = 10 minutes at a gate, 10 more after a forced password change), plus one minute for the requests themselves.
     */
    public const GATE_RELEASE_MINUTES = 21;

    private const MISSING = 'Enter your username (or email) and password.';
    private const WRONG = 'That username or password is not correct.';
    private const LOCKED = 'This account is locked. Please try again later or contact an Administrator.';
    private const RATE = 'Too many sign-in attempts. Please wait a few minutes and try again.';
    private const PIN_UNAVAILABLE = 'Quick switching is not available for this person on this tablet now. Sign in with your password.';
    private const PIN_LOCKED = 'Too many wrong PINs. Sign in with your password.';
    private const POLICY_CHANGED = 'The agreement was updated while you were reading it. Please read the current version.';

    /**
     * POST api/auth/login.php. @return array{user_id: int, session_id: string, site_id: int, body: array} body without csrf
     * @throws HttpException 403 | 422 | 429 (and the guard's)
     */
    public static function login(?string $identifier, #[\SensitiveParameter] ?string $password, string $ip, array $device): array
    {
        if ($identifier === null || trim($identifier) === '' || $password === null || $password === '') {
            throw new HttpException(422, self::MISSING, 'login_failed'); // no bucket is spent
        }
        $deviceId = (int) $device['device_id'];
        $siteId = (int) $device['site_id'];
        $r = Auth::attemptStation($identifier, $password, $ip, $deviceId,
            static fn(array $user): ?string => StationGate::access($user, $device),
            static fn(array $user): array => StationGate::lockDevice($device), // first: a Retire committed already or waits for us
            static function (array $user, int $userId, array $locked) use ($deviceId, $siteId, $ip): array {
                $at = Clock::now();                       // the grant and the session share this instant (decidePolicy's test)
                StationGate::requireSiteActive($locked);  // the first plain read, after the account UPDATE (§2.1)
                $fresh = AccountRepository::lockShared($userId) ?? throw new HttpException(422, StationGate::UNUSABLE, 'account_unusable');
                $gate = StationGate::release($fresh, $locked, $at);                                 // re-check, then auth_token
                $ended = SessionStore::endOnlineForDevice($deviceId, 'User Switch', $userId);      // then user_session
                $elsewhere = SessionStore::endOnlineElsewhere($userId, $deviceId, 'User Switch', $userId);
                $sessionId = SessionStore::create($userId, $siteId, 'Password', $deviceId, Clock::db($at));
                Audit::record('login', 'user_account', $userId, 'Success', null, ['ip' => $ip, 'station' => true,
                    'sessions_ended' => $ended, 'sessions_elsewhere_ended' => $elsewhere,
                    'release' => $gate['gate'] ?? $gate['unavailable'] ?? 'released'],
                    actor: ['user_id' => $userId, 'session_id' => $sessionId, 'site_id' => $siteId]);
                return ['session_id' => $sessionId, 'user' => $fresh, 'gate' => $gate];
            });
        if (!$r['ok']) {
            throw self::loginRefusal($r, $device);
        }
        $done = $r['result'];
        return ['user_id' => (int) $done['user']['user_id'], 'session_id' => $done['session_id'], 'site_id' => $siteId, 'body' => [
            'ok' => true,
            'user' => self::publicUser($done['user']),
            'session_ref' => $done['session_id'],
            'gate' => $done['gate']['gate'],
            'release' => $done['gate']['release'],
            'release_unavailable' => $done['gate']['unavailable'],
            'revoked_grants' => Tokens::revokedGrantIds($deviceId),
            'config' => StationConfig::client(),
            'password_min_length' => max(8, Settings::int('password_min_length', 12)),
            'server_time' => Clock::dbMillis(),
            'build' => DeviceStatus::currentBuild(),
        ]];
    }

    /**
     * POST api/auth/pin.php. No keys in the answer (factual error #26). $ctx is whoever's session this tablet's cookie
     * carries (null when it ended): only its user id is used, as from_user_id.
     * @return array{user_id: int, session_id: string, site_id: int, body: array}
     */
    public static function pinSwitch(?int $userId, #[\SensitiveParameter] string $pin, array $device, ?Context $ctx, string $ip): array
    {
        $deviceId = (int) $device['device_id'];
        $siteId = (int) $device['site_id'];
        if (!RateLimit::hit("pin:device:$deviceId", 60, 900)) {
            throw new HttpException(429, '', 'rate_limited', [], ['Retry-After' => '900']);
        }
        [$min, $max] = Pin::digitsRange();
        if (!preg_match('/^\d{4,6}$/D', $pin) || strlen($pin) < $min || strlen($pin) > $max) {
            throw new HttpException(422, 'That PIN is not correct.', 'pin_wrong'); // no counter change, no row
        }
        $target = $userId === null ? null : Pin::target($userId);
        [$reason, $window] = self::pinRefusal($target, $device);
        if ($reason !== null) {
            self::pinDenied($target, $reason, $ip);
            throw new HttpException(422, self::PIN_UNAVAILABLE, 'pin_unavailable');
        }
        $uid = (int) $target['user_id'];
        $maxFailed = max(1, Settings::int('pin_max_failed', 3));
        $count = Pin::reserveAttempt($uid, $maxFailed);
        if ($count === null) {
            self::pinDenied($target, 'locked', $ip);
            throw new HttpException(422, self::PIN_LOCKED, 'pin_locked');
        }
        if (Pin::$afterReserve !== null && Config::env() === 'test') {
            (Pin::$afterReserve)($uid, $count);
        }
        if (!Pin::verify($uid, $pin, (string) $target['pin_hash'])) {
            $left = max(0, $maxFailed - $count);
            Audit::record('pin_switch', 'user_account', $uid, 'Failed', 'Wrong PIN', ['reason_code' => 'wrong_pin', 'tries_left' => $left, 'ip' => $ip],
                actor: ['user_id' => $uid, 'session_id' => null]);
            throw new HttpException(422, self::wrongPin($left), 'pin_wrong', ['tries_left' => $left]);
        }
        $done = Db::transaction(static function () use ($uid, $deviceId, $siteId, $device, $ctx, $target): array {
            $locked = StationGate::lockDevice($device);
            Db::pdo()->prepare('UPDATE user_account SET pin_failed_count = 0 WHERE user_id = ?')->execute([$uid]); // also ends the reservation
            StationGate::requireSiteActive($locked);
            $fresh = Pin::target($uid);
            [$again, $window] = self::pinRefusal($fresh, $device);
            // Revoked, gated or blocked since the check, or the PIN changed while the old one was being checked: refused.
            if ($again !== null || !hash_equals((string) $target['pin_hash'], (string) ($fresh['pin_hash'] ?? ''))) {
                throw new HttpException(422, self::PIN_UNAVAILABLE, 'pin_unavailable');
            }
            $ended = SessionStore::endOnlineForDevice($deviceId, 'PIN Switch', $uid);
            $elsewhere = SessionStore::endOnlineElsewhere($uid, $deviceId, 'User Switch', $uid);
            $sessionId = SessionStore::create($uid, $siteId, 'PIN', $deviceId);
            Audit::record('pin_switch', 'user_account', $uid, 'Success', null, ['from_user_id' => $ctx?->userId(),
                'grant_id' => $window['token_id'], 'sessions_ended' => $ended, 'sessions_elsewhere_ended' => $elsewhere],
                actor: ['user_id' => $uid, 'session_id' => $sessionId, 'site_id' => $siteId]);
            return ['session_id' => $sessionId, 'user' => $fresh];
        });
        return ['user_id' => $uid, 'session_id' => $done['session_id'], 'site_id' => $siteId, 'body' => [
            'ok' => true, 'user' => self::publicUser($done['user']), 'session_ref' => $done['session_id'], 'gate' => null,
            'revoked_grants' => Tokens::revokedGrantIds($deviceId), 'server_time' => Clock::dbMillis(), 'build' => DeviceStatus::currentBuild(),
        ]];
    }

    /**
     * POST api/auth/logout.php (50-design §6.8).
     * @return array{ended_current: bool, body: array{ok: true, server_time: string}} ended_current: the session this PHP session
     *   carries was ended here; only then does the endpoint restart the PHP session (a replay must never sign out the person
     *   who signed in after the End shift it replays)
     */
    public static function logout(?Context $ctx, array $device, ?string $scope, ?string $endedAt): array
    {
        if ($scope !== 'user' && $scope !== 'device') {
            throw new HttpException(400, 'Unknown scope.', 'bad_request', ['field' => 'scope']);
        }
        $deviceId = (int) $device['device_id'];
        if ($scope === 'user') {
            if ($ctx === null) {
                return ['ended_current' => false, 'body' => ['ok' => true, 'server_time' => Clock::dbMillis()]];
            }
            Db::transaction(static function () use ($ctx, $deviceId): void {
                DeviceRepository::lockShared($deviceId);        // device S, then the account S, then the session (§2.1 FK edges);
                AccountRepository::lockShared($ctx->userId());  // no in-service test: a retiring tablet may still sign out
                SessionStore::end($ctx->sessionId, 'Logout', $ctx->userId());
                Audit::record('logout', 'user_account', $ctx->userId(), 'Success', null, ['station' => true]);
            });
            return ['ended_current' => true, 'body' => ['ok' => true, 'server_time' => Clock::dbMillis()]];
        }
        $now = Clock::now();
        $replay = $endedAt !== null;                                   // an End shift made offline, sent with its own time
        $at = $replay ? (Clock::fromClient($endedAt) ?? $now) : $now;  // unparsable counts as now
        if ($at > $now) {
            $at = $now;
        }
        $endedCurrent = Db::transaction(static function () use ($deviceId, $at, $replay, $ctx): bool {
            DeviceRepository::setShiftEnded($deviceId, Clock::db($at));  // device X
            if ($ctx !== null) {
                AccountRepository::lockShared($ctx->userId());           // before any session row (the ended_by and audit FKs, §2.1)
            }
            // A replay ends only sessions started before its time and names nobody: the person now at the tablet may be another.
            $n = SessionStore::endOnlineForDevice($deviceId, 'Device Lock', $replay ? null : $ctx?->userId(), $replay ? Clock::db($at) : null);
            Audit::record('device_lock', 'device', $deviceId, 'Success', null, ['sessions_ended' => $n, 'replayed' => $replay]);
            $own = $ctx === null ? null : SessionStore::row($ctx->sessionId);
            return $own !== null && $own['ended_at'] !== null;
        });
        return ['ended_current' => $endedCurrent, 'body' => ['ok' => true, 'server_time' => Clock::dbMillis()]];
    }

    /** GET api/auth/policy.php. */
    public static function policyDocument(Context $ctx): array
    {
        $doc = Policy::current(Policy::CONFIDENTIALITY);
        return ['required' => Policy::acknowledgementRequired($ctx->user), 'document' => $doc === null ? null : [
            'document_id' => (int) $doc['document_id'], 'version' => (string) $doc['version'], 'language' => (string) $doc['language_code'],
            'body' => (string) $doc['body'], 'fingerprint' => Policy::fingerprint($doc),
        ], 'server_time' => Clock::dbMillis()];
    }

    /**
     * POST api/auth/policy.php. Only the exact wording shown may be accepted (document_id + fingerprint). Accept: the grant in
     * the same transaction, but only to complete a password sign-in its gate held back (a Password session started at most
     * GATE_RELEASE_MINUTES ago with no grant since): an acceptance never stands in for a password (D-24), so a PIN session or
     * a sign-in already released gets release_unavailable 'password_needed'. Decline: the session ends, nothing is released.
     * @return array{signed_out: bool, body: array} (the endpoint regenerates or restarts the session)
     */
    public static function decidePolicy(Context $ctx, array $device, ?int $documentId, string $fingerprint, string $decision): array
    {
        if ($decision !== 'accept' && $decision !== 'decline') {
            throw new HttpException(400, 'Unknown decision.', 'bad_request', ['field' => 'decision']);
        }
        $doc = Policy::current(Policy::CONFIDENTIALITY);
        if ($doc === null || $documentId !== (int) $doc['document_id'] || !hash_equals(Policy::fingerprint($doc), $fingerprint)) {
            throw new HttpException(409, self::POLICY_CHANGED, 'policy_changed');
        }
        $uid = $ctx->userId();
        $deviceId = (int) $device['device_id'];
        if ($decision === 'decline') {
            Db::transaction(static function () use ($ctx, $uid, $doc, $deviceId): void {
                DeviceRepository::lockShared($deviceId); // device S, then the account S, then the session (§2.1 FK edges)
                AccountRepository::lockShared($uid);
                Audit::record('policy_decline', 'policy_document', (int) $doc['document_id'], 'Denied', 'Declined the confidentiality agreement', ['source' => 'station']);
                SessionStore::end($ctx->sessionId, 'Logout', $uid);
            });
            return ['signed_out' => true, 'body' => ['ok' => true, 'signed_out' => true, 'server_time' => Clock::dbMillis()]];
        }
        $gate = Db::transaction(static function () use ($uid, $doc, $device, $ctx): array {
            $locked = StationGate::lockDevice($device);
            $user = AccountRepository::lockShared($uid) ?? throw new HttpException(422, StationGate::UNUSABLE, 'account_unusable');
            StationGate::requireSiteActive($locked);                     // the first plain read (§2.1)
            $session = StationGate::requireSessionOpen($ctx->sessionId); // ended meanwhile (an access change, End shift): 401
            if (Policy::acknowledgementRequired($user)) { // else accepted meanwhile (another tablet, the web): release only
                Policy::acknowledge($uid, (int) $doc['document_id']);
                Audit::record('policy_acknowledge', 'policy_document', (int) $doc['document_id'], 'Success', null,
                    ['doc_type' => $doc['doc_type'], 'version' => $doc['version'], 'language' => $doc['language_code'], 'source' => 'station']);
            }
            $started = Clock::fromDb((string) $session['started_at']) ?? Clock::now();
            if ($session['auth_method'] !== 'Password' || $started < Clock::now()->modify('-' . self::GATE_RELEASE_MINUTES . ' minutes')
                || OfflineGrants::issuedSince($uid, (int) $locked['device_id'], (string) $session['started_at'])) {
                return ['gate' => null, 'release' => null, 'unavailable' => 'password_needed']; // no grant without a fresh password
            }
            return StationGate::release($user, $locked);
        });
        return ['signed_out' => false, 'body' => self::released($gate, $deviceId)];
    }

    /**
     * POST api/auth/password.php (D-54): the new password is hashed first; Tx1 re-checks under the account lock
     * (Auth::lockVerified) and writes it (revoking the person's grants, codes and links, and ending their other sessions), Tx2
     * separately releases (a grant INSERT may not follow Tx1's session updates).
     */
    public static function changePassword(Context $ctx, array $device, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $new, string $ip): array
    {
        $uid = $ctx->userId();
        if (!RateLimit::hit("pw_change:user:$uid", 5, 900)) {
            throw new HttpException(429, '', 'rate_limited', [], ['Retry-After' => '900']);
        }
        $verified = Auth::verifiedHash($uid, $current);
        if ($verified === null) {
            Audit::record('password_change', 'user_account', $uid, 'Failed', 'Wrong current password', ['source' => 'station', 'ip' => $ip]);
            throw new HttpException(422, 'The current password is not correct.', 'login_failed');
        }
        if (hash_equals($current, $new)) {
            throw self::invalid('Choose a password different from your current one.');
        }
        $problems = PasswordPolicy::check($new, $ctx->user);
        if ($problems !== []) {
            throw self::invalid(implode(' ', $problems));
        }
        $hash = PasswordPolicy::hash($new); // ≈0.4 s: before Tx1, which holds the tablet's and the account's locks
        Db::transaction(static function () use ($uid, $hash, $ctx, $device, $verified): void {
            StationGate::lockDevice($device); // device S first (§2.1: the audit row's FK); a tablet retired meanwhile keeps the old password
            // Then the account X and the session: a reset (or a deactivation, or another change) committed since the verify wins.
            $account = Auth::lockVerified($uid, $ctx->sessionId, $verified);
            Auth::setPasswordHash($uid, $hash, 'Password Reset', $ctx->sessionId);
            Audit::record('password_change', 'user_account', $uid, 'Success', AccountRules::mustChangePassword($account) ? 'Forced change' : null,
                ['source' => 'station']);
        });
        $deviceId = (int) $device['device_id'];
        $gate = Db::transaction(static function () use ($uid, $device, $ctx): array {
            $locked = StationGate::lockDevice($device);
            $user = AccountRepository::lockShared($uid) ?? throw new HttpException(422, StationGate::UNUSABLE, 'account_unusable');
            StationGate::requireSiteActive($locked);
            StationGate::requireSessionOpen($ctx->sessionId); // Tx1 kept it; anything else that ended it since: 401
            return StationGate::release($user, $locked);      // a password was just proved: no freshness test (unlike an acceptance)
        });
        return self::released($gate, $deviceId);
    }

    /** The person as the Station may know them: never a hash, a PIN or a token (REQ-29). */
    public static function publicUser(array $user): array
    {
        $role = (string) $user['role'];
        $display = trim((string) ($user['display_name'] ?? ''));
        return [
            'user_id' => (int) $user['user_id'],
            'username' => (string) $user['username'],
            'email' => (string) $user['email'],
            'display_name' => $display !== '' ? $display : trim($user['first_name'] . ' ' . $user['last_name']),
            'role' => $role,
            'capabilities' => Rbac::capabilities($role),
            'offline_caps' => StationGate::offlineCapabilities($role),
            'pin_switch' => Rbac::can($role, 'auth.pin_switch'),
            'has_pin' => Pin::hasPin((int) $user['user_id']),
        ];
    }

    /** @return array{0: ?string, 1: ?array} the refusal's reason_code (null: allowed) and the PIN-window grant */
    private static function pinRefusal(?array $target, array $device): array
    {
        if ($target === null) {
            return ['not_found', null];
        }
        if (!AccountRules::canHoldSession($target)) {
            return ['account', null];
        }
        if (AccountRules::mustChangePassword($target) || Policy::acknowledgementRequired($target)) {
            return ['gate', null];
        }
        if (!Rbac::can((string) $target['role'], 'auth.pin_switch')) {
            return ['capability', null];
        }
        if (StationGate::access($target, $device) !== null) {
            return ['access', null];
        }
        if ($target['pin_hash'] === null) {
            return ['no_pin', null];
        }
        $window = OfflineGrants::pinWindow((int) $target['user_id'], (int) $device['device_id'], Settings::int('pin_shift_hours', 12));
        return $window === null ? ['no_window', null] : [null, $window];
    }

    private static function pinDenied(?array $target, string $reason, string $ip): void
    {
        $uid = $target === null ? null : (int) $target['user_id'];
        Audit::record('pin_switch', 'user_account', $uid, 'Denied', $reason === 'locked' ? 'Too many wrong PINs' : 'Quick switching not available',
            ['reason_code' => $reason, 'ip' => $ip], actor: ['user_id' => $uid, 'session_id' => null]);
    }

    private static function wrongPin(int $left): string
    {
        return match (true) {
            $left <= 0 => self::PIN_LOCKED,
            $left === 1 => 'Wrong PIN. 1 try left before a password is needed.',
            default => "Wrong PIN. $left tries left before a password is needed.",
        };
    }

    private static function loginRefusal(array $r, array $device): HttpException
    {
        return match ($r['code']) {
            'rate_limited' => new HttpException(429, self::RATE, 'rate_limited', [], ['Retry-After' => '900']),
            'locked' => new HttpException(422, self::LOCKED, 'account_locked'),
            'inactive', 'expired', 'not_started' => new HttpException(422, StationGate::UNUSABLE, 'account_unusable'),
            'no_access' => StationGate::noAccess((string) $r['reason'], $device),
            default => new HttpException(422, self::WRONG, 'login_failed'),
        };
    }

    private static function invalid(string $message): HttpException
    {
        return new HttpException(422, $message, 'invalid', ['errors' => ['new_password' => $message]]);
    }

    /** The answer of an acceptance or a password change: the release (or why none), and this tablet's revoked grants. */
    private static function released(array $gate, int $deviceId): array
    {
        return ['ok' => true, 'gate' => $gate['gate'], 'release' => $gate['release'], 'release_unavailable' => $gate['unavailable'],
            'revoked_grants' => Tokens::revokedGrantIds($deviceId), 'server_time' => Clock::dbMillis()];
    }
}
