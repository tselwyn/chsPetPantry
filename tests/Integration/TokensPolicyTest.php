<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Policy;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Tests\TestCase;

/** Reset tokens (UC-01 §3.2.1), password rules (§4.1), confidentiality agreement (US-03). */
final class TokensPolicyTest extends TestCase
{
    public function testResetTokenIsSingleUseAndStoredOnlyAsAHash(): void
    {
        $user = $this->makeUser();
        $raw = Tokens::issue($user['user_id'], Tokens::PASSWORD_RESET, 60);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM auth_token WHERE token_hash = ?', [$raw]), 'the raw token is never stored');
        $row = Tokens::find($raw, Tokens::PASSWORD_RESET);
        $this->assertNotNull($row);
        $this->assertTrue(Tokens::consume((int) $row['token_id']));
        $this->assertFalse(Tokens::consume((int) $row['token_id']), 'second use refused');
        $this->assertNull(Tokens::find($raw, Tokens::PASSWORD_RESET));
    }

    public function testResetTokenExpiresAndIsPurposeBound(): void
    {
        $user = $this->makeUser();
        $raw = Tokens::issue($user['user_id'], Tokens::PASSWORD_RESET, 60);
        $this->assertNull(Tokens::find($raw, Tokens::TEMPORARY_CREDENTIAL));
        Clock::advance('+61 minutes');
        $this->assertNull(Tokens::find($raw, Tokens::PASSWORD_RESET));
    }

    public function testRevokeAll(): void
    {
        $user = $this->makeUser();
        $raw = Tokens::issue($user['user_id'], Tokens::PASSWORD_RESET, 60);
        Tokens::revokeAll($user['user_id'], Tokens::PASSWORD_RESET);
        $this->assertNull(Tokens::find($raw, Tokens::PASSWORD_RESET));
    }

    public function testPasswordPolicy(): void
    {
        $user = ['username' => 'jmartinez', 'email' => 'jmartinez@example.org', 'first_name' => 'Julia', 'last_name' => 'Martinez'];
        $this->assertSame([], PasswordPolicy::check('River-Stone-Candle-58', $user));
        $this->assertNotEmpty(PasswordPolicy::check('short-1', $user));
        $this->assertNotEmpty(PasswordPolicy::check('Password1234', $user), 'common list is case-insensitive');
        $this->assertNotEmpty(PasswordPolicy::check('martinez-garden-2026', $user), 'contains the last name');
        $this->assertNotEmpty(PasswordPolicy::check('aaaaaaaaaaaaaa', $user));
        $this->assertNotEmpty(PasswordPolicy::check('123456789012345', $user));
        $this->assertSame([], PasswordPolicy::check(PasswordPolicy::temporary(), $user), 'generated temporary passwords pass');
        $this->setSetting('password_min_length', '16');
        $this->assertNotEmpty(PasswordPolicy::check('River-Stone-58', $user));
    }

    public function testPasswordMaxAge(): void
    {
        $this->assertFalse(PasswordPolicy::isExpired('2020-01-01 00:00:00'), '0 means never');
        $this->setSetting('password_max_age_days', '90');
        $this->assertTrue(PasswordPolicy::isExpired('2026-06-01 00:00:00'));
        $this->assertFalse(PasswordPolicy::isExpired('2026-09-01 00:00:00'));
    }

    public function testConfidentialityAgreementGate(): void
    {
        $user = $this->makeUser();
        $this->assertFalse(Policy::acknowledgementRequired($user), 'nothing to accept when no agreement exists');

        $insert = Db::pdo()->prepare("INSERT INTO policy_document (doc_type, version, language_code, body, effective_from) VALUES ('Confidentiality Agreement', ?, ?, 'Text', ?)");
        $insert->execute(['1', 'en', '2026-01-01']);
        $v1 = (int) Db::pdo()->lastInsertId();
        $insert->execute(['1', 'es', '2026-01-01']);
        $this->assertTrue(Policy::acknowledgementRequired($user));

        Policy::acknowledge($user['user_id'], $v1);
        $this->assertFalse(Policy::acknowledgementRequired($user));

        $insert->execute(['2', 'en', '2026-09-15']);
        $v2 = (int) Db::pdo()->lastInsertId(); // read at once: any later query resets it
        $this->assertTrue(Policy::acknowledgementRequired($user), 'a new version needs a new acceptance');
        Policy::acknowledge($user['user_id'], $v2);
        $this->assertFalse(Policy::acknowledgementRequired($user));

        Clock::advance('+366 days');
        $this->assertTrue(Policy::acknowledgementRequired($user), 'due again after policy_reack_days (US-30)');
    }

    public function testCurrentPolicyFallsBackToTheDefaultLanguage(): void
    {
        $insert = Db::pdo()->prepare("INSERT INTO policy_document (doc_type, version, language_code, body, effective_from) VALUES ('SNV Explanation', '1', ?, ?, '2026-01-01')");
        $insert->execute(['en', 'English text']);
        $insert->execute(['es', 'Texto en español']);
        $this->assertSame('es', Policy::current('SNV Explanation', 'es')['language_code']);
        $this->assertSame('en', Policy::current('SNV Explanation', 'fr')['language_code']);
        $this->assertNull(Policy::current('Retention Notice', 'en'));
    }
}
