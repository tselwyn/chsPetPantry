<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Station;

use Pfpms\Account\AccountRepository;
use Pfpms\Account\AccountService;
use Pfpms\Auth\Auth;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\Api;
use Pfpms\Http\Context;
use Pfpms\Station\OfflineGrants;
use Pfpms\Station\StationAuth;
use Pfpms\Tests\Support\QueryLog;
use Pfpms\Tests\Support\StationFixture;
use Pfpms\Tests\TestCase;
use RuntimeException;

/**
 * The in-app password change (50-design §6.9, D-54; S3 spec §2.7, §2.8): the current password, the rules and the bucket,
 * Tx1 (the change, which revokes the old grant) and Tx2 (the release) as two transactions, the agreement after it, the
 * access re-check and a tablet retired during the request.
 */
final class StationPasswordTest extends TestCase
{
    use StationFixture;

    private const PASSWORD = 'Correct-Horse-Battery-9';
    private const NEW = 'Lantern-Orchard-Velvet-42';
    private const IP = '203.0.113.30';
    /** api/auth/password.php's options (S3 spec §2.8). */
    private const PASSWORD_OPTIONS = ['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'password_change' => true, 'policy_ack' => true];
    private const DEVICE_LOCK = '/FROM device d WHERE d\.device_id = \? LOCK IN SHARE MODE$/';
    private const ACCOUNT_LOCK = "/FROM user_account WHERE user_id = \\? AND username <> 'system' FOR UPDATE$/";
    private const SESSION_READ = '/^SELECT session_id, user_id, device_id, auth_method, started_at, ended_at FROM user_session WHERE session_id = \?$/';
    /** The request's one read of the stored password (Auth::verifiedHash), before any transaction. */
    private const PASSWORD_READ = '/^SELECT password_hash FROM user_account WHERE user_id = \?$/';
    private const PASSWORD_WRITE = '/^UPDATE user_account SET password_hash = \?/';

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

    private function ctxFor(array $user, string $sid, ?array $tablet = null): Context
    {
        $tablet ??= $this->tablet;
        $row = AccountRepository::find((int) $user['user_id']);
        $this->assertNotNull($row);
        return new Context($row, $sid, $this->north, [], $tablet['id'], 'Password', $this->stationDevice($tablet));
    }

    /**
     * A password sign-in held at the forced-change gate.
     * @return array{user: array, sid: string, ctx: Context}
     */
    private function forced(?array $tablet = null, ?array $user = null): array
    {
        $tablet ??= $this->tablet;
        $user ??= $this->person(['must_change_password' => 1]);
        $r = StationAuth::login((string) $user['username'], self::PASSWORD, self::IP, $this->stationDevice($tablet));
        $this->assertSame(['password_change', null], [$r['body']['gate'], $r['body']['release']]);
        return ['user' => $user, 'sid' => $r['session_id'], 'ctx' => $this->ctxFor($user, $r['session_id'], $tablet)];
    }

    /** Api::start(<password options>) for the session, as password.php starts. */
    private function apiCtx(array $user, string $sid): Context
    {
        $this->stationRequest($this->tablet, 'api/auth/password.php', ['current_password' => self::PASSWORD, 'new_password' => self::NEW]);
        $this->stationSession(['uid' => (int) $user['user_id'], 'sid' => $sid, 'site_id' => $this->north]);
        $ctx = Api::start(self::PASSWORD_OPTIONS);
        $this->assertNotNull($ctx);
        $this->assertSame($sid, $ctx->sessionId);
        return $ctx;
    }

    private function change(Context $ctx, string $current = self::PASSWORD, string $new = self::NEW, ?array $tablet = null): array
    {
        return StationAuth::changePassword($ctx, $this->stationDevice($tablet ?? $this->tablet), $current, $new, self::IP);
    }

    private function verifies(array $user, string $password): bool
    {
        return Auth::verifyPassword((int) $user['user_id'], $password);
    }

    private function mustChange(array $user): int
    {
        return (int) $this->scalar('SELECT must_change_password FROM user_account WHERE user_id = ?', [$user['user_id']]);
    }

    /** @return list<array> this test's password_change rows with $outcome */
    private function changeRows(string $outcome): array
    {
        return array_values(array_filter($this->auditRows('password_change'), fn(array $r): bool => $r['outcome'] === $outcome));
    }

    /** @return list<int> the indexes of the statements matching $regex */
    private static function all(array $log, string $regex): array
    {
        return array_keys(array_filter($log, static fn(string $sql): bool => preg_match($regex, $sql) === 1));
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

    // The two transactions ---------------------------------------------------------------------

    public function testAForcedChangeReleasesKeysInASecondTransaction(): void
    {
        ['user' => $user, 'ctx' => $ctx] = $this->forced();
        $result = null;
        PasswordPolicy::$afterHash = static fn() => QueryLog::mark('password hash');
        $log = QueryLog::during(function () use ($ctx, &$result): void {
            $result = $this->change($ctx);
        });
        PasswordPolicy::$afterHash = null;
        $hashes = array_keys($log, '-- password hash', true);
        $read = QueryLog::first($log, self::PASSWORD_READ);
        $locks = self::all($log, self::DEVICE_LOCK);
        $this->assertCount(1, $hashes, 'one hash: ' . json_encode($log));
        $this->assertTrue($read < $hashes[0] && $hashes[0] < $locks[0],
            "the new password is hashed after the verify and before Tx1: no Argon2 while the tablet's and the account's rows are locked");
        $account = QueryLog::first($log, self::ACCOUNT_LOCK);
        $session = QueryLog::first($log, self::SESSION_READ);
        $password = QueryLog::first($log, self::PASSWORD_WRITE);
        $sessions = QueryLog::first($log, '/^UPDATE user_session SET ended_at\b/');
        $grant = QueryLog::first($log, '/^INSERT INTO auth_token\b/');
        $this->assertNotNull($password, json_encode($log));
        $this->assertNotNull($sessions);
        $this->assertNotNull($grant);
        $this->assertCount(2, $locks, 'each transaction starts with the tablet: ' . json_encode($log));
        $this->assertSame([$locks[0] + 1, $locks[0] + 2, $locks[0] + 3], [$account, $session, $password],
            "Tx1: the device lock, the account X, the session (the first plain read: it sees a reset committed meanwhile), then the password");
        $this->assertTrue($password < $sessions && $sessions < $locks[1] && $locks[1] < $grant,
            "Tx1's session updates come before Tx2, which locks the tablet again before the grant: " . json_encode($log));

        $this->assertSame([null, null], [$result['gate'], $result['release_unavailable']]);
        $this->assertNotNull($result['release']);
        $this->assertTrue($this->verifies($user, self::NEW));
        $this->assertSame(0, $this->mustChange($user));
        $rows = $this->changeRows('Success');
        $this->assertCount(1, $rows);
        $this->assertSame(['Forced change', ['source' => 'station']], [$rows[0]['reason'], $rows[0]['details']]);

        // Tx1 commits on its own: the release failing afterwards keeps the new password.
        $second = $this->stationTablet($this->north);
        ['user' => $other, 'ctx' => $otherCtx] = $this->forced($second);
        OfflineGrants::$beforeIssue = static function (): void {
            throw new RuntimeException('grant store unavailable');
        };
        try {
            $this->change($otherCtx, self::PASSWORD, self::NEW, $second);
            $this->fail('the release should fail');
        } catch (RuntimeException $e) {
            $this->assertSame('grant store unavailable', $e->getMessage());
        }
        $this->assertTrue($this->verifies($other, self::NEW), 'Tx1 committed');
        $this->assertSame(0, $this->mustChange($other));
        $this->assertSame([], $this->grantsOf((int) $other['user_id']));
    }

    public function testThePasswordChangeRevokesTheOldGrantAndIssuesANewOne(): void
    {
        $user = $this->person();
        $r = StationAuth::login((string) $user['username'], self::PASSWORD, self::IP, $this->stationDevice($this->tablet));
        $old = (int) $r['body']['release']['grant_id'];
        Clock::advance('+1 minute');

        $body = $this->change($this->ctxFor($user, $r['session_id']));
        $this->assertSame(['ok', 'gate', 'release', 'release_unavailable', 'revoked_grants', 'server_time'], array_keys($body));
        $this->assertSame([$old], $body['revoked_grants'], 'the old grant is listed: the tablet deletes its entries, then writes the new ones');
        $this->assertNotSame($old, $body['release']['grant_id']);
        $this->assertSame('2026-10-01 12:01:00', $this->scalar('SELECT revoked_at FROM auth_token WHERE token_id = ?', [$old]));
        $this->assertNull($this->scalar('SELECT revoked_at FROM auth_token WHERE token_id = ?', [$body['release']['grant_id']]));
        $revokes = $this->auditRows('offline_grant_revoke');
        $this->assertCount(1, $revokes);
        $this->assertSame(['cause' => 'password', 'count' => 1], $revokes[0]['details']);
        $this->assertNull($this->changeRows('Success')[0]['reason'], 'not a forced change');
        $this->assertNull($this->sessionRow($r['session_id'])['ended_at'], 'the session that changed it goes on');
    }

    public function testAPendingAgreementFollowsTheChange(): void
    {
        $this->agreement();
        ['user' => $user, 'ctx' => $ctx] = $this->forced(); // the password change comes first, the agreement then
        $this->assertSame(['ok' => true, 'gate' => 'policy_ack', 'release' => null, 'release_unavailable' => null, 'revoked_grants' => [],
            'server_time' => '2026-10-01 12:00:00.000'], $this->change($ctx));
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
        $this->assertTrue($this->verifies($user, self::NEW));
    }

    // Refusals ---------------------------------------------------------------------------------

    public function testTheCurrentPasswordIsRequired(): void
    {
        ['user' => $user, 'ctx' => $ctx] = $this->forced();
        $e = $this->assertRefused(422, 'login_failed', fn() => $this->change($ctx, 'Not-The-Password-1'));
        $this->assertSame('The current password is not correct.', $e->getMessage());
        $rows = $this->changeRows('Failed');
        $this->assertCount(1, $rows);
        $this->assertSame(['Wrong current password', 'user_account', (int) $user['user_id'], ['ip' => self::IP, 'source' => 'station']],
            [$rows[0]['reason'], $rows[0]['entity_type'], (int) $rows[0]['entity_id'], $rows[0]['details']]);
        $this->assertTrue($this->verifies($user, self::PASSWORD));
        $this->assertSame(1, $this->mustChange($user));
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
    }

    public function testThePolicyRulesApply(): void
    {
        ['user' => $user, 'ctx' => $ctx] = $this->forced();
        $e = $this->assertRefused(422, 'invalid', fn() => $this->change($ctx, self::PASSWORD, self::PASSWORD));
        $same = 'Choose a password different from your current one.';
        $this->assertSame([$same, ['errors' => ['new_password' => $same]]], [$e->getMessage(), $e->extra]);

        foreach (['Short-1x', $user['username'] . '-Garden-Gate-42'] as $weak) {
            $problems = implode(' ', PasswordPolicy::check($weak, $ctx->user));
            $this->assertNotSame('', $problems, $weak);
            $e = $this->assertRefused(422, 'invalid', fn() => $this->change($ctx, self::PASSWORD, $weak), $weak);
            $this->assertSame([$problems, ['errors' => ['new_password' => $problems]]], [$e->getMessage(), $e->extra]);
        }
        $this->assertStringContainsString('Use at least 12 characters.', $this->refusal(fn() => $this->change($ctx, self::PASSWORD, 'Short-1x'))->getMessage());
        $this->assertTrue($this->verifies($user, self::PASSWORD));
        $this->assertSame([], $this->changeRows('Success'));
    }

    public function testTheRateLimitApplies(): void
    {
        ['user' => $user, 'ctx' => $ctx] = $this->forced();
        for ($i = 1; $i <= 5; $i++) {
            $this->assertRefused(422, 'login_failed', fn() => $this->change($ctx, 'Not-The-Password-1'), "attempt $i");
        }
        $e = $this->assertRefused(429, 'rate_limited', fn() => $this->change($ctx), 'the 6th, even with the right password');
        $this->assertSame(['Retry-After' => '900'], $e->headers);
        $this->assertTrue($this->verifies($user, self::PASSWORD));
        $this->assertSame(6, (int) $this->scalar('SELECT hits FROM rate_limit_bucket WHERE bucket = ?', ['pw_change:user:' . $user['user_id']]));

        Clock::advance('+15 minutes');
        $this->assertNotNull($this->change($ctx)['release'], 'a new window');
    }

    public function testTheProofIsRequired(): void
    {
        $this->assertEndpointOptions('api/auth/password.php', self::PASSWORD_OPTIONS);
        ['user' => $user, 'sid' => $sid] = $this->forced();
        $this->stationRequest($this->tablet, 'api/auth/password.php', ['current_password' => self::PASSWORD, 'new_password' => self::NEW], 'POST', false);
        $this->stationSession(['uid' => (int) $user['user_id'], 'sid' => $sid, 'site_id' => $this->north]);
        $this->assertRefused(401, 'device_proof_invalid', fn() => Api::start(self::PASSWORD_OPTIONS));
        $this->apiCtx($user, $sid); // signed, at the forced-change gate: allowed
    }

    // Changes during the request ---------------------------------------------------------------

    public function testAnAccessChangeDuringTheChangeReleasesNothing(): void
    {
        $south = $this->makeSite('Southside');
        $admin = $this->person(['role' => 'Administrator']);
        $user = $this->person(['must_change_password' => 1], [$this->north, $south]);
        ['sid' => $sid] = $this->forced(null, $user);
        $ctx = $this->apiCtx($user, $sid);

        Db::pdo()->prepare('UPDATE user_site_access SET ends_at = ? WHERE user_id = ? AND site_id = ?')->execute(['2026-10-01 11:59:59', $user['user_id'], $this->north]);
        $e = $this->assertRefused(403, 'no_station_access', fn() => $this->change($ctx));
        $this->assertSame(['reason' => 'site'], $e->extra, "Tx2's release() re-checks the access");
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
        $this->assertTrue($this->verifies($user, self::NEW), 'the new password is kept (Tx1)');
        $this->assertNull($this->sessionRow($sid)['ended_at']);
        $this->grantSite($user['user_id'], $this->north);

        $account = AccountRepository::find((int) $user['user_id']);
        $this->assertNotNull($account);
        AccountService::update((int) $user['user_id'], ['reason' => 'Moved to Southside'] + array_intersect_key($account, array_flip(AccountService::DETAIL_FIELDS)),
            [$south], (int) $account['row_version'], (int) $admin['user_id']);
        $this->assertSame('Permission Change', $this->sessionRow($sid)['end_reason']);
        $e = $this->assertRefused(401, 'session_ended', fn() => $this->change($ctx, self::NEW, 'Meadow-Copper-Sparrow-17'));
        $this->assertSame('Your session has ended. Please sign in again.', $e->getMessage());
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
    }

    public function testATabletRetiredBeforeTheChangeKeepsTheOldPassword(): void
    {
        ['user' => $user, 'ctx' => $ctx] = $this->forced();
        $guard = $this->stationDevice($this->tablet); // the guard read it in service
        $this->retire($this->tablet);
        $e = $this->assertRefused(403, 'device_revoked', fn() => StationAuth::changePassword($ctx, $guard, self::PASSWORD, self::NEW, self::IP));
        $this->assertSame(['directive' => ['wipe' => 'Push Then Wipe']], $e->extra);
        $this->assertTrue($this->verifies($user, self::PASSWORD), 'Tx1 refused: the old password stands');
        $this->assertFalse($this->verifies($user, self::NEW));
        $this->assertSame(1, $this->mustChange($user));
        $this->assertSame([], $this->changeRows('Success'));
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
    }

    public function testATabletRetiredBetweenTheTwoTransactionsKeepsTheNewPasswordAndReleasesNothing(): void
    {
        ['user' => $user, 'ctx' => $ctx] = $this->forced();
        $guard = $this->stationDevice($this->tablet); // the guard read it in service
        // The Retire commits once Tx1 has written the password: only Tx2's own lockDevice() can see it.
        $e = $this->assertRefused(403, 'device_revoked', fn() => QueryLog::after(self::PASSWORD_WRITE, fn() => $this->retire($this->tablet),
            fn() => StationAuth::changePassword($ctx, $guard, self::PASSWORD, self::NEW, self::IP)));
        $this->assertSame(['directive' => ['wipe' => 'Push Then Wipe']], $e->extra);
        $this->assertTrue($this->verifies($user, self::NEW), 'Tx1 committed: the new password is kept');
        $this->assertCount(1, $this->changeRows('Success'));
        $this->assertSame([], $this->grantsOf((int) $user['user_id']), 'no grant on a retired tablet');
        $this->assertSame([], $this->auditRows('offline_grant_issue'));
    }

    public function testASiteDeactivatedDuringTheChangeReleasesNothing(): void
    {
        ['user' => $user, 'sid' => $sid, 'ctx' => $ctx] = $this->forced();
        $guard = $this->stationDevice($this->tablet); // the guard read the site active
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->north]);
        $e = $this->assertRefused(403, 'device_site_inactive', fn() => StationAuth::changePassword($ctx, $guard, self::PASSWORD, self::NEW, self::IP),
            "Tx2's requireSiteActive(), before release()");
        $this->assertSame(['directive' => null], $e->extra);
        $this->assertTrue($this->verifies($user, self::NEW), 'Tx1 committed: the new password is kept');
        $this->assertSame([], $this->grantsOf((int) $user['user_id']));
        $this->assertNull($this->sessionRow($sid)['ended_at']);
    }

    // A reset during the request (the check before Tx1, the write in Tx1) ---------------------------

    public function testAResetCommittedDuringTheChangeWins(): void
    {
        $admin = $this->person(['role' => 'Administrator']);
        $user = $this->person();
        $uid = (int) $user['user_id'];
        $r = StationAuth::login((string) $user['username'], self::PASSWORD, self::IP, $this->stationDevice($this->tablet));
        $this->assertNotNull($r['body']['release']);
        $ctx = $this->ctxFor($user, $r['session_id']);

        // The Administrator's reset ("the account may be in the wrong hands") commits after the request read the password,
        // while it verifies it: Tx1's re-check under the account lock sees it.
        $e = $this->assertRefused(401, 'session_ended', fn() => QueryLog::after(self::PASSWORD_READ,
            fn() => AccountService::sendReset($uid, (int) $admin['user_id']), fn() => $this->change($ctx)));
        $this->assertSame('Your session has ended. Please sign in again.', $e->getMessage());
        $this->assertFalse($this->verifies($user, self::NEW), 'the request the reset cut off set nothing');
        $this->assertFalse($this->verifies($user, self::PASSWORD), "the reset's unusable password stands");
        $this->assertSame(1, $this->mustChange($user), "the reset's forced change stands");
        $this->assertSame([], $this->changeRows('Success'));
        $link = Db::pdo()->prepare("SELECT revoked_at, used_at FROM auth_token WHERE user_id = ? AND purpose = 'Temporary Credential'");
        $link->execute([$uid]);
        $this->assertSame([['revoked_at' => null, 'used_at' => null]], $link->fetchAll(), "the reset's emailed link still works");
        $this->assertSame([], array_filter(array_column($this->grantsOf($uid), 'revoked_at'), fn($at) => $at === null), 'no live grant');
        $this->assertSame('Password Reset', $this->sessionRow($r['session_id'])['end_reason']);
    }

    public function testAPasswordChangedSinceTheVerifyIsNotOverwritten(): void
    {
        ['user' => $user, 'ctx' => $ctx] = $this->forced();
        $uid = (int) $user['user_id'];
        $other = PasswordPolicy::hash('Meadow-Copper-Sparrow-17');
        // The stored hash changes and the session stays open (this session's own earlier request, or a rehash at a sign-in).
        $this->assertRefused(401, 'session_ended', fn() => QueryLog::after(self::PASSWORD_READ, static function () use ($uid, $other): void {
            Db::pdo()->prepare('UPDATE user_account SET password_hash = ? WHERE user_id = ?')->execute([$other, $uid]);
        }, fn() => $this->change($ctx)));
        $this->assertSame($other, $this->scalar('SELECT password_hash FROM user_account WHERE user_id = ?', [$uid]), 'the newer password stands');
        $this->assertSame(1, $this->mustChange($user));
        $this->assertSame([], $this->changeRows('Success'));
    }

    public function testAnAccountLockedDuringTheChangeKeepsItsPassword(): void
    {
        ['user' => $user, 'ctx' => $ctx] = $this->forced();
        $uid = (int) $user['user_id'];
        $e = $this->assertRefused(422, 'account_unusable', fn() => QueryLog::after(self::PASSWORD_READ, static function () use ($uid): void {
            Db::pdo()->prepare('UPDATE user_account SET locked_until = ? WHERE user_id = ?')->execute(['2026-10-01 12:15:00', $uid]);
        }, fn() => $this->change($ctx)));
        $this->assertSame('This account cannot be used at the moment. Please contact an Administrator.', $e->getMessage());
        $this->assertTrue($this->verifies($user, self::PASSWORD), 'unchanged');
        $this->assertSame('2026-10-01 12:15:00', $this->scalar('SELECT locked_until FROM user_account WHERE user_id = ?', [$uid]),
            'the lockout is not cleared by a change');
    }
}
