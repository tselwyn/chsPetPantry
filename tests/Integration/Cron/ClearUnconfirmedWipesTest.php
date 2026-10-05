<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Cron;

use Pfpms\Audit\Audit;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Cron\Jobs\ClearUnconfirmedWipes;
use Pfpms\Cron\Jobs\DeactivateDueAccounts;
use Pfpms\Cron\Runner;
use Pfpms\Db;
use Pfpms\Device\DeviceGuard;
use Pfpms\Device\DeviceHeartbeat;
use Pfpms\Device\DeviceProof;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\HttpException;
use Pfpms\Http\Request;
use Pfpms\Security\Crypto;
use Pfpms\Tests\TestCase;

/**
 * devices:clear-unconfirmed-wipes (50-design D-55, §12.1): a tablet taken out of service that never confirmed its erase
 * loses its server-side vault key and its offline grants' secrets once sync_payload_retention_days have passed since it
 * was revoked. Its proof key stays (it opens nothing), so a tablet that turns up later can still confirm its erase with a
 * signed heartbeat (S1 review).
 */
final class ClearUnconfirmedWipesTest extends TestCase
{
    private const HEARTBEAT = 'api/device/heartbeat.php';
    private const IP = '203.0.113.7';
    private const SERVER_KEYS = ['REQUEST_METHOD', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'REMOTE_ADDR', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION',
        'HTTP_PFPMS_PROOF', 'HTTP_X_PFPMS_CLIENT', 'CONTENT_TYPE', 'CONTENT_LENGTH'];

    private int $site;
    /** The person the hand-made grants belong to. */
    private int $holder;
    private array $savedServer = [];
    private array $savedConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::SERVER_KEYS as $key) {
            if (array_key_exists($key, $_SERVER)) {
                $this->savedServer[$key] = $_SERVER[$key];
            }
            unset($_SERVER[$key]);
        }
        $this->savedConfig = Config::snapshot();
        Config::override(['app' => ['base_path' => '/'] + (array) ($this->savedConfig['app'] ?? [])] + $this->savedConfig);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['SCRIPT_NAME'] = '/' . self::HEARTBEAT;
        $_SERVER['REMOTE_ADDR'] = self::IP;

        $this->site = $this->makeSite('Northside');
        $this->holder = (int) $this->makeUser(['password_hash' => 'not used: nobody signs in here'])['user_id'];
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
        Audit::setActor(null);
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    /** A registered tablet holding both keys, in service unless $overrides say otherwise. */
    private function tablet(array $overrides = []): int
    {
        static $n = 0;
        $n++;
        return $this->makeDevice($this->site, $overrides + ['label' => "Tablet $n", 'token_hash' => hash('sha256', "pfd1_cron_$n" . bin2hex(random_bytes(8))),
            'is_site_registered' => 1, 'vault_key_ciphertext' => "g1.test.vault$n", 'proof_key_ciphertext' => "g1.test.proof$n", 'pending_count' => 3]);
    }

    /** A tablet retired (Push Then Wipe) at $revokedAt that has not confirmed its erase. */
    private function retired(string $revokedAt, array $overrides = []): int
    {
        return $this->tablet($overrides + ['revoked_at' => $revokedAt, 'wipe_mode' => 'Push Then Wipe', 'is_site_registered' => 0, 'revoked_max_seq' => 0]);
    }

    private function ago(string $modifier): string
    {
        return Clock::db(Clock::now()->modify("-$modifier"));
    }

    private function row(int $id): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM device WHERE device_id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    /** @return list<array> the job's audit rows about the tablet */
    private function cleared(int $id): array
    {
        return $this->audits('device_vault_key_cleared', $id);
    }

    /** @return list<array> the audit rows of $action about the tablet, oldest first */
    private function audits(string $action, int $id): array
    {
        $st = Db::pdo()->prepare("SELECT * FROM audit_log WHERE action = ? AND entity_type = 'device' AND entity_id = ? ORDER BY audit_id");
        $st->execute([$action, $id]);
        return $st->fetchAll();
    }

    /** An audit row's details, keys sorted (MySQL's JSON type reorders keys; MariaDB keeps them). */
    private function details(array $audit): ?array
    {
        if ($audit['details'] === null) {
            return null;
        }
        $details = json_decode((string) $audit['details'], true, 512, JSON_THROW_ON_ERROR);
        ksort($details, SORT_STRING);
        return $details;
    }

    /** An auth_token row of the tablet, inserted by hand (S3 issues grants); $secret null is a grant already shredded. */
    private function grant(int $deviceId, ?string $secret, string $purpose = Tokens::OFFLINE_GRANT, ?string $revokedAt = null): int
    {
        Db::pdo()->prepare('INSERT INTO auth_token (user_id, purpose, token_hash, secret_ciphertext, device_id, expires_at, revoked_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$this->holder, $purpose, hash('sha256', random_bytes(16)), $secret, $deviceId, Clock::db(Clock::now()->modify('+3 days')), $revokedAt, $this->ago('200 days')]);
        return (int) Db::pdo()->lastInsertId();
    }

    private function secret(int $tokenId): ?string
    {
        $value = $this->scalar('SELECT secret_ciphertext FROM auth_token WHERE token_id = ?', [$tokenId]);
        return $value === false ? null : $value;
    }

    private function runJob(): string
    {
        return (new ClearUnconfirmedWipes())->run();
    }

    /** A tablet with a real credential (DeviceGuard::FORMAT) and its own proof key, as registration stores it (AAD device:<id>:proof). */
    private function keyedTablet(array $overrides): array
    {
        $credential = 'pfd1_' . Crypto::b64url(random_bytes(32));
        $this->assertSame(1, preg_match(DeviceGuard::FORMAT, $credential));
        $id = $this->retired($overrides['revoked_at'], ['token_hash' => Tokens::hash($credential)] + $overrides);
        $key = random_bytes(32);
        Db::pdo()->prepare('UPDATE device SET proof_key_ciphertext = ? WHERE device_id = ?')->execute([Crypto::encrypt($key, "device:$id:proof"), $id]);
        return [$id, $credential, $key];
    }

    /**
     * POST api/device/heartbeat.php with this raw body as the endpoint runs it: DeviceGuard::authenticate() in KNOWN mode (as
     * Api::start() step 4 calls it), with a PFPMS-Proof header signed with $key (null: none), then DeviceHeartbeat::receive().
     */
    private function heartbeat(string $credential, string $raw, ?string $key): array
    {
        Request::useBody($raw, 'application/json');
        $_SERVER['HTTP_AUTHORIZATION'] = 'PFPMS-Device ' . $credential;
        unset($_SERVER['HTTP_PFPMS_PROOF']);
        if ($key !== null) {
            $ts = (int) Clock::now()->format('Uv');
            $_SERVER['HTTP_PFPMS_PROOF'] = "v1 $ts " . Crypto::b64url(Crypto::hmac($key, DeviceProof::message('POST', self::HEARTBEAT, $ts, hash('sha256', $raw))));
        }
        $device = DeviceGuard::authenticate(Request::authorization(), DeviceGuard::KNOWN, Request::ip(), Request::scriptPath());
        $this->assertSame($key === null ? 'missing' : 'valid', $device['proof'], 'the guard\'s verdict on the proof');
        return DeviceHeartbeat::receive($device, Request::json());
    }

    // Tests ----------------------------------------------------------------------------------

    public function testClearsOnlyTheVaultKeyAndShredsTheGrantSecretsOfTabletsRevokedLongAgo(): void
    {
        $retired = $this->retired($this->ago('91 days'));
        $erasing = $this->retired($this->ago('200 days'), ['wipe_mode' => 'Wipe Now', 'erase_requested_at' => $this->ago('150 days')]);
        $grants = [$this->grant($retired, 'g1.test.grant-a'), $this->grant($retired, 'g1.test.grant-b', Tokens::OFFLINE_GRANT, $this->ago('91 days'))];
        $shredded = $this->grant($retired, null); // nothing left to shred: not counted
        $trusted = $this->grant($retired, 'g1.test.trusted', Tokens::TRUSTED_DEVICE);
        $proofOnly = $this->retired($this->ago('100 days'), ['vault_key_ciphertext' => null]); // its vault key is already gone
        $ids = [$retired, $erasing, $proofOnly];
        $before = array_combine($ids, array_map(fn(int $id) => $this->row($id), $ids));

        $this->assertSame('cleared the keys of 2 tablet(s)', $this->runJob());
        foreach ([$retired => 2, $erasing => 0] as $id => $grantKeys) {
            $row = $this->row($id);
            $this->assertSame(array_replace($before[$id], ['vault_key_ciphertext' => null]), $row,
                "tablet $id: only the vault key is cleared; still recognised, still told to erase, never marked erased");
            $this->assertNotNull($row['proof_key_ciphertext'], "tablet $id keeps its proof key for a late confirmation");
            $audits = $this->cleared($id);
            $this->assertCount(1, $audits, "tablet $id");
            $this->assertSame(['Success', 'Never confirmed its erase', null, null, self::NOW . '.000'],
                [$audits[0]['outcome'], $audits[0]['reason'], $audits[0]['user_id'], $audits[0]['session_id'], $audits[0]['occurred_at']]);
            $this->assertSame(['grant_keys_cleared' => $grantKeys], $this->details($audits[0]), "tablet $id");
        }
        $this->assertSame([null, null, null], [$this->secret($grants[0]), $this->secret($grants[1]), $this->secret($shredded)], 'the offline grants\' secrets are gone');
        $this->assertSame('g1.test.trusted', $this->secret($trusted), 'only Offline Grant secrets are shredded');
        $this->assertSame($before[$proofOnly], $this->row($proofOnly), 'no vault key: nothing to clear, and its proof key stays');
        $this->assertSame([], $this->cleared($proofOnly), 'nothing cleared, nothing audited');

        $after = array_map(fn(int $id) => $this->row($id), $ids);
        $this->assertSame('cleared the keys of 0 tablet(s)', $this->runJob(), 'a second run finds nothing');
        $this->assertSame($after, array_map(fn(int $id) => $this->row($id), $ids), 'and changes nothing: the proof keys stay');
        $this->assertSame([1, 1, 0], array_map(fn(int $id) => count($this->cleared($id)), $ids), 'and audits nothing');
        $this->assertSame('g1.test.trusted', $this->secret($trusted));
    }

    public function testATabletFoundAfterTheJobCanStillConfirmItsEraseWithItsOwnProofKey(): void
    {
        [$id, $credential, $key] = $this->keyedTablet(['revoked_at' => $this->ago('120 days'), 'pending_count' => 2]);
        $grant = $this->grant($id, 'g1.test.grant');
        $this->assertSame('cleared the keys of 1 tablet(s)', $this->runJob());
        $row = $this->row($id);
        $this->assertSame([null, null, null], [$row['vault_key_ciphertext'], $row['wiped_at'], $this->secret($grant)]);
        $this->assertNotNull($row['proof_key_ciphertext'], 'the job kept the proof key');

        $raw = json_encode(['wiped' => true, 'items_pushed' => 0, 'client_now' => Clock::dbMillis()], JSON_THROW_ON_ERROR);

        // A copy of the credential without the tablet's key cannot confirm the erase: the claim is ignored and the directive stands.
        $copy = $this->heartbeat($credential, $raw, null);
        $this->assertSame([200, 'revoked', ['wipe' => 'Push Then Wipe'], []], [$copy['status'], $copy['body']['status'], $copy['body']['directive'], $copy['headers']]);
        $row = $this->row($id);
        $this->assertNull($row['wiped_at']);
        $this->assertNotNull($row['proof_key_ciphertext']);
        $this->assertSame([['reason_code' => 'no_proof']], array_map(fn(array $a) => $this->details($a), $this->audits('device_wipe_claim', $id)));

        // The tablet itself signs the same confirmation with the proof key the job kept.
        $signed = $this->heartbeat($credential, $raw, $key);
        $this->assertSame(['status' => 200, 'body' => ['status' => 'wiped', 'server_time' => self::NOW . '.000', 'build' => DeviceStatus::currentBuild()],
            'headers' => ['Clear-Site-Data' => '"cache", "storage"']], $signed);
        $row = $this->row($id);
        $this->assertSame([self::NOW, null, null, 0], [$row['wiped_at'], $row['proof_key_ciphertext'], $row['vault_key_ciphertext'], (int) $row['pending_count']],
            'erased: nothing on the server can open a copy of its old storage any more');
        $this->assertNotNull($row['token_hash'], 'the credential stays, so the guard can answer 410');
        $wiped = $this->audits('device_wiped', $id);
        $this->assertCount(1, $wiped);
        $this->assertSame(['Success', null, null, $this->site, $id], [$wiped[0]['outcome'], $wiped[0]['user_id'], $wiped[0]['session_id'],
            (int) $wiped[0]['site_id'], (int) $wiped[0]['device_id']]);
        $this->assertSame(['grant_keys_cleared' => 0, 'items_pushed' => 0, 'reported_pending_count' => 2, 'wipe_mode' => 'Push Then Wipe'], $this->details($wiped[0]),
            'the job had already shredded the grant secrets');
        $this->assertCount(1, $this->cleared($id), 'the job\'s row stays the only one');

        try {
            DeviceGuard::authenticate(Request::authorization(), DeviceGuard::KNOWN, Request::ip(), Request::scriptPath());
            $this->fail('expected an HttpException');
        } catch (HttpException $e) {
            $this->assertSame([410, 'wiped'], [$e->status, $e->code()], 'from now on the tablet is told it is erased');
        }
    }

    public function testLeavesRecentConfirmedAndInServiceTabletsAlone(): void
    {
        $recent = $this->retired($this->ago('89 days'));
        $confirmed = $this->retired($this->ago('120 days'), ['wiped_at' => $this->ago('119 days')]); // keys left in place to prove wiped_at alone guards it
        $inService = $this->tablet(['registered_at' => $this->ago('400 days')]);
        $cancelled = $this->makeDevice($this->site, ['label' => 'Never registered', 'revoked_at' => $this->ago('300 days')]); // no credential, no keys
        $ids = [$recent, $confirmed, $inService, $cancelled];
        $grants = array_map(fn(int $id) => $this->grant($id, "g1.test.grant$id"), [$recent, $confirmed, $inService]);
        $before = array_map(fn(int $id) => $this->row($id), $ids);

        $this->assertSame('cleared the keys of 0 tablet(s)', $this->runJob());
        $this->assertSame($before, array_map(fn(int $id) => $this->row($id), $ids));
        $this->assertSame(["g1.test.grant$recent", "g1.test.grant$confirmed", "g1.test.grant$inService"], array_map(fn(int $t) => $this->secret($t), $grants),
            'their grant secrets are untouched');
        foreach ($ids as $id) {
            $this->assertSame([], $this->cleared($id), "tablet $id");
        }
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'device_vault_key_cleared'"));
    }

    public function testTheRetentionSettingDecidesWhenKeysAreCleared(): void
    {
        $this->setSetting('sync_payload_retention_days', '30');
        $old = $this->retired($this->ago('31 days'));
        $young = $this->retired($this->ago('29 days'));
        $this->assertSame('cleared the keys of 1 tablet(s)', $this->runJob());
        $this->assertNull($this->row($old)['vault_key_ciphertext']);
        $this->assertNotNull($this->row($young)['vault_key_ciphertext']);
        $this->assertSame([], $this->cleared($young));
    }

    public function testATabletRevokedExactlyTheRetentionAgoIsCleared(): void
    {
        $edge = $this->retired($this->ago('90 days'));
        $justInside = $this->retired(Clock::db(Clock::now()->modify('-90 days')->modify('+1 second')));
        $this->assertSame('cleared the keys of 1 tablet(s)', $this->runJob());
        $this->assertNull($this->row($edge)['vault_key_ciphertext'], 'revoked_at <= now − retention');
        $this->assertNotNull($this->row($justInside)['vault_key_ciphertext']);
    }

    public function testARetentionBelowOneDayCountsAsOneDay(): void
    {
        $this->setSetting('sync_payload_retention_days', '0');
        $twoDays = $this->retired($this->ago('2 days'));
        $halfDay = $this->retired($this->ago('12 hours'));
        $this->assertSame('cleared the keys of 1 tablet(s)', $this->runJob());
        $this->assertNull($this->row($twoDays)['vault_key_ciphertext']);
        $this->assertNotNull($this->row($halfDay)['vault_key_ciphertext'], 'never cleared the moment it is retired');
    }

    public function testTheJobIsRegisteredAfterDeactivateDueAccounts(): void
    {
        $job = new ClearUnconfirmedWipes();
        $this->assertSame('devices:clear-unconfirmed-wipes', $job->name());
        $names = array_keys(Runner::jobs());
        $at = array_search($job->name(), $names, true);
        $this->assertNotFalse($at);
        $this->assertSame((new DeactivateDueAccounts())->name(), $names[$at - 1]);
        $this->assertInstanceOf(ClearUnconfirmedWipes::class, Runner::jobs()[$job->name()]);
    }
}
