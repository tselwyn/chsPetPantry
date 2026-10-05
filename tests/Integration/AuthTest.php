<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use Pfpms\Account\AccountRepository;
use Pfpms\Account\AccountService;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Auth;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\SessionStore;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\Context;
use Pfpms\Http\HttpException;
use Pfpms\Tests\Support\QueryLog;
use Pfpms\Tests\TestCase;

/** UC-01 Login: success, generic failures, lockout, account states, rehashing. */
final class AuthTest extends TestCase
{
    private const OLD = 'Correct-Horse-Battery-9';
    private const NEW = 'Lantern-Orchard-Velvet-42';
    /** The request's one read of the stored password (Auth::verifiedHash), before any transaction. */
    private const PASSWORD_READ = '/^SELECT password_hash FROM user_account WHERE user_id = \?$/';
    /** Auth::lockVerified()'s account lock, the transaction's first statement. */
    private const ACCOUNT_LOCK = "/FROM user_account WHERE user_id = \\? AND username <> 'system' FOR UPDATE$/";

    protected function tearDown(): void
    {
        PasswordPolicy::$afterHash = null;
        parent::tearDown();
    }

    public function testSuccessfulLoginCreatesASessionAndAuditsIt(): void
    {
        $user = $this->makeUser();
        $result = Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.0.0.1');
        $this->assertTrue($result['ok']);
        $this->assertSame(64, strlen($result['sessionId']));
        $this->assertSame('Password', $this->scalar('SELECT auth_method FROM user_session WHERE session_id = ?', [$result['sessionId']]));
        $this->assertSame('Success', $this->scalar("SELECT outcome FROM audit_log WHERE action = 'login' AND user_id = ? ORDER BY audit_id DESC LIMIT 1", [$user['user_id']]));
        $this->assertSame(self::NOW, $this->scalar('SELECT last_login_at FROM user_account WHERE user_id = ?', [$user['user_id']]));
    }

    public function testEmailWorksAsTheIdentifier(): void
    {
        $user = $this->makeUser();
        $this->assertTrue(Auth::attempt(strtoupper($user['email']), 'Correct-Horse-Battery-9', '10.0.0.1')['ok'], 'case-insensitive email');
    }

    public function testUnknownUserAndWrongPasswordLookTheSame(): void
    {
        $user = $this->makeUser();
        $this->assertSame(['ok' => false, 'code' => 'invalid'], Auth::attempt('nobody-here', 'whatever-password', '10.0.0.2'));
        $this->assertSame(['ok' => false, 'code' => 'invalid'], Auth::attempt($user['username'], 'wrong-password-123', '10.0.0.2'));
    }

    public function testTheSystemAccountCanNeverSignIn(): void
    {
        $this->assertFalse(Auth::attempt('system', '!', '10.0.0.3')['ok']);
    }

    public function testLockoutAfterMaxFailedLoginsAndAdministratorsAreNotified(): void
    {
        $this->setSetting('max_failed_logins', '3');
        $user = $this->makeUser();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('invalid', Auth::attempt($user['username'], 'wrong-password-123', '10.0.0.4')['code']);
        }
        $this->assertNotNull($this->scalar('SELECT locked_until FROM user_account WHERE user_id = ?', [$user['user_id']]));
        $this->assertSame('locked', Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.0.0.4')['code'],
            'the right password on a locked account says locked, not invalid');
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'account_locked' AND recipient_role = 'Administrator' AND entity_id = ?", [$user['user_id']]));
    }

    public function testRepeatedLockoutsDoNotFloodAdministrators(): void
    {
        $this->setSetting('max_failed_logins', '2');
        $user = $this->makeUser();
        for ($round = 0; $round < 3; $round++) {
            Auth::attempt($user['username'], 'wrong-password-123', "10.2.0.$round");
            Auth::attempt($user['username'], 'wrong-password-123', "10.2.0.$round");
            Clock::advance('+16 minutes'); // lock expires, attacker locks it again
        }
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notification WHERE kind = 'account_locked' AND entity_id = ? AND resolved_at IS NULL", [$user['user_id']]));
        $this->assertSame(3, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'login' AND entity_id = ? AND reason = 'Wrong password; account locked'", [$user['user_id']]),
            'every lock is still audited');
    }

    public function testLockoutExpiresAfterLockoutMinutes(): void
    {
        $this->setSetting('max_failed_logins', '2');
        $this->setSetting('lockout_minutes', '15');
        $user = $this->makeUser();
        Auth::attempt($user['username'], 'wrong-password-123', '10.0.0.5');
        Auth::attempt($user['username'], 'wrong-password-123', '10.0.0.5');
        Clock::advance('+14 minutes');
        $this->assertSame('locked', Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.0.0.5')['code']);
        Clock::advance('+2 minutes');
        $this->assertTrue(Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.0.0.5')['ok']);
        $this->assertSame(0, (int) $this->scalar('SELECT failed_login_count FROM user_account WHERE user_id = ?', [$user['user_id']]));
    }

    public function testInactiveExpiredAndNotYetStartedAccountsAreRefused(): void
    {
        $inactive = $this->makeUser(['status' => 'Inactive']);
        $expired = $this->makeUser(['expiry_date' => '2026-09-30']);
        $future = $this->makeUser(['start_date' => '2026-10-02']);
        $this->assertSame('inactive', Auth::attempt($inactive['username'], 'Correct-Horse-Battery-9', '10.0.0.6')['code']);
        $this->assertSame('expired', Auth::attempt($expired['username'], 'Correct-Horse-Battery-9', '10.0.0.6')['code']);
        $this->assertSame('not_started', Auth::attempt($future['username'], 'Correct-Horse-Battery-9', '10.0.0.6')['code']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM user_session WHERE user_id IN (?, ?, ?)', [$inactive['user_id'], $expired['user_id'], $future['user_id']]));
    }

    public function testPendingAccountMustChangePasswordAndIsActivatedByTheChange(): void
    {
        $user = $this->makeUser(['status' => 'Pending', 'must_change_password' => 1]);
        $result = Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.0.0.7');
        $this->assertTrue($result['ok']);
        $this->assertTrue(AccountRules::mustChangePassword($result['user']));
        Auth::setPassword($user['user_id'], 'A-Brand-New-Password-4', 'Password Reset', $result['sessionId']);
        $this->assertSame('Active', $this->scalar('SELECT status FROM user_account WHERE user_id = ?', [$user['user_id']]));
        $this->assertSame(0, (int) $this->scalar('SELECT must_change_password FROM user_account WHERE user_id = ?', [$user['user_id']]));
        $this->assertTrue(Auth::attempt($user['username'], 'A-Brand-New-Password-4', '10.0.0.7')['ok']);
    }

    public function testPasswordChangeEndsOtherSessionsButKeepsTheCurrentOne(): void
    {
        $user = $this->makeUser();
        $first = Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.0.0.8')['sessionId'];
        $second = Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.0.0.8')['sessionId'];
        Auth::setPassword($user['user_id'], 'A-Brand-New-Password-4', 'Password Reset', $second);
        $this->assertSame(['ended' => 'ended'], SessionStore::validate($first));
        $this->assertArrayHasUser(SessionStore::validate($second));
    }

    public function testBcryptHashesAreUpgradedOnLogin(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->assertTrue(true);
            return;
        }
        $user = $this->makeUser(['password_hash' => password_hash('Correct-Horse-Battery-9', PASSWORD_BCRYPT)]);
        $this->assertTrue(Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.0.0.9')['ok']);
        $this->assertStringContainsString('$argon2id$', (string) $this->scalar('SELECT password_hash FROM user_account WHERE user_id = ?', [$user['user_id']]));
    }

    public function testRateLimitPerIdentifier(): void
    {
        $user = $this->makeUser();
        for ($i = 0; $i < 10; $i++) {
            Auth::attempt($user['username'], 'wrong-password-123', "10.1.0.$i");
        }
        $this->assertSame('rate_limited', Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.1.0.99')['code']);
        Clock::advance('+16 minutes');
        $this->setSetting('max_failed_logins', '100');
        $this->assertNotSame('rate_limited', Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.1.0.99')['code'] ?? 'ok');
    }

    // The shared core (S3 spec §2.2) -----------------------------------------------------------------

    public function testAWebSignInResetsTheOnlinePinCounter(): void
    {
        $user = $this->makeUser();
        $count = fn() => (int) $this->scalar('SELECT pin_failed_count FROM user_account WHERE user_id = ?', [$user['user_id']]);
        Db::pdo()->prepare('UPDATE user_account SET pin_failed_count = 3 WHERE user_id = ?')->execute([$user['user_id']]);
        $this->assertSame('invalid', Auth::attempt($user['username'], 'wrong-password-123', '10.0.0.10')['code']);
        $this->assertSame(3, $count(), 'a wrong password changes nothing');
        $this->assertTrue(Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.0.0.10')['ok']);
        $this->assertSame(0, $count(), 'any password sign-in clears the online PIN failures (D-22)');
    }

    public function testTheWebSignInNeverReturnsThePasswordHash(): void
    {
        $user = $this->makeUser();
        $result = Auth::attempt($user['username'], 'Correct-Horse-Battery-9', '10.0.0.11');
        $this->assertSame(['ok', 'user', 'sessionId'], array_keys($result));
        $this->assertArrayNotHasKey('password_hash', $result['user']);
        $this->assertSame([$user['user_id'], $user['username']], [(int) $result['user']['user_id'], $result['user']['username']]);
        $this->assertFalse(str_contains((string) json_encode($result), '$argon2id$'));
    }

    public function testTheWebSignInStillUsesTheIpBucket(): void
    {
        $user = $this->makeUser();
        $this->assertTrue(Auth::attempt(' ' . strtoupper($user['username']) . ' ', 'Correct-Horse-Battery-9', '10.0.0.12')['ok']);
        $hits = fn(string $bucket) => $this->scalar('SELECT hits FROM rate_limit_bucket WHERE bucket = ?', [$bucket]);
        $this->assertSame(1, (int) $hits('login:ip:10.0.0.12'));
        $this->assertSame(1, (int) $hits('login:id:' . mb_strtolower($user['username'])), 'the trimmed, lower-case identifier');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM rate_limit_bucket WHERE bucket LIKE 'login:device:%'"));
        $details = $this->scalar("SELECT details FROM audit_log WHERE action = 'login' AND entity_id = ? ORDER BY audit_id DESC LIMIT 1", [$user['user_id']]);
        $this->assertSame(['ip' => '10.0.0.12'], json_decode((string) $details, true), 'the web row says nothing of a tablet');
    }

    public function testTheStationSignInSharesTheCoreWithTheTabletsBucket(): void
    {
        $user = $this->makeUser();
        $calls = [];
        $before = function (array $u) use (&$calls): string {
            $calls[] = 'before';
            return 'locked';
        };
        $after = function (array $u, int $uid, mixed $b) use (&$calls, $user): array {
            $calls[] = 'after';
            $row = Db::pdo()->prepare('SELECT pin_failed_count, last_login_at FROM user_account WHERE user_id = ?');
            $row->execute([$uid]);
            return ['before' => $b, 'uid' => $uid, 'row' => $row->fetch(), 'same' => $uid === $user['user_id']];
        };
        $noAccess = $this->makeUser();
        $r = Auth::attemptStation($noAccess['username'], 'Correct-Horse-Battery-9', '10.0.0.13', 77, fn(array $u) => 'site', $before, $after);
        $this->assertSame(['ok' => false, 'code' => 'no_access', 'reason' => 'site'], $r);
        $this->assertSame([], $calls, 'refused before any write');
        $this->assertNull($this->scalar('SELECT last_login_at FROM user_account WHERE user_id = ?', [$noAccess['user_id']]));
        $denied = Db::pdo()->prepare("SELECT outcome, reason, details FROM audit_log WHERE action = 'login' AND entity_id = ? ORDER BY audit_id");
        $denied->execute([$noAccess['user_id']]);
        $row = $denied->fetch();
        $details = json_decode((string) $row['details'], true);
        ksort($details);
        $this->assertSame(['Denied', "No access to this tablet's site or the Station", ['ip' => '10.0.0.13', 'reason_code' => 'site', 'station' => true]],
            [$row['outcome'], $row['reason'], $details]);

        Db::pdo()->prepare('UPDATE user_account SET pin_failed_count = 2 WHERE user_id = ?')->execute([$user['user_id']]);
        $this->assertSame(['ok' => false, 'code' => 'invalid'], Auth::attemptStation($user['username'], 'wrong-password-123', '10.0.0.13', 77, fn(array $u) => null, $before, $after));
        $r = Auth::attemptStation($user['username'], 'Correct-Horse-Battery-9', '10.0.0.13', 77, fn(array $u) => null, $before, $after);
        $this->assertTrue($r['ok']);
        $this->assertSame(['before', 'after'], $calls);
        $this->assertSame(['before' => 'locked', 'uid' => $user['user_id'], 'row' => ['pin_failed_count' => 0, 'last_login_at' => self::NOW], 'same' => true], $r['result'],
            'afterUpdate runs after the account UPDATE, with what beforeUpdate returned');
        $this->assertArrayNotHasKey('password_hash', $r['user']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM user_session WHERE user_id = ?', [$user['user_id']]), 'the Station makes its own session');
        $this->assertSame(3, (int) $this->scalar('SELECT hits FROM rate_limit_bucket WHERE bucket = ?', ['login:device:77']));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM rate_limit_bucket WHERE bucket LIKE 'login:ip:%'"), 'no address bucket on the Station (D-53)');
    }

    // The web change (change_password.php) and the re-check under the account lock -------------------

    public function testTheWebChangeChecksThenChangesKeepingThisSession(): void
    {
        $user = $this->makeUser(['must_change_password' => 1]);
        [$ctx, $other] = $this->webSessions($user);
        $this->assertSame(['The current password is not correct.'], Auth::changeOwnPassword($ctx, 'Not-The-Password-1', self::NEW, self::NEW));
        $this->assertSame('Failed', $this->scalar("SELECT outcome FROM audit_log WHERE action = 'password_change' AND entity_id = ?", [$user['user_id']]));
        $this->assertSame(['The new passwords do not match.'], Auth::changeOwnPassword($ctx, self::OLD, self::NEW, self::NEW . 'x'));
        $this->assertSame(['Choose a password different from your current one.'], Auth::changeOwnPassword($ctx, self::OLD, self::OLD, self::OLD));
        $this->assertNotSame([], Auth::changeOwnPassword($ctx, self::OLD, 'Short-1x', 'Short-1x'), 'the policy rules');
        $this->assertTrue(Auth::verifyPassword($user['user_id'], self::OLD), 'nothing changed yet');

        $this->assertSame([], Auth::changeOwnPassword($ctx, self::OLD, self::NEW, self::NEW));
        $this->assertTrue(Auth::verifyPassword($user['user_id'], self::NEW));
        $this->assertSame(0, (int) $this->scalar('SELECT must_change_password FROM user_account WHERE user_id = ?', [$user['user_id']]));
        $this->assertSame('Forced change', $this->scalar("SELECT reason FROM audit_log WHERE action = 'password_change' AND outcome = 'Success' AND entity_id = ?", [$user['user_id']]));
        $this->assertArrayHasUser(SessionStore::validate($ctx->sessionId));
        $this->assertSame(['ended' => 'ended'], SessionStore::validate($other), 'the other session ended');
    }

    public function testTheWebChangeHashesTheNewPasswordBeforeItsTransaction(): void
    {
        // The ≈0.4 s of Argon2 runs before the account FOR UPDATE, not while the row is locked (sign-ins, PIN reservations
        // and the FK checks of audit rows naming the person would wait on it).
        $user = $this->makeUser();
        [$ctx] = $this->webSessions($user);
        PasswordPolicy::$afterHash = static fn() => QueryLog::mark('password hash');
        $result = null;
        $log = QueryLog::during(function () use ($ctx, &$result): void {
            $result = Auth::changeOwnPassword($ctx, self::OLD, self::NEW, self::NEW);
        });
        $this->assertSame([], $result);
        $this->assertCount(1, array_keys($log, '-- password hash', true), 'one hash: ' . json_encode($log));
        $read = QueryLog::first($log, self::PASSWORD_READ);
        $hash = QueryLog::first($log, '/^-- password hash$/');
        $lock = QueryLog::first($log, self::ACCOUNT_LOCK);
        $write = QueryLog::first($log, '/^UPDATE user_account SET password_hash = \?/');
        $this->assertNotNull($write, json_encode($log));
        $this->assertTrue($read < $hash && $hash < $lock && $lock < $write,
            'the verify, the hash, then the transaction: the account lock and the write: ' . json_encode($log));
        $this->assertTrue(Auth::verifyPassword($user['user_id'], self::NEW), 'the hash made before the transaction is the one stored');
    }

    public function testSetPasswordHashTakesOnlyACurrentHash(): void
    {
        $user = $this->makeUser();
        foreach ([self::NEW, password_hash(self::NEW, PASSWORD_BCRYPT, ['cost' => 10])] as $notCurrent) {
            try {
                Auth::setPasswordHash($user['user_id'], $notCurrent, 'Password Reset');
                $this->fail('a plain password or an outdated hash is refused');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertTrue(Auth::verifyPassword($user['user_id'], self::OLD), 'nothing written');
        Auth::setPasswordHash($user['user_id'], PasswordPolicy::hash(self::NEW), 'Password Reset');
        $this->assertTrue(Auth::verifyPassword($user['user_id'], self::NEW));
    }

    public function testAResetCommittedDuringTheWebChangeWins(): void
    {
        $admin = $this->makeUser(['role' => 'Administrator']);
        $user = $this->makeUser();
        [$ctx] = $this->webSessions($user);
        // The Administrator's reset commits after the request read the password, while it verifies it. lockVerified() refuses
        // with 401 session_ended; changeOwnPassword() returns null, and change_password.php reloads so that Page::start() signs
        // the person out and says why.
        $this->assertNull($this->webChangeAfter(self::PASSWORD_READ, fn() => AccountService::sendReset($user['user_id'], $admin['user_id']), $ctx),
            'null: the page reloads');
        $this->assertSame(['ended' => 'ended'], SessionStore::validate($ctx->sessionId), 'the reset ended the session: Page::start() signs out');
        $this->assertFalse(Auth::verifyPassword($user['user_id'], self::NEW), 'the request the reset cut off set nothing');
        $this->assertSame(1, (int) $this->scalar('SELECT must_change_password FROM user_account WHERE user_id = ?', [$user['user_id']]));
        $this->assertNull($this->scalar("SELECT revoked_at FROM auth_token WHERE user_id = ? AND purpose = 'Temporary Credential'", [$user['user_id']]),
            "the reset's emailed link still works");
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'password_change' AND entity_id = ?", [$user['user_id']]));
    }

    public function testAnAccountLockedDuringTheWebChangeKeepsItsPassword(): void
    {
        $user = $this->makeUser();
        $uid = (int) $user['user_id'];
        [$ctx] = $this->webSessions($user);
        // A lockout (or a deactivation) commits while the request verifies: lockVerified()'s 422 account_unusable, also null.
        $this->assertNull($this->webChangeAfter(self::PASSWORD_READ, static function () use ($uid): void {
            Db::pdo()->prepare('UPDATE user_account SET locked_until = ? WHERE user_id = ?')->execute(['2026-10-01 12:15:00', $uid]);
        }, $ctx), 'null: the page reloads');
        $this->assertSame(['ended' => 'account'], SessionStore::validate($ctx->sessionId), 'Page::start() signs out: the account can no longer be used');
        $this->assertTrue(Auth::verifyPassword($uid, self::OLD), 'nothing changed');
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'password_change' AND entity_id = ?", [$uid]));
    }

    public function testAnyOtherRefusalDuringTheWebChangeIsNotTurnedIntoAReload(): void
    {
        $user = $this->makeUser();
        [$ctx] = $this->webSessions($user);
        $e = $this->refusal(fn() => $this->webChangeAfter(self::ACCOUNT_LOCK, static function (): never {
            throw new HttpException(503, 'Down for maintenance.', 'maintenance');
        }, $ctx));
        $this->assertSame([503, 'maintenance'], [$e->status, $e->code()], 'only the two re-check refusals mean "reload"');
        $this->assertTrue(Auth::verifyPassword($user['user_id'], self::OLD));
    }

    public function testTheReCheckRefusesWhatChangedSinceTheVerify(): void
    {
        $user = $this->makeUser();
        $uid = (int) $user['user_id'];
        [$ctx] = $this->webSessions($user);
        $verified = Auth::verifiedHash($uid, self::OLD);
        $this->assertIsString($verified);
        $this->assertNull(Auth::verifiedHash($uid, 'Not-The-Password-1'));
        $row = Auth::lockVerified($uid, $ctx->sessionId, $verified);
        $this->assertSame([$uid, $verified], [(int) $row['user_id'], $row['password_hash']]);
        $this->assertArrayHasKey('pin_hash', $row);

        $changes = [
            'the password changed' => ['UPDATE user_account SET password_hash = ? WHERE user_id = ?', ['$argon2id$v=19$m=65536,t=4,p=1$other', $uid], 401],
            'the session ended' => ['UPDATE user_session SET ended_at = ? WHERE session_id = ?', [self::NOW, $ctx->sessionId], 401],
            'the account locked' => ['UPDATE user_account SET locked_until = ? WHERE user_id = ?', ['2026-10-01 12:15:00', $uid], 422],
        ];
        foreach ($changes as $name => [$sql, $args, $status]) {
            Db::pdo()->exec('SAVEPOINT recheck');
            Db::pdo()->prepare($sql)->execute($args);
            $e = $this->refusal(fn() => Auth::lockVerified($uid, $ctx->sessionId, $verified));
            $this->assertSame([$status, $status === 401 ? 'session_ended' : 'account_unusable'], [$e->status, $e->code()], $name);
            Db::pdo()->exec('ROLLBACK TO SAVEPOINT recheck');
        }
        [$otherCtx] = $this->webSessions($this->makeUser());
        $this->assertSame(401, $this->refusal(fn() => Auth::lockVerified($uid, $otherCtx->sessionId, $verified))->status, "another person's session");
    }

    /**
     * change_password.php's call, with $then committed right after the first statement matching $regex.
     * @return ?list<string> what Auth::changeOwnPassword() returned
     */
    private function webChangeAfter(string $regex, callable $then, Context $ctx): ?array
    {
        $result = [];
        QueryLog::after($regex, $then, function () use ($ctx, &$result): void {
            $result = Auth::changeOwnPassword($ctx, self::OLD, self::NEW, self::NEW);
        });
        return $result;
    }

    /** @return array{0: Context, 1: string} the Context of a web session of $user, and the id of a second session of theirs */
    private function webSessions(array $user): array
    {
        $row = AccountRepository::find((int) $user['user_id']);
        $this->assertNotNull($row);
        $sid = SessionStore::create((int) $user['user_id'], null);
        return [new Context($row, $sid, null, []), SessionStore::create((int) $user['user_id'], null)];
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

    private function assertArrayHasUser(array $checked): void
    {
        $this->assertTrue(isset($checked['user']), 'expected a live session, got ' . json_encode($checked));
    }
}
