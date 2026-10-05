<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Station;

use Pfpms\Account\AccountRepository;
use Pfpms\Auth\Auth;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Pin;
use Pfpms\Auth\Rbac;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\WebSession;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\Api;
use Pfpms\Http\Context;
use Pfpms\Http\HttpException;
use Pfpms\Security\Crypto;
use Pfpms\Security\Csrf;
use Pfpms\Station\OfflineGrants;
use Pfpms\Station\StationAuth;
use Pfpms\Tests\Support\StationFixture;
use Pfpms\Tests\TestCase;
use RuntimeException;

/**
 * Quick switching by PIN (50-design §6.6, D-24, D-25; S3 spec §2.7): only on a registered tablet, only within the window a
 * password sign-in there opened (closed by End shift and by credential events), the attempt counted before the PIN is
 * checked, the re-check inside the transaction, the sessions it ends, and an answer without keys.
 */
final class StationPinTest extends TestCase
{
    use StationFixture;

    private const PASSWORD = 'Correct-Horse-Battery-9';
    private const PIN = '4821';
    private const IP = '203.0.113.30';
    private const UNAVAILABLE = 'Quick switching is not available for this person on this tablet now. Sign in with your password.';
    private const LOCKED = 'Too many wrong PINs. Sign in with your password.';
    /** api/auth/pin.php's options (S3 spec §2.8). */
    private const PIN_OPTIONS = ['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'public' => true, 'touch' => false];

    private static ?string $passwordHash = null;

    private int $north;
    private array $tablet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stationSetUp();
        $this->north = $this->makeSite('Northside');
        $this->tablet = $this->stationTablet($this->north);
        $this->setSetting('pin_max_failed', '3');
        $this->setSetting('pin_shift_hours', '12');
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

    /** The person's PIN, stored as Pin::set() stores it. */
    private function withPin(array $user, string $pin = self::PIN): array
    {
        Db::pdo()->prepare('UPDATE user_account SET pin_hash = ? WHERE user_id = ?')->execute([Pin::hash((int) $user['user_id'], $pin), $user['user_id']]);
        return $user;
    }

    /** A person with a PIN and a password sign-in on the tablet now (its grant): the PIN window is open. */
    private function ready(array $overrides = []): array
    {
        $user = $this->withPin($this->person($overrides));
        $this->stationGrant($user, $this->tablet);
        return $user;
    }

    private function pin(array|int|null $user, string $pin = self::PIN, ?array $tablet = null, ?Context $ctx = null): array
    {
        $userId = is_array($user) ? (int) $user['user_id'] : $user;
        return StationAuth::pinSwitch($userId, $pin, $this->stationDevice($tablet ?? $this->tablet), $ctx, self::IP);
    }

    private function unavailable(array|int|null $user, string $pin = self::PIN): HttpException
    {
        $e = $this->assertRefused(422, 'pin_unavailable', fn() => $this->pin($user, $pin));
        $this->assertSame([self::UNAVAILABLE, []], [$e->getMessage(), $e->extra]);
        return $e;
    }

    private function failedCount(array $user): int
    {
        return (int) $this->scalar('SELECT pin_failed_count FROM user_account WHERE user_id = ?', [$user['user_id']]);
    }

    private function setFailedCount(array $user, int $count): void
    {
        Db::pdo()->prepare('UPDATE user_account SET pin_failed_count = ? WHERE user_id = ?')->execute([$count, $user['user_id']]);
    }

    /** @return list<array> the person's PIN sessions */
    private function pinSessions(array $user): array
    {
        $st = Db::pdo()->prepare("SELECT * FROM user_session WHERE user_id = ? AND auth_method = 'PIN' ORDER BY started_at");
        $st->execute([$user['user_id']]);
        return $st->fetchAll();
    }

    /** @return list<?string> the reason_code of this test's pin_switch rows with $outcome */
    private function reasons(string $outcome): array
    {
        return array_values(array_map(fn(array $r) => $r['details']['reason_code'] ?? null,
            array_filter($this->auditRows('pin_switch'), fn(array $r): bool => $r['outcome'] === $outcome)));
    }

    private function retire(array $tablet): void
    {
        $admin = $this->person(['role' => 'Administrator']);
        Db::pdo()->prepare("UPDATE device SET revoked_at = ?, revoked_by = ?, wipe_mode = 'Push Then Wipe' WHERE device_id = ?")
            ->execute([Clock::db(), $admin['user_id'], $tablet['id']]);
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

    // The tablet --------------------------------------------------------------------------------

    public function testPinIsRefusedOnAnUnregisteredTablet(): void
    {
        $this->assertEndpointOptions('api/auth/pin.php', self::PIN_OPTIONS);
        $user = $this->ready();
        $body = ['user_id' => (int) $user['user_id'], 'pin' => self::PIN];
        $unknown = ['id' => 0, 'credential' => 'pfd1_' . Crypto::b64url(random_bytes(32)), 'proofKey' => null, 'dvk' => null];
        $this->stationRequest($unknown, 'api/auth/pin.php', $body);
        $this->stationSession();
        $this->assertRefused(401, 'device_unknown', fn() => Api::start(self::PIN_OPTIONS));

        $unregistered = $this->stationTablet($this->north, ['is_site_registered' => 0]);
        $this->stationRequest($unregistered, 'api/auth/pin.php', $body);
        $this->stationSession();
        $e = $this->assertRefused(403, 'device_not_registered', fn() => Api::start(self::PIN_OPTIONS));
        $this->assertSame(['directive' => null], $e->extra);
        $this->assertSame([], $this->pinSessions($user));
    }

    public function testPinIsRefusedOnAWaitingOrRetiredTablet(): void
    {
        $this->assertEndpointOptions('api/auth/pin.php', self::PIN_OPTIONS);
        $user = $this->ready();
        $body = ['user_id' => (int) $user['user_id'], 'pin' => self::PIN];
        $waiting = $this->stationTablet($this->north, ['token_hash' => null, 'is_site_registered' => 0]);
        $this->stationRequest($waiting, 'api/auth/pin.php', $body);
        $this->stationSession();
        $this->assertRefused(401, 'device_unknown', fn() => Api::start(self::PIN_OPTIONS), 'a tablet waiting for registration has no credential yet');

        $this->retire($this->tablet);
        $this->stationRequest($this->tablet, 'api/auth/pin.php', $body);
        $this->stationSession();
        $e = $this->assertRefused(403, 'device_revoked', fn() => Api::start(self::PIN_OPTIONS));
        $this->assertSame(['directive' => ['wipe' => 'Push Then Wipe']], $e->extra);
        $this->assertSame([], $this->pinSessions($user));
    }

    public function testTheProofIsRequired(): void
    {
        $this->assertEndpointOptions('api/auth/pin.php', self::PIN_OPTIONS);
        $user = $this->ready();
        $body = ['user_id' => (int) $user['user_id'], 'pin' => self::PIN];
        $this->stationRequest($this->tablet, 'api/auth/pin.php', $body, 'POST', false);
        $this->stationSession();
        $this->assertRefused(401, 'device_proof_invalid', fn() => Api::start(self::PIN_OPTIONS));

        $this->stationRequest($this->tablet, 'api/auth/pin.php', $body);
        $this->stationSession();
        $this->assertNull(Api::start(self::PIN_OPTIONS), 'signed: the guard passes (nobody is signed in)');
        $this->assertSame('valid', Api::device()['proof']);
    }

    // The switch -------------------------------------------------------------------------------

    public function testPinSwitchEndsThePreviousSessionAndStartsAPinSession(): void
    {
        $a = $this->person();
        $b = $this->ready();
        $grant = OfflineGrants::pinWindow((int) $b['user_id'], $this->tablet['id'], 12);
        $this->assertNotNull($grant);
        $sidA = SessionStore::create((int) $a['user_id'], $this->north, 'Password', $this->tablet['id']);
        $ctx = new Context($a, $sidA, $this->north, [], $this->tablet['id'], 'Password', $this->stationDevice($this->tablet));
        Clock::advance('+1 minute');

        $r = $this->pin($b, self::PIN, null, $ctx);
        $rowA = $this->sessionRow($sidA);
        $this->assertSame(['2026-10-01 12:01:00', 'PIN Switch', (int) $b['user_id']], [$rowA['ended_at'], $rowA['end_reason'], (int) $rowA['ended_by']]);
        $new = $this->sessionRow($r['session_id']);
        $this->assertSame([(int) $b['user_id'], $this->tablet['id'], $this->north, 'PIN', null],
            [(int) $new['user_id'], (int) $new['device_id'], (int) $new['site_id'], $new['auth_method'], $new['ended_at']]);
        $this->assertSame([(int) $b['user_id'], $this->north], [$r['user_id'], $r['site_id']]);

        $body = $r['body'];
        $this->assertSame(['ok', 'user', 'session_ref', 'gate', 'revoked_grants', 'server_time', 'build'], array_keys($body));
        $this->assertSame([true, (int) $b['user_id'], $r['session_id'], null, [], '2026-10-01 12:01:00.000'],
            [$body['ok'], $body['user']['user_id'], $body['session_ref'], $body['gate'], $body['revoked_grants'], $body['server_time']]);
        $this->assertTrue($body['user']['has_pin']);

        $rows = array_values(array_filter($this->auditRows('pin_switch'), fn(array $row): bool => $row['outcome'] === 'Success'));
        $this->assertCount(1, $rows);
        $this->assertSame(['from_user_id' => (int) $a['user_id'], 'grant_id' => $grant['token_id'], 'sessions_elsewhere_ended' => 0, 'sessions_ended' => 1],
            $rows[0]['details']);
        $this->assertSame([(int) $b['user_id'], $r['session_id'], $this->north], [(int) $rows[0]['user_id'], $rows[0]['session_id'], (int) $rows[0]['site_id']]);
    }

    public function testPinSwitchIsFollowedBySessionRegenerationAndCsrfRotation(): void
    {
        $code = (string) file_get_contents(APP_ROOT . '/public/api/auth/pin.php');
        $service = strpos($code, 'StationAuth::pinSwitch(');
        $login = strpos($code, 'WebSession::login(');
        $this->assertNotFalse($service);
        $this->assertNotFalse($login);
        $this->assertGreaterThan($service, $login, 'pin.php regenerates the PHP session after the service');

        $b = $this->ready();
        $this->stationSession();
        $before = (string) session_id();
        $csrf = Csrf::token();
        $r = $this->pin($b);
        WebSession::login($r['user_id'], $r['session_id'], $r['site_id']);
        $this->assertNotSame($before, session_id(), 'a new PHP session id');
        $this->assertNotSame($csrf, $_SESSION['csrf'], 'a fresh CSRF token');
        $this->assertSame([(int) $b['user_id'], $r['session_id'], $this->north], [$_SESSION['uid'], $_SESSION['sid'], $_SESSION['site_id']]);
    }

    public function testThePinResponseCarriesNoKeys(): void
    {
        $b = $this->withPin($this->person());
        $grant = $this->stationGrant($b, $this->tablet);
        $json = (string) json_encode($this->pin($b)['body']);
        foreach (['"release"', '"dvk"', '"grant_hmac_key"', '"release_unavailable"', 'pin_hash', '$argon2id$'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $this->assertStringNotContainsString(Crypto::b64url((string) $this->tablet['dvk']), $json, 'no vault key');
        $this->assertStringNotContainsString(Crypto::b64url($grant['secret']), $json, 'no grant secret');
    }

    public function testPinSwitchEndsThePersonsSessionsOnOtherTablets(): void
    {
        $b = $this->ready();
        $other = $this->stationTablet($this->north);
        $there = SessionStore::create((int) $b['user_id'], $this->north, 'PIN', $other['id']);
        $web = SessionStore::create((int) $b['user_id'], $this->north);
        Clock::advance('+1 minute');

        $this->pin($b);
        $row = $this->sessionRow($there);
        $this->assertSame(['2026-10-01 12:01:00', 'User Switch', (int) $b['user_id']], [$row['ended_at'], $row['end_reason'], (int) $row['ended_by']]);
        $this->assertNull($this->sessionRow($web)['ended_at'], 'the web session is untouched');
        $success = array_values(array_filter($this->auditRows('pin_switch'), fn(array $r): bool => $r['outcome'] === 'Success'));
        $this->assertSame([0, 1], [$success[0]['details']['sessions_ended'], $success[0]['details']['sessions_elsewhere_ended']]);
    }

    // The window -------------------------------------------------------------------------------

    public function testPinNeedsAPasswordSignInOnThisTabletWithinTheWindow(): void
    {
        $b = $this->withPin($this->person());
        $this->unavailable($b);
        $this->assertSame(['no_window'], $this->reasons('Denied'), 'no grant: no window');

        Clock::freeze('2026-09-30 23:00:00'); // a password sign-in 13 hours ago (its grant is still live: 72 hours)
        $old = $this->stationGrant($b, $this->tablet);
        Clock::freeze(self::NOW);
        $this->unavailable($b);
        $this->assertSame(['no_window', 'no_window'], $this->reasons('Denied'), 'older than pin_shift_hours');
        $this->assertNull(OfflineGrants::pinWindow((int) $b['user_id'], $this->tablet['id'], 12));

        Clock::freeze('2026-10-01 01:00:00'); // 11 hours ago
        $recent = $this->stationGrant($b, $this->tablet);
        Clock::freeze(self::NOW);
        $this->assertNotSame($old['grant_id'], $recent['grant_id']);
        $this->assertTrue($this->pin($b)['body']['ok']);
        $this->assertSame(0, $this->failedCount($b), 'refusals before the reservation count nothing');
    }

    public function testACredentialEventClosesThePinWindow(): void
    {
        $b = $this->ready();
        $this->assertNotNull(OfflineGrants::pinWindow((int) $b['user_id'], $this->tablet['id'], 12));
        Db::transaction(static fn() => Auth::setPassword((int) $b['user_id'], 'Lantern-Orchard-Velvet-42', 'Password Reset'));
        $this->assertNull(OfflineGrants::pinWindow((int) $b['user_id'], $this->tablet['id'], 12));
        $this->unavailable($b);
        $this->assertSame(['no_window'], $this->reasons('Denied'));
    }

    public function testEndShiftClosesThePinWindowWithNoSessionOpen(): void
    {
        $b = $this->ready(); // the grant at 12:00:00
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM user_session WHERE device_id = ? AND ended_at IS NULL', [$this->tablet['id']]));
        $r = StationAuth::logout(null, $this->stationDevice($this->tablet), 'device', null); // the same second
        $this->assertFalse($r['ended_current']);
        $this->unavailable($b);
        $this->assertSame(['no_window'], $this->reasons('Denied'), 'a grant from the same second as End shift does not count');

        Clock::advance('+1 second');
        StationAuth::login((string) $b['username'], self::PASSWORD, self::IP, $this->stationDevice($this->tablet)); // a password sign-in reopens it
        $this->assertTrue($this->pin($b)['body']['ok']);
    }

    public function testAnOfflinePasswordUnlockDoesNotOpenTheOnlinePinWindow(): void
    {
        $b = $this->withPin($this->person());
        SessionStore::create((int) $b['user_id'], $this->north, 'Offline', $this->tablet['id']);
        $this->unavailable($b);
        $this->assertSame(['no_window'], $this->reasons('Denied'));
    }

    // Counting ---------------------------------------------------------------------------------

    public function testPinFallsBackToPasswordAfterMaxFailures(): void
    {
        $b = $this->ready();
        $expected = [
            2 => 'Wrong PIN. 2 tries left before a password is needed.',
            1 => 'Wrong PIN. 1 try left before a password is needed.',
            0 => self::LOCKED,
        ];
        foreach ($expected as $left => $message) {
            $e = $this->assertRefused(422, 'pin_wrong', fn() => $this->pin($b, '8901'));
            $this->assertSame([$message, ['tries_left' => $left]], [$e->getMessage(), $e->extra]);
        }
        $e = $this->assertRefused(422, 'pin_locked', fn() => $this->pin($b, self::PIN), 'the right PIN no longer helps');
        $this->assertSame([self::LOCKED, []], [$e->getMessage(), $e->extra]);
        $this->assertSame(3, $this->failedCount($b));
        $this->assertSame([], $this->pinSessions($b));

        StationAuth::login((string) $b['username'], self::PASSWORD, self::IP, $this->stationDevice($this->tablet));
        $this->assertSame(0, $this->failedCount($b), 'a password sign-in clears the online count');
        $this->assertTrue($this->pin($b)['body']['ok']);
    }

    public function testWrongPinsAreAuditedWithTriesLeft(): void
    {
        $b = $this->ready();
        $this->refusal(fn() => $this->pin($b, '8901'));
        $this->refusal(fn() => $this->pin($b, '2468'));
        $rows = array_values(array_filter($this->auditRows('pin_switch'), fn(array $r): bool => $r['outcome'] === 'Failed'));
        $this->assertCount(2, $rows);
        foreach ([2, 1] as $i => $left) {
            $this->assertSame(['Wrong PIN', 'user_account', (int) $b['user_id'], (int) $b['user_id'], null],
                [$rows[$i]['reason'], $rows[$i]['entity_type'], (int) $rows[$i]['entity_id'], (int) $rows[$i]['user_id'], $rows[$i]['session_id']]);
            $this->assertSame(['ip' => self::IP, 'reason_code' => 'wrong_pin', 'tries_left' => $left], $rows[$i]['details']);
        }
        $this->assertFalse(str_contains((string) json_encode($rows), '8901'), 'never the PIN');
    }

    public function testThePerTabletBucketLimitsGuessing(): void
    {
        $b = $this->ready();
        Db::pdo()->prepare('INSERT INTO rate_limit_bucket (bucket, window_start, hits) VALUES (?, ?, 59)')
            ->execute(['pin:device:' . $this->tablet['id'], Clock::db()]);
        $this->assertRefused(422, 'pin_wrong', fn() => $this->pin($b, '12'), 'the 60th call in the window is still answered');
        $e = $this->assertRefused(429, 'rate_limited', fn() => $this->pin($b), 'the 61st is not');
        $this->assertSame(['Retry-After' => '900'], $e->headers);
        $this->assertSame([0, []], [$this->failedCount($b), $this->pinSessions($b)], 'refused before anything is counted');

        Clock::advance('+15 minutes');
        $this->assertTrue($this->pin($b)['body']['ok'], 'a new window');
    }

    public function testAnAttemptIsCountedBeforeThePinIsChecked(): void
    {
        $b = $this->ready();
        $this->setFailedCount($b, 1);
        $seen = null;
        $atVerify = null;
        Pin::$afterReserve = function (int $userId, int $count) use (&$seen): void {
            $seen = [$count, (int) $this->scalar('SELECT pin_failed_count FROM user_account WHERE user_id = ?', [$userId])];
        };
        Pin::$beforeVerify = function (int $userId) use (&$atVerify): void { // inside Pin::verify(), wherever it is called from
            $atVerify = (int) $this->scalar('SELECT pin_failed_count FROM user_account WHERE user_id = ?', [$userId]);
        };
        $this->assertTrue($this->pin($b)['body']['ok']);
        $this->assertSame([2, 2], $seen, 'the attempt is counted before the ≈0.5 s verify');
        $this->assertSame(2, $atVerify, 'already counted when the PIN is checked');
        $this->assertSame(0, $this->failedCount($b), 'and cleared by the success');
    }

    public function testARequestThatDiesWhileThePinIsCheckedLeavesTheAttemptCounted(): void
    {
        $b = $this->ready();
        Pin::$beforeVerify = static function (): void {
            throw new RuntimeException('the request died');
        };
        try {
            $this->pin($b);
            $this->fail('the request should die');
        } catch (RuntimeException $e) {
            $this->assertSame('the request died', $e->getMessage());
        }
        $this->assertSame(1, $this->failedCount($b), 'it fails closed: the reservation came first and stays');
        $this->assertSame([], $this->pinSessions($b));
    }

    public function testParallelAttemptsCannotPassTheLimit(): void
    {
        $b = $this->ready();
        $this->setFailedCount($b, 2); // pin_max_failed − 1
        $inner = null;
        Pin::$afterReserve = function () use ($b, &$inner): void {
            Pin::$afterReserve = null;
            $inner = $this->refusal(fn() => $this->pin($b)); // a second request with the right PIN while the first is being checked
        };
        $this->assertTrue($this->pin($b)['body']['ok'], 'the first took the last attempt');
        $this->assertInstanceOf(HttpException::class, $inner);
        $this->assertSame([422, 'pin_locked'], [$inner->status, $inner->code()]);
        $this->assertCount(1, $this->pinSessions($b));
    }

    public function testAMalformedPinIsWrongWithoutCounting(): void
    {
        $b = $this->ready();
        foreach (['12a4', '12', '1234567', '', ' 4821', '4821 ', '48.2'] as $bad) {
            $e = $this->assertRefused(422, 'pin_wrong', fn() => $this->pin($b, $bad), json_encode($bad));
            $this->assertSame(['That PIN is not correct.', []], [$e->getMessage(), $e->extra], 'no tries_left');
        }
        $this->setSetting('pin_min_digits', '5');
        $e = $this->assertRefused(422, 'pin_wrong', fn() => $this->pin($b, self::PIN), 'shorter than the settings allow');
        $this->assertSame([], $e->extra);
        $this->assertSame(0, $this->failedCount($b));
        $this->assertSame([], $this->auditRows('pin_switch'), 'no row');
    }

    public function testASuccessfulPinResetsTheCounter(): void
    {
        $b = $this->ready();
        $this->setFailedCount($b, 2);
        $this->assertTrue($this->pin($b)['body']['ok']);
        $this->assertSame(0, $this->failedCount($b));
    }

    // Who may switch ---------------------------------------------------------------------------

    public function testPinNeedsThePinSwitchCapabilityAndSiteAccess(): void
    {
        $b = $this->ready();
        Db::pdo()->prepare('UPDATE user_site_access SET ends_at = ? WHERE user_id = ?')->execute(['2026-10-01 11:59:59', $b['user_id']]);
        $this->unavailable($b);
        $this->assertSame(['access'], $this->reasons('Denied'));
        $this->grantSite($b['user_id'], $this->north);

        $resolved = new \ReflectionProperty(Rbac::class, 'resolved');
        Rbac::capabilities('Volunteer');
        $saved = $resolved->getValue();
        $this->assertIsArray($saved);
        $changed = $saved;
        $changed['Volunteer'] = array_values(array_diff($saved['Volunteer'], ['auth.pin_switch']));
        $resolved->setValue(null, $changed);
        try {
            $this->unavailable($b);
            $this->assertSame(['access', 'capability'], $this->reasons('Denied'));
        } finally {
            $resolved->setValue(null, $saved);
        }
        $this->assertTrue($this->pin($b)['body']['ok']);
    }

    public function testRefusalsAreAuditedWithTheirReason(): void
    {
        $this->unavailable(999999);
        $this->unavailable(null);
        $inactive = $this->ready(['status' => 'Inactive']);
        $this->unavailable($inactive);
        $gated = $this->ready(['must_change_password' => 1]);
        $this->unavailable($gated);
        $noPin = $this->person();
        $this->stationGrant($noPin, $this->tablet);
        $this->unavailable($noPin);
        $noWindow = $this->withPin($this->person());
        $this->unavailable($noWindow);
        $locked = $this->ready();
        $this->setFailedCount($locked, 3);
        $this->assertRefused(422, 'pin_locked', fn() => $this->pin($locked));

        $rows = $this->auditRows('pin_switch');
        $this->assertSame(['not_found', 'not_found', 'account', 'gate', 'no_pin', 'no_window', 'locked'], array_map(fn(array $r) => $r['details']['reason_code'], $rows));
        $this->assertSame(['Denied'], array_values(array_unique(array_column($rows, 'outcome'))));
        $this->assertSame([null, null], [$rows[0]['entity_id'], $rows[0]['user_id']], 'nobody to name');
        $this->assertSame((int) $gated['user_id'], (int) $rows[3]['entity_id']);
        $this->assertSame(array_merge(array_fill(0, 6, 'Quick switching not available'), ['Too many wrong PINs']), array_column($rows, 'reason'));
        foreach ($rows as $row) {
            $this->assertSame(['ip', 'reason_code'], array_keys($row['details']));
            $this->assertNull($row['session_id']);
        }
    }

    // The re-check inside the transaction ------------------------------------------------------

    public function testAGrantRevokedAfterTheCheckIsRefusedInTheTransaction(): void
    {
        $a = $this->person();
        $b = $this->ready();
        $sidA = SessionStore::create((int) $a['user_id'], $this->north, 'Password', $this->tablet['id']);
        Pin::$afterReserve = static function (int $userId): void {
            OfflineGrants::revokeForUser($userId, 'password'); // committed while the PIN was being checked
        };
        $this->unavailable($b);
        $this->assertSame([], $this->pinSessions($b));
        $this->assertNull($this->sessionRow($sidA)['ended_at'], "A's session was not ended");
        $this->assertSame(1, $this->failedCount($b), 'the reserved attempt stays counted (it fails closed)');
        $this->assertSame([], array_filter($this->auditRows('pin_switch'), fn(array $r): bool => $r['outcome'] === 'Success'));
    }

    public function testAPinChangedWhileItWasCheckedIsRefused(): void
    {
        $b = $this->ready();
        Pin::$afterReserve = static function (int $userId): void {
            Db::pdo()->prepare('UPDATE user_account SET pin_hash = ? WHERE user_id = ?')->execute([Pin::hash($userId, '8901'), $userId]);
        };
        $this->unavailable($b, self::PIN); // the old PIN verified against the hash read before
        $this->assertSame([], $this->pinSessions($b));
        $stored = (string) $this->scalar('SELECT pin_hash FROM user_account WHERE user_id = ?', [$b['user_id']]);
        $this->assertTrue(Pin::verify((int) $b['user_id'], '8901', $stored), 'the new PIN stands');
    }

    public function testATabletRetiredAfterTheGuardReadIsRefusedInTheTransaction(): void
    {
        $b = $this->ready();
        $guard = $this->stationDevice($this->tablet); // the guard read it in service
        $this->retire($this->tablet);
        $e = $this->assertRefused(403, 'device_revoked', fn() => StationAuth::pinSwitch((int) $b['user_id'], self::PIN, $guard, null, self::IP),
            'the transaction starts with lockDevice()');
        $this->assertSame(['This tablet has been taken out of service.', ['directive' => ['wipe' => 'Push Then Wipe']]], [$e->getMessage(), $e->extra]);
        $this->assertSame([], $this->pinSessions($b));
        $this->assertSame(1, $this->failedCount($b), 'the reserved attempt stays counted (the zeroing rolled back)');
        $this->assertSame([], array_filter($this->auditRows('pin_switch'), fn(array $r): bool => $r['outcome'] === 'Success'));
    }

    public function testASiteDeactivatedAfterTheCheckIsRefusedInTheTransaction(): void
    {
        $b = $this->ready();
        Pin::$afterReserve = function (): void { // after the refusal check (which reads the site through access()), before the transaction
            Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->north]);
        };
        $e = $this->assertRefused(403, 'device_site_inactive', fn() => $this->pin($b), 'requireSiteActive(), before the re-check');
        $this->assertSame(['directive' => null], $e->extra);
        $this->assertSame([], $this->pinSessions($b));
        $this->assertSame(1, $this->failedCount($b));
        $this->assertSame([], array_filter($this->auditRows('pin_switch'), fn(array $r): bool => $r['outcome'] === 'Success'));
    }
}
