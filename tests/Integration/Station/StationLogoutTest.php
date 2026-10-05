<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Station;

use Pfpms\Account\AccountRepository;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\WebSession;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\Api;
use Pfpms\Http\Context;
use Pfpms\Http\Json;
use Pfpms\Http\Request;
use Pfpms\Security\Csrf;
use Pfpms\Station\OfflineGrants;
use Pfpms\Station\StationAuth;
use Pfpms\Tests\Support\QueryLog;
use Pfpms\Tests\Support\StationFixture;
use Pfpms\Tests\TestCase;

/**
 * Signing one person out and End shift / Lock device (50-design §6.8, D-24; S3 spec §2.7, §2.8): which sessions end and
 * how, shift_ended_at (never backwards), an End shift made offline and replayed later (it never ends a later sign-in and
 * restarts no PHP session but its own), a retiring tablet, and the lock order.
 */
final class StationLogoutTest extends TestCase
{
    use StationFixture;

    private const PASSWORD = 'Correct-Horse-Battery-9';
    /** api/auth/logout.php's options (S3 spec §2.8). */
    private const LOGOUT = ['method' => 'POST', 'device' => 'known', 'public' => true, 'touch' => false];
    private const ACCOUNT_LOCK = "/FROM user_account WHERE user_id = \\? AND username <> 'system' LOCK IN SHARE MODE$/";

    private static ?string $passwordHash = null;

    private int $north;
    private array $tablet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stationSetUp();
        $this->north = $this->makeSite('Northside');
        $this->tablet = $this->stationTablet($this->north);
    }

    protected function tearDown(): void
    {
        $this->stationTearDown();
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    private function person(array $overrides = []): array
    {
        self::$passwordHash ??= PasswordPolicy::hash(self::PASSWORD);
        $user = $this->makeUser($overrides + ['password_hash' => self::$passwordHash]);
        $this->grantSite($user['user_id'], $this->north);
        $row = AccountRepository::find($user['user_id']);
        $this->assertNotNull($row);
        return $row;
    }

    private function session(array $user, string $method = 'Password', ?array $tablet = null): string
    {
        return SessionStore::create((int) $user['user_id'], $this->north, $method, ($tablet ?? $this->tablet)['id']);
    }

    private function ctxFor(array $user, string $sid): Context
    {
        return new Context($user, $sid, $this->north, [], $this->tablet['id'], 'Password', $this->stationDevice($this->tablet));
    }

    private function logout(?Context $ctx, ?string $scope, ?string $endedAt = null): array
    {
        return StationAuth::logout($ctx, $this->stationDevice($this->tablet), $scope, $endedAt);
    }

    private function shiftEndedAt(): ?string
    {
        $at = $this->scalar('SELECT shift_ended_at FROM device WHERE device_id = ?', [$this->tablet['id']]);
        return $at === false || $at === null ? null : (string) $at;
    }

    /** [ended_at, end_reason, ended_by] of a session */
    private function ending(string $sid): array
    {
        $row = $this->sessionRow($sid);
        $this->assertNotNull($row);
        return [$row['ended_at'], $row['end_reason'], $row['ended_by'] === null ? null : (int) $row['ended_by']];
    }

    /**
     * What logout.php does: the request, Api::start(<logout options>) over the PHP session as it is, the service on the decoded
     * body, and the restart only when the session the PHP session carries was ended.
     */
    private function endpoint(array $body): array
    {
        $this->stationRequest($this->tablet, 'api/auth/logout.php', $body);
        $ctx = Api::start(self::LOGOUT);
        $json = Request::json();
        $r = StationAuth::logout($ctx, Api::device(), Json::string($json, 'scope', 10), Json::string($json, 'ended_at', 23));
        if ($r['ended_current']) {
            WebSession::restart();
        }
        return $r + ['ctx' => $ctx];
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

    // Scope user and scope device --------------------------------------------------------------

    public function testUserLogoutEndsOnlyThatSession(): void
    {
        $a = $this->person();
        $b = $this->person();
        $sid = $this->session($a);
        $web = SessionStore::create((int) $a['user_id'], $this->north);
        $other = $this->session($b, 'PIN', $this->stationTablet($this->north));
        Clock::advance('+1 minute');

        $r = $this->logout($this->ctxFor($a, $sid), 'user');
        $this->assertSame(['ended_current' => true, 'body' => ['ok' => true, 'server_time' => '2026-10-01 12:01:00.000']], $r);
        $this->assertSame(['2026-10-01 12:01:00', 'Logout', (int) $a['user_id']], $this->ending($sid));
        $this->assertNull($this->sessionRow($web)['ended_at']);
        $this->assertNull($this->sessionRow($other)['ended_at']);
        $this->assertNull($this->shiftEndedAt(), 'signing one person out is not End shift');
        $rows = $this->auditRows('logout');
        $this->assertCount(1, $rows);
        $this->assertSame(['user_account', (int) $a['user_id'], ['station' => true]], [$rows[0]['entity_type'], (int) $rows[0]['entity_id'], $rows[0]['details']]);
    }

    public function testDeviceLockEndsEveryOnlineSessionAndStampsShiftEndedAt(): void
    {
        $a = $this->person();
        $b = $this->person();
        $c = $this->person();
        $sidA = $this->session($a);
        $sidB = $this->session($b, 'PIN');
        $offline = $this->session($c, 'Offline');
        $elsewhere = $this->session($a, 'Password', $this->stationTablet($this->north));
        Clock::advance('+1 minute');

        $r = $this->logout($this->ctxFor($a, $sidA), 'device');
        $this->assertSame(['ended_current' => true, 'body' => ['ok' => true, 'server_time' => '2026-10-01 12:01:00.000']], $r);
        foreach ([$sidA, $sidB] as $sid) {
            $this->assertSame(['2026-10-01 12:01:00', 'Device Lock', (int) $a['user_id']], $this->ending($sid), 'ended by the person who pressed it');
        }
        $this->assertNull($this->sessionRow($offline)['ended_at'], 'an offline session is untouched');
        $this->assertNull($this->sessionRow($elsewhere)['ended_at'], 'other tablets are untouched');
        $this->assertSame('2026-10-01 12:01:00', $this->shiftEndedAt());
        $rows = $this->auditRows('device_lock');
        $this->assertCount(1, $rows);
        $this->assertSame(['device', $this->tablet['id'], ['replayed' => false, 'sessions_ended' => 2]], [$rows[0]['entity_type'], (int) $rows[0]['entity_id'], $rows[0]['details']]);
    }

    public function testLogoutWithoutASessionIsHarmless(): void
    {
        $a = $this->person();
        $open = $this->session($a);
        $this->assertSame(['ended_current' => false, 'body' => ['ok' => true, 'server_time' => '2026-10-01 12:00:00.000']], $this->logout(null, 'user'));
        $this->assertNull($this->sessionRow($open)['ended_at'], 'scope user without a session ends nobody');
        $this->assertSame([], $this->auditRows('logout'));
        $this->assertNull($this->shiftEndedAt());

        $r = $this->logout(null, 'device');
        $this->assertFalse($r['ended_current']);
        $this->assertSame(['2026-10-01 12:00:00', 'Device Lock', null], $this->ending($open), 'End shift still ends the tablet\'s sessions, naming nobody');
        $this->assertSame('2026-10-01 12:00:00', $this->shiftEndedAt());
    }

    public function testAnUnknownScopeIs400(): void
    {
        $a = $this->person();
        $sid = $this->session($a);
        foreach ([null, '', 'all', 'USER', 'device '] as $scope) {
            $e = $this->assertRefused(400, 'bad_request', fn() => $this->logout($this->ctxFor($a, $sid), $scope), json_encode($scope));
            $this->assertSame(['Unknown scope.', ['field' => 'scope']], [$e->getMessage(), $e->extra]);
        }
        $this->assertNull($this->sessionRow($sid)['ended_at']);
        $this->assertNull($this->shiftEndedAt());
    }

    // shift_ended_at and the replay ------------------------------------------------------------

    public function testShiftEndedAtNeverMovesBackwards(): void
    {
        $this->logout(null, 'device');
        $this->assertSame('2026-10-01 12:00:00', $this->shiftEndedAt());
        $this->logout(null, 'device', '2026-10-01 11:00:00.000'); // an older End shift replayed later
        $this->assertSame('2026-10-01 12:00:00', $this->shiftEndedAt());
        Clock::advance('+5 minutes');
        $this->logout(null, 'device');
        $this->assertSame('2026-10-01 12:05:00', $this->shiftEndedAt());
        $this->logout(null, 'device', '2026-10-01 12:03:00.000');
        $this->assertSame('2026-10-01 12:05:00', $this->shiftEndedAt());
    }

    public function testAReplayedOfflineEndShiftNeverEndsALaterSignIn(): void
    {
        $a = $this->person();
        $b = $this->person();
        Clock::freeze('2026-10-01 11:20:00');
        $sidA = $this->session($a);
        // 11:30: A ends the shift while the tablet is offline; the tablet keeps it to replay with its own time.
        Clock::freeze('2026-10-01 11:45:00');
        $sidB = $this->stationSignedIn($b, $this->tablet); // B signs in with a password later
        $grant = $this->stationGrant($b, $this->tablet);
        Clock::freeze(self::NOW);
        $phpSession = (string) session_id();
        $csrf = Csrf::token();

        $r = $this->endpoint(['scope' => 'device', 'ended_at' => '2026-10-01 11:30:00.000']);
        $this->assertSame($sidB, $r['ctx']?->sessionId, "the cookie carries B's session");
        $this->assertFalse($r['ended_current']);
        $this->assertSame('2026-10-01 11:30:00', $this->shiftEndedAt());
        $this->assertSame(['2026-10-01 12:00:00', 'Device Lock', null], $this->ending($sidA), 'A\'s earlier session ends, naming nobody');
        $this->assertNull($this->sessionRow($sidB)['ended_at'], "B's later session stays open");
        $this->assertSame($grant['grant_id'], OfflineGrants::pinWindow((int) $b['user_id'], $this->tablet['id'], 12)['token_id'] ?? null, "B's PIN window stays open");
        $this->assertSame([$phpSession, (int) $b['user_id'], $csrf], [session_id(), $_SESSION['uid'] ?? null, $_SESSION['csrf'] ?? null],
            "logout.php keeps B's PHP session and CSRF token");
        $this->assertSame([['replayed' => true, 'sessions_ended' => 1]], array_column($this->auditRows('device_lock'), 'details'));

        // An even older End shift (another offline spell) replayed after it changes nothing.
        $this->endpoint(['scope' => 'device', 'ended_at' => '2026-10-01 11:10:00.000']);
        $this->assertSame('2026-10-01 11:30:00', $this->shiftEndedAt(), 'never backwards');
        $this->assertNull($this->sessionRow($sidB)['ended_at']);
        $this->assertNotNull(OfflineGrants::pinWindow((int) $b['user_id'], $this->tablet['id'], 12));
    }

    public function testAReplayOfTheCurrentSessionsShiftRestartsIt(): void
    {
        $a = $this->person();
        Clock::freeze('2026-10-01 11:40:00');
        $sidA = $this->stationSignedIn($a, $this->tablet);
        Clock::freeze(self::NOW);
        $phpSession = (string) session_id();

        $r = $this->endpoint(['scope' => 'device', 'ended_at' => '2026-10-01 11:50:00.000']);
        $this->assertSame($sidA, $r['ctx']?->sessionId);
        $this->assertTrue($r['ended_current'], "the cookie's own session started before the End shift");
        $this->assertSame(['2026-10-01 12:00:00', 'Device Lock', null], $this->ending($sidA));
        $this->assertNotSame($phpSession, session_id(), 'a fresh anonymous Station session');
        $this->assertArrayNotHasKey('uid', $_SESSION);
        $this->assertSame('2026-10-01 11:50:00', $this->shiftEndedAt());
    }

    public function testAFutureEndedAtIsCappedAtNow(): void
    {
        $a = $this->person();
        $b = $this->person();
        Clock::freeze('2026-10-01 11:50:00');
        $before = $this->session($a);
        Clock::freeze(self::NOW);
        $atNow = $this->session($b);

        $r = $this->logout(null, 'device', '2026-10-01 13:00:00.000');
        $this->assertFalse($r['ended_current']);
        $this->assertSame('2026-10-01 12:00:00', $this->shiftEndedAt(), 'capped at the server\'s now');
        $this->assertSame(['2026-10-01 12:00:00', 'Device Lock', null], $this->ending($before));
        $this->assertNull($this->sessionRow($atNow)['ended_at'], 'a session started at the capped instant is not before it');
        $this->assertSame([['replayed' => true, 'sessions_ended' => 1]], array_column($this->auditRows('device_lock'), 'details'));
    }

    public function testAnUnparsableEndedAtCountsAsNow(): void
    {
        foreach (['yesterday', '2026-10-01T11:00:00Z', '2026-02-30 11:00:00.000', '2026-10-01 11:00:00', ''] as $bad) {
            Clock::advance('+1 minute');
            $this->logout(null, 'device', $bad);
            $this->assertSame(Clock::db(), $this->shiftEndedAt(), json_encode($bad));
        }
        $this->assertSame([true], array_values(array_unique(array_map(fn(array $r) => $r['details']['replayed'], $this->auditRows('device_lock')))));
    }

    public function testARetiringTabletCanEndTheShift(): void
    {
        $this->assertEndpointOptions('api/auth/logout.php', self::LOGOUT);
        $a = $this->person();
        $sid = $this->session($a);
        $admin = $this->person(['role' => 'Administrator']);
        Db::pdo()->prepare("UPDATE device SET revoked_at = ?, revoked_by = ?, wipe_mode = 'Push Then Wipe' WHERE device_id = ?")
            ->execute([Clock::db(), $admin['user_id'], $this->tablet['id']]);

        $this->stationSession();
        $r = $this->endpoint(['scope' => 'device']);
        $this->assertNull($r['ctx']);
        $this->assertNotNull(Api::device()['revoked_at'], "'known': the guard answers a revoked tablet");
        $this->assertFalse($r['ended_current']);
        $this->assertSame('2026-10-01 12:00:00', $this->shiftEndedAt());
        $this->assertSame(['2026-10-01 12:00:00', 'Device Lock', null], $this->ending($sid));

        $this->stationSession();
        $r = $this->endpoint(['scope' => 'user']);
        $this->assertSame([false, ['ok' => true, 'server_time' => '2026-10-01 12:00:00.000']], [$r['ended_current'], $r['body']]);

        $this->stationRequest($this->tablet, 'api/auth/logout.php', ['scope' => 'device']);
        $this->stationSession();
        $this->assertRefused(403, 'device_revoked', fn() => Api::start(['device' => 'in_service'] + self::LOGOUT), 'what in_service would answer');
    }

    // Locks ------------------------------------------------------------------------------------

    public function testEndShiftAndLogoutTakeTheirLocksInOrder(): void
    {
        $a = $this->person();
        $ctx = $this->ctxFor($a, $this->session($a));
        $log = QueryLog::during(fn() => $this->logout($ctx, 'device'));
        $device = QueryLog::first($log, '/^UPDATE device SET shift_ended_at\b/');
        $account = QueryLog::first($log, self::ACCOUNT_LOCK);
        $session = QueryLog::first($log, '/^UPDATE user_session SET ended_at\b/');
        $this->assertNotNull($device, json_encode($log));
        $this->assertNotNull($account);
        $this->assertNotNull($session);
        $this->assertTrue($device < $account && $account < $session, 'End shift: device X → account S → sessions: ' . json_encode($log));

        $ctx = $this->ctxFor($a, $this->session($a));
        $log = QueryLog::during(fn() => $this->logout($ctx, 'user'));
        $device = QueryLog::first($log, '/FROM device d WHERE d\.device_id = \? LOCK IN SHARE MODE$/');
        $account = QueryLog::first($log, self::ACCOUNT_LOCK);
        $session = QueryLog::first($log, '/^UPDATE user_session SET ended_at\b/');
        $this->assertNotNull($device, json_encode($log));
        $this->assertNotNull($account);
        $this->assertNotNull($session);
        $this->assertTrue($device < $account && $account < $session, 'logout user: device S → account S → session: ' . json_encode($log));
    }
}
