<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Device;

use Pfpms\Account\AccountRepository;
use Pfpms\Audit\Audit;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Device\DeviceGuard;
use Pfpms\Device\DeviceHeartbeat;
use Pfpms\Device\DeviceProof;
use Pfpms\Device\DeviceRepository;
use Pfpms\Device\DeviceScope;
use Pfpms\Device\DeviceService;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\HttpException;
use Pfpms\Http\Request;
use Pfpms\Inventory\CountRepository;
use Pfpms\Security\Crypto;
use Pfpms\Station\StationConfig;
use Pfpms\Tests\TestCase;

/**
 * POST api/device/heartbeat.php (50-design §6.4, §12.1 DeviceHeartbeat): what the tablet reports, the offline flag,
 * the directive, the erase confirmation, the audit rows and the response. The tablet is passed to receive() as
 * DeviceGuard::authenticate() hands it over: the byCredentialHash() row plus its 'proof' verdict, with the audit
 * actor carrying the tablet's site and device.
 */
final class DeviceHeartbeatTest extends TestCase
{
    private const ENDPOINT = 'api/device/heartbeat.php';

    private int $north;
    private int $south;
    private array $admin;
    private array $coordinator;
    /** @var array<int, string> device_id => credential */
    private array $credentials = [];
    /** @var array<string, ?string> the $_SERVER values replaced by this test */
    private array $savedServer = [];
    private array $savedConfig;
    /** One Argon2id hash for every person made here: nothing in this file signs in, and hashing per person is slow. */
    private static ?string $passwordHash = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedConfig = Config::snapshot();
        // The install checks apply here whatever the test config says; the one test of the relaxation turns it on itself.
        $this->relax(false);
        $this->north = $this->makeSite('Northside');
        $this->south = $this->makeSite('Southside');
        self::$passwordHash ??= PasswordPolicy::hash('Correct-Horse-Battery-9');
        $this->admin = $this->makeUser(['role' => 'Administrator', 'password_hash' => self::$passwordHash]);
        $this->coordinator = $this->makeUser(['role' => 'Coordinator', 'password_hash' => self::$passwordHash]);
        Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, starts_at, granted_by) VALUES (?, ?, ?, ?)')
            ->execute([$this->coordinator['user_id'], $this->north, '2026-01-01 00:00:00', $this->admin['user_id']]);
        $this->server('REQUEST_METHOD', 'POST');
        // With the file that serves it, whatever app.base_path is, scriptPath() is the path under public/.
        $this->server('SCRIPT_NAME', '/' . self::ENDPOINT);
        $this->server('SCRIPT_FILENAME', APP_ROOT . '/public/' . self::ENDPOINT);
    }

    protected function tearDown(): void
    {
        Request::useBody(null);
        foreach ($this->savedServer as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        Config::override($this->savedConfig);
        Audit::setActor(null);
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    /** Set a $_SERVER value for this test (null removes it); tearDown puts the original back. */
    private function server(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->savedServer)) {
            $this->savedServer[$key] = isset($_SERVER[$key]) && is_string($_SERVER[$key]) ? $_SERVER[$key] : null;
        }
        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }

    /** The configuration this test started with, station.dev_relax_install set to $on (merged, so the other station keys stay). */
    private function relax(bool $on): void
    {
        Config::override(['station' => ['dev_relax_install' => $on] + (array) ($this->savedConfig['station'] ?? [])] + $this->savedConfig);
    }

    /** A registered tablet in service (credential in DeviceGuard::FORMAT); $proofKey gives it a proof key (AAD device:<id>:proof). */
    private function tablet(array $overrides = [], ?string $proofKey = null, ?int $siteId = null): int
    {
        static $n = 0;
        $n++;
        $credential = 'pfd1_' . Crypto::b64url(random_bytes(32));
        $this->assertSame(1, preg_match(DeviceGuard::FORMAT, $credential));
        $id = $this->makeDevice($siteId ?? $this->north, $overrides + ['token_hash' => Tokens::hash($credential), 'is_site_registered' => 1,
            'vault_key_ciphertext' => "g1.test.vault$n", 'label' => "Front desk $n"]);
        if ($proofKey !== null) {
            Db::pdo()->prepare('UPDATE device SET proof_key_ciphertext = ? WHERE device_id = ?')->execute([Crypto::encrypt($proofKey, "device:$id:proof"), $id]);
        }
        $this->credentials[$id] = $credential;
        return $id;
    }

    /** What DeviceGuard::authenticate() returns for the tablet, and the actor it sets (step 4). */
    private function guardRow(int $id, string $proof = 'none'): array
    {
        $device = DeviceRepository::byCredentialHash(Tokens::hash($this->credentials[$id]));
        $this->assertNotNull($device, "tablet $id is known by its credential");
        Audit::setActor(null, null, $device['site_id'] === null ? null : (int) $device['site_id'], $id);
        $device['proof'] = $proof;
        return $device;
    }

    /** A full, valid heartbeat body (the server's clock is the tablet's), with overrides. */
    private function body(array $overrides = []): array
    {
        return $overrides + [
            'app_build' => '0.1.0-dev+ab12cd34ef', 'client_now' => '2026-10-01 12:00:00.000', 'storage_persisted' => true, 'display_mode' => 'standalone',
            'pending_count' => 3, 'attention_count' => 0, 'max_seq' => 41, 'oldest_pending_at' => null, 'storage_estimate_kb' => 812,
            'locked_out_since' => null, 'failed_unlock_wipe' => false, 'clock_rollback' => false, 'auth_failures' => [], 'wiped' => false,
            'items_pushed' => null,
        ];
    }

    /** @param int|array $device a tablet id (its guard row is read now) or a guard row read earlier */
    private function beat(int|array $device, array $overrides = [], string $proof = 'none'): array
    {
        return DeviceHeartbeat::receive(is_array($device) ? $device : $this->guardRow($device, $proof), $this->body($overrides));
    }

    /**
     * The tablet as DeviceGuard::authenticate() hands it over for this heartbeat body, sent with $key's proof header
     * (50-design §3.2) signed at $tsMs (default now), or with no proof header when $key is null.
     */
    private function guarded(int $id, array $overrides, ?string $key, ?int $tsMs = null): array
    {
        $raw = json_encode($this->body($overrides), JSON_THROW_ON_ERROR);
        Request::useBody($raw, 'application/json');
        $header = null;
        if ($key !== null) {
            $ts = $tsMs ?? (int) Clock::now()->format('Uv');
            $header = "v1 $ts " . Crypto::b64url(Crypto::hmac($key, DeviceProof::message('POST', Request::scriptPath(), $ts, hash('sha256', $raw))));
        }
        $this->server('HTTP_PFPMS_PROOF', $header);
        return DeviceGuard::authenticate('PFPMS-Device ' . $this->credentials[$id], DeviceGuard::KNOWN, '203.0.113.7', Request::scriptPath());
    }

    /** The same heartbeat through DeviceGuard, signed with $key (the proof header of 50-design §3.2). */
    private function signedBeat(int $id, array $overrides, string $key, ?int $tsMs = null): array
    {
        $device = $this->guarded($id, $overrides, $key, $tsMs);
        return DeviceHeartbeat::receive($device, Request::json());
    }

    /** A time for the proof header that the guard finds stale (a signed request copied from the network log). */
    private static function staleTs(): int
    {
        return (int) Clock::now()->format('Uv') - (DeviceProof::WINDOW_SECONDS + 60) * 1000;
    }

    private function row(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM device WHERE device_id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    /** The columns a heartbeat must never write. */
    private function untouchable(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT site_id, label, is_site_registered, registered_by, registered_at, token_hash, vault_key_ciphertext, proof_key_ciphertext,
                                         pbkdf2_iterations, revoked_at, revoked_by, revoked_lost, revoked_max_seq, wipe_mode, erase_requested_at, erase_requested_by,
                                         wiped_at, last_sync_at, shift_ended_at
                                    FROM device WHERE device_id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    private function rev(int $id): string
    {
        return DeviceService::revision(DeviceRepository::find($id));
    }

    private function scope(array $user): DeviceScope
    {
        return DeviceScope::forUser(AccountRepository::find((int) $user['user_id']) ?? $user);
    }

    private function retire(int $id): void
    {
        $this->assertNotNull(DeviceService::retire($id, 'No longer needed', false, $this->rev($id), $this->scope($this->coordinator)));
    }

    /** @return list<array> the audit rows of $action about the tablet, oldest first */
    private function audits(string $action, int $id): array
    {
        $st = Db::pdo()->prepare("SELECT * FROM audit_log WHERE action = ? AND entity_type = 'device' AND entity_id = ? ORDER BY audit_id");
        $st->execute([$action, $id]);
        return $st->fetchAll();
    }

    /** The details of an audit row, objects key-sorted (MySQL's JSON type reorders keys; MariaDB keeps them). */
    private function details(array $audit): ?array
    {
        return $audit['details'] === null ? null : self::sorted(json_decode((string) $audit['details'], true, 512, JSON_THROW_ON_ERROR));
    }

    /** Objects (string-keyed arrays) key-sorted at every depth, so details compare whatever the engine's key order. */
    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map([self::class, 'sorted'], $value);
    }

    /** @return array<string, mixed> the tablet's columns named by $expected's keys, in that order */
    private function columns(int $id, array $expected): array
    {
        $row = $this->row($id);
        $out = [];
        foreach (array_keys($expected) as $column) {
            $out[$column] = $row[$column];
        }
        return $out;
    }

    /** @return array<string, array{0: ?string, 1: ?string}> field => [old, new] of one audit row */
    private function fieldChanges(int $auditId): array
    {
        $st = Db::pdo()->prepare('SELECT field_name, old_value, new_value FROM audit_field_change WHERE audit_id = ? ORDER BY field_name');
        $st->execute([$auditId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['field_name']] = [$r['old_value'], $r['new_value']];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Everything recorded about the tablet: its audit rows (about it, or acting as it) with their field changes,
     * and the alerts about it. Scoped to the tablet, so rows other tests committed never count.
     */
    private function trail(int $id): array
    {
        $audit = Db::pdo()->prepare("SELECT a.audit_id, a.action, (SELECT COUNT(*) FROM audit_field_change c WHERE c.audit_id = a.audit_id) AS changes
                                       FROM audit_log a WHERE (a.entity_type = 'device' AND a.entity_id = ?) OR a.device_id = ? ORDER BY a.audit_id");
        $audit->execute([$id, $id]);
        $alerts = Db::pdo()->prepare("SELECT notification_id, kind FROM notification WHERE entity_type = 'device' AND entity_id = ? ORDER BY notification_id");
        $alerts->execute([$id]);
        return ['audit' => $audit->fetchAll(), 'alerts' => $alerts->fetchAll()];
    }

    /** The clone alerts (X-5) about the tablet. */
    private function cloneAlerts(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'device_clone_suspected' AND entity_type = 'device' AND entity_id = ?", [$id]);
    }

    private function auditCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM audit_log') + (int) $this->scalar('SELECT COUNT(*) FROM audit_field_change');
    }

    /** An auth_token row inserted by hand (S3 issues grants; S1 only lists the revoked ones). */
    private function token(int $deviceId, ?string $revokedAt, string $purpose = Tokens::OFFLINE_GRANT, ?string $secret = null): int
    {
        Db::pdo()->prepare('INSERT INTO auth_token (user_id, purpose, token_hash, secret_ciphertext, device_id, expires_at, revoked_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$this->coordinator['user_id'], $purpose, hash('sha256', random_bytes(16)), $secret, $deviceId, '2026-10-04 12:00:00', $revokedAt, '2026-09-28 12:00:00']);
        return (int) Db::pdo()->lastInsertId();
    }

    private function httpError(callable $fn): HttpException
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('expected an HttpException');
    }

    // What the tablet reports ----------------------------------------------------------------

    public function testItWritesTheReportedFieldsAndNeverChangesTheRevision(): void
    {
        $id = $this->tablet(['pbkdf2_iterations' => 610000], random_bytes(32));
        $retired = $this->tablet(['pending_count' => 2]);
        $this->retire($retired);
        $revisions = [$id => $this->rev($id), $retired => $this->rev($retired)];
        $fixed = [$id => $this->untouchable($id), $retired => $this->untouchable($retired)];

        $result = $this->beat($id, ['client_now' => '2026-10-01 11:59:30.000', 'pending_count' => 3, 'attention_count' => 1, 'max_seq' => 41,
            'oldest_pending_at' => '2026-10-01 11:00:00.000', 'storage_estimate_kb' => 812]);
        $this->assertSame(200, $result['status']);
        $expected = ['last_seen_at' => self::NOW, 'app_build' => '0.1.0-dev+ab12cd34ef', 'storage_persisted' => 1, 'display_mode' => 'standalone', 'pending_count' => 3,
            'attention_count' => 1, 'oldest_pending_at' => '2026-10-01 11:00:30', 'storage_estimate_kb' => 812, 'clock_skew_seconds' => 30,
            'locked_out_since' => null, 'reported_max_seq' => 41, 'offline_enabled' => 1];
        $this->assertSame($expected, $this->columns($id, $expected));
        $this->assertSame(200, $this->beat($retired, ['pending_count' => 1])['status']);
        $this->assertSame(1, (int) $this->row($retired)['pending_count'], 'a retiring tablet still reports its count');

        foreach ($revisions as $device => $revision) {
            $this->assertSame($revision, $this->rev($device), "no open form of tablet $device goes stale");
            $this->assertSame($fixed[$device], $this->untouchable($device), "tablet $device: label, site, registration, revocation and keys are never written");
        }
    }

    public function testReportedMaxSeqIsWrittenOnlyInServiceAndNeverGoesDown(): void
    {
        $id = $this->tablet();
        $seen = [];
        foreach ([10, 15, 12, null] as $seq) {
            $this->beat($id, ['max_seq' => $seq]);
            $seen[] = $this->row($id)['reported_max_seq'];
        }
        $this->assertSame([10, 15, 15, 15], $seen, 'it only rises; a missing max_seq changes nothing');

        $silent = $this->tablet();
        $this->beat($silent, ['max_seq' => null]);
        $this->assertNull($this->row($silent)['reported_max_seq'], 'never reported: still unknown');

        $retired = $this->tablet(['reported_max_seq' => 7]);
        $this->retire($retired);
        $this->beat($retired, ['max_seq' => 20]);
        $this->assertSame(7, $this->row($retired)['reported_max_seq'], 'a tablet out of service no longer moves it');
    }

    public function testANotPersistedTabletAndAMalformedPendingCountAreStoredSafely(): void
    {
        $id = $this->tablet(['storage_persisted' => 1, 'pending_count' => 5, 'offline_enabled' => 1]);
        $result = $this->beat($id, ['storage_persisted' => false, 'pending_count' => '3']);
        $this->assertSame([200, 'ok'], [$result['status'], $result['body']['status']]);
        $row = $this->row($id);
        $this->assertSame([0, 5, 0], [$row['storage_persisted'], $row['pending_count'], $row['offline_enabled']], 'false is bound as 0; "3" keeps the stored count');

        foreach (['a string' => ['storage_persisted' => 'true', 'pending_count' => 3.0], 'absent' => []] as $case => $overrides) {
            Db::pdo()->prepare('UPDATE device SET storage_persisted = 1 WHERE device_id = ?')->execute([$id]);
            $body = $this->body($overrides);
            if ($case === 'absent') {
                unset($body['storage_persisted'], $body['pending_count']);
            }
            $this->assertSame(200, DeviceHeartbeat::receive($this->guardRow($id), $body)['status'], $case);
            $row = $this->row($id);
            $this->assertSame([0, 5], [$row['storage_persisted'], $row['pending_count']], "$case: not persisted, the count kept");
        }
    }

    public function testReportedTimesAreStoredAtSecondsPrecisionAndNeverInTheFuture(): void
    {
        $id = $this->tablet();
        $this->beat($id, ['oldest_pending_at' => '2026-10-01 10:15:30.999', 'locked_out_since' => '2026-10-01 12:45:00.000']);
        $row = $this->row($id);
        $this->assertSame(['2026-10-01 10:15:30', self::NOW], [$row['oldest_pending_at'], $row['locked_out_since']],
            'milliseconds are dropped (never rounded up), and a future time becomes now');
    }

    public function testReportedTimesAreCorrectedByTheClockSkew(): void
    {
        $slow = $this->tablet();
        $this->beat($slow, ['client_now' => '2026-10-01 11:58:00.000', 'oldest_pending_at' => '2026-10-01 11:00:00.000', 'locked_out_since' => '2026-10-01 11:30:00.000']);
        $row = $this->row($slow);
        $this->assertSame([120, '2026-10-01 11:02:00', '2026-10-01 11:32:00'], [$row['clock_skew_seconds'], $row['oldest_pending_at'], $row['locked_out_since']]);

        $fast = $this->tablet();
        $this->beat($fast, ['client_now' => '2026-10-01 12:05:00.000', 'oldest_pending_at' => '2026-10-01 11:00:00.000', 'locked_out_since' => '2026-10-01 12:04:00.000']);
        $row = $this->row($fast);
        $this->assertSame([-300, '2026-10-01 10:55:00', '2026-10-01 11:59:00'], [$row['clock_skew_seconds'], $row['oldest_pending_at'], $row['locked_out_since']],
            'a fast clock is corrected back, not merely capped at now');
    }

    public function testAClientClockMoreThanAWeekOffOrMalformedGivesNoSkew(): void
    {
        foreach (['2026-09-23 11:59:59.000', '2026-10-09 12:00:01.000', '2026-10-01T12:00:00Z', '2026-02-30 12:00:00.000'] as $clientNow) {
            $id = $this->tablet(['clock_skew_seconds' => 42]);
            $this->beat($id, ['client_now' => $clientNow, 'oldest_pending_at' => '2026-10-01 11:00:00.000']);
            $row = $this->row($id);
            $this->assertSame([null, '2026-10-01 11:00:00'], [$row['clock_skew_seconds'], $row['oldest_pending_at']], $clientNow);
        }
        $week = $this->tablet();
        $this->beat($week, ['client_now' => '2026-09-24 12:00:00.000']);
        $this->assertSame(604800, $this->row($week)['clock_skew_seconds'], 'exactly seven days is still used');
    }

    public function testOutOfRangeAndMistypedFieldsAreIgnored(): void
    {
        $id = $this->tablet(['app_build' => '0.0.9', 'pending_count' => 4, 'reported_max_seq' => 9, 'attention_count' => 2, 'storage_estimate_kb' => 100]);
        $result = $this->beat($id, ['app_build' => 'bad build!', 'display_mode' => 'kiosk', 'pending_count' => 10000001, 'attention_count' => -1,
            'max_seq' => 2147483648, 'storage_estimate_kb' => true, 'items_pushed' => 'x']);
        $this->assertSame(200, $result['status']);
        $row = $this->row($id);
        $this->assertSame(['0.0.9', 'other', 4, null, 9, null],
            [$row['app_build'], $row['display_mode'], $row['pending_count'], $row['attention_count'], $row['reported_max_seq'], $row['storage_estimate_kb']]);
        $this->beat($id, ['pending_count' => 10000000, 'attention_count' => 10000000, 'max_seq' => 2147483647, 'app_build' => str_repeat('a', 40)]);
        $row = $this->row($id);
        $this->assertSame([str_repeat('a', 40), 10000000, 10000000, 2147483647], [$row['app_build'], $row['pending_count'], $row['attention_count'], $row['reported_max_seq']],
            'the upper bounds themselves are accepted');
    }

    // Offline flag ---------------------------------------------------------------------------

    public function testOfflineEnabledNeedsServicePersistenceStandaloneAndTheSetting(): void
    {
        $cases = [
            'eligible' => [[], [], null, 1],
            'standalone as stored, not reported' => [['display_mode' => 'standalone'], ['display_mode' => null], null, 1],
            'storage not persisted' => [[], ['storage_persisted' => false], null, 0],
            'a browser tab' => [[], ['display_mode' => 'browser'], null, 0],
            'minimal-ui' => [[], ['display_mode' => 'minimal-ui'], null, 0],
            'not registered for use' => [['is_site_registered' => 0], [], null, 0],
            'site inactive' => [[], [], $this->south, 0],
        ];
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->south]);
        foreach ($cases as $case => [$overrides, $report, $site, $expected]) {
            $id = $this->tablet($overrides + ['offline_enabled' => 1 - $expected], null, $site);
            $result = $this->beat($id, $report);
            $this->assertSame([$expected, $expected === 1], [$this->row($id)['offline_enabled'], $result['body']['offline_enabled']], $case);
        }
        $retired = $this->tablet(['storage_persisted' => 1, 'display_mode' => 'standalone']);
        $this->retire($retired);
        $this->assertFalse($this->beat($retired)['body']['offline_enabled'], 'retired');
        $this->assertSame(0, $this->row($retired)['offline_enabled']);

        $this->setSetting('offline_mode_enabled', '0');
        $off = $this->tablet(['offline_enabled' => 1]);
        $result = $this->beat($off);
        $this->assertSame([0, false], [$this->row($off)['offline_enabled'], $result['body']['offline_enabled']], 'offline_mode_enabled off');
    }

    public function testOfflineEnabledIsComputedUnderTheRowPredicate(): void
    {
        $id = $this->tablet(['offline_enabled' => 1, 'storage_persisted' => 1, 'display_mode' => 'standalone']);
        $guardRead = $this->guardRow($id); // the guard saw it in service at an active site
        $this->assertSame([1, 1], [(int) $guardRead['in_service'], (int) $guardRead['site_active']]);
        $this->retire($id); // a Coordinator retires it before the heartbeat's UPDATE runs
        $result = $this->beat($guardRead);
        $this->assertSame(200, $result['status']);
        $this->assertSame([0, false], [$this->row($id)['offline_enabled'], $result['body']['offline_enabled']], 'the Retire is never overwritten with 1');
    }

    public function testTheDevRelaxationTreatsABrowserTabAsInstalledButNothingElse(): void
    {
        $this->assertFalse(StationConfig::relaxInstallChecks(), 'off for every other test of this file');
        $this->relax(true);
        $this->assertTrue(StationConfig::relaxInstallChecks(), 'the test environment allows it');
        $tab = $this->tablet();
        $this->assertTrue($this->beat($tab, ['storage_persisted' => false, 'display_mode' => 'browser'])['body']['offline_enabled']);
        $row = $this->row($tab);
        $this->assertSame([1, 0, 'browser'], [$row['offline_enabled'], $row['storage_persisted'], $row['display_mode']], 'the report itself is stored as it is');

        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->south]);
        $closed = $this->tablet(['offline_enabled' => 1], null, $this->south);
        $this->assertFalse($this->beat($closed, ['display_mode' => 'browser'])['body']['offline_enabled'], 'an inactive site');
        $retired = $this->tablet();
        $this->retire($retired);
        $this->assertFalse($this->beat($retired, ['display_mode' => 'browser'])['body']['offline_enabled'], 'out of service');

        Config::override(['env' => 'staging'] + Config::snapshot());
        $staging = $this->tablet(['offline_enabled' => 1]);
        $this->assertFalse($this->beat($staging, ['display_mode' => 'browser'])['body']['offline_enabled'], 'outside dev and test the flag is ignored');
        $this->relax(true); // back in the test environment, the flag on

        $this->setSetting('offline_mode_enabled', '0');
        $off = $this->tablet(['offline_enabled' => 1]);
        $this->assertFalse($this->beat($off, ['display_mode' => 'browser'])['body']['offline_enabled'], 'the setting still rules');
    }

    // Directives -----------------------------------------------------------------------------

    public function testARetiringTabletGetsPushThenWipeWithoutClearSiteData(): void
    {
        $id = $this->tablet(['pending_count' => 9, 'offline_enabled' => 1]);
        $this->retire($id);
        $result = $this->beat($id, ['pending_count' => 4]);
        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['headers'], 'no Clear-Site-Data: it must still upload');
        $this->assertSame(['revoked', ['wipe' => 'Push Then Wipe'], false],
            [$result['body']['status'], $result['body']['directive'], $result['body']['offline_enabled']]);
        $this->assertSame(4, $this->row($id)['pending_count'], 'its upload progress is still recorded');
    }

    public function testAnErasingTabletGetsWipeNow(): void
    {
        $id = $this->tablet(['label' => 'Stolen one', 'pending_count' => 2]);
        $this->assertNotNull(DeviceService::erase($id, 'Stolen', 'Stolen one', 2, false, $this->rev($id), $this->scope($this->admin)));
        $result = $this->beat($id);
        $this->assertSame([200, []], [$result['status'], $result['headers']], 'the answer to a routine heartbeat never erases by header');
        $this->assertSame(['revoked', ['wipe' => 'Wipe Now'], false], [$result['body']['status'], $result['body']['directive'], $result['body']['offline_enabled']]);
    }

    // The erase confirmation -----------------------------------------------------------------

    public function testWipeConfirmationNeedsAValidProof(): void
    {
        $key = random_bytes(32);
        $id = $this->tablet(['pending_count' => 1], $key);
        $this->retire($id);

        $forged = $this->signedBeat($id, ['wiped' => true], random_bytes(32)); // the credential without the tablet's key
        $this->assertSame([200, 'revoked', []], [$forged['status'], $forged['body']['status'], $forged['headers']]);
        $this->assertNull($this->row($id)['wiped_at']);
        $this->assertNotNull($this->row($id)['proof_key_ciphertext']);
        $this->assertSame(['no_proof'], array_map(fn($a) => $this->details($a)['reason_code'], $this->audits('device_wipe_claim', $id)));

        $signed = $this->signedBeat($id, ['wiped' => true], $key);
        $this->assertSame(200, $signed['status']);
        $this->assertSame('wiped', $signed['body']['status']);
        $this->assertSame(['Clear-Site-Data' => '"cache", "storage"'], $signed['headers']);
        $this->assertSame(self::NOW, $this->row($id)['wiped_at']);
        $this->assertCount(1, $this->audits('device_wiped', $id));
    }

    public function testAStaleProofOnWipeConfirmationIs401WithServerTime(): void
    {
        $id = $this->tablet(['pending_count' => 2, 'last_seen_at' => '2026-10-01 09:00:00'], random_bytes(32));
        $this->retire($id);
        $before = $this->row($id);
        $e = $this->httpError(fn() => $this->beat($id, ['wiped' => true], 'stale'));
        $this->assertSame([401, 'device_proof_stale', ['server_time' => '2026-10-01 12:00:00.000']], [$e->status, $e->code(), $e->extra]);
        $this->assertSame($before, $this->row($id), 'nothing written: the tablet re-bases its offset and retries');
        $this->assertSame([], $this->audits('device_wiped', $id));
        $this->assertSame([], $this->audits('device_wipe_claim', $id));
    }

    public function testAnUnsignedWipeClaimIsIgnoredAndAudited(): void
    {
        $id = $this->tablet(['pending_count' => 8], random_bytes(32));
        $this->retire($id);
        foreach (['missing', 'invalid'] as $proof) {
            $result = $this->beat($id, ['wiped' => true, 'pending_count' => 6], $proof);
            $this->assertSame([200, 'revoked', ['wipe' => 'Push Then Wipe'], []],
                [$result['status'], $result['body']['status'], $result['body']['directive'], $result['headers']], "$proof: the normal answer");
        }
        $row = $this->row($id);
        $this->assertSame([null, 6, self::NOW], [$row['wiped_at'], $row['pending_count'], $row['last_seen_at']], 'the normal path ran; nothing was erased');
        $this->assertNotNull($row['vault_key_ciphertext']);
        $this->assertNotNull($row['proof_key_ciphertext']);
        $claims = $this->audits('device_wipe_claim', $id);
        $this->assertSame([['Denied', 'An erase confirmation was not signed by the tablet', ['reason_code' => 'no_proof']]],
            array_map(fn($a) => [$a['outcome'], $a['reason'], $this->details($a)], $claims), 'one row in the hour: the tablet keeps retrying');
        $this->assertSame([], $this->audits('device_wiped', $id));
    }

    public function testIgnoredWipeClaimsAreAuditedOnceAnHourPerTablet(): void
    {
        $retired = $this->tablet([], random_bytes(32));
        $this->retire($retired);
        $inService = $this->tablet([], random_bytes(32));
        foreach (['missing', 'invalid', 'missing'] as $proof) {
            $result = $this->beat($retired, ['wiped' => true], $proof);
            $this->assertSame([200, 'revoked'], [$result['status'], $result['body']['status']], "$proof: ignored, the normal answer");
        }
        $this->beat($inService, ['wiped' => true], 'valid');
        $this->beat($inService, ['wiped' => true], 'valid');
        Clock::advance('+59 minutes');
        $this->beat($retired, ['wiped' => true], 'invalid');
        $this->assertCount(1, $this->audits('device_wipe_claim', $retired), 'the tablet retries all hour: one row');

        Clock::advance('+1 minute');
        $this->beat($retired, ['wiped' => true], 'missing');
        $claims = fn(int $id) => array_map(fn($a) => [$a['occurred_at'], $this->details($a)['reason_code']], $this->audits('device_wipe_claim', $id));
        $this->assertSame([[self::NOW . '.000', 'no_proof'], ['2026-10-01 13:00:00.000', 'no_proof']], $claims($retired), 'the next hour has its own row');
        $this->assertSame([[self::NOW . '.000', 'in_service']], $claims($inService), 'each tablet has its own hour');
        $this->assertSame([null, null], [$this->row($retired)['wiped_at'], $this->row($inService)['wiped_at']]);
    }

    public function testAWipeClaimFromATabletWithoutAProofKeyIsIgnored(): void
    {
        $id = $this->tablet(['pending_count' => 1]); // a seeded tablet: no proof key, so its guard verdict is 'none'
        $this->retire($id);
        $this->assertSame(0, (int) $this->guardRow($id)['has_proof_key']);
        $result = $this->beat($id, ['wiped' => true], 'none');
        $this->assertSame([200, 'revoked'], [$result['status'], $result['body']['status']]);
        $this->assertNull($this->row($id)['wiped_at']);
        $this->assertSame([['reason_code' => 'no_proof_key']], array_map(fn($a) => $this->details($a), $this->audits('device_wipe_claim', $id)));
    }

    public function testWipeConfirmationIsIgnoredFromATabletInService(): void
    {
        $id = $this->tablet(['pending_count' => 5], random_bytes(32));
        $result = $this->beat($id, ['wiped' => true, 'pending_count' => 5], 'valid');
        $this->assertSame([200, 'ok', null, []], [$result['status'], $result['body']['status'], $result['body']['directive'], $result['headers']]);
        $row = $this->row($id);
        $this->assertSame([null, 5, self::NOW], [$row['wiped_at'], $row['pending_count'], $row['last_seen_at']]);
        $this->assertNotNull($row['vault_key_ciphertext']);
        $this->assertNotNull($row['proof_key_ciphertext']);
        $claims = $this->audits('device_wipe_claim', $id);
        $this->assertCount(1, $claims);
        $this->assertSame(['Denied', ['reason_code' => 'in_service']], [$claims[0]['outcome'], $this->details($claims[0])]);
        $this->assertSame([], $this->audits('device_wiped', $id));
    }

    public function testWipeConfirmationClearsTheVaultAndProofKeysShredsGrantSecretsAndSetsWipedAt(): void
    {
        $id = $this->tablet(['pending_count' => 4, 'attention_count' => 1, 'offline_enabled' => 1], random_bytes(32));
        $other = $this->tablet();
        $grants = [$this->token($id, null, Tokens::OFFLINE_GRANT, 'g1.test.secret-a'), $this->token($id, '2026-10-01 11:00:00', Tokens::OFFLINE_GRANT, 'g1.test.secret-b')];
        $spent = $this->token($id, null, Tokens::OFFLINE_GRANT); // no secret left to clear
        $trusted = $this->token($id, null, Tokens::TRUSTED_DEVICE, 'g1.test.trusted');
        $othersGrant = $this->token($other, null, Tokens::OFFLINE_GRANT, 'g1.test.other');
        $this->retire($id);

        $result = $this->beat($id, ['wiped' => true, 'items_pushed' => 3, 'pending_count' => 0], 'valid');
        $this->assertSame(['status' => 200, 'body' => ['status' => 'wiped', 'server_time' => '2026-10-01 12:00:00.000', 'build' => DeviceStatus::currentBuild()],
            'headers' => ['Clear-Site-Data' => '"cache", "storage"']], $result);

        $row = $this->row($id);
        $this->assertSame([self::NOW, 0, null, null, null, 0],
            [$row['wiped_at'], $row['pending_count'], $row['attention_count'], $row['vault_key_ciphertext'], $row['proof_key_ciphertext'], $row['offline_enabled']]);
        $this->assertNotNull($row['token_hash'], 'the credential stays, so the guard can answer 410');
        $secret = fn(int $tokenId) => $this->scalar('SELECT secret_ciphertext FROM auth_token WHERE token_id = ?', [$tokenId]);
        $this->assertSame([null, null, null], [$secret($grants[0]), $secret($grants[1]), $secret($spent)]);
        $this->assertSame(['g1.test.trusted', 'g1.test.other'], [$secret($trusted), $secret($othersGrant)], 'only this tablet\'s offline grants');

        $wiped = $this->audits('device_wiped', $id);
        $this->assertCount(1, $wiped);
        $this->assertSame(['Success', null, null, $this->north, $id, self::NOW . '.000'],
            [$wiped[0]['outcome'], $wiped[0]['user_id'], $wiped[0]['session_id'], $wiped[0]['site_id'], $wiped[0]['device_id'], $wiped[0]['occurred_at']]);
        $this->assertSame(self::sorted(['wipe_mode' => 'Push Then Wipe', 'items_pushed' => 3, 'reported_pending_count' => 4, 'grant_keys_cleared' => 2]), $this->details($wiped[0]));
    }

    public function testAnInvalidItemsPushedIsRecordedAsUnknown(): void
    {
        foreach ([10000001, -1, '5', null] as $pushed) {
            $id = $this->tablet(['pending_count' => 2], random_bytes(32));
            $this->retire($id);
            $this->assertSame('wiped', $this->beat($id, ['wiped' => true, 'items_pushed' => $pushed], 'valid')['body']['status']);
            $this->assertNull($this->details($this->audits('device_wiped', $id)[0])['items_pushed'], var_export($pushed, true));
        }
        $id = $this->tablet([], random_bytes(32));
        $this->retire($id);
        $this->beat($id, ['wiped' => true, 'items_pushed' => 10000000], 'valid');
        $this->assertSame(10000000, $this->details($this->audits('device_wiped', $id)[0])['items_pushed'], 'the upper bound is accepted');
    }

    public function testARepeatedConfirmationIsAnsweredAlikeAndAuditedOnce(): void
    {
        $id = $this->tablet([], random_bytes(32));
        $this->retire($id);
        $guardRead = $this->guardRow($id, 'valid'); // two confirmations read by the guard before either was stored
        $first = $this->beat($guardRead, ['wiped' => true]);
        $second = $this->beat($guardRead, ['wiped' => true]);
        $this->assertSame($first, $second);
        $this->assertSame('wiped', $second['body']['status']);
        $this->assertCount(1, $this->audits('device_wiped', $id));
    }

    public function testAConfirmedWipeMakesTheGuardAnswer410(): void
    {
        $id = $this->tablet([], random_bytes(32));
        $this->retire($id);
        $this->beat($id, ['wiped' => true], 'valid');
        $e = $this->httpError(fn() => DeviceGuard::authenticate('PFPMS-Device ' . $this->credentials[$id], DeviceGuard::KNOWN, '203.0.113.7', self::ENDPOINT));
        $this->assertSame([410, 'wiped', ['status' => 'wiped'], ['Clear-Site-Data' => '"cache", "storage"']], [$e->status, $e->code(), $e->extra, $e->headers]);
    }

    public function testTheCountWarningDropsAnErasedTablet(): void
    {
        $this->tablet(['pending_count' => 3]);
        $retiring = $this->tablet(['pending_count' => 5], random_bytes(32));
        $this->retire($retiring);
        $this->assertSame(8, CountRepository::pendingDeviceItems($this->north), 'a retiring tablet still has records to upload');
        $this->beat($retiring, ['wiped' => true, 'items_pushed' => 5], 'valid');
        $this->assertSame(3, CountRepository::pendingDeviceItems($this->north));
    }

    // Audit ----------------------------------------------------------------------------------

    public function testARoutineHeartbeatWritesNoAuditRow(): void
    {
        $id = $this->tablet(['storage_persisted' => 1, 'app_build' => '0.1.0-dev+ab12cd34ef', 'display_mode' => 'standalone', 'offline_enabled' => 1, 'pending_count' => 1]);
        $before = $this->auditCount();
        $this->assertSame(200, $this->beat($id, ['pending_count' => 7, 'max_seq' => 50, 'storage_estimate_kb' => 900])['status']);
        $this->assertSame(7, $this->row($id)['pending_count']);
        $this->assertSame($before, $this->auditCount(), 'counts and sequence numbers are not state changes');
    }

    public function testChangesOfStateAreAudited(): void
    {
        $id = $this->tablet();
        $this->beat($id, ['locked_out_since' => '2026-10-01 11:40:00.000']);
        $rows = $this->audits('device_state', $id);
        $this->assertCount(1, $rows);
        $this->assertSame(['Success', null, $id, $this->north, ['proof' => 'none']],
            [$rows[0]['outcome'], $rows[0]['user_id'], $rows[0]['device_id'], $rows[0]['site_id'], $this->details($rows[0])], 'a seeded tablet: no proof key');
        $this->assertSame([
            'app_build' => [null, '0.1.0-dev+ab12cd34ef'],
            'display_mode' => [null, 'standalone'],
            'locked_out_since' => [null, '2026-10-01 11:40:00'],
            'offline_enabled' => ['0', '1'],
            'storage_persisted' => ['0', '1'],
        ], $this->fieldChanges((int) $rows[0]['audit_id']));

        $this->beat($id, ['locked_out_since' => '2026-10-01 11:40:00.000']);
        $this->assertCount(1, $this->audits('device_state', $id), 'the same report again changes nothing');

        $this->beat($id, ['display_mode' => 'browser']);
        $rows = $this->audits('device_state', $id);
        $this->assertCount(2, $rows);
        $this->assertSame(['display_mode' => ['standalone', 'browser'], 'locked_out_since' => ['2026-10-01 11:40:00', null], 'offline_enabled' => ['1', '0']],
            $this->fieldChanges((int) $rows[1]['audit_id']), 'only what changed');
        $this->assertSame(['proof' => 'none'], $this->details($rows[1]));
    }

    public function testRowsWrittenFromTheReportCarryTheGuardsProofVerdict(): void
    {
        $key = random_bytes(32);
        $report = ['failed_unlock_wipe' => true, 'clock_rollback' => true,
            'auth_failures' => [['user_id' => null, 'factor' => 'pin', 'count' => 1, 'first_at' => '2026-10-01 11:55:00.000', 'last_at' => '2026-10-01 11:55:00.000']]];
        $cases = [
            'valid' => [$this->tablet([], $key), $key], // the tablet itself
            'missing' => [$this->tablet([], random_bytes(32)), null], // its credential alone, no proof header
            'invalid' => [$this->tablet([], random_bytes(32)), random_bytes(32)], // its credential with some other key
        ];
        foreach ($cases as $verdict => [$id, $signingKey]) {
            $device = $this->guarded($id, $report, $signingKey);
            $this->assertSame($verdict, $device['proof'], 'the guard\'s verdict');
            $this->assertSame(200, DeviceHeartbeat::receive($device, Request::json())['status'], $verdict);
            $state = $this->audits('device_state', $id);
            $this->assertCount(1, $state, "$verdict: the first report is a change of state");
            $this->assertSame(['proof' => $verdict], $this->details($state[0]), "$verdict: device_state");
            foreach (['device_unlock_wipe', 'device_clock_rollback', 'offline_auth_failures'] as $action) {
                $rows = $this->audits($action, $id);
                $this->assertCount(1, $rows, "$verdict: $action");
                $this->assertSame($verdict, $this->details($rows[0])['proof'], "$verdict: $action tells a copied credential apart");
            }
        }
    }

    public function testTheSameLockoutReportedWithAJitteringSkewIsOneStateChange(): void
    {
        $id = $this->tablet();
        // The tablet reports the same lockout every time; the second of rounding in client_now moves the corrected time to and fro.
        foreach ([0, 1, 0, 1, 0, 1] as $i => $skew) {
            $this->beat($id, ['client_now' => Clock::dbMillis(Clock::now()->modify("-$skew seconds")), 'locked_out_since' => '2026-10-01 11:40:00.000']);
            $row = $this->row($id);
            $this->assertSame([$skew, '2026-10-01 11:40:00'], [$row['clock_skew_seconds'], $row['locked_out_since']], "heartbeat $i keeps the stored time");
        }
        $rows = $this->audits('device_state', $id);
        $this->assertCount(1, $rows, 'exactly one device_state row: the jitter is not a change');
        $this->assertSame([null, '2026-10-01 11:40:00'], $this->fieldChanges((int) $rows[0]['audit_id'])['locked_out_since']);

        foreach (['2026-10-01 11:40:02.000', '2026-10-01 11:39:58.000'] as $near) {
            $this->beat($id, ['locked_out_since' => $near]);
            $this->assertSame('2026-10-01 11:40:00', $this->row($id)['locked_out_since'], "$near: two seconds off is the same lockout");
        }
        $this->assertCount(1, $this->audits('device_state', $id));
        $this->beat($id, ['locked_out_since' => '2026-10-01 11:40:03.000']);
        $rows = $this->audits('device_state', $id);
        $this->assertCount(2, $rows, 'three seconds off is a new lockout');
        $this->assertSame(['locked_out_since' => ['2026-10-01 11:40:00', '2026-10-01 11:40:03']], $this->fieldChanges((int) $rows[1]['audit_id']));
    }

    public function testARetireCommittedAfterTheGuardReadIsNotRecordedAsTheHeartbeatsChange(): void
    {
        $id = $this->tablet(['offline_enabled' => 1, 'storage_persisted' => 1, 'display_mode' => 'standalone', 'app_build' => '0.1.0-dev+ab12cd34ef']);
        $guardRead = $this->guardRow($id); // the guard saw it in service, offline_enabled 1
        $this->assertSame([1, null], [(int) $guardRead['offline_enabled'], $guardRead['revoked_at']]);
        $this->retire($id); // a Coordinator's Retire commits before the heartbeat locks the row
        $result = $this->beat($guardRead); // the same report the tablet always sends
        $this->assertSame([200, 'revoked', ['wipe' => 'Push Then Wipe'], false],
            [$result['status'], $result['body']['status'], $result['body']['directive'], $result['body']['offline_enabled']], 'the tablet learns it now');
        $this->assertSame([], $this->audits('device_state', $id), 'offline_enabled 1 to 0 was the Retire, not something the tablet reported');

        $this->beat($guardRead, ['display_mode' => 'browser']);
        $rows = $this->audits('device_state', $id);
        $this->assertCount(1, $rows);
        $this->assertSame(['display_mode' => ['standalone', 'browser']], $this->fieldChanges((int) $rows[0]['audit_id']), 'changes are measured from the row as locked');
    }

    public function testAFailedUnlockWipeIsAudited(): void
    {
        $id = $this->tablet();
        $this->beat($id, ['failed_unlock_wipe' => true, 'pending_count' => 6, 'locked_out_since' => '2026-10-01 11:50:00.000']);
        $rows = $this->audits('device_unlock_wipe', $id);
        $this->assertCount(1, $rows);
        $this->assertSame(['Success', 'The tablet erased its offline sign-ins after too many wrong passwords; its unsent records were kept',
            self::sorted(['pending_count' => 6, 'proof' => 'none']), $id, null],
            [$rows[0]['outcome'], $rows[0]['reason'], $this->details($rows[0]), $rows[0]['device_id'], $rows[0]['user_id']]);
        $this->beat($id, ['failed_unlock_wipe' => false]);
        $this->assertCount(1, $this->audits('device_unlock_wipe', $id), 'only when reported');
    }

    public function testAClockRollbackIsAudited(): void
    {
        $id = $this->tablet();
        $this->beat($id, ['clock_rollback' => true]);
        $rows = $this->audits('device_clock_rollback', $id);
        $this->assertCount(1, $rows);
        $this->assertSame(['Denied', 'The tablet clock was set back', ['proof' => 'none'], $id],
            [$rows[0]['outcome'], $rows[0]['reason'], $this->details($rows[0]), $rows[0]['device_id']]);
        $this->beat($id, ['clock_rollback' => 'yes']);
        $this->assertCount(1, $this->audits('device_clock_rollback', $id), 'only a JSON true counts');
    }

    public function testOfflineSignInFailuresAreOneAuditRowWithClampedTime(): void
    {
        $person = $this->makeUser(['password_hash' => self::$passwordHash]);
        $id = $this->tablet();
        $failures = [
            ['user_id' => $person['user_id'], 'factor' => 'password', 'count' => 2, 'first_at' => '2026-10-01 09:00:00.000', 'last_at' => '2026-10-01 09:05:00.500'],
            ['user_id' => null, 'factor' => 'pin', 'count' => 3, 'first_at' => '2026-10-01 10:00:00.000', 'last_at' => '2026-10-01 11:30:00.250'],
        ];
        $this->beat($id, ['client_now' => '2026-10-01 11:58:00.000', 'auth_failures' => $failures]); // the tablet's clock is 2 minutes slow
        $rows = $this->audits('offline_auth_failures', $id);
        $this->assertCount(1, $rows, 'one row for every group');
        $this->assertSame(['Failed', 'Wrong password or PIN while offline (reported by the tablet)', null, null, $id, $this->north, '2026-10-01 11:32:00.250'],
            [$rows[0]['outcome'], $rows[0]['reason'], $rows[0]['user_id'], $rows[0]['session_id'], $rows[0]['device_id'], $rows[0]['site_id'], $rows[0]['occurred_at']],
            'dated at the latest failure, corrected by the skew');
        $this->assertSame(self::sorted(['failures' => $failures, 'proof' => 'none']), $this->details($rows[0]));
    }

    public function testAFailureReportedInTheFutureIsDatedNow(): void
    {
        $id = $this->tablet();
        $this->beat($id, ['auth_failures' => [['user_id' => null, 'factor' => 'pin', 'count' => 1, 'first_at' => '2026-10-01 12:30:00.000', 'last_at' => '2026-10-01 13:00:00.000']]]);
        $this->assertSame('2026-10-01 12:00:00.000', $this->audits('offline_auth_failures', $id)[0]['occurred_at']);
    }

    public function testAFailureReportedLongAgoIsDatedAtMostThirtyDaysBack(): void
    {
        $id = $this->tablet();
        $this->beat($id, ['auth_failures' => [['user_id' => null, 'factor' => 'password', 'count' => 1, 'first_at' => '2026-08-01 10:00:00.000', 'last_at' => '2026-08-01 10:00:00.000']]]);
        $this->assertSame('2026-09-01 12:00:00.000', $this->audits('offline_auth_failures', $id)[0]['occurred_at']);
    }

    public function testMalformedFailureGroupsAreDroppedAndAtMostTwentyKept(): void
    {
        $good = fn(int $i) => ['user_id' => $i, 'factor' => 'pin', 'count' => $i, 'first_at' => '2026-10-01 10:00:00.000', 'last_at' => '2026-10-01 10:00:00.000'];
        $bad = [
            ['factor' => 'sms'] + $good(1), ['count' => 0] + $good(1), ['count' => 1001] + $good(1), ['count' => '2'] + $good(1),
            ['last_at' => '2026-10-01 10:00:00'] + $good(1), ['first_at' => null] + $good(1), ['user_id' => '12'] + $good(1), ['user_id' => 0] + $good(1),
            'not a group', [1, 2, 3],
        ];
        $onlyBad = $this->tablet();
        $this->beat($onlyBad, ['auth_failures' => $bad]);
        $this->assertSame([], $this->audits('offline_auth_failures', $onlyBad), 'nothing well-formed: no row');

        $id = $this->tablet();
        $noUser = $good(1);
        unset($noUser['user_id']);
        $this->beat($id, ['auth_failures' => array_merge($bad, [$noUser], array_map($good, range(2, 25)))]);
        $failures = $this->details($this->audits('offline_auth_failures', $id)[0])['failures'];
        $this->assertCount(20, $failures, 'extras are ignored');
        $this->assertSame(self::sorted(['user_id' => null, 'factor' => 'pin', 'count' => 1, 'first_at' => '2026-10-01 10:00:00.000', 'last_at' => '2026-10-01 10:00:00.000']), $failures[0],
            'a group without user_id is kept, as an unknown person');
        $this->assertSame(range(2, 20), array_column(array_slice($failures, 1), 'user_id'));
    }

    public function testAuthFailuresNeverTouchLockoutCounters(): void
    {
        $person = $this->makeUser(['failed_login_count' => 2, 'pin_failed_count' => 1, 'password_hash' => self::$passwordHash]);
        $id = $this->tablet();
        $this->beat($id, ['auth_failures' => [
            ['user_id' => $person['user_id'], 'factor' => 'password', 'count' => 9, 'first_at' => '2026-10-01 11:00:00.000', 'last_at' => '2026-10-01 11:10:00.000'],
            ['user_id' => $person['user_id'], 'factor' => 'pin', 'count' => 9, 'first_at' => '2026-10-01 11:00:00.000', 'last_at' => '2026-10-01 11:10:00.000'],
        ]]);
        $this->assertCount(1, $this->audits('offline_auth_failures', $id));
        $st = Db::pdo()->prepare('SELECT failed_login_count, pin_failed_count, status, locked_until FROM user_account WHERE user_id = ?');
        $st->execute([$person['user_id']]);
        $this->assertSame(['failed_login_count' => 2, 'pin_failed_count' => 1, 'status' => 'Active', 'locked_until' => null], $st->fetch());
    }

    // Clone signal, bucket and response ------------------------------------------------------

    public function testAMaxSeqGoingBackwardsRaisesTheCloneSignal(): void
    {
        $this->assertSame(self::ENDPOINT, Request::scriptPath());
        $id = $this->tablet(['label' => 'Front desk 3', 'reported_max_seq' => 41]);
        $this->assertSame(200, $this->beat($id, ['max_seq' => 40])['status'], 'served all the same');
        $this->assertSame(41, $this->row($id)['reported_max_seq']);
        $rows = $this->audits('device_unproven', $id);
        $this->assertCount(1, $rows);
        $this->assertSame(['Denied', "A request used this tablet's credential without the tablet's own key", self::sorted(['endpoint' => self::ENDPOINT, 'signal' => 'seq_went_back'])],
            [$rows[0]['outcome'], $rows[0]['reason'], $this->details($rows[0])]);
        $alerts = Db::pdo()->query("SELECT recipient_role, site_id, entity_type, entity_id, message FROM notification WHERE kind = 'device_clone_suspected'")->fetchAll();
        $this->assertCount(1, $alerts);
        $this->assertSame(['Administrator', null, 'device', $id], [$alerts[0]['recipient_role'], $alerts[0]['site_id'], $alerts[0]['entity_type'], $alerts[0]['entity_id']]);
        $this->assertStringStartsWith('Front desk 3 (Northside): ', $alerts[0]['message']);

        $this->beat($id, ['max_seq' => 39]);
        $this->assertCount(1, $this->audits('device_unproven', $id), 'once an hour');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'device_clone_suspected'"));
    }

    public function testAnEqualOrHigherMaxSeqIsNoCloneSignal(): void
    {
        $id = $this->tablet(['reported_max_seq' => 41]);
        foreach ([41, 42, null] as $seq) {
            $this->beat($id, ['max_seq' => $seq]);
        }
        $this->assertSame([], $this->audits('device_unproven', $id));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'device_clone_suspected'"));
    }

    public function testTheHeartbeatBucketLimits(): void
    {
        $id = $this->tablet();
        $other = $this->tablet();
        for ($i = 1; $i <= 120; $i++) {
            $this->assertSame(200, $this->beat($id)['status'], "heartbeat $i");
        }
        Clock::advance('+1 minute');
        $e = $this->httpError(fn() => $this->beat($id));
        $this->assertSame([429, 'rate_limited', ['Retry-After' => '900']], [$e->status, $e->code(), $e->headers]);
        $this->assertSame(self::NOW, $this->row($id)['last_seen_at'], 'refused before anything is written');
        $this->assertSame(200, $this->beat($other)['status'], 'the bucket is per tablet');
        Clock::advance('+14 minutes');
        $this->assertSame(200, $this->beat($id)['status'], 'a new window after 900 seconds');
    }

    public function testHeartbeatsWithoutTheProofCannotSpendTheTabletsOwnBudget(): void
    {
        $key = random_bytes(32);
        $id = $this->tablet(['pending_count' => 3], $key);
        $this->retire($id);
        for ($i = 1; $i <= 120; $i++) {
            $this->assertSame(200, $this->beat($id, [], 'missing')['status'], "unproven heartbeat $i");
        }
        $e = $this->httpError(fn() => $this->beat($id, [], 'missing'));
        $this->assertSame([429, 'rate_limited'], [$e->status, $e->code()], 'the copied credential has spent its own budget');
        $this->assertSame(429, $this->httpError(fn() => $this->beat($id, [], 'invalid'))->status, 'an invalid proof shares it');

        $result = $this->signedBeat($id, ['pending_count' => 1], $key);
        $this->assertSame([200, 'revoked', ['wipe' => 'Push Then Wipe']], [$result['status'], $result['body']['status'], $result['body']['directive']],
            'the tablet itself still gets its directive');
        $this->assertSame(1, $this->row($id)['pending_count']);
    }

    public function testReplayedStaleSignaturesCannotSpendTheTabletsOwnBudget(): void
    {
        $key = random_bytes(32);
        $id = $this->tablet([], $key);
        $this->retire($id);
        $this->assertSame('stale', $this->guarded($id, [], $key, self::staleTs())['proof'], 'a signed heartbeat copied from the network log');
        for ($i = 1; $i <= 120; $i++) {
            $this->assertSame(200, $this->signedBeat($id, [], $key, self::staleTs())['status'], "replayed heartbeat $i");
        }
        $e = $this->httpError(fn() => $this->signedBeat($id, [], $key, self::staleTs()));
        $this->assertSame([429, 'rate_limited'], [$e->status, $e->code()], 'a stale proof counts as unproven');

        $result = $this->signedBeat($id, [], $key);
        $this->assertSame([200, 'revoked', ['wipe' => 'Push Then Wipe']], [$result['status'], $result['body']['status'], $result['body']['directive']],
            'the tablet itself still gets its directive');
    }

    public function testASpentBucketStillGivesARevokedTabletItsDirectiveAndWritesNothing(): void
    {
        $id = $this->tablet(['label' => 'Lost one', 'pending_count' => 2]); // seeded: no proof key, so its heartbeats count as proven ('none')
        $this->assertNotNull(DeviceService::erase($id, 'Stolen', 'Lost one', 2, false, $this->rev($id), $this->scope($this->admin)));
        for ($i = 1; $i <= 120; $i++) {
            $this->assertSame(200, $this->beat($id)['status'], "heartbeat $i");
        }
        Clock::advance('+1 minute');
        $row = $this->row($id);
        $trail = $this->trail($id);
        $e = $this->httpError(fn() => $this->beat($id, ['pending_count' => 1, 'display_mode' => 'browser', 'locked_out_since' => '2026-10-01 11:50:00.000',
            'failed_unlock_wipe' => true, 'clock_rollback' => true, 'wiped' => true,
            'auth_failures' => [['user_id' => null, 'factor' => 'pin', 'count' => 1, 'first_at' => '2026-10-01 11:55:00.000', 'last_at' => '2026-10-01 11:55:00.000']]]));
        $this->assertSame([429, 'rate_limited', ['status' => 'revoked', 'directive' => ['wipe' => 'Wipe Now']], ['Retry-After' => '900']],
            [$e->status, $e->code(), $e->extra, $e->headers], 'the tablet still learns it must erase itself');
        $this->assertSame($row, $this->row($id), 'refused before anything is written');
        $this->assertSame($trail, $this->trail($id), 'no audit row, field change or alert');

        $inService = $this->tablet();
        for ($i = 1; $i <= 120; $i++) {
            $this->beat($inService);
        }
        $e = $this->httpError(fn() => $this->beat($inService));
        $this->assertSame([429, []], [$e->status, $e->extra], 'a tablet in service has no directive to carry');
    }

    public function testAnUnprovenMaxSeqNeverReachesReportedMaxSeq(): void
    {
        $id = $this->tablet(['reported_max_seq' => 41], random_bytes(32));
        $fresh = $this->tablet([], random_bytes(32)); // never reported one
        foreach (['missing', 'invalid', 'stale'] as $proof) {
            $this->assertSame(200, $this->beat($id, ['max_seq' => 2147483647], $proof)['status'], "$proof: served all the same");
            $this->beat($fresh, ['max_seq' => 2147483647], $proof);
            $this->assertSame([41, null], [$this->row($id)['reported_max_seq'], $this->row($fresh)['reported_max_seq']],
                "$proof: a copied credential cannot push it to the top, where it would never come down");
        }
        $this->beat($id, ['max_seq' => 50], 'valid');
        $this->beat($fresh, ['max_seq' => 7], 'valid');
        $this->assertSame([50, 7], [$this->row($id)['reported_max_seq'], $this->row($fresh)['reported_max_seq']], "the tablet's own report still moves it");
    }

    public function testOnlyAProvenHeartbeatRaisesTheSeqWentBackSignal(): void
    {
        $id = $this->tablet(['reported_max_seq' => 41], random_bytes(32));
        foreach (['missing', 'invalid', 'stale'] as $proof) {
            $this->assertSame(200, $this->beat($id, ['max_seq' => 40], $proof)['status'], $proof);
        }
        $this->assertSame([], $this->audits('device_unproven', $id), "an unproven report says nothing about the tablet's records");
        $this->assertSame(0, $this->cloneAlerts($id));

        $this->beat($id, ['max_seq' => 40], 'valid');
        $this->assertSame([self::sorted(['endpoint' => self::ENDPOINT, 'signal' => 'seq_went_back'])],
            array_map(fn($a) => $this->details($a), $this->audits('device_unproven', $id)), 'the tablet itself reports fewer records than before');
        $this->assertSame(1, $this->cloneAlerts($id));
        $this->assertSame(41, $this->row($id)['reported_max_seq']);
    }

    public function testTheResponseCarriesRevokedGrantsConfigAndBuild(): void
    {
        $this->setSetting('organisation_name', 'Riverside Pet Pantry');
        $this->setSetting('pin_max_failed', '5');
        $id = $this->tablet(['label' => 'Front desk 3']);
        $grant = $this->token($id, '2026-10-01 10:00:00');
        $result = $this->beat($id);
        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['headers']);
        $this->assertSame([
            'status' => 'ok', 'offline_enabled' => true, 'label' => 'Front desk 3', 'site' => ['site_id' => $this->north, 'name' => 'Northside'],
            'directive' => null, 'revoked_grants' => [$grant],
            'config' => ['organisation_name' => 'Riverside Pet Pantry', 'session_idle_minutes' => 30, 'session_absolute_hours' => 12, 'pin_min_digits' => 4,
                'pin_max_digits' => 6, 'pin_max_failed' => 5, 'pin_shift_hours' => 12, 'offline_grant_hours' => 72, 'offline_max_failed_unlocks' => 10,
                'sync_clock_skew_minutes' => 10, 'offline_mode_enabled' => true],
            'server_time' => '2026-10-01 12:00:00.000', 'build' => DeviceStatus::currentBuild(),
        ], $result['body']);
        $this->assertSame(StationConfig::client(), $result['body']['config']);
    }

    public function testRevokedGrantsAreThisTabletsOfflineGrantsRevokedInTheLastWeek(): void
    {
        $id = $this->tablet();
        $other = $this->tablet();
        $listed = [];
        $listed[] = $this->token($id, '2026-09-24 12:00:01'); // 167 h 59 min 59 s ago
        $this->token($id, '2026-09-24 12:00:00'); // exactly 168 hours ago
        $this->token($id, null); // still valid
        $this->token($other, '2026-10-01 11:00:00'); // another tablet's
        $this->token($id, '2026-10-01 11:00:00', Tokens::TRUSTED_DEVICE); // not a grant
        $this->token($id, '2026-10-01 11:00:00', Tokens::DEVICE_REGISTRATION);
        $listed[] = $this->token($id, self::NOW);
        $this->assertSame($listed, $this->beat($id)['body']['revoked_grants']);
    }
}
