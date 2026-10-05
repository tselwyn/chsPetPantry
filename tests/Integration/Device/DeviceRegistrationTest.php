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
use Pfpms\Device\DeviceRegistration;
use Pfpms\Device\DeviceRepository;
use Pfpms\Device\DeviceScope;
use Pfpms\Device\DeviceService;
use Pfpms\Device\DeviceStatus;
use Pfpms\Device\RegistrationCode;
use Pfpms\Http\HttpException;
use Pfpms\Security\Crypto;
use Pfpms\Security\Csrf;
use Pfpms\Settings;
use Pfpms\Tests\TestCase;

/**
 * The installed Station redeems a registration code (plan P2B; 40-design §14.3, 50-design §6.3 and §12.1, X-3):
 * the T40 six first, then the rest of 50-design §11.1 S1 and the behaviours §6.3 names.
 *
 * Durable Denied rows are read on Db::durable() and told apart by a per-test IP address (they survive the
 * rollback). Their FK columns (user, session, site, device) are always NULL, so writing them never waits on a row
 * of the test transaction; only entity_id (no foreign key) may name the test's tablet.
 */
final class DeviceRegistrationTest extends TestCase
{
    private const INVALID = 'This code is not valid. It may have expired or already been used. Ask a Coordinator for a new registration sheet.';
    private const UNUSABLE = 'The tablet sent an unusable request. Close the app, open it again and scan the code again.';
    private const BUSY = 'Someone else is changing this tablet right now. Wait a few seconds and try again.';
    private const RESPONSE_KEYS = ['device_id', 'site', 'label', 'credential', 'pbkdf2_iterations', 'replayed', 'server_time', 'build'];

    private int $site;
    private array $admin;
    private array $coordinator;
    private string $ip;
    private array $savedConfig;
    /** @var array<string, mixed> $_SERVER keys this test may set, restored in tearDown */
    private array $savedServer = [];
    /** The newest committed audit_id when the test started: durable rows of earlier tests stay visible. */
    private int $auditBaseline = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditBaseline = (int) Db::durable()->query('SELECT COALESCE(MAX(audit_id), 0) FROM audit_log')->fetchColumn();
        $this->savedConfig = Config::snapshot();
        foreach (['HTTP_X_CSRF_TOKEN', 'HTTP_ORIGIN', 'HTTP_SEC_FETCH_SITE', 'REQUEST_METHOD'] as $key) {
            $this->savedServer[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }
        $this->configure(Config::env(), false); // whatever the test config says, the install checks apply
        $this->site = $this->makeSite('Dev Site North');
        $this->admin = $this->makeUser(['role' => 'Administrator']);
        $this->coordinator = $this->makeUser(['role' => 'Coordinator']);
        Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, starts_at, granted_by) VALUES (?, ?, ?, ?)')
            ->execute([$this->coordinator['user_id'], $this->site, '2026-01-01 00:00:00', $this->admin['user_id']]);
        $this->ip = self::uniqueIp();
        Audit::setActor(null); // an anonymous Station (Api::start with 'public' => true)
    }

    protected function tearDown(): void
    {
        Config::override($this->savedConfig);
        foreach ($this->savedServer as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    /** A per-test documentation address (RFC 3849), so durable rows and buckets of other tests never mix in. */
    private static function uniqueIp(): string
    {
        return sprintf('2001:db8::%x:%x:%x', random_int(1, 0xffff), random_int(1, 0xffff), random_int(1, 0xffff));
    }

    private function configure(string $env, bool $relax): void
    {
        $station = ['dev_relax_install' => $relax] + (array) ($this->savedConfig['station'] ?? []);
        Config::override(['env' => $env, 'station' => $station] + $this->savedConfig);
    }

    private function scope(?array $user = null): DeviceScope
    {
        return DeviceScope::forUser(AccountRepository::find((int) ($user ?? $this->coordinator)['user_id']));
    }

    /** A waiting tablet (added by the Administrator) and a code the Coordinator created for it: [device_id, canonical code]. */
    private function ready(string $label = 'Front desk 3'): array
    {
        $id = DeviceService::add(['site_id' => (string) $this->site, 'label' => $label], $this->scope($this->admin));
        $code = DeviceService::issueCode($id, DeviceService::revision(DeviceRepository::find($id)), $this->scope())['code'];
        Audit::setActor(null);
        return [$id, $code];
    }

    private static function nonce(): string
    {
        return Crypto::b64url(random_bytes(16));
    }

    private static function facts(array $overrides = []): array
    {
        return $overrides + ['display_mode' => 'standalone', 'storage_persisted' => true, 'app_build' => '0.1.0-dev+ab12cd34ef', 'pbkdf2_iterations' => 610000];
    }

    /** DeviceRegistration::redeem() as the endpoint calls it; the proof key is given raw (32 bytes) and sent base64url. */
    private function redeem(?string $code, ?string $nonce = null, ?string $proofKeyRaw = null, ?array $facts = null, ?string $ip = null): array
    {
        return DeviceRegistration::redeem($code, $nonce ?? self::nonce(), Crypto::b64url($proofKeyRaw ?? random_bytes(32)), $facts ?? self::facts(), $ip ?? $this->ip);
    }

    private function refusal(callable $fn): HttpException
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('expected an HttpException');
    }

    /** @return array{0: int, 1: ?string, 2: string, 3: array, 4: array} */
    private static function answer(HttpException $e): array
    {
        return [$e->status, $e->errorCode, $e->getMessage(), $e->extra, $e->headers];
    }

    private function deviceRow(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM device WHERE device_id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    private function tokenRow(string $code): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM auth_token WHERE token_hash = ?');
        $st->execute([Tokens::hash($code)]);
        return $st->fetch();
    }

    /**
     * The durable Denied device_register rows for one IP address, oldest first (on the second connection).
     * @return list<array>
     */
    /** Object keys sorted: MySQL's JSON type reorders them, MariaDB keeps the text as written. */
    private static function byKey(array $data): array
    {
        ksort($data);
        return $data;
    }

    private function denials(string $ip): array
    {
        $st = Db::durable()->prepare("SELECT user_id, session_id, site_id, device_id, entity_type, entity_id, outcome, reason, details FROM audit_log
                                       WHERE action = 'device_register' AND outcome = 'Denied' AND details LIKE ? ORDER BY audit_id");
        $st->execute(['%' . $ip . '%']);
        $rows = [];
        foreach ($st->fetchAll() as $row) {
            $row['details'] = self::byKey(json_decode((string) $row['details'], true));
            if (($row['details']['ip'] ?? null) === $ip) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return list<string> */
    private function denialReasons(string $ip): array
    {
        return array_map(fn(array $row) => $row['details']['reason_code'], $this->denials($ip));
    }

    private function bucketHits(string $bucket): ?int
    {
        $hits = $this->scalar('SELECT hits FROM rate_limit_bucket WHERE bucket = ?', [$bucket]);
        return $hits === false ? null : (int) $hits;
    }

    private function seedBucket(string $bucket, int $hits): void
    {
        Db::pdo()->prepare('INSERT INTO rate_limit_bucket (bucket, window_start, hits) VALUES (?, ?, ?)')->execute([$bucket, Clock::db(), $hits]);
    }

    private function successRows(string $action, int $id): array
    {
        $st = Db::pdo()->prepare("SELECT * FROM audit_log WHERE action = ? AND entity_type = 'device' AND entity_id = ? AND outcome = 'Success' ORDER BY audit_id");
        $st->execute([$action, $id]);
        return $st->fetchAll();
    }

    private function notifications(string $kind): array
    {
        $st = Db::pdo()->prepare('SELECT recipient_user_id, recipient_role, site_id, kind, entity_type, entity_id, message FROM notification WHERE kind = ? ORDER BY notification_id');
        $st->execute([$kind]);
        return $st->fetchAll();
    }

    /** device_register* audit rows written since setUp: in the test transaction (main) plus committed ones (durable). */
    private function registrationAuditsSinceSetUp(): int
    {
        $count = 0;
        foreach ([Db::pdo(), Db::durable()] as $pdo) {
            $st = $pdo->prepare("SELECT audit_id FROM audit_log WHERE action LIKE 'device_register%' AND audit_id > ?");
            $st->execute([$this->auditBaseline]);
            $count += count($st->fetchAll());
        }
        return $count; // used only to show there are none, so a row seen by both connections does no harm
    }

    private function exec(string $sql, array $args): void
    {
        Db::pdo()->prepare($sql)->execute($args);
    }

    // The T40 six ---------------------------------------------------------------------------

    public function testRacingRedemptionsConsumeTheCodeOnce(): void
    {
        [$id, $code] = $this->ready();
        $other = Db::durable(); // another request registering (or changing) this tablet right now
        $name = Db::lockName(Db::pdo(), "device:$id");
        $other->prepare('SELECT GET_LOCK(?, 0)')->execute([$name]);
        try {
            $busy = $this->refusal(fn() => $this->redeem($code));
        } finally {
            $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
        $this->assertSame([409, 'busy', self::BUSY, [], []], self::answer($busy));
        $this->assertNull($this->tokenRow($code)['used_at'], 'a busy answer spends nothing');
        $this->assertNull($this->deviceRow($id)['token_hash']);
        $this->assertSame([], $this->denials($this->ip), 'busy is not a refusal of the code');

        // Two tablets scanned the same sheet: both found the live code without a lock.
        $found = Tokens::find($code, Tokens::DEVICE_REGISTRATION);
        [$nonceA, $keyA, $nonceB, $keyB, $ipB] = [self::nonce(), random_bytes(32), self::nonce(), random_bytes(32), self::uniqueIp()];
        $first = DeviceRegistration::redeemFound($found, $nonceA, $keyA, self::facts(), $this->ip);
        $second = $this->refusal(fn() => DeviceRegistration::redeemFound($found, $nonceB, $keyB, self::facts(), $ipB));
        $again = $this->refusal(fn() => $this->redeem($code, $nonceB, $keyB, null, $ipB));
        $this->assertSame([422, 'code_invalid', self::INVALID], array_slice(self::answer($second), 0, 3));
        $this->assertSame([422, 'code_invalid', self::INVALID], array_slice(self::answer($again), 0, 3));
        $this->assertSame(['device_not_waiting', 'not_found'], $this->denialReasons($ipB));
        $this->assertSame(self::NOW, $this->tokenRow($code)['used_at']);
        $this->assertSame(Tokens::hash($first['credential']), $this->deviceRow($id)['token_hash'], 'the first tablet keeps the registration');
        $this->assertSame($keyA, Crypto::decrypt((string) $this->deviceRow($id)['proof_key_ciphertext'], "device:$id:proof"), 'and its proof key');
        $this->assertCount(1, $this->successRows('device_register', $id));
        $this->assertCount(1, $this->notifications('device_registered'));
    }

    public function testACodeThatExpiresBetweenFindAndConsumeIsRefused(): void
    {
        [$id, $code] = $this->ready();
        $found = Tokens::find($code, Tokens::DEVICE_REGISTRATION);
        $this->assertNotNull($found);
        Clock::advance('+2 hours'); // the code (60 minutes) expires while the request waits
        $e = $this->refusal(fn() => DeviceRegistration::redeemFound($found, self::nonce(), random_bytes(32), self::facts(), $this->ip));
        $this->assertSame([422, 'code_invalid', self::INVALID, [], []], self::answer($e));
        $this->assertSame(['code_used'], $this->denialReasons($this->ip));
        $this->assertNull($this->tokenRow($code)['used_at']);
        $row = $this->deviceRow($id);
        $this->assertSame([null, 0, null, null], [$row['token_hash'], (int) $row['is_site_registered'], $row['vault_key_ciphertext'], $row['proof_key_ciphertext']],
            'the transaction rolled back');
        $this->assertSame([], $this->successRows('device_register', $id));
        $this->assertSame([], $this->notifications('device_registered'));
    }

    public function testACreatorWhoWasDemotedDeactivatedOrLostTheSiteIsRefused(): void
    {
        [$id, $code] = $this->ready();
        $uid = $this->coordinator['user_id'];
        foreach ([
            'demoted' => ["UPDATE user_account SET role = 'Volunteer' WHERE user_id = ?", "UPDATE user_account SET role = 'Coordinator' WHERE user_id = ?"],
            'deactivated' => ["UPDATE user_account SET status = 'Inactive' WHERE user_id = ?", "UPDATE user_account SET status = 'Active' WHERE user_id = ?"],
            'lost the site' => ["UPDATE user_site_access SET ends_at = '" . self::NOW . "' WHERE user_id = ?", 'UPDATE user_site_access SET ends_at = NULL WHERE user_id = ?'],
        ] as $what => [$change, $undo]) {
            $ip = self::uniqueIp();
            $this->exec($change, [$uid]);
            $e = $this->refusal(fn() => $this->redeem($code, null, null, null, $ip));
            $this->assertSame([422, 'code_invalid', self::INVALID], array_slice(self::answer($e), 0, 3), $what);
            $this->assertSame(['issuer_not_allowed'], $this->denialReasons($ip), $what);
            $this->assertNull($this->tokenRow($code)['used_at'], "$what: the code is not spent");
            $this->assertNull($this->deviceRow($id)['token_hash'], $what);
            $this->exec($undo, [$uid]);
        }
        $this->assertSame($id, $this->redeem($code)['device_id'], 'with the creator as they were, the same code works');
    }

    public function testTheRateLimitHitSurvivesAFailedRedemption(): void
    {
        [$id, $code] = $this->ready();
        $bucket = 'device_register:ip:' . $this->ip;
        $this->exec("UPDATE user_account SET role = 'Volunteer' WHERE user_id = ?", [$this->coordinator['user_id']]);
        $this->assertSame('code_invalid', $this->refusal(fn() => $this->redeem($code))->errorCode);
        $this->assertSame(1, $this->bucketHits($bucket), 'the hit is kept although the redemption rolled back');
        for ($i = 2; $i <= 10; $i++) {
            $this->assertSame('code_invalid', $this->refusal(fn() => $this->redeem($code))->errorCode, "attempt $i");
        }
        $this->assertSame(10, $this->bucketHits($bucket));
        $this->exec("UPDATE user_account SET role = 'Coordinator' WHERE user_id = ?", [$this->coordinator['user_id']]);
        $e = $this->refusal(fn() => $this->redeem($code)); // the eleventh, now with a code that would work
        $this->assertSame([429, 'rate_limited'], [$e->status, $e->errorCode]);
        $this->assertNull($this->tokenRow($code)['used_at']);
        $this->assertNull($this->deviceRow($id)['token_hash']);
    }

    public function testTheResponseCarriesNoVaultKeyAndOfflineStaysOff(): void
    {
        [$id, $code] = $this->ready();
        $result = $this->redeem($code, null, null, self::facts(['storage_persisted' => true, 'display_mode' => 'standalone']));
        $this->assertSame(self::RESPONSE_KEYS, array_keys($result));
        $this->assertSame([$id, ['site_id' => $this->site, 'name' => 'Dev Site North'], 'Front desk 3', 610000, false, '2026-10-01 12:00:00.000', DeviceStatus::currentBuild()],
            [$result['device_id'], $result['site'], $result['label'], $result['pbkdf2_iterations'], $result['replayed'], $result['server_time'], $result['build']]);
        $dvk = Crypto::decrypt((string) $this->deviceRow($id)['vault_key_ciphertext'], "device:$id:dvk");
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([Crypto::b64url($dvk), base64_encode($dvk), bin2hex($dvk)] as $form) {
            $this->assertStringNotContainsString($form, $json);
        }
        $this->assertStringNotContainsString($dvk, serialize($result));
        $this->assertTrue(Settings::bool('offline_mode_enabled', true), 'offline mode is on: only the heartbeat may enable it');
        $this->assertSame(0, (int) $this->deviceRow($id)['offline_enabled']);
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM auth_token WHERE device_id = ? AND purpose = 'Offline Grant'", [$id]), 'no grant either');
    }

    public function testNoCsrfTokenGives400(): void
    {
        [$id, $code] = $this->ready();
        $_SESSION = [];
        $token = Csrf::token(); // the anonymous Station session, as GET api/session.php made it
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $endpoint = function () use ($code): array { // public/api/device/register.php after Api::start()
            Csrf::verify();
            return $this->redeem($code);
        };
        foreach (['no header' => null, 'another token' => str_repeat('0', 64)] as $what => $sent) {
            if ($sent === null) {
                unset($_SERVER['HTTP_X_CSRF_TOKEN']);
            } else {
                $_SERVER['HTTP_X_CSRF_TOKEN'] = $sent;
            }
            $e = $this->refusal($endpoint);
            $this->assertSame([400, 'csrf_failed'], [$e->status, $e->errorCode], $what);
        }
        $this->assertNull($this->bucketHits('device_register:ip:' . $this->ip), 'refused before any attempt is counted');
        $this->assertNull($this->tokenRow($code)['used_at']);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $this->assertSame($id, $endpoint()['device_id'], 'with the session token it registers');
    }

    // Then -----------------------------------------------------------------------------------

    public function testAMistypedCodeIs422AndSpendsNothing(): void
    {
        [$id, $code] = $this->ready();
        $mistyped = substr($code, 0, 16) . ($code[16] === '0' ? '1' : '0'); // the check symbol is wrong
        $this->assertNull(RegistrationCode::normalise($mistyped));
        $e = $this->refusal(fn() => $this->redeem(RegistrationCode::format($mistyped)));
        $this->assertSame([422, 'code_mistyped', 'Check the code: one of the characters looks wrong.', [], []], self::answer($e));
        $this->assertSame(1, $this->bucketHits('device_register:ip:' . $this->ip), 'the per-address bucket comes first (§6.3 step 1)');
        $this->assertNull($this->bucketHits('device_register:all'), 'the global bucket counts only codes that pass normalise()');
        $this->assertSame([], $this->denials($this->ip), 'nothing is recorded');
        $this->assertSame(0, $this->registrationAuditsSinceSetUp(), 'no registration audit row on either connection');
        $this->assertNull($this->tokenRow($code)['used_at']);
        $this->assertSame($id, $this->redeem($code)['device_id'], 'the right code still works');
    }

    public function testABrowserTabIsNotInstalled409(): void
    {
        [$id, $code] = $this->ready();
        foreach (['browser', 'minimal-ui', 'fullscreen', 'something else', null] as $mode) {
            $e = $this->refusal(fn() => $this->redeem($code, null, null, self::facts(['display_mode' => $mode]), self::uniqueIp()));
            $this->assertSame([409, 'not_installed', 'Open the installed app from the home screen, then scan the code again.', [], []], self::answer($e), (string) $mode);
        }
        $this->assertNull($this->tokenRow($code)['used_at']);
        $this->assertNull($this->deviceRow($id)['token_hash']);
        $this->assertSame(0, $this->registrationAuditsSinceSetUp(), 'no registration audit row on either connection');
    }

    public function testTheDevRelaxationOnlyWorksInDevAndTest(): void
    {
        $tablets = [];
        foreach (['dev', 'test', 'staging', 'prod'] as $env) {
            $tablets[$env] = $this->ready("Tab $env");
        }
        foreach (['dev', 'test'] as $env) {
            $this->configure($env, true);
            [$id, $code] = $tablets[$env];
            $result = $this->redeem($code, null, null, self::facts(['display_mode' => 'browser', 'storage_persisted' => false]), self::uniqueIp());
            $this->assertSame($id, $result['device_id'], $env);
            $row = $this->deviceRow($id);
            $this->assertSame(['browser', 0, 0], [$row['display_mode'], (int) $row['storage_persisted'], (int) $row['offline_enabled']],
                "$env: the tablet's own report is stored as it is");
        }
        foreach (['staging', 'prod'] as $env) {
            $this->configure($env, true);
            [$id, $code] = $tablets[$env];
            $e = $this->refusal(fn() => $this->redeem($code, null, null, self::facts(['display_mode' => 'browser']), self::uniqueIp()));
            $this->assertSame([409, 'not_installed'], [$e->status, $e->errorCode], "$env ignores the flag");
            $this->assertNull($this->tokenRow($code)['used_at'], $env);
        }
        $this->configure('dev', false);
        $e = $this->refusal(fn() => $this->redeem($tablets['staging'][1], null, null, self::facts(['display_mode' => 'browser']), self::uniqueIp()));
        $this->assertSame([409, 'not_installed'], [$e->status, $e->errorCode], 'dev without the flag');
    }

    public function testEveryRefusalIsOneGeneric422WithADurableRow(): void
    {
        $cases = [];
        // not_found: a well-formed code nobody made.
        $cases['not_found'] = [null, fn(string $ip) => $this->redeem(RegistrationCode::generate(), null, null, null, $ip)];
        // device_not_waiting: the tablet was cancelled after its code was read.
        [$cancelled, $code] = $this->ready('Cancelled');
        $found = Tokens::find($code, Tokens::DEVICE_REGISTRATION);
        DeviceService::cancel($cancelled, DeviceService::revision(DeviceRepository::find($cancelled)), $this->scope());
        $cases['device_not_waiting'] = [$cancelled, fn(string $ip) => DeviceRegistration::redeemFound($found, self::nonce(), random_bytes(32), self::facts(), $ip)];
        // issuer_not_allowed: the creator can no longer register tablets.
        [$refused, $refusedCode] = $this->ready('Refused');
        $cases['issuer_not_allowed'] = [$refused, function (string $ip) use ($refusedCode) {
            $this->exec("UPDATE user_account SET role = 'Volunteer' WHERE user_id = ?", [$this->coordinator['user_id']]);
            try {
                return $this->redeem($refusedCode, null, null, null, $ip);
            } finally {
                $this->exec("UPDATE user_account SET role = 'Coordinator' WHERE user_id = ?", [$this->coordinator['user_id']]);
            }
        }];
        // code_used: another request consumed the code between the find and the lock.
        [$used, $usedCode] = $this->ready('Used');
        $usedToken = Tokens::find($usedCode, Tokens::DEVICE_REGISTRATION);
        $cases['code_used'] = [$used, function (string $ip) use ($usedToken) {
            Tokens::consume((int) $usedToken['token_id']);
            return DeviceRegistration::redeemFound($usedToken, self::nonce(), random_bytes(32), self::facts(), $ip);
        }];
        foreach ($cases as $reason => [$deviceId, $call]) {
            $ip = self::uniqueIp();
            $e = $this->refusal(fn() => $call($ip));
            $this->assertSame([422, 'code_invalid', self::INVALID, [], []], self::answer($e), $reason);
            $this->assertSame([[
                'user_id' => null, 'session_id' => null, 'site_id' => null, 'device_id' => null, 'entity_type' => 'device', 'entity_id' => $deviceId,
                'outcome' => 'Denied', 'reason' => 'Tablet registration refused', 'details' => ['ip' => $ip, 'reason_code' => $reason],
            ]], $this->denials($ip), $reason);
        }
        foreach ([$cancelled, $refused, $used] as $id) {
            $this->assertNull($this->deviceRow($id)['token_hash']);
            $this->assertSame([], $this->successRows('device_register', $id));
        }
    }

    public function testADurableRefusalNeverCarriesTheRequestsActor(): void
    {
        // Whatever actor the request carries (here ids that exist nowhere, so a foreign-key check would fail at once),
        // the Denied row sets every FK column to NULL: it can never wait on a row the main connection holds.
        Audit::setActor(2147480001, str_repeat('f', 64), 2147480002, 2147480003);
        $e = $this->refusal(fn() => $this->redeem(RegistrationCode::generate()));
        $this->assertSame([422, 'code_invalid'], [$e->status, $e->errorCode]);
        $row = $this->denials($this->ip)[0];
        $this->assertSame([null, null, null, null], [$row['user_id'], $row['session_id'], $row['site_id'], $row['device_id']]);
    }

    public function testTheCodeNonceAndProofKeyNeverReachAuditOrNotifications(): void
    {
        [$id, $code] = $this->ready();
        [$nonce, $key] = [self::nonce(), random_bytes(32)];
        $result = $this->redeem(RegistrationCode::format($code), $nonce, $key);
        Clock::advance('+5 minutes');
        $this->assertTrue($this->redeem($code, $nonce, $key)['replayed']);
        [$otherNonce, $otherKey] = [self::nonce(), random_bytes(32)];
        $this->refusal(fn() => $this->redeem($code, $otherNonce, $otherKey)); // a refused replay: durable not_found
        [$second, $secondCode] = $this->ready('Front desk 4');
        $this->exec("UPDATE user_account SET role = 'Volunteer' WHERE user_id = ?", [$this->coordinator['user_id']]);
        $this->refusal(fn() => $this->redeem($secondCode, $otherNonce, $otherKey)); // issuer_not_allowed: durable, after the rollback
        $this->assertSame(['not_found', 'issuer_not_allowed'], $this->denialReasons($this->ip));
        $dvk = Crypto::decrypt((string) $this->deviceRow($id)['vault_key_ciphertext'], "device:$id:dvk");

        $parts = [];
        $details = [];
        foreach (['main' => Db::pdo(), 'durable' => Db::durable()] as $pdo) {
            $parts = array_merge($parts,
                $pdo->query("SELECT CONCAT_WS('|', action, reason, details, snapshot) FROM audit_log")->fetchAll(\PDO::FETCH_COLUMN),
                $pdo->query("SELECT CONCAT_WS('|', field_name, old_value, new_value) FROM audit_field_change")->fetchAll(\PDO::FETCH_COLUMN),
                $pdo->query('SELECT message FROM notification')->fetchAll(\PDO::FETCH_COLUMN));
            $details = array_merge($details, $pdo->query("SELECT details FROM audit_log WHERE action LIKE 'device_register%'")->fetchAll(\PDO::FETCH_COLUMN));
        }
        $haystack = implode("\n", $parts);
        $secrets = [];
        foreach ([$code, $secondCode] as $c) {
            array_push($secrets, $c, RegistrationCode::format($c), Tokens::hash($c));
        }
        foreach ([$nonce, $otherNonce] as $n) {
            $secrets[] = $n;
        }
        foreach ([$key, $otherKey, $dvk] as $k) {
            array_push($secrets, Crypto::b64url($k), base64_encode($k), bin2hex($k));
        }
        array_push($secrets, $result['credential'], substr($result['credential'], 5), Tokens::hash($result['credential']));
        foreach ($secrets as $i => $secret) {
            $this->assertStringNotContainsString($secret, $haystack, "secret $i");
        }
        $this->assertNotSame([], $details);
        foreach ($details as $json) {
            $this->assertStringNotContainsString('[redacted]', (string) $json, 'no detail key is swallowed by the audit redaction');
            $this->assertArrayNotHasKey('token_id', json_decode((string) $json, true));
        }
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM device WHERE device_id = ? AND token_hash IS NOT NULL', [$second]));
    }

    public function testTheCreatorIsNotified(): void
    {
        [$id, $code] = $this->ready();
        $before = (int) $this->scalar('SELECT COUNT(*) FROM notification');
        $this->redeem($code);
        $this->assertSame([[
            'recipient_user_id' => $this->coordinator['user_id'], 'recipient_role' => null, 'site_id' => null, 'kind' => 'device_registered',
            'entity_type' => 'device', 'entity_id' => $id,
            'message' => 'Front desk 3 at Dev Site North was registered with the code you created. If that was not your tablet, retire it at once.',
        ]], $this->notifications('device_registered'));
        $this->assertSame($before + 1, (int) $this->scalar('SELECT COUNT(*) FROM notification'), 'nobody else is told');
    }

    public function testTheCalibratedRoundsAreStored(): void
    {
        [$id, $code] = $this->ready();
        $result = $this->redeem($code, null, null, self::facts(['pbkdf2_iterations' => 610000]));
        $this->assertSame(610000, $result['pbkdf2_iterations']);
        $this->assertSame(610000, (int) $this->deviceRow($id)['pbkdf2_iterations']);
        $this->assertSame(610000, json_decode((string) $this->successRows('device_register', $id)[0]['details'], true)['pbkdf2_iterations']);
        foreach ([100000, 2000000] as $rounds) { // the registry's own limits are inside it
            [$edge, $edgeCode] = $this->ready("Edge $rounds");
            $this->assertSame($rounds, $this->redeem($edgeCode, null, null, self::facts(['pbkdf2_iterations' => $rounds]))['pbkdf2_iterations']);
            $this->assertSame($rounds, (int) $this->deviceRow($edge)['pbkdf2_iterations']);
        }
    }

    public function testRoundsOutsideTheRegistryAreIgnored(): void
    {
        $this->setSetting('offline_pbkdf2_iterations', '700000');
        foreach ([99999, 2000001, 0, -1, null] as $rounds) {
            [$id, $code] = $this->ready('Tab ' . var_export($rounds, true));
            $result = $this->redeem($code, null, null, self::facts(['pbkdf2_iterations' => $rounds]), self::uniqueIp());
            $label = var_export($rounds, true);
            $this->assertNull($this->deviceRow($id)['pbkdf2_iterations'], "$label: NULL means the setting is used");
            $this->assertSame(700000, $result['pbkdf2_iterations'], "$label: the tablet is told the setting");
            $this->assertNull(json_decode((string) $this->successRows('device_register', $id)[0]['details'], true)['pbkdf2_iterations'], $label);
        }
    }

    public function testTheGlobalBucketAlertsAdministratorsButNeverRefuses(): void
    {
        $this->seedBucket('device_register:all', 100); // this hour's 100 codes are spent
        [$id, $code] = $this->ready();
        $this->assertSame($id, $this->redeem($code)['device_id'], 'the 101st code still registers its tablet');
        $alert = [
            'recipient_user_id' => null, 'recipient_role' => 'Administrator', 'site_id' => null, 'kind' => 'device_register_limit',
            'entity_type' => 'device_register', 'entity_id' => 0,
            'message' => 'Many tablet registration codes were tried in the last hour. If no tablets are being set up, someone may be guessing codes.',
        ];
        $this->assertSame([$alert], $this->notifications('device_register_limit'));
        $e = $this->refusal(fn() => $this->redeem(RegistrationCode::generate(), null, null, null, self::uniqueIp()));
        $this->assertSame([422, 'code_invalid'], [$e->status, $e->errorCode], 'a guess over the limit is answered as usual, never 429');
        $this->assertSame([$alert], $this->notifications('device_register_limit'), 'Administrators are alerted once while the alert is open');
        $this->assertSame(102, $this->bucketHits('device_register:all'));
    }

    public function testTheVaultKeyAndProofKeyDecryptWithTheirDeviceAad(): void
    {
        [$id, $code] = $this->ready();
        $key = random_bytes(32);
        $this->redeem($code, null, $key);
        $row = $this->deviceRow($id);
        $active = (string) Config::get('crypto.active');
        foreach (['vault_key_ciphertext', 'proof_key_ciphertext'] as $column) {
            $this->assertStringStartsWith("g1.$active.", (string) $row[$column], $column);
        }
        $dvk = Crypto::decrypt((string) $row['vault_key_ciphertext'], "device:$id:dvk");
        $this->assertSame(32, strlen($dvk));
        $this->assertSame($key, Crypto::decrypt((string) $row['proof_key_ciphertext'], "device:$id:proof"));
        $next = $id + 1;
        foreach ([['vault_key_ciphertext', "device:$id:proof"], ['vault_key_ciphertext', "device:$next:dvk"], ['vault_key_ciphertext', ''],
                  ['proof_key_ciphertext', "device:$id:dvk"], ['proof_key_ciphertext', "device:$next:proof"], ['proof_key_ciphertext', '']] as [$column, $aad]) {
            $error = null;
            try {
                Crypto::decrypt((string) $row[$column], $aad);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
            $this->assertStringContainsString('Decryption failed', (string) $error, "$column must not open with AAD '$aad'");
        }
        [$other, $otherCode] = $this->ready('Front desk 4');
        $this->redeem($otherCode);
        $this->assertNotSame($dvk, Crypto::decrypt((string) $this->deviceRow($other)['vault_key_ciphertext'], "device:$other:dvk"), 'every tablet has its own vault key');
    }

    public function testTheCredentialIsDerivedFromTheTokenAndNonce(): void
    {
        [$id, $code] = $this->ready();
        $tokenId = (int) $this->tokenRow($code)['token_id'];
        $nonce = self::nonce();
        $credential = $this->redeem($code, $nonce)['credential'];
        $this->assertMatchesRegularExpression(DeviceGuard::FORMAT, $credential);
        $this->assertSame(DeviceRegistration::credentialFor($tokenId, $nonce), $credential);
        $ring = base64_decode((string) Config::get('crypto.keys.' . Config::get('crypto.active')), true);
        $derived = hash_hkdf('sha256', (string) $ring, 32, 'pfpms/v1/device-credential', '');
        $this->assertSame('pfd1_' . Crypto::b64url(hash_hmac('sha256', "pfpms/v1/device-credential|$tokenId|$nonce", $derived, true)), $credential,
            '§3.1: HMAC(HKDF(active key), "pfpms/v1/device-credential|<token_id>|<nonce>")');
        $this->assertSame(hash('sha256', $credential), $this->deviceRow($id)['token_hash'], 'only its SHA-256 is stored');
        $this->assertNotSame($credential, DeviceRegistration::credentialFor($tokenId, self::nonce()));
        $this->assertNotSame($credential, DeviceRegistration::credentialFor($tokenId + 1, $nonce));
        $tablet = DeviceRepository::byCredentialHash(Tokens::hash($credential));
        $this->assertSame($id, (int) $tablet['device_id']);
        $this->assertTrue(DeviceGuard::trusted($tablet), 'the guard trusts the new credential at once');
    }

    public function testASameNonceReplayWithinFifteenMinutesReturnsTheSameTablet(): void
    {
        [$id, $code] = $this->ready();
        [$nonce, $key] = [self::nonce(), random_bytes(32)];
        $first = $this->redeem($code, $nonce, $key);
        $before = $this->deviceRow($id);
        Clock::advance('+' . DeviceRegistration::REPLAY_MINUTES . ' minutes'); // the boundary is inclusive
        Audit::setActor(null);
        $replay = $this->redeem($code, $nonce, $key);
        $this->assertSame(self::RESPONSE_KEYS, array_keys($replay), 'never the vault key');
        $this->assertSame([$id, $first['credential'], $first['site'], 'Front desk 3', 610000, true, '2026-10-01 12:15:00.000'],
            [$replay['device_id'], $replay['credential'], $replay['site'], $replay['label'], $replay['pbkdf2_iterations'], $replay['replayed'], $replay['server_time']]);
        $this->assertSame($before, $this->deviceRow($id), 'nothing about the tablet changes');
        $rows = $this->successRows('device_register_replay', $id);
        $this->assertCount(1, $rows);
        $this->assertSame([null, null, $this->site, $id, ['issued_by' => $this->coordinator['user_id']]],
            [$rows[0]['user_id'], $rows[0]['session_id'], (int) $rows[0]['site_id'], (int) $rows[0]['device_id'], json_decode((string) $rows[0]['details'], true)]);
        $this->assertCount(1, $this->successRows('device_register', $id), 'no second registration');
        $this->assertCount(1, $this->notifications('device_registered'), 'and no second notification');
        $this->assertSame([], $this->denials($this->ip));
    }

    public function testAReplayStillWorksAfterTheCodeItselfExpired(): void
    {
        [$id, $code] = $this->ready();
        Clock::advance('+59 minutes'); // redeemed a minute before the code (60 minutes) expires
        [$nonce, $key] = [self::nonce(), random_bytes(32)];
        $first = $this->redeem($code, $nonce, $key);
        Clock::advance('+10 minutes'); // the answer was lost; the code has expired since
        $this->assertNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION));
        $replay = $this->redeem($code, $nonce, $key);
        $this->assertSame([$id, $first['credential'], true], [$replay['device_id'], $replay['credential'], $replay['replayed']]);
    }

    public function testAReplayWithAnotherNonceOrProofKeyOrAfterFifteenMinutesIsRefused(): void
    {
        [$id, $code] = $this->ready();
        [$nonce, $key] = [self::nonce(), random_bytes(32)];
        $credential = $this->redeem($code, $nonce, $key)['credential'];
        foreach ([
            'another nonce' => fn(string $ip) => $this->redeem($code, self::nonce(), $key, null, $ip),
            'another proof key' => fn(string $ip) => $this->redeem($code, $nonce, random_bytes(32), null, $ip),
            'after fifteen minutes' => function (string $ip) use ($code, $nonce, $key) {
                Clock::advance('+' . DeviceRegistration::REPLAY_MINUTES . ' minutes +1 second');
                return $this->redeem($code, $nonce, $key, null, $ip);
            },
        ] as $what => $call) {
            $ip = self::uniqueIp();
            $e = $this->refusal(fn() => $call($ip));
            $this->assertSame([422, 'code_invalid', self::INVALID, [], []], self::answer($e), $what);
            $denials = $this->denials($ip);
            $this->assertSame([['not_found', null]], array_map(fn($r) => [$r['details']['reason_code'], $r['entity_id']], $denials), $what);
        }
        $this->assertSame(Tokens::hash($credential), $this->deviceRow($id)['token_hash']);
        $this->assertSame([], $this->successRows('device_register_replay', $id));
    }

    public function testAReplayForARetiredTabletOrAtAnInactiveSiteIsRefused(): void
    {
        [$retired, $code] = $this->ready();
        [$nonce, $key] = [self::nonce(), random_bytes(32)];
        $this->redeem($code, $nonce, $key);
        DeviceService::retire($retired, 'Broken screen', false, DeviceService::revision(DeviceRepository::find($retired)), $this->scope());
        $ip = self::uniqueIp();
        $e = $this->refusal(fn() => $this->redeem($code, $nonce, $key, null, $ip));
        $this->assertSame([422, 'code_invalid'], [$e->status, $e->errorCode], 'retired');
        $this->assertSame(['not_found'], $this->denialReasons($ip));

        [$closed, $closedCode] = $this->ready('Front desk 4');
        $this->redeem($closedCode, $nonce, $key);
        $this->exec('UPDATE site SET is_active = 0 WHERE site_id = ?', [$this->site]);
        $ip = self::uniqueIp();
        $e = $this->refusal(fn() => $this->redeem($closedCode, $nonce, $key, null, $ip));
        $this->assertSame([422, 'code_invalid'], [$e->status, $e->errorCode], 'inactive site');
        $this->assertSame(['not_found'], $this->denialReasons($ip));
        $this->assertSame([], array_merge($this->successRows('device_register_replay', $retired), $this->successRows('device_register_replay', $closed)));
    }

    public function testMalformedNonceOrProofKeyIs400(): void
    {
        [$id, $code] = $this->ready();
        $good = self::nonce();
        $goodKey = Crypto::b64url(random_bytes(32));
        $cases = [
            ['registration_nonce', null, $goodKey], ['registration_nonce', '', $goodKey], ['registration_nonce', substr($good, 0, 21), $goodKey],
            ['registration_nonce', $good . 'A', $goodKey], ['registration_nonce', str_repeat('A', 21) . '+', $goodKey],
            ['registration_nonce', base64_encode(random_bytes(16)) . '', $goodKey], ['registration_nonce', str_repeat('A', 21) . 'B', $goodKey],
            ['registration_nonce', ' ' . substr($good, 1), $goodKey], ['registration_nonce', "$good\n", $goodKey], // "$" also matches before a final newline
            ['proof_key', $good, "$goodKey\n"],
            ['proof_key', $good, null], ['proof_key', $good, ''], ['proof_key', $good, substr($goodKey, 0, 42)], ['proof_key', $good, $goodKey . 'A'],
            ['proof_key', $good, str_repeat('A', 42) . 'B'], ['proof_key', $good, str_repeat('A', 42) . '/'], ['proof_key', $good, ' ' . substr($goodKey, 1)],
            ['proof_key', $good, rtrim(base64_encode(random_bytes(32)), '=') . '='],
        ];
        foreach ($cases as $i => [$field, $nonce, $proofKey]) {
            $ip = self::uniqueIp();
            $e = $this->refusal(fn() => DeviceRegistration::redeem($code, $nonce, $proofKey, self::facts(), $ip));
            $this->assertSame([400, 'bad_request', self::UNUSABLE, ['field' => $field], []], self::answer($e), "case $i");
            $this->assertSame([], $this->denials($ip), "case $i");
        }
        $this->assertNull($this->tokenRow($code)['used_at']);
        $this->assertSame($id, DeviceRegistration::redeem($code, $good, $goodKey, self::facts(), $this->ip)['device_id'], 'the well-formed pair registers');
    }

    // Further behaviours of §6.3 and §12.1 ------------------------------------------------------

    public function testTheChecksRunInTheDesignedOrder(): void
    {
        [, $code] = $this->ready();
        $mistyped = substr($code, 0, 16) . ($code[16] === '0' ? '1' : '0');
        $limited = self::uniqueIp();
        $this->seedBucket('device_register:ip:' . $limited, 10);
        $steps = [
            [429, 'rate_limited', fn() => DeviceRegistration::redeem($mistyped, 'x', 'x', self::facts(['display_mode' => 'browser']), $limited)],
            [422, 'code_mistyped', fn() => DeviceRegistration::redeem($mistyped, 'x', 'x', self::facts(['display_mode' => 'browser']), self::uniqueIp())],
            [400, 'bad_request', fn() => DeviceRegistration::redeem($code, 'x', 'x', self::facts(['display_mode' => 'browser']), self::uniqueIp())],
            [409, 'not_installed', fn() => DeviceRegistration::redeem(RegistrationCode::generate(), self::nonce(), Crypto::b64url(random_bytes(32)),
                self::facts(['display_mode' => 'browser']), self::uniqueIp())],
            [422, 'code_invalid', fn() => DeviceRegistration::redeem(RegistrationCode::generate(), self::nonce(), Crypto::b64url(random_bytes(32)), self::facts(), self::uniqueIp())],
        ];
        foreach ($steps as $i => [$status, $errorCode, $call]) {
            $e = $this->refusal($call);
            $this->assertSame([$status, $errorCode], [$e->status, $e->errorCode], "step $i");
        }
        $this->assertSame(['field' => 'registration_nonce'], $this->refusal($steps[2][2])->extra, 'the nonce is checked before the proof key');
        $this->assertNull($this->tokenRow($code)['used_at']);
    }

    public function testTheIpBucketAllowsTenAttemptsThen429WithADurableRow(): void
    {
        [$id, $code] = $this->ready();
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame('code_invalid', $this->refusal(fn() => $this->redeem(RegistrationCode::generate()))->errorCode, "guess $i");
        }
        $e = $this->refusal(fn() => $this->redeem($code));
        $this->assertSame([429, 'rate_limited', 'Too many attempts. Please wait a few minutes and try again.', [], ['Retry-After' => '900']], self::answer($e));
        $denials = $this->denials($this->ip);
        $this->assertCount(11, $denials);
        $this->assertSame(['user_id' => null, 'session_id' => null, 'site_id' => null, 'device_id' => null, 'entity_type' => 'device', 'entity_id' => null,
            'outcome' => 'Denied', 'reason' => 'Tablet registration refused', 'details' => ['ip' => $this->ip, 'reason_code' => 'rate_limited']], $denials[10]);
        $this->assertNull($this->tokenRow($code)['used_at'], 'refused before the code is looked at');
        $this->assertSame('code_invalid', $this->refusal(fn() => $this->redeem(RegistrationCode::generate(), null, null, null, self::uniqueIp()))->errorCode,
            'another address is not limited');
        Clock::advance('+900 seconds');
        $this->assertSame($id, $this->redeem($code)['device_id'], 'a new window lets the address try again');
    }

    public function testRegistrationPutsTheTabletInServiceWithItsReportedFacts(): void
    {
        [$id, $code] = $this->ready();
        $result = $this->redeem($code);
        $row = $this->deviceRow($id);
        $this->assertSame([Tokens::hash($result['credential']), 1, $this->coordinator['user_id'], self::NOW, self::NOW, 0, 1, 'standalone', '0.1.0-dev+ab12cd34ef', 610000, null, null],
            [$row['token_hash'], (int) $row['is_site_registered'], (int) $row['registered_by'], $row['registered_at'], $row['last_seen_at'], (int) $row['offline_enabled'],
             (int) $row['storage_persisted'], $row['display_mode'], $row['app_build'], (int) $row['pbkdf2_iterations'], $row['revoked_at'], $row['wiped_at']],
            'registered_by is the code\'s creator, not the person who added the tablet');
        $this->assertSame(DeviceStatus::IN_SERVICE, DeviceStatus::code(DeviceRepository::find($id)));
        $this->assertSame(self::NOW, $this->tokenRow($code)['used_at']);
    }

    public function testANotPersistedTabletIsStoredAsZero(): void
    {
        [$id, $code] = $this->ready();
        $this->redeem($code, null, null, self::facts(['storage_persisted' => false]));
        $this->assertSame(0, (int) $this->deviceRow($id)['storage_persisted']);
        $this->assertFalse(json_decode((string) $this->successRows('device_register', $id)[0]['details'], true)['storage_persisted']);
    }

    public function testTheSuccessAuditRowCarriesTheFactsAndTheTabletsSiteAndDevice(): void
    {
        [$id, $code] = $this->ready();
        $this->redeem($code);
        $rows = $this->successRows('device_register', $id);
        $this->assertCount(1, $rows);
        $this->assertSame([null, null, $this->site, $id, '2026-10-01 12:00:00.000', null],
            [$rows[0]['user_id'], $rows[0]['session_id'], (int) $rows[0]['site_id'], (int) $rows[0]['device_id'], $rows[0]['occurred_at'], $rows[0]['reason']]);
        $this->assertSame(self::byKey(['issued_by' => $this->coordinator['user_id'], 'app_build' => '0.1.0-dev+ab12cd34ef', 'display_mode' => 'standalone',
            'storage_persisted' => true, 'pbkdf2_iterations' => 610000]), self::byKey(json_decode((string) $rows[0]['details'], true)));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM audit_field_change f JOIN audit_log a ON a.audit_id = f.audit_id WHERE a.audit_id = ?',
            [$rows[0]['audit_id']]));
    }

    public function testAnUnusableBuildOrDisplayModeIsStoredAsUnknown(): void
    {
        $this->configure('test', true); // so an unknown display mode is not refused
        foreach ([['bad build', 'weird'], [str_repeat('1', 41), 'tv'], ['0.1.0<script>', 'Standalone']] as $i => [$build, $mode]) {
            [$id, $code] = $this->ready("Tab $i");
            $this->redeem($code, null, null, self::facts(['app_build' => $build, 'display_mode' => $mode]), self::uniqueIp());
            $row = $this->deviceRow($id);
            $this->assertSame([null, 'other'], [$row['app_build'], $row['display_mode']], "case $i");
            $details = json_decode((string) $this->successRows('device_register', $id)[0]['details'], true);
            $this->assertSame([null, 'other'], [$details['app_build'], $details['display_mode']], "case $i");
        }
    }

    public function testThePrintedAndScannedFormsOfTheCodeAreAccepted(): void
    {
        [$printed, $printedCode] = $this->ready('Printed');
        [$scanned, $scannedCode] = $this->ready('Scanned');
        $typed = strtolower(RegistrationCode::format($printedCode));
        $this->assertSame($printed, $this->redeem(" $typed ")['device_id'], 'typed in lower case with hyphens and spaces');
        $this->assertSame($scanned, $this->redeem(RegistrationCode::qrPayload($scannedCode))['device_id'], 'the QR payload');
    }

    public function testACreatorWhoMustChangeTheirPasswordIsRefused(): void
    {
        [$id, $code] = $this->ready();
        $this->exec('UPDATE user_account SET must_change_password = 1 WHERE user_id = ?', [$this->coordinator['user_id']]);
        $e = $this->refusal(fn() => $this->redeem($code));
        $this->assertSame([422, 'code_invalid'], [$e->status, $e->errorCode]);
        $this->assertSame(['issuer_not_allowed'], $this->denialReasons($this->ip));
        $this->assertNull($this->tokenRow($code)['used_at']);
        $this->assertNull($this->deviceRow($id)['token_hash']);
    }

    public function testACodeForATabletAtASiteDeactivatedSinceIsRefused(): void
    {
        [$id, $code] = $this->ready();
        $this->exec('UPDATE site SET is_active = 0 WHERE site_id = ?', [$this->site]);
        $e = $this->refusal(fn() => $this->redeem($code));
        $this->assertSame([422, 'code_invalid'], [$e->status, $e->errorCode]);
        $this->assertSame(['issuer_not_allowed'], $this->denialReasons($this->ip), 'the creator can no longer add a tablet there');
        $this->assertNull($this->deviceRow($id)['token_hash']);
    }
}
