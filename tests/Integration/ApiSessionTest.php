<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use Pfpms\Audit\Audit;
use Pfpms\Auth\Policy;
use Pfpms\Auth\Rbac;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\Tokens;
use Pfpms\Auth\WebSession;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\Api;
use Pfpms\Http\Context;
use Pfpms\Http\HttpException;
use Pfpms\Http\Request;
use Pfpms\Security\Crypto;
use Pfpms\Settings;
use Pfpms\Station\SessionInfo;
use Pfpms\Tests\TestCase;

/**
 * Sessions through the real Api::start() (50-design §5.1 steps 6-11, §5.9, §6.2): the no-touch path, the rules for a
 * tablet's session, and what SessionInfo::describe() tells the Station. A real PHP session is started (the Station's,
 * WebSession::start(true)), with its files in a temporary directory instead of storage/sessions.
 */
final class ApiSessionTest extends TestCase
{
    private const SERVER_KEYS = ['REQUEST_METHOD', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'REMOTE_ADDR', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION',
        'HTTP_PFPMS_PROOF', 'HTTP_X_PFPMS_CLIENT', 'HTTP_X_CSRF_TOKEN', 'HTTP_ORIGIN', 'HTTP_SEC_FETCH_SITE', 'CONTENT_TYPE', 'CONTENT_LENGTH'];
    /** api/session.php's own options (§6.2), as a GET or POST would evaluate them. */
    private const SESSION_PHP = ['method' => ['GET', 'POST'], 'public' => true];

    private array $savedServer = [];
    private array $savedConfig = [];
    private bool $savedStation = false;
    private string $sessionDir;
    private int $north;
    private int $south;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // left open by an earlier test class
        }
        $this->savedServer = $_SERVER;
        foreach (self::SERVER_KEYS as $key) {
            unset($_SERVER[$key]);
        }
        $this->savedStation = (bool) $this->stationFlag()->getValue();
        $this->savedConfig = Config::snapshot();
        $this->sessionDir = sys_get_temp_dir() . '/pfpms-api-session-test';
        Config::override(['app' => ['session_path' => $this->sessionDir, 'base_path' => '/'] + (array) ($this->savedConfig['app'] ?? [])]
            + $this->savedConfig);
        Request::useBody(null);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/api/session.php';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.20';
        $this->setSetting('session_idle_minutes', '30');
        $this->setSetting('session_absolute_hours', '12');

        $this->north = $this->makeSite('Northside');
        $this->south = $this->makeSite('Southside');
        $this->admin = $this->makeUser(['role' => 'Administrator']);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        foreach (glob($this->sessionDir . '/sess_*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->sessionDir);
        $this->stationFlag()->setValue(null, $this->savedStation);
        Request::useBody(null);
        $_SERVER = $this->savedServer;
        Config::override($this->savedConfig);
        Audit::setActor(null);
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    /** WebSession's private "is this the Station's session" flag, restored after each test (restart() reuses it). */
    private function stationFlag(): \ReflectionProperty
    {
        return new \ReflectionProperty(WebSession::class, 'station');
    }

    /** The Station's PHP session carrying these ids, as WebSession::login() leaves them. */
    private function phpSession(int $userId, string $sessionId, ?int $siteId): void
    {
        WebSession::start(true);
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $_SESSION = ['uid' => $userId, 'sid' => $sessionId, 'site_id' => $siteId];
    }

    private function grant(int $userId, int $siteId): void
    {
        Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, starts_at, granted_by) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $siteId, '2026-01-01 00:00:00', $this->admin['user_id']]);
    }

    /** @return array{0: int, 1: string} a tablet in service at $siteId (no proof key, like a seed row) and its credential */
    private function tablet(int $siteId): array
    {
        $credential = 'pfd1_' . Crypto::b64url(random_bytes(32));
        $id = $this->makeDevice($siteId, ['token_hash' => Tokens::hash($credential), 'is_site_registered' => 1]);
        return [$id, $credential];
    }

    /** The request comes from the Station on this tablet. */
    private function asTablet(string $credential): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'PFPMS-Device ' . $credential;
        $_SERVER['HTTP_X_PFPMS_CLIENT'] = 'station';
        $_SERVER['SCRIPT_NAME'] = '/api/sync/status.php';
    }

    /** Nothing stands between this person and a signed-in endpoint (a committed confidentiality agreement is accepted). */
    private function noGates(array $user): void
    {
        $doc = Policy::current(Policy::CONFIDENTIALITY);
        if ($doc !== null && Policy::acknowledgementRequired($user)) {
            Policy::acknowledge((int) $user['user_id'], (int) $doc['document_id']);
        }
        $this->assertFalse(Policy::acknowledgementRequired($user));
    }

    private function lastActivity(string $sessionId): string
    {
        return (string) $this->scalar('SELECT last_activity_at FROM user_session WHERE session_id = ?', [$sessionId]);
    }

    /** @return array{ended_at: ?string, end_reason: ?string, site_id: ?int} */
    private function sessionRow(string $sessionId): array
    {
        $st = Db::pdo()->prepare('SELECT ended_at, end_reason, site_id FROM user_session WHERE session_id = ?');
        $st->execute([$sessionId]);
        $row = $st->fetch();
        $row['site_id'] = $row['site_id'] === null ? null : (int) $row['site_id'];
        return $row;
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

    private function assertRefused(int $status, string $code, callable $fn, string $message = ''): HttpException
    {
        $e = $this->refusal($fn);
        $this->assertSame([$status, $code], [$e->status, $e->code()], $message);
        return $e;
    }

    // No-touch validation (§5.9) ------------------------------------------------------------

    public function testTouchFalseLeavesLastActivityAndTheDefaultTouchesIt(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        $this->noGates($user);
        $this->phpSession($user['user_id'], $sid, null);
        Clock::advance('+20 minutes');

        $ctx = Api::start(['method' => 'GET', 'public' => true, 'touch' => false]);
        $this->assertSame($sid, $ctx?->sessionId);
        $this->assertSame(self::NOW, $this->lastActivity($sid), "'touch' => false never extends the idle timer");

        $ctx = Api::start(self::SESSION_PHP + ['touch' => Request::method() === 'POST']);
        $this->assertSame($sid, $ctx?->sessionId);
        $this->assertSame(self::NOW, $this->lastActivity($sid), "api/session.php's GET is a poll");

        $ctx = Api::start(['method' => 'GET']);
        $this->assertSame($sid, $ctx?->sessionId);
        $this->assertSame('2026-10-01 12:20:00', $this->lastActivity($sid), 'the default touches');
    }

    public function testPollingNeverKeepsASessionAlive(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        $this->phpSession($user['user_id'], $sid, null);
        foreach (['+10 minutes', '+10 minutes', '+9 minutes'] as $step) {
            Clock::advance($step);
            $this->assertNotNull(Api::start(self::SESSION_PHP + ['touch' => false]), "polled at $step");
        }
        Clock::advance('+1 minute'); // 30 minutes after the last real activity
        $this->assertRefused(401, 'session_timeout', fn() => Api::start(['method' => 'GET', 'touch' => false]));
        $this->assertSame('Timeout', $this->sessionRow($sid)['end_reason']);
        $this->assertSame([], array_intersect_key($_SESSION, ['uid' => 1, 'sid' => 1]), 'the PHP session was restarted without the ids');
    }

    // A tablet's session (§5.1 steps 8-9) ---------------------------------------------------

    public function testATabletSessionKeepsItsSiteAndCarriesTheTablet(): void
    {
        $user = $this->makeUser(['role' => 'Coordinator']);
        $this->grant($user['user_id'], $this->north);
        $this->grant($user['user_id'], $this->south);
        [$tablet, $credential] = $this->tablet($this->north);
        $sid = SessionStore::create($user['user_id'], $this->north, 'PIN', $tablet);
        $this->noGates($user);
        // The PHP session names the other site, which the web rules would keep (the person can use both).
        $this->phpSession($user['user_id'], $sid, $this->south);
        $this->asTablet($credential);

        $ctx = Api::start(['method' => 'GET', 'device' => 'in_service']);
        $this->assertInstanceOf(Context::class, $ctx);
        $this->assertSame([$sid, $user['user_id'], $this->north, $tablet, 'PIN'],
            [$ctx->sessionId, $ctx->userId(), $ctx->siteId, $ctx->deviceId, $ctx->authMethod], "the tablet's site, never re-picked");
        $this->assertSame($tablet, (int) $ctx->device['device_id'], 'the tablet authenticated in this request');
        $this->assertSame('none', $ctx->device['proof'], 'a tablet without a proof key');
        $this->assertSame($ctx->device, Api::device());
        $ids = $ctx->siteIds();
        sort($ids);
        $expected = [$this->north, $this->south];
        sort($expected);
        $this->assertSame($expected, $ids, 'the sites the person can use, for display');
        $this->assertSame($this->north, $this->sessionRow($sid)['site_id'], 'the server session keeps its site');

        $auditId = Audit::record('test_api_session_actor', 'device', $tablet);
        $st = Db::pdo()->prepare('SELECT user_id, session_id, site_id, device_id FROM audit_log WHERE audit_id = ?');
        $st->execute([$auditId]);
        $row = $st->fetch();
        $this->assertSame([$user['user_id'], $sid, $this->north, $tablet],
            [(int) $row['user_id'], $row['session_id'], (int) $row['site_id'], (int) $row['device_id']], 'the actor of what this request records');

        // The same server session read by a web page's rules (no tablet in the request) would keep the PHP session's site.
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['SCRIPT_NAME'] = '/api/session.php';
        $ctx = Api::start(self::SESSION_PHP + ['touch' => false]);
        $this->assertSame([$this->north, $tablet, 'PIN'], [$ctx->siteId, $ctx->deviceId, $ctx->authMethod],
            'a tablet-bound session keeps its site at api/session.php too');
        $this->assertNull($ctx->device, 'no tablet was authenticated in that request');
    }

    public function testATabletSessionAtASiteThePersonCanNoLongerUseEnds(): void
    {
        $user = $this->makeUser(['role' => 'Coordinator']);
        $this->grant($user['user_id'], $this->north);
        $this->grant($user['user_id'], $this->south);
        [$tablet, $credential] = $this->tablet($this->north);
        $sid = SessionStore::create($user['user_id'], $this->north, 'Password', $tablet);
        $this->noGates($user);
        $this->phpSession($user['user_id'], $sid, $this->north);
        $this->asTablet($credential);
        Db::pdo()->prepare('UPDATE user_site_access SET ends_at = ? WHERE user_id = ? AND site_id = ?')
            ->execute([Clock::db(), $user['user_id'], $this->north]); // the grant at the tablet's site lapses now

        $this->assertRefused(401, 'session_ended', fn() => Api::start(['method' => 'GET', 'device' => 'in_service']));
        $this->assertSame(['ended_at' => self::NOW, 'end_reason' => 'Permission Change', 'site_id' => $this->north], $this->sessionRow($sid),
            'ended, and never moved to the site the person can still use');
        $this->assertSame([], array_intersect_key($_SESSION, ['uid' => 1, 'sid' => 1]), 'the PHP session was restarted without the ids');
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertNull(Api::start(['method' => 'GET', 'device' => 'in_service', 'public' => true]), 'the next request is anonymous');

        // A public endpoint treats it as absent in the same way.
        $again = SessionStore::create($user['user_id'], $this->north, 'PIN', $tablet);
        $this->phpSession($user['user_id'], $again, $this->north);
        $this->assertNull(Api::start(['method' => 'GET', 'device' => 'in_service', 'public' => true]));
        $this->assertSame('Permission Change', $this->sessionRow($again)['end_reason']);
    }

    public function testASessionThatIsNotThisTabletsIsTreatedAsAbsent(): void
    {
        $user = $this->makeUser(['role' => 'Coordinator']);
        $this->grant($user['user_id'], $this->north);
        [, $credential] = $this->tablet($this->north);
        [$other] = $this->tablet($this->north);
        $this->noGates($user);
        $cases = [
            'another tablet\'s session' => SessionStore::create($user['user_id'], $this->north, 'PIN', $other),
            'a web session' => SessionStore::create($user['user_id'], $this->north),
        ];
        foreach ($cases as $case => $sid) {
            $this->phpSession($user['user_id'], $sid, $this->north);
            $this->asTablet($credential);
            $this->assertRefused(401, 'session_ended', fn() => Api::start(['method' => 'GET', 'device' => 'in_service']), $case);
            $this->assertNull(Api::start(['method' => 'GET', 'device' => 'in_service', 'public' => true]), "$case: a public endpoint sees no one");
            $this->assertSame(['ended_at' => null, 'end_reason' => null, 'site_id' => $this->north], $this->sessionRow($sid),
                "$case: left open for where it belongs");

            unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_PFPMS_CLIENT']);
            $ctx = Api::start(['method' => 'GET']);
            $this->assertSame($sid, $ctx?->sessionId, "$case: still a session without this tablet in the request");
        }
    }

    // SessionInfo::describe() (§6.2) --------------------------------------------------------

    public function testDescribeWithNoOneSignedIn(): void
    {
        $this->setSetting('organisation_name', 'Riverside Pet Pantry');
        WebSession::start(true);
        $_SESSION = [];
        $ctx = Api::start(self::SESSION_PHP + ['touch' => false]);
        $this->assertNull($ctx);

        $info = SessionInfo::describe($ctx);
        $this->assertSame(['csrf', 'organisation_name', 'server_time', 'build', 'dev_relax', 'user', 'session', 'gate'], array_keys($info));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $info['csrf']);
        $this->assertSame($_SESSION['csrf'], $info['csrf'], 'the token Api::start() will check on the POSTs');
        $this->assertSame($info['csrf'], SessionInfo::describe(null)['csrf'], 'stable within the session');
        $this->assertSame('Riverside Pet Pantry', $info['organisation_name']);
        $this->assertSame('2026-10-01 12:00:00.000', $info['server_time']);
        $this->assertSame(DeviceStatus::currentBuild(), $info['build']);
        $this->assertIsBool($info['dev_relax']);
        $this->assertSame([null, null, null], [$info['user'], $info['session'], $info['gate']]);
    }

    public function testDescribeATabletSessionAndItsTimers(): void
    {
        $user = $this->makeUser(['role' => 'Volunteer', 'display_name' => 'Jo Doe']);
        $this->grant($user['user_id'], $this->north);
        [$tablet] = $this->tablet($this->north);
        $sid = SessionStore::create($user['user_id'], $this->north, 'PIN', $tablet);
        $this->noGates($user);
        $this->phpSession($user['user_id'], $sid, $this->north);
        $_SERVER['HTTP_X_PFPMS_CLIENT'] = 'station';
        Clock::advance('+20 minutes');

        $info = SessionInfo::describe(Api::start(self::SESSION_PHP + ['touch' => Request::method() === 'POST']));
        $this->assertSame(['user_id' => $user['user_id'], 'username' => $user['username'], 'display_name' => 'Jo Doe', 'role' => 'Volunteer',
            'capabilities' => Rbac::capabilities('Volunteer')], $info['user']);
        $this->assertSame(['session_ref' => $sid, 'device_id' => $tablet, 'site_id' => $this->north, 'auth_method' => 'PIN',
            'idle_seconds_left' => 10 * 60, 'absolute_seconds_left' => 12 * 3600 - 20 * 60], $info['session'], 'the GET did not touch');
        $this->assertNull($info['gate']);
        $this->assertSame($_SESSION['csrf'], $info['csrf']);

        // The Station's keep-alive: POST {"action":"touch"} with the token.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $info['csrf'];
        $info = SessionInfo::describe(Api::start(self::SESSION_PHP + ['touch' => Request::method() === 'POST']));
        $this->assertSame('2026-10-01 12:20:00', $this->lastActivity($sid));
        $this->assertSame([30 * 60, 12 * 3600 - 20 * 60], [$info['session']['idle_seconds_left'], $info['session']['absolute_seconds_left']],
            'the touch restarted the idle timer, never the absolute one');

        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $this->assertRefused(400, 'csrf_failed', fn() => Api::start(self::SESSION_PHP + ['touch' => true]), 'a POST without the token');
    }

    public function testDescribeAWebSessionHasNoTablet(): void
    {
        $user = $this->makeUser(['role' => 'Coordinator']);
        $this->grant($user['user_id'], $this->north);
        $sid = SessionStore::create($user['user_id'], null);
        $this->phpSession($user['user_id'], $sid, null);

        $session = SessionInfo::describe(Api::start(self::SESSION_PHP + ['touch' => false]))['session'];
        $this->assertSame(['session_ref' => $sid, 'device_id' => null, 'site_id' => $this->north, 'auth_method' => 'Password',
            'idle_seconds_left' => 30 * 60, 'absolute_seconds_left' => 12 * 3600], $session, 'the only site is picked, as on a web page');
    }

    public function testDescribeNamesThePasswordChangeBeforeThePolicyAcknowledgement(): void
    {
        $user = $this->makeUser(['must_change_password' => 1]);
        $sid = SessionStore::create($user['user_id'], null);
        $this->phpSession($user['user_id'], $sid, null);
        $insert = Db::pdo()->prepare("INSERT INTO policy_document (doc_type, version, language_code, body, effective_from)
                                      VALUES ('Confidentiality Agreement', ?, ?, 'Keep what you see here private.', ?)");
        $insert->execute(['A' . bin2hex(random_bytes(3)), Settings::string('default_language', 'en'), Clock::orgToday()]);
        $documentId = (int) Db::pdo()->lastInsertId();
        $this->assertSame($documentId, (int) Policy::current(Policy::CONFIDENTIALITY)['document_id'], 'the agreement in force');
        $this->assertTrue(Policy::acknowledgementRequired($user));

        $this->assertSame('password_change', SessionInfo::describe(Api::start(self::SESSION_PHP + ['touch' => false]))['gate'],
            'both pending: the password change comes first');
        $this->assertRefused(403, 'password_change_required', fn() => Api::start(['method' => 'GET']), 'the same order as the guard');

        Db::pdo()->prepare('UPDATE user_account SET must_change_password = 0 WHERE user_id = ?')->execute([$user['user_id']]);
        $this->assertSame('policy_ack', SessionInfo::describe(Api::start(self::SESSION_PHP + ['touch' => false]))['gate']);
        $this->assertRefused(403, 'policy_ack_required', fn() => Api::start(['method' => 'GET']));

        Policy::acknowledge($user['user_id'], $documentId);
        $this->assertNull(SessionInfo::describe(Api::start(self::SESSION_PHP + ['touch' => false]))['gate']);
        $this->assertSame($sid, Api::start(['method' => 'GET'])?->sessionId, 'and the signed-in endpoints open');
    }
}
