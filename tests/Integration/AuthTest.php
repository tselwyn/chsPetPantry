<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Auth;
use Pfpms\Auth\SessionStore;
use Pfpms\Clock;
use Pfpms\Tests\TestCase;

/** UC-01 Login: success, generic failures, lockout, account states, rehashing. */
final class AuthTest extends TestCase
{
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

    private function assertArrayHasUser(array $checked): void
    {
        $this->assertTrue(isset($checked['user']), 'expected a live session, got ' . json_encode($checked));
    }
}
