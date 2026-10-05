<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Station;

use Pfpms\Account\AccountRepository;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Policy;
use Pfpms\Auth\Rbac;
use Pfpms\Auth\SessionStore;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\Api;
use Pfpms\Http\Json;
use Pfpms\Http\Request;
use Pfpms\Security\Crypto;
use Pfpms\Station\OfflineGrants;
use Pfpms\Station\StationAuth;
use Pfpms\Station\StationConfig;
use Pfpms\Station\StationGate;
use Pfpms\Tests\Support\QueryLog;
use Pfpms\Tests\Support\StationFixture;
use Pfpms\Tests\TestCase;
use RuntimeException;

/**
 * The Station's password sign-in (50-design §6.5, D-16, D-17, D-18, D-59; S3 spec §2.7, §2.8): the tablet's site and the
 * role's offline capability, the sessions it ends, what it releases (or why not) and in which order, the tablet's own
 * bucket, the proof and its single use, and the answer's shape.
 */
final class StationAuthTest extends TestCase
{
    use StationFixture;

    private const PASSWORD = 'Correct-Horse-Battery-9';
    private const IP = '203.0.113.30';
    private const WRONG = 'That username or password is not correct.';
    /** api/auth/login.php's options (S3 spec §2.8). */
    private const LOGIN = ['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'public' => true, 'touch' => false];
    private const PUBLIC_USER = ['user_id', 'username', 'email', 'display_name', 'role', 'capabilities', 'offline_caps', 'pin_switch', 'has_pin'];

    private static ?string $passwordHash = null;

    private int $north;
    private int $south;
    private array $tablet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stationSetUp();
        $this->north = $this->makeSite('Northside');
        $this->south = $this->makeSite('Southside');
        $this->tablet = $this->stationTablet($this->north);
    }

    protected function tearDown(): void
    {
        $this->stationTearDown();
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    /** A person (the shared test password, hashed once) with access to $sites (Northside by default); the account row. */
    private function person(array $overrides = [], ?array $sites = null): array
    {
        self::$passwordHash ??= PasswordPolicy::hash(self::PASSWORD);
        $user = $this->makeUser($overrides + ['password_hash' => self::$passwordHash]);
        foreach ($sites ?? [$this->north] as $siteId) {
            $this->grantSite($user['user_id'], $siteId);
        }
        $row = AccountRepository::find($user['user_id']);
        $this->assertNotNull($row);
        return $row;
    }

    /** StationAuth::login() for $user on the tablet, with the guard's row read now. */
    private function login(array $user, ?array $tablet = null, string $password = self::PASSWORD): array
    {
        return StationAuth::login((string) $user['username'], $password, self::IP, $this->stationDevice($tablet ?? $this->tablet));
    }

    /**
     * What login.php does up to the service: the request as the Station sends it, Api::start(<login options>) (the guard and the
     * PHP session; a fresh anonymous Station session unless $keepSession), then the service on the decoded body.
     */
    private function apiLogin(array $user, ?array $tablet = null, bool $keepSession = false): array
    {
        $this->stationRequest($tablet ?? $this->tablet, 'api/auth/login.php', ['identifier' => (string) $user['username'], 'password' => self::PASSWORD]);
        if (!$keepSession) {
            $this->stationSession();
        }
        Api::start(self::LOGIN);
        $body = Request::json();
        return StationAuth::login(Json::string($body, 'identifier', 254), Json::string($body, 'password', 1024), Request::ip(), Api::device());
    }

    /** @return list<array> the person's user_session rows, oldest first */
    private function sessionsOf(int $userId): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM user_session WHERE user_id = ? ORDER BY started_at, session_id');
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** @return list<array> this test's login rows with $outcome */
    private function loginRows(string $outcome): array
    {
        return array_values(array_filter($this->auditRows('login'), fn(array $row): bool => $row['outcome'] === $outcome));
    }

    private function retire(array $tablet): void
    {
        $admin = $this->person(['role' => 'Administrator']);
        Db::pdo()->prepare("UPDATE device SET revoked_at = ?, revoked_by = ?, wipe_mode = 'Push Then Wipe' WHERE device_id = ?")
            ->execute([Clock::db(), $admin['user_id'], $tablet['id']]);
    }

    private function fillBucket(string $bucket, int $hits): void
    {
        Db::pdo()->prepare('INSERT INTO rate_limit_bucket (bucket, window_start, hits) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE window_start = ?, hits = ?')
            ->execute([$bucket, Clock::db(), $hits, Clock::db(), $hits]);
    }

    private function hits(string $bucket): ?int
    {
        $hits = $this->scalar('SELECT hits FROM rate_limit_bucket WHERE bucket = ?', [$bucket]);
        return $hits === false ? null : (int) $hits;
    }

    /** The endpoint's own Api::start([...]) option text equals $options (so the tests below test the endpoint's guard). */
    private function assertEndpointOptions(string $endpoint, array $options): void
    {
        $code = (string) file_get_contents(APP_ROOT . '/public/' . $endpoint);
        $this->assertSame(1, preg_match('~Api::start\((\[[^;]*\])\);~', $code, $m), "$endpoint calls Api::start([...])");
        $this->assertSame(self::literal($options), (string) preg_replace('/\s+/', ' ', trim($m[1])), "$endpoint: Api::start's options");
    }

    private static function literal(mixed $value): string
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = (array_is_list($value) ? '' : var_export($key, true) . ' => ') . self::literal($item);
        }
        return '[' . implode(', ', $parts) . ']';
    }

    // The tablet, its site and the role --------------------------------------------------------

    public function testSignInPutsTheTabletOnTheLoginAuditRow(): void
    {
        $this->assertEndpointOptions('api/auth/login.php', self::LOGIN);
        $user = $this->person();
        $r = $this->apiLogin($user);
        $rows = $this->loginRows('Success');
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame([(int) $user['user_id'], $r['session_id'], $this->tablet['id'], $this->north, 'user_account', (int) $user['user_id']],
            [(int) $row['user_id'], $row['session_id'], (int) $row['device_id'], (int) $row['site_id'], $row['entity_type'], (int) $row['entity_id']]);
        $this->assertSame(['ip' => self::IP, 'release' => 'released', 'sessions_elsewhere_ended' => 0, 'sessions_ended' => 0, 'station' => true], $row['details']);
        $this->assertTrue($row['details']['station']);

        $issued = $this->auditRows('offline_grant_issue');
        $this->assertCount(1, $issued);
        $this->assertSame([(int) $user['user_id'], null, $this->tablet['id']], [(int) $issued[0]['user_id'], $issued[0]['session_id'], (int) $issued[0]['device_id']]);
    }

    public function testTheSessionIsPinnedToTheTabletsSiteWithTheTablet(): void
    {
        $user = $this->person([], [$this->south, $this->north]); // two sites: the tablet's is taken, never picked
        $r = $this->login($user);
        $sessions = $this->sessionsOf((int) $user['user_id']);
        $this->assertCount(1, $sessions);
        $this->assertSame([$r['body']['session_ref'], $this->tablet['id'], $this->north, 'Password', null],
            [$sessions[0]['session_id'], (int) $sessions[0]['device_id'], (int) $sessions[0]['site_id'], $sessions[0]['auth_method'], $sessions[0]['ended_at']]);
        $this->assertSame([(int) $user['user_id'], $sessions[0]['session_id'], $this->north], [$r['user_id'], $r['session_id'], $r['site_id']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $r['body']['session_ref']);
    }

    public function testNoSessionWithoutAccessToTheTabletsSite(): void
    {
        $user = $this->person([], [$this->south]);
        $e = $this->assertRefused(403, 'no_station_access', fn() => $this->login($user));
        $this->assertSame("You don't have access to Northside, where this tablet is used.", $e->getMessage());
        $this->assertSame(['reason' => 'site'], $e->extra);
        $this->assertSame([], $this->sessionsOf((int) $user['user_id']));
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
        $this->assertSame([], $this->loginRows('Success'));
        $denied = $this->loginRows('Denied');
        $this->assertCount(1, $denied);
        $this->assertSame(["No access to this tablet's site or the Station", ['ip' => self::IP, 'reason_code' => 'site', 'station' => true]],
            [$denied[0]['reason'], $denied[0]['details']]);
        $this->assertNull($this->scalar('SELECT last_login_at FROM user_account WHERE user_id = ?', [$user['user_id']]), 'nothing written before the check');
    }

    public function testNoSessionWithoutAnOfflineCapability(): void
    {
        $board = $this->person(['role' => 'Board']);
        $this->assertSame([], StationGate::offlineCapabilities('Board'));
        $e = $this->assertRefused(403, 'no_station_access', fn() => $this->login($board));
        $this->assertSame([StationGate::ROLE, ['reason' => 'role']], [$e->getMessage(), $e->extra]);
        $this->assertSame([], $this->sessionsOf((int) $board['user_id']));
        $this->assertSame([], $this->grantsOf((int) $board['user_id']));
        $this->assertSame('role', $this->loginRows('Denied')[0]['details']['reason_code']);
    }

    // What the answer carries ------------------------------------------------------------------

    public function testTheResponseNeverCarriesAPasswordHash(): void
    {
        $user = $this->person(['display_name' => 'Jo at the desk']);
        Db::pdo()->prepare('UPDATE user_account SET pin_hash = ? WHERE user_id = ?')
            ->execute(['p1.k1.$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHQ$aGFzaGhhc2hoYXNo', $user['user_id']]);
        $body = $this->login($user)['body'];
        $json = (string) json_encode($body);
        foreach (['$argon2id$', 'password_hash', 'pin_hash', 'token_hash', 'secret_ciphertext', 'vault_key', 'proof_key'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $this->assertSame(self::PUBLIC_USER, array_keys($body['user']));
        $this->assertSame([
            'user_id' => (int) $user['user_id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'display_name' => 'Jo at the desk',
            'role' => 'Volunteer',
            'capabilities' => Rbac::capabilities('Volunteer'),
            'offline_caps' => ['offline.checkin', 'offline.distribute', 'offline.pet_edit', 'offline.register'],
            'pin_switch' => true,
            'has_pin' => true,
        ], $body['user']);

        $plain = $this->person();
        $this->assertSame(trim($plain['first_name'] . ' ' . $plain['last_name']), StationAuth::publicUser($plain)['display_name'], 'no display name: the full name');
        $this->assertFalse(StationAuth::publicUser($plain)['has_pin']);
    }

    public function testTheResponseCarriesRevokedGrantsConfigAndBuild(): void
    {
        $other = $this->person();
        $old = $this->stationGrant($other, $this->tablet);
        $elsewhere = $this->stationGrant($other, $this->stationTablet($this->north));
        OfflineGrants::revokeForUser((int) $other['user_id'], 'password');
        $this->setSetting('password_min_length', '14');

        $body = $this->login($this->person())['body'];
        $this->assertSame(['ok', 'user', 'session_ref', 'gate', 'release', 'release_unavailable', 'revoked_grants', 'config', 'password_min_length', 'server_time', 'build'],
            array_keys($body));
        $this->assertTrue($body['ok']);
        $this->assertSame([$old['grant_id']], $body['revoked_grants'], "this tablet's revoked grants only");
        $this->assertNotContains($elsewhere['grant_id'], $body['revoked_grants']);
        $this->assertSame(StationConfig::client(), $body['config']);
        $this->assertSame(14, $body['password_min_length']);
        $this->assertSame('2026-10-01 12:00:00.000', $body['server_time']);
        $this->assertSame(DeviceStatus::currentBuild(), $body['build']);

        $this->setSetting('password_min_length', '6');
        $this->assertSame(8, $this->login($this->person())['body']['password_min_length'], 'never below 8, as PasswordPolicy');
    }

    // The sessions a sign-in ends ---------------------------------------------------------------

    public function testATabletSignInEndsTheTabletsOtherOnlineSessionsAsUserSwitch(): void
    {
        $a = $this->person();
        $b = $this->person();
        $password = SessionStore::create((int) $a['user_id'], $this->north, 'Password', $this->tablet['id']);
        $pin = SessionStore::create((int) $a['user_id'], $this->north, 'PIN', $this->tablet['id']);
        $offline = SessionStore::create((int) $a['user_id'], $this->north, 'Offline', $this->tablet['id']);
        Clock::advance('+1 minute');

        $this->login($b);
        foreach ([$password, $pin] as $sid) {
            $row = $this->sessionRow($sid);
            $this->assertSame(['2026-10-01 12:01:00', 'User Switch', (int) $b['user_id']], [$row['ended_at'], $row['end_reason'], (int) $row['ended_by']]);
        }
        $this->assertNull($this->sessionRow($offline)['ended_at'], 'an offline session is S5\'s to close');
        $this->assertSame(2, $this->loginRows('Success')[0]['details']['sessions_ended']);
    }

    public function testASignInEndsThePersonsOnlineSessionsOnOtherTabletsButNotTheirWebSession(): void
    {
        $b = $this->person();
        $other = $this->stationTablet($this->north);
        $there = SessionStore::create((int) $b['user_id'], $this->north, 'Password', $other['id']);
        $offlineThere = SessionStore::create((int) $b['user_id'], $this->north, 'Offline', $other['id']);
        $web = SessionStore::create((int) $b['user_id'], $this->north);
        Clock::advance('+1 minute');

        $this->login($b);
        $row = $this->sessionRow($there);
        $this->assertSame(['2026-10-01 12:01:00', 'User Switch', (int) $b['user_id']], [$row['ended_at'], $row['end_reason'], (int) $row['ended_by']]);
        $this->assertNull($this->sessionRow($web)['ended_at'], 'the web session (PFPMSSID) is untouched');
        $this->assertNull($this->sessionRow($offlineThere)['ended_at']);
        $details = $this->loginRows('Success')[0]['details'];
        $this->assertSame([0, 1], [$details['sessions_ended'], $details['sessions_elsewhere_ended']]);
    }

    // What is released, and why not ------------------------------------------------------------

    public function testNoGrantOrVaultKeyBeforePolicyAcknowledgement(): void
    {
        $this->agreement(); // in force, nobody has accepted it
        $user = $this->person();
        $r = $this->login($user);
        $this->assertSame(['policy_ack', null, null], [$r['body']['gate'], $r['body']['release'], $r['body']['release_unavailable']]);
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
        $this->assertSame([], $this->auditRows('offline_grant_issue'));
        $session = $this->sessionRow($r['session_id']);
        $this->assertNotNull($session, 'the session exists: the gate is passed in the app');
        $this->assertSame(['Password', null], [$session['auth_method'], $session['ended_at']]);
        $this->assertSame('policy_ack', $this->loginRows('Success')[0]['details']['release']);
        $this->assertStringNotContainsString(Crypto::b64url((string) $this->tablet['dvk']), (string) json_encode($r['body']));
    }

    public function testNoGrantOrVaultKeyWhileAPasswordChangeIsForced(): void
    {
        $this->agreement();
        $user = $this->person(['must_change_password' => 1]);
        $r = $this->login($user);
        $this->assertSame(['password_change', null, null], [$r['body']['gate'], $r['body']['release'], $r['body']['release_unavailable']],
            'the password change comes before the agreement');
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
        $this->assertSame('password_change', $this->loginRows('Success')[0]['details']['release']);
    }

    public function testNoVaultKeyForASeededTabletWithoutOne(): void
    {
        $seeded = $this->stationTablet($this->north, [], false);
        $user = $this->person();
        $r = $this->login($user, $seeded);
        $this->assertSame([null, null, 'no_vault_key'], [$r['body']['gate'], $r['body']['release'], $r['body']['release_unavailable']]);
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
        $this->assertNotNull($this->sessionRow($r['session_id']), 'the person still signs in online');
        $this->assertSame('no_vault_key', $this->loginRows('Success')[0]['details']['release']);
    }

    public function testTheReleaseOpensWithTheTabletsVaultKeyAndTheGrantSecret(): void
    {
        $tablet = $this->stationTablet($this->north, ['pbkdf2_iterations' => 310000]);
        $user = $this->person();
        $body = $this->login($user, $tablet)['body'];
        $release = $body['release'];
        $this->assertNull($body['gate']);
        $this->assertNull($body['release_unavailable']);
        $this->assertSame(['dvk', 'grant_id', 'grant_hmac_key', 'grant_issued_at', 'grant_expires_at', 'pbkdf2_iterations', 'offline_allowed'], array_keys($release));
        $this->assertSame($tablet['dvk'], Crypto::unb64urlStrict($release['dvk'], 32), "the tablet's own vault key");

        $grant = OfflineGrants::find($release['grant_id']);
        $this->assertNotNull($grant);
        $this->assertSame([(int) $user['user_id'], $tablet['id'], null], [(int) $grant['user_id'], (int) $grant['device_id'], $grant['revoked_at']]);
        $this->assertSame(OfflineGrants::secret($grant), Crypto::unb64urlStrict($release['grant_hmac_key'], 32), "the grant's own secret");
        $this->assertSame([$grant['created_at'] . '.000', $grant['expires_at'] . '.000'], [$release['grant_issued_at'], $release['grant_expires_at']]);
        $this->assertSame(['2026-10-01 12:00:00.000', '2026-10-04 12:00:00.000'], [$release['grant_issued_at'], $release['grant_expires_at']]);
        $this->assertSame(310000, $release['pbkdf2_iterations'], "the tablet's own rounds");
        $this->assertTrue($release['offline_allowed']);
        $this->assertNull($this->scalar('SELECT used_at FROM auth_token WHERE token_id = ?', [$release['grant_id']]));
    }

    public function testTheGrantIsIssuedBeforeAnySessionChanges(): void
    {
        $a = $this->person();
        $b = $this->person();
        $sidA = SessionStore::create((int) $a['user_id'], $this->north, 'Password', $this->tablet['id']);
        $seen = null;
        OfflineGrants::$beforeIssue = function () use ($sidA, &$seen): void {
            $open = (int) $this->scalar('SELECT COUNT(*) FROM user_session WHERE device_id = ? AND ended_at IS NULL', [$this->tablet['id']]);
            $seen = [$this->sessionRow($sidA)['ended_at'], $open];
        };

        $r = null;
        $log = QueryLog::during(function () use ($b, &$r): void {
            $r = $this->login($b);
        });
        $this->assertSame([null, 1], $seen, "at the grant, A's session is still open and B's does not exist yet");
        $this->assertNotNull($r['body']['release']);
        $grant = QueryLog::last($log, '/^(INSERT INTO|UPDATE) auth_token\b/');
        $session = QueryLog::first($log, '/^(UPDATE|INSERT INTO) user_session\b/');
        $this->assertNotNull($grant, 'the grant was written: ' . json_encode($log));
        $this->assertNotNull($session);
        $this->assertLessThan($session, $grant, 'lock order auth_token → user_session: ' . json_encode($log));
        $this->assertSame('User Switch', $this->sessionRow($sidA)['end_reason']);
    }

    public function testTheGrantAndTheSessionShareOneInstant(): void
    {
        OfflineGrants::$beforeIssue = static function (): void {
            Clock::advance('+2 seconds'); // the sign-in took a while before the grant
        };
        $r = $this->login($this->person());
        $grant = OfflineGrants::find($r['body']['release']['grant_id']);
        $session = $this->sessionRow($r['session_id']);
        $this->assertNotNull($grant);
        $this->assertSame('2026-10-01 12:00:00', $grant['created_at']);
        $this->assertSame([$grant['created_at'], $grant['created_at']], [$session['started_at'], $session['last_activity_at']]);
        $this->assertSame('2026-10-01 12:00:00.000', $r['body']['release']['grant_issued_at']);
        $this->assertSame('2026-10-01 12:00:02', Clock::db(), 'the clock did move during the sign-in');
    }

    public function testOnlineOnlyTabletsGetAShortGrant(): void
    {
        $user = $this->person();
        $online = $this->stationTablet($this->north, ['offline_enabled' => 0]);
        $cases = [
            'online-only tablet' => [$online, null, '2026-10-02 00:00:00', false],
            'offline tablet' => [$this->tablet, null, '2026-10-04 12:00:00', true],
            'offline switched off' => [$this->tablet, '0', '2026-10-02 00:00:00', false],
        ];
        foreach ($cases as $name => [$tablet, $setting, $expires, $allowed]) {
            if ($setting !== null) {
                $this->setSetting('offline_mode_enabled', $setting);
            }
            $release = $this->login($user, $tablet)['body']['release'];
            $this->assertSame([$expires . '.000', $allowed], [$release['grant_expires_at'], $release['offline_allowed']], $name);
            $this->assertSame($expires, OfflineGrants::find($release['grant_id'])['expires_at'], $name);
        }
        $issued = $this->auditRows('offline_grant_issue');
        $this->assertSame([false, true, false], array_map(fn(array $r) => $r['details']['offline_allowed'], $issued));
        $this->assertSame(['hours', 'hours', 'hours'], array_map(fn(array $r) => $r['details']['capped_by'], $issued));
    }

    public function testAGrantFailureRollsBackTheWholeSignIn(): void
    {
        $a = $this->person();
        $b = $this->person();
        Db::pdo()->prepare('UPDATE user_account SET pin_failed_count = 2 WHERE user_id = ?')->execute([$b['user_id']]);
        $sidA = SessionStore::create((int) $a['user_id'], $this->north, 'Password', $this->tablet['id']);
        OfflineGrants::$beforeIssue = static function (): void {
            throw new RuntimeException('grant store unavailable');
        };
        try {
            $this->login($b);
            $this->fail('the sign-in should fail');
        } catch (RuntimeException $e) {
            $this->assertSame('grant store unavailable', $e->getMessage());
        }
        $this->assertNull($this->sessionRow($sidA)['ended_at'], "A's session was not ended");
        $this->assertSame([], $this->sessionsOf((int) $b['user_id']));
        $this->assertSame([], $this->grantsOf((int) $b['user_id']));
        $this->assertSame([], $this->loginRows('Success'));
        $this->assertSame([2, null], [(int) $this->scalar('SELECT pin_failed_count FROM user_account WHERE user_id = ?', [$b['user_id']]),
            $this->scalar('SELECT last_login_at FROM user_account WHERE user_id = ?', [$b['user_id']])], 'the account UPDATE rolled back too');
    }

    public function testATabletRetiredAfterTheGuardReadIsRefused(): void
    {
        $user = $this->person();
        $guard = $this->stationDevice($this->tablet); // the guard read it in service
        $this->retire($this->tablet);
        $e = $this->assertRefused(403, 'device_revoked', fn() => StationAuth::login((string) $user['username'], self::PASSWORD, self::IP, $guard));
        $this->assertSame(['directive' => ['wipe' => 'Push Then Wipe']], $e->extra);
        $this->assertSame([], $this->sessionsOf((int) $user['user_id']));
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
        $this->assertSame([], $this->loginRows('Success'));
        $this->assertNull($this->scalar('SELECT last_login_at FROM user_account WHERE user_id = ?', [$user['user_id']]), 'last_login_at unchanged');
    }

    public function testASiteDeactivatedAfterTheAccessCheckIsRefused(): void
    {
        $user = $this->person();
        // The site goes inactive once the access check has read it active: only the transaction's requireSiteActive() sees it.
        $e = $this->assertRefused(403, 'device_site_inactive', fn() => QueryLog::after('/FROM user_site_access a JOIN site s ON s\.site_id = a\.site_id/',
            fn() => Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->north]), fn() => $this->login($user)));
        $this->assertSame(["This tablet's site is not active. Ask a Coordinator.", ['directive' => null]], [$e->getMessage(), $e->extra]);
        $this->assertSame([], $this->sessionsOf((int) $user['user_id']));
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
        $this->assertSame([], $this->loginRows('Success'));
        $this->assertNull($this->scalar('SELECT last_login_at FROM user_account WHERE user_id = ?', [$user['user_id']]), 'the account UPDATE rolled back');
    }

    // Buckets and refusals ---------------------------------------------------------------------

    public function testTheSiteBucketNeverLocksOutASiteAtShiftStart(): void
    {
        $this->fillBucket('login:ip:' . self::IP, 30); // the web's bucket for the site's one public IP is full
        $this->assertTrue($this->login($this->person())['body']['ok'], 'a Station sign-in from that IP still works');
        $this->assertSame(30, $this->hits('login:ip:' . self::IP), 'the Station never hits the IP bucket');

        $tabletB = $this->stationTablet($this->north);
        $this->fillBucket('login:device:' . $this->tablet['id'], 30);
        $user = $this->person();
        $e = $this->assertRefused(429, 'rate_limited', fn() => $this->login($user));
        $this->assertSame(['Too many sign-in attempts. Please wait a few minutes and try again.', ['Retry-After' => '900']], [$e->getMessage(), $e->headers]);
        $this->assertTrue($this->login($user, $tabletB)['body']['ok'], 'tablet B, same IP, is not limited by tablet A');
        $this->assertSame(1, $this->hits('login:device:' . $tabletB['id']));
    }

    public function testSignInResetsTheOnlinePinCounter(): void
    {
        $user = $this->person();
        Db::pdo()->prepare('UPDATE user_account SET pin_failed_count = 3 WHERE user_id = ?')->execute([$user['user_id']]);
        $this->login($user);
        $this->assertSame(0, (int) $this->scalar('SELECT pin_failed_count FROM user_account WHERE user_id = ?', [$user['user_id']]));
    }

    public function testWrongPasswordUnknownUserAndBlockedAccountsGiveTheirCodes(): void
    {
        $user = $this->person();
        $e = $this->assertRefused(422, 'login_failed', fn() => $this->login($user, null, 'Not-The-Password-1'));
        $this->assertSame(self::WRONG, $e->getMessage());
        $e = $this->assertRefused(422, 'login_failed', fn() => StationAuth::login('nobody.here', self::PASSWORD, self::IP, $this->stationDevice($this->tablet)));
        $this->assertSame(self::WRONG, $e->getMessage(), 'an unknown account reads the same');

        $locked = 'This account is locked. Please try again later or contact an Administrator.';
        foreach ([['status' => 'Locked'], ['locked_until' => '2026-10-01 12:10:00']] as $overrides) {
            $e = $this->assertRefused(422, 'account_locked', fn() => $this->login($this->person($overrides)), json_encode($overrides));
            $this->assertSame($locked, $e->getMessage());
        }
        foreach ([['status' => 'Inactive'], ['expiry_date' => '2026-09-01'], ['start_date' => '2026-12-01']] as $overrides) {
            $e = $this->assertRefused(422, 'account_unusable', fn() => $this->login($this->person($overrides)), json_encode($overrides));
            $this->assertSame(StationGate::UNUSABLE, $e->getMessage());
        }
        $this->assertSame([], $this->loginRows('Success'));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM auth_token WHERE purpose = 'Offline Grant'"));
    }

    public function testAnEmptyIdentifierOrPasswordSpendsNoBucket(): void
    {
        $user = $this->person();
        $device = $this->stationDevice($this->tablet);
        foreach ([[null, self::PASSWORD], ['', self::PASSWORD], ['   ', self::PASSWORD], [(string) $user['username'], null], [(string) $user['username'], '']] as [$identifier, $password]) {
            $e = $this->assertRefused(422, 'login_failed', fn() => StationAuth::login($identifier, $password, self::IP, $device), json_encode([$identifier, $password !== null]));
            $this->assertSame('Enter your username (or email) and password.', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM rate_limit_bucket WHERE bucket LIKE 'login:%'"), 'no bucket is spent');
        $this->assertSame([], $this->auditRows('login'));
    }

    // Through the guard ------------------------------------------------------------------------

    public function testAPreviousUsersPendingGateDoesNotBlockTheNextSignIn(): void
    {
        $this->assertEndpointOptions('api/auth/login.php', self::LOGIN);
        $doc = $this->agreement();
        $a = $this->person();
        $b = $this->person();
        Policy::acknowledge((int) $b['user_id'], (int) $doc['document_id']);
        $sidA = $this->stationSignedIn($a, $this->tablet); // A is at the agreement gate in the Station's PHP session
        $this->assertTrue(Policy::acknowledgementRequired($a));

        $r = $this->apiLogin($b, null, true);
        $this->assertSame([null, false], [$r['body']['gate'], $r['body']['release'] === null], "B is not held at A's gate");
        $row = $this->sessionRow($sidA);
        $this->assertSame(['User Switch', (int) $b['user_id']], [$row['end_reason'], (int) $row['ended_by']]);
    }

    public function testASignInAttemptNeverExtendsThePreviousSessionsIdleTimer(): void
    {
        $this->assertEndpointOptions('api/auth/login.php', self::LOGIN);
        $a = $this->person();
        $b = $this->person();
        $sidA = $this->stationSignedIn($a, $this->tablet);
        Clock::advance('+20 minutes');
        $this->stationRequest($this->tablet, 'api/auth/login.php', ['identifier' => (string) $b['username'], 'password' => self::PASSWORD]);
        $ctx = Api::start(self::LOGIN);
        $this->assertSame($sidA, $ctx?->sessionId, "the cookie still carries A's session");
        $this->assertSame('2026-10-01 12:00:00', $this->sessionRow($sidA)['last_activity_at'], 'a sign-in attempt is not A\'s activity');
    }

    public function testTheProofIsRequired(): void
    {
        $this->assertEndpointOptions('api/auth/login.php', self::LOGIN);
        $user = $this->person();
        $body = ['identifier' => (string) $user['username'], 'password' => self::PASSWORD];

        $this->stationRequest($this->tablet, 'api/auth/login.php', $body, 'POST', false);
        $this->stationSession();
        $this->assertRefused(401, 'device_proof_invalid', fn() => Api::start(self::LOGIN), 'no proof');

        $stale = $this->stationProof($this->tablet, 'api/auth/login.php', (string) json_encode($body), 'POST', (int) Clock::now()->format('Uv') - 16 * 60 * 1000);
        $this->stationRequest($this->tablet, 'api/auth/login.php', $body, 'POST', $stale);
        $this->stationSession();
        $e = $this->assertRefused(401, 'device_proof_stale', fn() => Api::start(self::LOGIN), 'a proof 16 minutes old');
        $this->assertSame(['server_time' => '2026-10-01 12:00:00.000'], $e->extra);

        $seeded = $this->stationTablet($this->north, [], true, false);
        $this->stationRequest($seeded, 'api/auth/login.php', $body);
        $this->stationSession();
        $this->assertNull(Api::start(self::LOGIN));
        $this->assertSame('none', Api::device()['proof'], 'a tablet without a proof key passes (D-46)');
        $this->assertTrue(StationAuth::login((string) $user['username'], self::PASSWORD, self::IP, Api::device())['body']['ok']);
    }

    public function testAReplayedProofIsRefused(): void
    {
        $this->assertEndpointOptions('api/auth/login.php', self::LOGIN);
        $user = $this->person();
        $body = ['identifier' => (string) $user['username'], 'password' => self::PASSWORD];
        $this->stationRequest($this->tablet, 'api/auth/login.php', $body);
        $proof = (string) $_SERVER['HTTP_PFPMS_PROOF'];
        $this->stationSession();
        Api::start(self::LOGIN);
        $this->assertTrue(StationAuth::login((string) $user['username'], self::PASSWORD, self::IP, Api::device())['body']['ok']);

        // The same headers and body, sent again (a copied request: devtools, a HAR file, a proxy log).
        Request::useBody((string) json_encode($body), 'application/json');
        $_SERVER['HTTP_PFPMS_PROOF'] = $proof;
        $e = $this->assertRefused(401, 'device_proof_invalid', fn() => Api::start(self::LOGIN));
        $this->assertSame('This tablet needs to be registered again. Ask a Coordinator.', $e->getMessage());

        $this->assertCount(1, $this->sessionsOf((int) $user['user_id']));
        $this->assertCount(1, $this->grantsOf((int) $user['user_id']));
        $rows = $this->auditRows('device_unproven');
        $this->assertCount(1, $rows);
        $this->assertSame([$this->tablet['id'], ['endpoint' => 'api/auth/login.php', 'signal' => 'proof_replayed']], [(int) $rows[0]['entity_id'], $rows[0]['details']]);
        $this->assertCount(1, $this->notificationRows('device_clone_suspected'));
    }
}
