<?php
declare(strict_types=1);

namespace Pfpms\Device;

use Pfpms\Account\AccountRepository;
use Pfpms\Audit\Audit;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\HttpException;
use Pfpms\Notify\Notifications;
use Pfpms\Reference\SiteRepository;
use Pfpms\Security\Crypto;
use Pfpms\Security\RateLimit;
use Pfpms\Settings;
use Pfpms\Station\StationConfig;
use Pfpms\Validation\ValidationException;
use RuntimeException;

/**
 * The installed Station redeems a registration code for the tablet's credential (40-design §14.3;
 * 50-design §6.3, X-3). The server makes the credential, derived from the code's token and a nonce only the
 * tablet knows, so a response lost on bad Wi-Fi can be asked for again with the same body within
 * REPLAY_MINUTES and gets the same credential. The tablet also sends its proof key once (X-1).
 *
 * Lock order: named device:<id> → device row → user_account (shared) → auth_token (consume) → device UPDATE.
 * Every refusal after the format checks is the same 422; its reason is kept only in a durable Denied row,
 * written after the lock and transaction are gone. The code, nonce, proof key and credential are never
 * audited or logged.
 */
final class DeviceRegistration
{
    public const REPLAY_MINUTES = 15;

    private const INVALID = 'This code is not valid. It may have expired or already been used. Ask a Coordinator for a new registration sheet.';
    private const DISPLAY_MODES = ['standalone', 'browser', 'minimal-ui', 'fullscreen'];

    /**
     * @param array{display_mode: ?string, storage_persisted: bool, app_build: ?string, pbkdf2_iterations: ?int} $facts
     * @return array{device_id: int, site: array{site_id: int, name: string}, label: string, credential: string,
     *   pbkdf2_iterations: int, replayed: bool, server_time: string, build: string}
     * @throws HttpException 400 | 409 | 422 | 429
     */
    public static function redeem(#[\SensitiveParameter] ?string $code, #[\SensitiveParameter] ?string $nonce,
        #[\SensitiveParameter] ?string $proofKey, array $facts, string $ip): array
    {
        if (!RateLimit::hit('device_register:ip:' . $ip, 10, 900)) {
            self::deny('rate_limited', $ip, null);
            throw new HttpException(429, '', 'rate_limited', [], ['Retry-After' => '900']);
        }
        $canonical = RegistrationCode::normalise($code);
        if ($canonical === null) {
            throw new HttpException(422, 'Check the code: one of the characters looks wrong.', 'code_mistyped');
        }
        if (!RateLimit::hit('device_register:all', 100, 3600)) {
            Notifications::toRoleOnce('Administrator', null, 'device_register_limit', 'Many tablet registration codes were tried in the last'
                . ' hour. If no tablets are being set up, someone may be guessing codes.', 'device_register', 0);
        }
        $nonceText = $nonce !== null && preg_match('/^[A-Za-z0-9_-]{22}$/D', $nonce) === 1 && Crypto::unb64urlStrict($nonce, 16) !== null ? $nonce : null;
        if ($nonceText === null) {
            throw new HttpException(400, 'The tablet sent an unusable request. Close the app, open it again and scan the code again.', 'bad_request',
                ['field' => 'registration_nonce']);
        }
        $proofKeyRaw = $proofKey !== null && preg_match('/^[A-Za-z0-9_-]{43}$/D', $proofKey) === 1 ? Crypto::unb64urlStrict($proofKey, 32) : null;
        if ($proofKeyRaw === null) {
            throw new HttpException(400, 'The tablet sent an unusable request. Close the app, open it again and scan the code again.', 'bad_request',
                ['field' => 'proof_key']);
        }
        $facts = self::facts($facts);
        if ($facts['display_mode'] !== 'standalone' && !StationConfig::relaxInstallChecks()) {
            throw new HttpException(409, 'Open the installed app from the home screen, then scan the code again.', 'not_installed');
        }
        $token = Tokens::find($canonical, Tokens::DEVICE_REGISTRATION);
        if ($token === null || $token['device_id'] === null) {
            $replayed = self::replay($canonical, $nonceText, $proofKeyRaw);
            if ($replayed !== null) {
                return $replayed;
            }
            self::deny('not_found', $ip, null);
            throw new HttpException(422, self::INVALID, 'code_invalid');
        }
        return self::redeemFound($token, $nonceText, $proofKeyRaw, $facts, $ip);
    }

    /**
     * The locked part, for a token row found without a lock (tests find a token, change the world, then call this).
     * @param array{display_mode: ?string, storage_persisted: bool, app_build: ?string, pbkdf2_iterations: ?int} $facts
     */
    public static function redeemFound(array $token, #[\SensitiveParameter] string $nonce, #[\SensitiveParameter] string $proofKeyRaw,
        array $facts, string $ip): array
    {
        $deviceId = (int) $token['device_id'];
        try {
            return DeviceLocks::device($deviceId, fn(): array => Db::transaction(fn(): array => self::inTransaction($token, $nonce, $proofKeyRaw, self::facts($facts))));
        } catch (ValidationException) {
            throw new HttpException(409, 'Someone else is changing this tablet right now. Wait a few seconds and try again.', 'busy');
        } catch (DeviceRefusal $e) {
            self::deny($e->reasonCode, $ip, $deviceId);
            throw new HttpException(422, self::INVALID, 'code_invalid');
        }
    }

    /** 'pfd1_' . b64url(HMAC(HKDF(active key, 'pfpms/v1/device-credential'), 'pfpms/v1/device-credential|<token_id>|<nonce>')) */
    public static function credentialFor(int $tokenId, #[\SensitiveParameter] string $nonce): string
    {
        [, $key] = Crypto::derivedKey('pfpms/v1/device-credential');
        return 'pfd1_' . Crypto::b64url(Crypto::hmac($key, "pfpms/v1/device-credential|$tokenId|$nonce"));
    }

    private static function inTransaction(array $token, #[\SensitiveParameter] string $nonce, #[\SensitiveParameter] string $proofKeyRaw, array $facts): array
    {
        $id = (int) $token['device_id'];
        $d = DeviceRepository::lock($id);
        if ($d === null || !DeviceStatus::waitingForRegistration($d)) {
            throw new DeviceRefusal('device_not_waiting');
        }
        // The creator as committed now, in the order account changes take it: a code never outlives their access.
        $issuer = AccountRepository::lockShared((int) $token['user_id']);
        $scope = $issuer !== null ? DeviceScope::forUser($issuer) : null;
        if ($issuer === null || $scope === null || !AccountRules::canHoldSession($issuer) || AccountRules::mustChangePassword($issuer)
            || !$scope->can('device.register') || $d['site_id'] === null || !$scope->canAddAt((int) $d['site_id'])) {
            throw new DeviceRefusal('issuer_not_allowed');
        }
        if (Tokens::consume((int) $token['token_id']) !== true) {
            throw new DeviceRefusal('code_used');
        }
        $credential = self::credentialFor((int) $token['token_id'], $nonce);
        $dvk = random_bytes(32);
        $now = Clock::db();
        if (!DeviceRepository::register($id, Tokens::hash($credential), Crypto::encrypt($dvk, "device:$id:dvk"), Crypto::encrypt($proofKeyRaw, "device:$id:proof"),
            (int) $token['user_id'], $now, $facts['storage_persisted'], $facts['display_mode'], $facts['app_build'], $facts['pbkdf2_iterations'])) {
            throw new DeviceRefusal('device_not_waiting');
        }
        $siteId = (int) $d['site_id'];
        $siteName = (string) (SiteRepository::find($siteId)['name'] ?? '');
        Audit::record('device_register', 'device', $id, details: ['issued_by' => (int) $token['user_id'], 'app_build' => $facts['app_build'],
            'display_mode' => $facts['display_mode'], 'storage_persisted' => $facts['storage_persisted'], 'pbkdf2_iterations' => $facts['pbkdf2_iterations']],
            actor: ['site_id' => $siteId, 'device_id' => $id]);
        Notifications::toUser((int) $token['user_id'], 'device_registered', "{$d['label']} at $siteName was registered with the code you created."
            . ' If that was not your tablet, retire it at once.', 'device', $id);
        return self::body($id, $siteId, $siteName, (string) $d['label'], $credential, $facts['pbkdf2_iterations'], false);
    }

    /**
     * The same code, nonce and proof key again, within REPLAY_MINUTES of the registration, on a tablet still in service:
     * the answer the tablet may not have received (X-3).
     */
    private static function replay(#[\SensitiveParameter] string $code, #[\SensitiveParameter] string $nonce, #[\SensitiveParameter] string $proofKeyRaw): ?array
    {
        $token = Tokens::findAny($code, Tokens::DEVICE_REGISTRATION);
        if ($token === null || $token['used_at'] === null || $token['device_id'] === null) {
            return null;
        }
        $credential = self::credentialFor((int) $token['token_id'], $nonce);
        $d = DeviceRepository::byCredentialHash(Tokens::hash($credential));
        if ($d === null || (int) $d['device_id'] !== (int) $token['device_id'] || !DeviceGuard::trusted($d) || $d['registered_at'] === null
            || Clock::fromDb((string) $d['registered_at']) < Clock::now()->modify('-' . self::REPLAY_MINUTES . ' minutes')) {
            return null;
        }
        $sealed = DeviceRepository::proofKeyCiphertext((int) $d['device_id']);
        try {
            $stored = $sealed === null ? null : Crypto::decrypt($sealed, "device:{$d['device_id']}:proof");
        } catch (RuntimeException) {
            $stored = null;
        }
        if ($stored === null || !hash_equals($stored, $proofKeyRaw)) {
            return null;
        }
        $id = (int) $d['device_id'];
        Audit::record('device_register_replay', 'device', $id, details: ['issued_by' => (int) $token['user_id']],
            actor: ['site_id' => (int) $d['site_id'], 'device_id' => $id]);
        return self::body($id, (int) $d['site_id'], (string) $d['site_name'], (string) $d['label'], $credential,
            $d['pbkdf2_iterations'] === null ? null : (int) $d['pbkdf2_iterations'], true);
    }

    /** @return array{display_mode: ?string, storage_persisted: bool, app_build: ?string, pbkdf2_iterations: ?int} */
    private static function facts(array $facts): array
    {
        $mode = $facts['display_mode'] ?? null;
        $build = $facts['app_build'] ?? null;
        $rounds = $facts['pbkdf2_iterations'] ?? null;
        return [
            'display_mode' => $mode === null ? null : (in_array($mode, self::DISPLAY_MODES, true) ? $mode : 'other'),
            'storage_persisted' => ($facts['storage_persisted'] ?? false) === true,
            'app_build' => is_string($build) && preg_match('/^[0-9A-Za-z.+_-]{1,40}$/D', $build) === 1 ? $build : null,
            'pbkdf2_iterations' => is_int($rounds) && $rounds >= 100000 && $rounds <= 2000000 ? $rounds : null,
        ];
    }

    private static function body(int $id, int $siteId, string $siteName, string $label, #[\SensitiveParameter] string $credential, ?int $rounds, bool $replayed): array
    {
        return ['device_id' => $id, 'site' => ['site_id' => $siteId, 'name' => $siteName], 'label' => $label, 'credential' => $credential,
            'pbkdf2_iterations' => $rounds ?? Settings::int('offline_pbkdf2_iterations', 600000), 'replayed' => $replayed,
            'server_time' => Clock::dbMillis(), 'build' => DeviceStatus::currentBuild()];
    }

    /**
     * A durable Denied row, written after the lock and transaction are gone. The actor is anonymous and every FK column
     * is set to null explicitly, so the row can never wait on a tablet or person the main connection holds.
     */
    private static function deny(string $reasonCode, string $ip, ?int $deviceId): void
    {
        Audit::durable('device_register', 'device', $deviceId, 'Denied', 'Tablet registration refused', ['reason_code' => $reasonCode, 'ip' => $ip],
            ['user_id' => null, 'session_id' => null, 'site_id' => null, 'device_id' => null]);
    }
}
