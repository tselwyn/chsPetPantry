<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Device;

use Pfpms\Account\AccountRepository;
use Pfpms\Audit\Audit;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Device\DeviceGuard;
use Pfpms\Device\DeviceProof;
use Pfpms\Device\DeviceRepository;
use Pfpms\Device\DeviceScope;
use Pfpms\Device\DeviceService;
use Pfpms\Http\HttpException;
use Pfpms\Http\Request;
use Pfpms\Security\Crypto;
use Pfpms\Tests\TestCase;

/**
 * The Station tablet guard (40-design §14.2; 50-design §5.1 step 4, §5.4, §5.6 and §12.1 DeviceGuard): the credential in
 * "Authorization: PFPMS-Device …", trust (IN_SERVICE_SQL at an active site), the proof signature, and the alerts it raises.
 * Every call goes through DeviceGuard::authenticate() with the request state in $_SERVER, as Api::start() calls it.
 */
final class DeviceGuardTest extends TestCase
{
    private const IP = '203.0.113.7';
    private const OTHER_IP = '198.51.100.9';
    private const HEARTBEAT = 'api/device/heartbeat.php';
    private const LOGIN = 'api/auth/login.php';
    private const SERVER_KEYS = ['REQUEST_METHOD', 'SCRIPT_NAME', 'REMOTE_ADDR', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION',
        'HTTP_PFPMS_PROOF', 'HTTP_X_PFPMS_CLIENT', 'CONTENT_TYPE', 'CONTENT_LENGTH'];
    /** The hosting alert (50-design §5.4) as reworded in the S1 review: a missing header only suggests the host strips it. */
    private const HEADER_ALERT = 'Tablets may be reaching the server without their key: the web host may be removing the Authorization header,'
        . ' and then tablets cannot upload. Run bin/station-smoke.php to check (see the hosting notes).';

    private int $north;
    private array $admin;
    private array $coordinator;
    private array $savedConfig = [];
    private array $savedServer = [];
    private string|false $savedIgnoreArgs = false;
    /** The newest audit and notification rows before the test: durable rows from other tests may already be committed. */
    private int $auditMark = 0;
    private int $notificationMark = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditMark = (int) $this->scalar('SELECT COALESCE(MAX(audit_id), 0) FROM audit_log');
        $this->notificationMark = (int) $this->scalar('SELECT COALESCE(MAX(notification_id), 0) FROM notification');
        foreach (self::SERVER_KEYS as $key) {
            if (array_key_exists($key, $_SERVER)) {
                $this->savedServer[$key] = $_SERVER[$key];
            }
            unset($_SERVER[$key]);
        }
        $this->savedConfig = Config::snapshot();
        Config::override(['app' => ['base_path' => '/'] + (array) ($this->savedConfig['app'] ?? [])] + $this->savedConfig);
        $this->savedIgnoreArgs = ini_get('zend.exception_ignore_args');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['SCRIPT_NAME'] = '/' . self::HEARTBEAT;
        $_SERVER['REMOTE_ADDR'] = self::IP;
        Request::useBody('{}', 'application/json');

        $this->north = $this->makeSite('Northside');
        $this->admin = $this->makeUser(['role' => 'Administrator']);
        $this->coordinator = $this->makeUser(['role' => 'Coordinator']);
        Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, starts_at, granted_by) VALUES (?, ?, ?, ?)')
            ->execute([$this->coordinator['user_id'], $this->north, '2026-01-01 00:00:00', $this->admin['user_id']]);
    }

    protected function tearDown(): void
    {
        Request::useBody(null);
        foreach (self::SERVER_KEYS as $key) {
            unset($_SERVER[$key]);
        }
        foreach ($this->savedServer as $key => $value) {
            $_SERVER[$key] = $value;
        }
        Config::override($this->savedConfig);
        if ($this->savedIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->savedIgnoreArgs);
        }
        Audit::setActor(null);
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    /** A credential in DeviceGuard::FORMAT, as DeviceRegistration::credentialFor() makes them. */
    private function newCredential(): string
    {
        return 'pfd1_' . Crypto::b64url(random_bytes(32));
    }

    /** @return array{0: int, 1: string} a tablet in service at Northside (unless overridden) and its credential */
    private function tablet(array $overrides = []): array
    {
        static $n = 0;
        $n++;
        $credential = $this->newCredential();
        $id = $this->makeDevice($this->north, $overrides + ['token_hash' => Tokens::hash($credential), 'is_site_registered' => 1, 'label' => "Front desk $n"]);
        return [$id, $credential];
    }

    /** Give the tablet a proof key, as registration stores it (the AAD names the row, so it is set after the insert). */
    private function giveProofKey(int $deviceId): string
    {
        $key = random_bytes(32);
        Db::pdo()->prepare('UPDATE device SET proof_key_ciphertext = ? WHERE device_id = ?')->execute([Crypto::encrypt($key, "device:$deviceId:proof"), $deviceId]);
        return $key;
    }

    /** A PFPMS-Proof header value signed with $key (by default for this POST to the heartbeat with its '{}' body, now). */
    private function sign(string $key, ?int $tsMs = null, string $method = 'POST', string $endpoint = self::HEARTBEAT, string $body = '{}'): string
    {
        $ts = $tsMs ?? (int) Clock::now()->format('Uv');
        return "v1 $ts " . Crypto::b64url(Crypto::hmac($key, DeviceProof::message($method, $endpoint, $ts, hash('sha256', $body))));
    }

    /** The request carries this Authorization header (null: none) and PFPMS-Proof header (null: none). */
    private function send(?string $authorization, ?string $proof = null): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_PFPMS_PROOF']);
        if ($authorization !== null) {
            $_SERVER['HTTP_AUTHORIZATION'] = $authorization;
        }
        if ($proof !== null) {
            $_SERVER['HTTP_PFPMS_PROOF'] = $proof;
        }
    }

    private function sendCredential(string $credential, ?string $proof = null): void
    {
        $this->send('PFPMS-Device ' . $credential, $proof);
    }

    /** DeviceGuard::authenticate() exactly as Api::start() step 4 calls it. */
    private function guard(string $mode = DeviceGuard::KNOWN, bool $proofRequired = false): array
    {
        return DeviceGuard::authenticate(Request::authorization(), $mode, Request::ip(), Request::scriptPath(), $proofRequired);
    }

    private function refusal(callable $call): HttpException
    {
        try {
            $call();
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('expected an HttpException');
    }

    /** @return array{0: int, 1: string, 2: string, 3: array} status, code, message, extra */
    private function shape(HttpException $e): array
    {
        return [$e->status, $e->code(), $e->getMessage(), $e->extra];
    }

    /** @return list<array> the audit rows of $action written during this test, oldest first */
    private function audits(string $action): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM audit_log WHERE action = ? AND audit_id > ? ORDER BY audit_id');
        $st->execute([$action, $this->auditMark]);
        return $st->fetchAll();
    }

    /** @return list<array> the notifications of $kind made during this test, oldest first */
    private function notifications(string $kind): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM notification WHERE kind = ? AND notification_id > ? ORDER BY notification_id');
        $st->execute([$kind, $this->notificationMark]);
        return $st->fetchAll();
    }

    /** A request that says it is the Station (X-PFPMS-Client: station) and carries this Authorization header (null: none). */
    private function sendFromStation(?string $authorization): void
    {
        $_SERVER['HTTP_X_PFPMS_CLIENT'] = 'station';
        $this->send($authorization);
    }

    /** @return list<array{0: string, 1: ?string, 2: ?string, 3: string, 4: int, 5: string}> the hosting alerts made during this test */
    private function headerAlerts(): array
    {
        return array_map(fn(array $n) => [$n['recipient_role'], $n['recipient_user_id'], $n['site_id'], $n['entity_type'], (int) $n['entity_id'], $n['message']],
            $this->notifications('device_header_missing'));
    }

    private function ago(string $modifier): string
    {
        return Clock::db(Clock::now()->modify("-$modifier"));
    }

    /** An audit row's details, keys sorted (MySQL's JSON type reorders keys; MariaDB keeps them). */
    private function details(array $auditRow): array
    {
        $details = json_decode((string) $auditRow['details'], true);
        ksort($details);
        return $details;
    }

    private function bucketHits(string $bucket): ?int
    {
        $hits = $this->scalar('SELECT hits FROM rate_limit_bucket WHERE bucket = ?', [$bucket]);
        return $hits === false ? null : (int) $hits;
    }

    private function scope(array $user): DeviceScope
    {
        return DeviceScope::forUser(AccountRepository::find((int) $user['user_id']) ?? $user);
    }

    private function retire(int $deviceId): void
    {
        $this->assertNotNull(DeviceService::retire($deviceId, 'No longer needed', false, DeviceService::revision(DeviceRepository::find($deviceId)), $this->scope($this->coordinator)));
    }

    private function erase(int $deviceId): void
    {
        $label = (string) DeviceRepository::find($deviceId)['label'];
        $this->assertNotNull(DeviceService::erase($deviceId, 'Stolen', $label, 0, false, DeviceService::revision(DeviceRepository::find($deviceId)), $this->adminScope()));
    }

    private function adminScope(): DeviceScope
    {
        return $this->scope($this->admin);
    }

    // The header ------------------------------------------------------------------------------

    public function testMissingHeaderIsCredentialMissing(): void
    {
        foreach ([null, 'browser'] as $client) {
            unset($_SERVER['HTTP_X_PFPMS_CLIENT']);
            if ($client !== null) {
                $_SERVER['HTTP_X_PFPMS_CLIENT'] = $client;
            }
            $this->send(null);
            $e = $this->refusal(fn() => $this->guard());
            $this->assertSame([401, 'device_credential_missing', "The server did not receive this tablet's key.", []], $this->shape($e));
            $this->assertSame([], $e->headers);
        }
        $this->assertSame([], $this->audits('device_header_missing'), 'only a request that says it is the Station raises the hosting alert');
        $this->assertSame([], $this->notifications('device_header_missing'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM rate_limit_bucket'), 'no bucket is spent: nothing was presented');
    }

    public function testMissingHeaderFromTheStationAlertsAdministratorsOncePerHour(): void
    {
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM device WHERE last_seen_at >= ?', [$this->ago('15 minutes')]), 'no tablet has called in');
        $this->sendFromStation(null);
        $this->assertSame('device_credential_missing', $this->refusal(fn() => $this->guard())->code());

        $rows = $this->audits('device_header_missing');
        $this->assertCount(1, $rows);
        $this->assertSame(['Failed', 'device', null, null, null, null, null, 'A tablet request reached the server without its Authorization header'],
            [$rows[0]['outcome'], $rows[0]['entity_type'], $rows[0]['entity_id'], $rows[0]['user_id'], $rows[0]['site_id'], $rows[0]['device_id'],
                $rows[0]['session_id'], $rows[0]['reason']]);
        $this->assertSame(['endpoint' => self::HEARTBEAT, 'ip' => self::IP], $this->details($rows[0]));
        $this->assertSame([['Administrator', null, null, 'device', 0, self::HEADER_ALERT]], $this->headerAlerts(),
            'no tablet in service has got through in the last 15 minutes: the host may be stripping the header');

        $_SERVER['REMOTE_ADDR'] = self::OTHER_IP;
        Clock::advance('+59 minutes');
        $this->assertSame('device_credential_missing', $this->refusal(fn() => $this->guard())->code(), 'still refused');
        $this->assertCount(1, $this->audits('device_header_missing'), 'one bucket for every tablet and address, one row an hour');

        Clock::advance('+1 minute');
        $this->refusal(fn() => $this->guard());
        $rows = $this->audits('device_header_missing');
        $this->assertCount(2, $rows, 'the next hour records it again');
        $this->assertSame(['endpoint' => self::HEARTBEAT, 'ip' => self::OTHER_IP], $this->details($rows[1]));
        $this->assertCount(1, $this->headerAlerts(), 'the open alert is not repeated');
    }

    public function testMissingHeaderIsOnlyAuditedWhileATabletInServiceIsGettingThrough(): void
    {
        [$id] = $this->tablet(['last_seen_at' => $this->ago('14 minutes')]);
        $this->sendFromStation(null);
        $e = $this->refusal(fn() => $this->guard());
        $this->assertSame([401, 'device_credential_missing', "The server did not receive this tablet's key.", []], $this->shape($e), 'refused all the same');

        $rows = $this->audits('device_header_missing');
        $this->assertCount(1, $rows, 'the row is written whether or not Administrators are alerted');
        $this->assertSame(['Failed', null, 'A tablet request reached the server without its Authorization header'],
            [$rows[0]['outcome'], $rows[0]['entity_id'], $rows[0]['reason']]);
        $this->assertSame(['endpoint' => self::HEARTBEAT, 'ip' => self::IP], $this->details($rows[0]));
        $this->assertSame([], $this->headerAlerts(), 'a tablet got through 14 minutes ago, so the host passes the header: anyone can send such a request');

        // The alert is decided with each hourly row. Until 13:00 the bucket is spent, so nothing is decided.
        Clock::advance('+59 minutes');
        $this->refusal(fn() => $this->guard());
        $this->assertCount(1, $this->audits('device_header_missing'));
        $this->assertSame([], $this->headerAlerts(), 'nothing between the hourly rows');

        Db::pdo()->prepare('UPDATE device SET last_seen_at = ? WHERE device_id = ?')->execute([$this->ago('10 minutes'), $id]); // its 12:49 heartbeat
        Clock::advance('+1 minute');
        $this->refusal(fn() => $this->guard());
        $this->assertCount(2, $this->audits('device_header_missing'), 'the next hour records it again');
        $this->assertSame([], $this->headerAlerts(), 'the tablet got through 11 minutes ago');

        Clock::advance('+1 hour'); // 14:00, and the tablet has not got through since 12:49
        $this->refusal(fn() => $this->guard());
        $this->assertCount(3, $this->audits('device_header_missing'));
        $this->assertSame([['Administrator', null, null, 'device', 0, self::HEADER_ALERT]], $this->headerAlerts(),
            'once no tablet in service has got through for 15 minutes, the hourly row alerts');
    }

    public function testMissingHeaderAlertsWhenNoTabletInServiceWasSeenInTheLast15Minutes(): void
    {
        $this->tablet(['last_seen_at' => $this->ago('16 minutes')]); // in service, but quiet
        $this->tablet(['last_seen_at' => $this->ago('1 minute'), 'revoked_at' => $this->ago('1 day'), 'wipe_mode' => 'Push Then Wipe', 'is_site_registered' => 0]);
        $this->tablet(['last_seen_at' => $this->ago('2 minutes'), 'revoked_at' => $this->ago('1 day'), 'wipe_mode' => 'Wipe Now', 'wiped_at' => $this->ago('1 minute'),
            'is_site_registered' => 0]);
        $this->tablet(['last_seen_at' => $this->ago('1 minute'), 'is_site_registered' => 0]); // a credential, but not registered for use
        $this->makeDevice($this->north, ['last_seen_at' => $this->ago('1 minute')]); // waiting for registration: no credential

        $this->sendFromStation(null);
        $this->assertSame('device_credential_missing', $this->refusal(fn() => $this->guard())->code());
        $rows = $this->audits('device_header_missing');
        $this->assertCount(1, $rows);
        $this->assertSame(['endpoint' => self::HEARTBEAT, 'ip' => self::IP], $this->details($rows[0]));
        $this->assertSame([['Administrator', null, null, 'device', 0, self::HEADER_ALERT]], $this->headerAlerts(),
            'only a tablet in service vouches for the host: retired, erased and unregistered tablets, and one quiet for 16 minutes, do not');
    }

    public function testAnotherSchemeFromTheStationRaisesNothing(): void
    {
        foreach (['Basic ' . base64_encode('tablet:secret'), 'basic ' . base64_encode('station:x'), 'Bearer abc.def'] as $header) {
            $this->sendFromStation($header);
            $e = $this->refusal(fn() => $this->guard());
            $this->assertSame([401, 'device_credential_missing', "The server did not receive this tablet's key.", []], $this->shape($e), $header);
        }
        $this->assertSame([], $this->audits('device_header_missing'), 'an Authorization header arrived, so the host is not removing it');
        $this->assertSame([], $this->headerAlerts());
        $this->assertNull($this->bucketHits('device_header_missing:all'), 'the hourly bucket is not spent either');
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM rate_limit_bucket'), 'nor any other');

        $this->sendFromStation(null);
        $this->refusal(fn() => $this->guard());
        $this->assertCount(1, $this->audits('device_header_missing'), 'the same request with no header at all is recorded');
        $this->assertSame([['Administrator', null, null, 'device', 0, self::HEADER_ALERT]], $this->headerAlerts());
    }

    public function testAnotherSchemeIsCredentialMissing(): void
    {
        [, $credential] = $this->tablet();
        foreach (["Bearer $credential", 'Basic ' . base64_encode("tablet:$credential"), $credential, "PFPMS-Device-X $credential", "PFPMSDevice $credential"] as $header) {
            $this->send($header);
            $e = $this->refusal(fn() => $this->guard());
            $this->assertSame([401, 'device_credential_missing'], [$e->status, $e->code()], $header);
        }
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM rate_limit_bucket'), 'not an unknown credential: no bucket is spent');
    }

    public function testTheSchemeIsCaseInsensitive(): void
    {
        [$id, $credential] = $this->tablet();
        foreach (['pfpms-device', 'PFPMS-DEVICE', 'Pfpms-Device'] as $scheme) {
            $this->send("$scheme $credential");
            $this->assertSame($id, (int) $this->guard(DeviceGuard::IN_SERVICE)['device_id'], $scheme);
        }
    }

    public function testAnyOtherShapeOfTheHeaderIsCredentialMissing(): void
    {
        [, $credential] = $this->tablet();
        foreach (['', 'PFPMS-Device', 'PFPMS-Device ', "PFPMS-Device  $credential", "PFPMS-Device $credential extra", " PFPMS-Device $credential"] as $header) {
            $this->send($header);
            $this->assertSame('device_credential_missing', $this->refusal(fn() => $this->guard())->code(), var_export($header, true));
        }
    }

    // Unknown credentials ---------------------------------------------------------------------

    public function testMalformedCredentialIsUnknown(): void
    {
        $short = 'pfd1_' . str_repeat('A', 42);
        $this->makeDevice($this->north, ['token_hash' => Tokens::hash($short), 'is_site_registered' => 1]); // even a row with its hash is never looked up
        foreach ([$short, 'pfd1_' . str_repeat('A', 44), 'pfd2_' . str_repeat('A', 43), 'pfd1_' . str_repeat('A', 42) . '='] as $i => $malformed) {
            $this->send("PFPMS-Device $malformed");
            $e = $this->refusal(fn() => $this->guard(DeviceGuard::KNOWN));
            $this->assertSame([401, 'device_unknown', 'The server does not recognise this tablet.', []], $this->shape($e), $malformed);
            $this->assertSame($i + 1, $this->bucketHits('device_auth:ip:' . self::IP), 'a malformed credential spends the address bucket');
        }
    }

    public function testUnknownCredentialsAreRateLimitedPerIp(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->sendCredential($this->newCredential());
            $this->assertSame('device_unknown', $this->refusal(fn() => $this->guard())->code(), "attempt $i");
        }
        $this->sendCredential($this->newCredential());
        $e = $this->refusal(fn() => $this->guard());
        $this->assertSame([429, 'rate_limited', HttpException::defaultMessage(429), []], $this->shape($e));
        $this->assertSame(['Retry-After' => '900'], $e->headers);
        $this->assertSame(21, $this->bucketHits('device_auth:ip:' . self::IP));

        $_SERVER['REMOTE_ADDR'] = self::OTHER_IP;
        $this->assertSame('device_unknown', $this->refusal(fn() => $this->guard())->code(), 'another address has its own bucket');
        $this->assertSame(1, $this->bucketHits('device_auth:ip:' . self::OTHER_IP));

        $_SERVER['REMOTE_ADDR'] = self::IP;
        Clock::advance('+14 minutes');
        $this->assertSame('rate_limited', $this->refusal(fn() => $this->guard())->code(), 'the window is 900 seconds');
        Clock::advance('+1 minute');
        $this->assertSame('device_unknown', $this->refusal(fn() => $this->guard())->code(), 'a new window');
    }

    public function testAKnownCredentialIsNeverRateLimited(): void
    {
        [$id, $credential] = $this->tablet();
        for ($i = 1; $i <= 25; $i++) {
            $this->sendCredential($credential);
            $this->assertSame($id, (int) $this->guard(DeviceGuard::IN_SERVICE)['device_id'], "call $i");
        }
        $this->assertNull($this->bucketHits('device_auth:ip:' . self::IP), 'a known credential never spends the address bucket');

        for ($i = 1; $i <= 21; $i++) {
            $this->sendCredential($this->newCredential());
            $this->refusal(fn() => $this->guard());
        }
        $this->assertSame('rate_limited', $this->refusal(fn() => $this->guard())->code());
        $this->sendCredential($credential);
        $this->assertSame($id, (int) $this->guard(DeviceGuard::IN_SERVICE)['device_id'], 'guessing from the same address never locks a real tablet out');
        $this->assertSame(22, $this->bucketHits('device_auth:ip:' . self::IP));
    }

    // Trust -------------------------------------------------------------------------------------

    public function testInServiceModeAcceptsARegisteredTabletAtAnActiveSite(): void
    {
        [$id, $credential] = $this->tablet(['label' => 'Front desk 3', 'vault_key_ciphertext' => 'v1:k1:vault']);
        $this->sendCredential($credential);
        $d = $this->guard(DeviceGuard::IN_SERVICE);
        $this->assertSame([$id, $this->north, 'Front desk 3', 'Northside', 1, 1, 1, 0, 'none'],
            [(int) $d['device_id'], (int) $d['site_id'], $d['label'], $d['site_name'], (int) $d['in_service'], (int) $d['site_active'],
                (int) $d['has_vault_key'], (int) $d['has_proof_key'], $d['proof']]);
        $this->assertTrue(DeviceGuard::trusted($d));
        $this->assertNull(DeviceGuard::directive($d));
        foreach (['token_hash', 'vault_key_ciphertext', 'proof_key_ciphertext'] as $secret) {
            $this->assertFalse(array_key_exists($secret, $d), "the row never carries $secret");
        }
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'device' AND entity_id = ?", [$id]), 'a routine call writes nothing');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE entity_type = 'device' AND entity_id = ?", [$id]));
    }

    public function testInServiceModeRefusesARevokedTabletWithItsDirective(): void
    {
        [$retiring, $retiringCredential] = $this->tablet();
        $this->retire($retiring);
        [$erasing, $erasingCredential] = $this->tablet();
        $this->erase($erasing);
        foreach ([[$retiringCredential, 'Push Then Wipe'], [$erasingCredential, 'Wipe Now']] as [$credential, $wipe]) {
            $this->sendCredential($credential);
            $e = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE));
            $this->assertSame([403, 'device_revoked', 'This tablet has been taken out of service.', ['directive' => ['wipe' => $wipe]]], $this->shape($e), $wipe);
            $this->assertSame([], $e->headers, 'never Clear-Site-Data: a Push Then Wipe tablet must still upload');
        }
    }

    public function testARevokedTabletRefusedInServiceModeIsStillAuditedAndAlerted(): void
    {
        [$id, $credential] = $this->tablet(['label' => 'Front desk 4']);
        $this->retire($id);
        $_SERVER['SCRIPT_NAME'] = '/' . self::LOGIN;
        $this->sendCredential($credential);
        $this->assertSame('device_revoked', $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true))->code());
        $rows = $this->audits('device_revoked_contact');
        $this->assertCount(1, $rows);
        $this->assertSame(['endpoint' => self::LOGIN, 'wipe_mode' => 'Push Then Wipe'], $this->details($rows[0]));
        $this->assertCount(1, $this->notifications('device_revoked_contact'));
    }

    public function testInServiceModeRefusesATabletWhoseSiteIsInactive(): void
    {
        [$id, $credential] = $this->tablet();
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->north]);
        $this->sendCredential($credential);
        $e = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE));
        $this->assertSame([403, 'device_site_inactive', "This tablet's site is not active. Ask a Coordinator.", ['directive' => null]], $this->shape($e));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'device' AND entity_id = ?", [$id]), 'not revoked: no contact row');
    }

    public function testKnownModeServesATabletWhoseSiteIsInactive(): void
    {
        [$id, $credential] = $this->tablet();
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->north]);
        $this->sendCredential($credential);
        $d = $this->guard(DeviceGuard::KNOWN);
        $this->assertSame([$id, 1, 0], [(int) $d['device_id'], (int) $d['in_service'], (int) $d['site_active']], 'the heartbeat still answers it');
        $this->assertFalse(DeviceGuard::trusted($d));
    }

    public function testTheGuardNeverTrustsTokenHashAlone(): void
    {
        [, $credential] = $this->tablet(['is_site_registered' => 0]);
        $this->sendCredential($credential);
        $e = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE));
        $this->assertSame([403, 'device_not_registered', 'This tablet is not registered for use.', ['directive' => null]], $this->shape($e));
    }

    public function testATabletWithNoSiteIsTrustedNowhereAndServedWithNoSite(): void
    {
        [$id, $credential] = $this->tablet(['site_id' => null]);
        $this->sendCredential($credential);
        $e = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE));
        $this->assertSame([403, 'device_site_inactive', ['directive' => null]], [$e->status, $e->code(), $e->extra]);

        $d = $this->guard(DeviceGuard::KNOWN);
        $this->assertSame([$id, null, null, 0], [(int) $d['device_id'], $d['site_id'], $d['site_name'], (int) $d['site_active']]);
        Audit::record('probe', 'device', $id);
        $probe = $this->audits('probe')[0];
        $this->assertSame([null, $id], [$probe['site_id'], (int) $probe['device_id']], 'the actor has the tablet and no site');
    }

    public function testAlertsForATabletWithNoSiteSayNoSite(): void
    {
        [$id, $credential] = $this->tablet(['site_id' => null, 'label' => 'Spare', 'revoked_at' => self::NOW, 'wipe_mode' => 'Wipe Now', 'is_site_registered' => 0]);
        $this->giveProofKey($id);
        $this->sendCredential($credential);
        $this->assertSame(['wipe' => 'Wipe Now'], DeviceGuard::directive($this->guard(DeviceGuard::KNOWN)));
        $this->assertSame('Spare (no site) contacted the server after it was taken out of service. It has been told to erase itself.',
            $this->notifications('device_revoked_contact')[0]['message']);
        $this->assertStringContainsString('Spare (no site): a request used this tablet', $this->notifications('device_clone_suspected')[0]['message']);
        $this->assertNull($this->audits('device_revoked_contact')[0]['site_id']);
    }

    public function testKnownModeAcceptsARetiringTablet(): void
    {
        [$id, $credential] = $this->tablet();
        $this->retire($id);
        $this->sendCredential($credential);
        $d = $this->guard(DeviceGuard::KNOWN);
        $this->assertSame([$id, 0, 'Push Then Wipe', self::NOW], [(int) $d['device_id'], (int) $d['in_service'], $d['wipe_mode'], $d['revoked_at']]);
        $this->assertSame(['wipe' => 'Push Then Wipe'], DeviceGuard::directive($d));
    }

    public function testKnownModeAcceptsAnErasingTablet(): void
    {
        [$id, $credential] = $this->tablet();
        $this->erase($id);
        $this->sendCredential($credential);
        $d = $this->guard(DeviceGuard::KNOWN);
        $this->assertSame([$id, 0, 'Wipe Now'], [(int) $d['device_id'], (int) $d['in_service'], $d['wipe_mode']]);
        $this->assertSame(['wipe' => 'Wipe Now'], DeviceGuard::directive($d));
    }

    public function testAWipedTabletGets410WithClearSiteData(): void
    {
        [$id, $credential] = $this->tablet(['revoked_at' => '2026-10-01 10:00:00', 'wipe_mode' => 'Push Then Wipe', 'wiped_at' => '2026-10-01 11:00:00', 'is_site_registered' => 0]);
        $this->giveProofKey($id);
        $this->sendCredential($credential);
        foreach ([DeviceGuard::KNOWN, DeviceGuard::IN_SERVICE] as $mode) {
            $e = $this->refusal(fn() => $this->guard($mode, true));
            $this->assertSame([410, 'wiped', 'This tablet has erased itself and is no longer registered.', ['status' => 'wiped']], $this->shape($e), $mode);
            $this->assertSame(['Clear-Site-Data' => '"cache", "storage"'], $e->headers, $mode);
        }
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'device' AND entity_id = ?", [$id]), 'no contact or clone row for an erased tablet');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE entity_type = 'device' AND entity_id = ?", [$id]));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM rate_limit_bucket'), 'a known credential: no bucket is spent');
    }

    // Alerts and the actor ----------------------------------------------------------------------

    public function testARevokedTabletCallingInIsAuditedAndAlertedOncePerHour(): void
    {
        [$id, $credential] = $this->tablet(['label' => 'Front desk 5']);
        $this->retire($id);
        $this->sendCredential($credential);
        $this->guard(DeviceGuard::KNOWN);

        $rows = $this->audits('device_revoked_contact');
        $this->assertCount(1, $rows);
        $this->assertSame(['Denied', 'device', $id, null, null, $this->north, $id, 'A tablet taken out of service contacted the server'],
            [$rows[0]['outcome'], $rows[0]['entity_type'], (int) $rows[0]['entity_id'], $rows[0]['user_id'], $rows[0]['session_id'],
                (int) $rows[0]['site_id'], (int) $rows[0]['device_id'], $rows[0]['reason']]);
        $this->assertSame(['endpoint' => self::HEARTBEAT, 'wipe_mode' => 'Push Then Wipe'], $this->details($rows[0]));
        $alerts = $this->notifications('device_revoked_contact');
        $this->assertCount(1, $alerts);
        $this->assertSame(['Administrator', null, null, 'device', $id,
            'Front desk 5 (Northside) contacted the server after it was taken out of service. It has been told to erase itself.'],
            [$alerts[0]['recipient_role'], $alerts[0]['recipient_user_id'], $alerts[0]['site_id'], $alerts[0]['entity_type'], (int) $alerts[0]['entity_id'], $alerts[0]['message']]);

        Clock::advance('+59 minutes');
        $this->guard(DeviceGuard::KNOWN);
        $this->assertCount(1, $this->audits('device_revoked_contact'), 'a heartbeat every 5 minutes writes one row an hour');
        Clock::advance('+1 minute');
        $this->guard(DeviceGuard::KNOWN);
        $this->assertCount(2, $this->audits('device_revoked_contact'));
        $this->assertCount(1, $this->notifications('device_revoked_contact'), 'the open alert is not repeated');
    }

    public function testTheActorCarriesTheSiteAndDevice(): void
    {
        [$id, $credential] = $this->tablet();
        Audit::setActor($this->admin['user_id'], null, null, null); // whatever came before is replaced
        $this->sendCredential($credential);
        $this->guard(DeviceGuard::IN_SERVICE);
        Audit::record('probe', 'device', $id);
        $probe = $this->audits('probe')[0];
        $this->assertSame([null, null, $this->north, $id], [$probe['user_id'], $probe['session_id'], (int) $probe['site_id'], (int) $probe['device_id']]);
    }

    // The proof -----------------------------------------------------------------------------------

    public function testARequiredProofIsEnforcedOnlyWhenTheTabletHasAKey(): void
    {
        [$seeded, $seededCredential] = $this->tablet();
        $this->sendCredential($seededCredential);
        $d = $this->guard(DeviceGuard::IN_SERVICE, true);
        $this->assertSame([$seeded, 'none'], [(int) $d['device_id'], $d['proof']], 'a tablet registered before proof keys (a seed) is served');

        [$id, $credential] = $this->tablet();
        $key = $this->giveProofKey($id);
        $this->sendCredential($credential);
        $e = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true));
        $this->assertSame([401, 'device_proof_invalid', 'This tablet needs to be registered again. Ask a Coordinator.', []], $this->shape($e), 'no signature');
        $this->sendCredential($credential, $this->sign($key));
        $d = $this->guard(DeviceGuard::IN_SERVICE, true);
        $this->assertSame([$id, 'valid'], [(int) $d['device_id'], $d['proof']]);
    }

    public function testAnInvalidRequiredProofIs401AndRateLimited(): void
    {
        [$id, $credential] = $this->tablet();
        $this->giveProofKey($id);
        $this->sendCredential($credential, $this->sign(random_bytes(32))); // well formed, signed with another key
        for ($i = 1; $i <= 10; $i++) {
            $e = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true));
            $this->assertSame([401, 'device_proof_invalid', 'This tablet needs to be registered again. Ask a Coordinator.', []], $this->shape($e), "attempt $i");
        }
        $e = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true));
        $this->assertSame([429, 'rate_limited', []], [$e->status, $e->code(), $e->extra]);
        $this->assertSame(['Retry-After' => '900'], $e->headers);
        $this->assertSame(11, $this->bucketHits("device_proof:device:$id"));
        $this->assertNull($this->bucketHits('device_auth:ip:' . self::IP), 'the credential is known: the address bucket is untouched');
    }

    public function testAValidProofPassesWhenTheFailureBucketIsSpent(): void
    {
        [$id, $credential] = $this->tablet();
        $key = $this->giveProofKey($id);
        $this->sendCredential($credential, $this->sign(random_bytes(32)));
        for ($i = 1; $i <= 11; $i++) {
            $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true));
        }
        $this->sendCredential($credential, $this->sign($key));
        $this->assertSame('valid', $this->guard(DeviceGuard::IN_SERVICE, true)['proof'], 'the bucket counts failures only');
        $this->assertSame(11, $this->bucketHits("device_proof:device:$id"));
    }

    public function testARefusedRequiredProofRaisesNoCloneAlert(): void
    {
        [$id, $credential] = $this->tablet();
        $this->giveProofKey($id);
        $this->sendCredential($credential);
        $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true));
        $this->sendCredential($credential, 'v1 ' . Clock::now()->format('Uv') . ' ' . str_repeat('A', 43));
        $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true));
        $this->assertSame([], $this->audits('device_unproven'), 'refused, so nothing was served to a copy');
        $this->assertSame([], $this->notifications('device_clone_suspected'));
    }

    public function testAStaleRequiredProofIs401WithServerTime(): void
    {
        [$id, $credential] = $this->tablet();
        $key = $this->giveProofKey($id);
        foreach (['-16 minutes', '+16 minutes'] as $offset) {
            $this->sendCredential($credential, $this->sign($key, (int) Clock::now()->modify($offset)->format('Uv')));
            $e = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true));
            $this->assertSame([401, 'device_proof_stale', "The tablet's clock is too far from the server's. Try again.", ['server_time' => '2026-10-01 12:00:00.000']],
                $this->shape($e), $offset);
        }
        $this->assertNull($this->bucketHits("device_proof:device:$id"), 'a stale signature is genuine: it spends no failure bucket');
        $this->assertSame([], $this->audits('device_unproven'));
    }

    public function testAValidProofCoversThisRequestsMethodEndpointAndBody(): void
    {
        [$id, $credential] = $this->tablet();
        $key = $this->giveProofKey($id);
        $body = '{"pending_count":3,"wiped":false}';
        Request::useBody($body, 'application/json');
        $this->sendCredential($credential, $this->sign($key, null, 'POST', self::HEARTBEAT, $body));
        $this->assertSame('valid', $this->guard(DeviceGuard::KNOWN, true)['proof']);

        Request::useBody('{"pending_count":0,"wiped":false}', 'application/json');
        $this->assertSame('device_proof_invalid', $this->refusal(fn() => $this->guard(DeviceGuard::KNOWN, true))->code(), 'another body');
        Request::useBody($body, 'application/json');

        $_SERVER['SCRIPT_NAME'] = '/' . self::LOGIN;
        $this->assertSame('device_proof_invalid', $this->refusal(fn() => $this->guard(DeviceGuard::KNOWN, true))->code(), 'another endpoint');
        $_SERVER['SCRIPT_NAME'] = '/' . self::HEARTBEAT;

        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->assertSame('device_proof_invalid', $this->refusal(fn() => $this->guard(DeviceGuard::KNOWN, true))->code(), 'another method');
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Checked unenforced: an enforced proof is single-use (S3), and this header already passed once above.
        $this->assertSame('valid', $this->guard(DeviceGuard::KNOWN)['proof'], 'the original request still verifies');
    }

    public function testAnEnforcedProofIsSingleUse(): void
    {
        [$id, $credential] = $this->tablet(['label' => 'Front desk 7']);
        $key = $this->giveProofKey($id);
        $header = $this->sign($key);
        $this->sendCredential($credential, $header);
        $this->assertSame('valid', $this->guard(DeviceGuard::IN_SERVICE, true)['proof']);
        $bucket = 'proof_used:' . $id . ':' . hash('sha256', $header);
        $this->assertMatchesRegularExpression('/^proof_used:' . $id . ':[0-9a-f]{64}$/D', $bucket);
        $this->assertSame(1, $this->bucketHits($bucket), 'keyed by the lower-case hex SHA-256 of the header');
        $this->assertNull($this->bucketHits("device_proof:device:$id"));

        $e = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true)); // the same signed request, sent again
        $this->assertSame([401, 'device_proof_invalid', 'This tablet needs to be registered again. Ask a Coordinator.', []], $this->shape($e));
        $this->assertSame(1, $this->bucketHits("device_proof:device:$id"), 'counted as a failed proof');
        $rows = $this->audits('device_unproven');
        $this->assertCount(1, $rows);
        $this->assertSame(['endpoint' => self::HEARTBEAT, 'signal' => 'proof_replayed'], $this->details($rows[0]));
        $this->assertCount(1, $this->notifications('device_clone_suspected'), 'a reused proof is the clone signal');

        Clock::advance('+1 millisecond');
        $this->sendCredential($credential, $this->sign($key));
        $this->assertSame('valid', $this->guard(DeviceGuard::IN_SERVICE, true)['proof'], 'a request signed afresh passes');
        $this->assertSame('device_proof_invalid', $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true))->code());
        $this->assertCount(1, $this->audits('device_unproven'), 'one row an hour (S1 bucket)');

        Clock::advance('+14 minutes'); // the first proof is still inside its own ±15 minutes: still used
        $this->sendCredential($credential, $header);
        $this->assertSame('device_proof_invalid', $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true))->code(),
            'single-use for as long as the proof is fresh, not for a minute');

        Clock::advance('+17 minutes');
        $this->sendCredential($credential, $header);
        $this->assertSame('device_proof_stale', $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true))->code(),
            'after the bucket window the proof is long out of its own window');
    }

    public function testAProofFromATabletClockAheadStaysSingleUseForItsWholeWindow(): void
    {
        [$id, $credential] = $this->tablet();
        $key = $this->giveProofKey($id);
        // Signed by a tablet whose clock runs 14 minutes ahead: fresh from now until now + 29 minutes, 29 minutes in all.
        $header = $this->sign($key, (int) Clock::now()->modify('+14 minutes')->format('Uv'));
        $this->sendCredential($credential, $header);
        $this->assertSame('valid', $this->guard(DeviceGuard::IN_SERVICE, true)['proof']);

        Clock::advance('+28 minutes'); // still fresh (14 minutes after its own time), and still used: the bucket is 2 × the window
        $this->sendCredential($credential, $header);
        $this->assertSame('device_proof_invalid', $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true))->code());
        $this->assertSame(['endpoint' => self::HEARTBEAT, 'signal' => 'proof_replayed'], $this->details($this->audits('device_unproven')[0]));
    }

    public function testAnUnenforcedProofMayRepeat(): void
    {
        [$id, $credential] = $this->tablet();
        $key = $this->giveProofKey($id);
        $this->sendCredential($credential, $this->sign($key));
        $this->assertSame('valid', $this->guard(DeviceGuard::KNOWN)['proof']);
        $this->assertSame('valid', $this->guard(DeviceGuard::KNOWN)['proof'], 'the heartbeat and S1/S2 endpoints behave as built');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM rate_limit_bucket WHERE bucket LIKE 'proof_used:%'"), 'no single-use bucket is written');
        $this->assertSame([], $this->audits('device_unproven'));

        [$seeded, $seededCredential] = $this->tablet(); // no proof key: 'none' passes an enforced call, and is never single-use
        $this->sendCredential($seededCredential);
        $this->assertSame('none', $this->guard(DeviceGuard::IN_SERVICE, true)['proof']);
        $this->assertSame('none', $this->guard(DeviceGuard::IN_SERVICE, true)['proof']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM rate_limit_bucket WHERE bucket LIKE 'proof_used:%'"));
    }

    public function testAnUnprovenCallIsServedButAlertsOncePerHour(): void
    {
        [$id, $credential] = $this->tablet(['label' => 'Front desk 6']);
        $this->giveProofKey($id);
        $this->sendCredential($credential); // a copy of the credential without the tablet's key
        $d = $this->guard(DeviceGuard::KNOWN);
        $this->assertSame([$id, 'missing'], [(int) $d['device_id'], $d['proof']], 'served: the clone signal never refuses');

        $rows = $this->audits('device_unproven');
        $this->assertCount(1, $rows);
        $this->assertSame(['Denied', 'device', $id, $this->north, $id, "A request used this tablet's credential without the tablet's own key"],
            [$rows[0]['outcome'], $rows[0]['entity_type'], (int) $rows[0]['entity_id'], (int) $rows[0]['site_id'], (int) $rows[0]['device_id'], $rows[0]['reason']]);
        $this->assertSame(['endpoint' => self::HEARTBEAT, 'signal' => 'proof_missing'], $this->details($rows[0]));
        $alerts = $this->notifications('device_clone_suspected');
        $this->assertCount(1, $alerts);
        $this->assertSame(['Administrator', null, null, 'device', $id, "Front desk 6 (Northside): a request used this tablet's credential without the tablet's own key,"
            . ' or reported fewer records than before. Someone may have copied its registration. If so, retire it as lost or stolen.'],
            [$alerts[0]['recipient_role'], $alerts[0]['recipient_user_id'], $alerts[0]['site_id'], $alerts[0]['entity_type'], (int) $alerts[0]['entity_id'], $alerts[0]['message']]);

        Clock::advance('+59 minutes');
        $this->assertSame('missing', $this->guard(DeviceGuard::KNOWN)['proof']);
        $this->assertCount(1, $this->audits('device_unproven'), 'one row an hour');
        Clock::advance('+1 minute');
        $this->guard(DeviceGuard::KNOWN);
        $this->assertCount(2, $this->audits('device_unproven'));
        $this->assertCount(1, $this->notifications('device_clone_suspected'), 'the open alert is not repeated');
    }

    public function testAnInvalidProofOnAnOptionalCallIsServedWithItsSignal(): void
    {
        [$id, $credential] = $this->tablet();
        $this->giveProofKey($id);
        $this->sendCredential($credential, $this->sign(random_bytes(32)));
        $this->assertSame('invalid', $this->guard(DeviceGuard::KNOWN)['proof']);
        $rows = $this->audits('device_unproven');
        $this->assertCount(1, $rows);
        $this->assertSame(['endpoint' => self::HEARTBEAT, 'signal' => 'proof_invalid'], $this->details($rows[0]));
        $this->assertNull($this->bucketHits("device_proof:device:$id"), 'not enforced here: no failure bucket');
    }

    public function testAStaleButValidProofIsNotACloneSignal(): void
    {
        [$id, $credential] = $this->tablet();
        $key = $this->giveProofKey($id);
        $this->sendCredential($credential, $this->sign($key, (int) Clock::now()->modify('-2 hours')->format('Uv')));
        $this->assertSame('stale', $this->guard(DeviceGuard::KNOWN)['proof'], 'served, and the heartbeat decides what a stale proof means');
        $this->sendCredential($credential, $this->sign($key));
        $this->assertSame('valid', $this->guard(DeviceGuard::KNOWN)['proof']);
        $this->assertSame([], $this->audits('device_unproven'), 'a slow tablet clock is not a copy');
        $this->assertSame([], $this->notifications('device_clone_suspected'));
    }

    // Secrets --------------------------------------------------------------------------------------

    public function testTheCredentialNeverReachesAuditRowsNotificationsOrExceptions(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        [$revoked, $revokedCredential] = $this->tablet(['label' => 'Revoked']);
        $this->giveProofKey($revoked);
        $this->retire($revoked);
        [$wiped, $wipedCredential] = $this->tablet(['revoked_at' => self::NOW, 'wipe_mode' => 'Wipe Now', 'wiped_at' => self::NOW, 'is_site_registered' => 0]);
        [$keyed, $keyedCredential] = $this->tablet();
        $this->giveProofKey($keyed);
        $unknown = $this->newCredential();
        $credentials = [$revokedCredential, $wipedCredential, $keyedCredential, $unknown];

        $exceptions = [];
        $this->sendCredential($revokedCredential);
        $this->guard(DeviceGuard::KNOWN); // served: a contact row and a clone row, with their alerts
        $exceptions[] = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE));
        $this->sendCredential($wipedCredential);
        $exceptions[] = $this->refusal(fn() => $this->guard(DeviceGuard::KNOWN));
        $this->sendCredential($keyedCredential);
        $exceptions[] = $this->refusal(fn() => $this->guard(DeviceGuard::IN_SERVICE, true));
        $this->sendCredential($unknown);
        $exceptions[] = $this->refusal(fn() => $this->guard(DeviceGuard::KNOWN));
        $this->assertSame(['device_revoked', 'wiped', 'device_proof_invalid', 'device_unknown'], array_map(fn(HttpException $e) => $e->code(), $exceptions));
        $this->assertCount(1, $this->audits('device_unproven'));
        $this->assertCount(1, $this->audits('device_revoked_contact'));

        $haystack = implode("\n", array_merge(
            Db::pdo()->query("SELECT CONCAT_WS('|', action, reason, details, snapshot) FROM audit_log")->fetchAll(\PDO::FETCH_COLUMN),
            Db::pdo()->query("SELECT CONCAT_WS('|', field_name, old_value, new_value) FROM audit_field_change")->fetchAll(\PDO::FETCH_COLUMN),
            Db::pdo()->query('SELECT message FROM notification')->fetchAll(\PDO::FETCH_COLUMN),
            Db::pdo()->query('SELECT bucket FROM rate_limit_bucket')->fetchAll(\PDO::FETCH_COLUMN),
            array_map(fn(HttpException $e) => $e->getMessage() . json_encode($e->extra) . json_encode($e->headers), $exceptions),
        ));
        foreach ($credentials as $credential) {
            $this->assertFalse(str_contains($haystack, $credential), 'the credential is never written or echoed');
            $this->assertFalse(str_contains($haystack, substr($credential, 5, 20)), 'nor any recognisable part of it');
            $this->assertFalse(str_contains($haystack, Tokens::hash($credential)), 'nor its hash');
        }
        $this->assertFalse(str_contains($haystack, '[redacted]'), 'no detail key is swallowed by the audit redaction');

        foreach ($exceptions as $e) {
            $frame = $e->getTrace()[0];
            $this->assertSame([DeviceGuard::class, 'authenticate'], [$frame['class'] ?? null, $frame['function']]);
            $this->assertTrue(($frame['args'][0] ?? null) instanceof \SensitiveParameterValue, 'the Authorization value is hidden in stack traces');
            foreach ($e->getTrace() as $f) {
                foreach ($f['args'] ?? [] as $arg) {
                    foreach ($credentials as $credential) {
                        $this->assertFalse(is_string($arg) && str_contains($arg, $credential), "no frame of {$e->code()} carries a credential");
                    }
                }
            }
        }
    }
}
