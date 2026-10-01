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

    public function testConsumeRefusesATokenThatExpiredAfterFind(): void
    {
        $user = $this->makeUser();
        $raw = Tokens::issue($user['user_id'], Tokens::PASSWORD_RESET, 60);
        $row = Tokens::find($raw, Tokens::PASSWORD_RESET);
        Clock::advance('+61 minutes');
        $this->assertFalse(Tokens::consume((int) $row['token_id']));
        $this->assertNull($this->scalar('SELECT used_at FROM auth_token WHERE token_id = ?', [$row['token_id']]));
    }

    public function testIssueValueStoresOnlyTheHashAndReturnsTheExpiry(): void
    {
        $user = $this->makeUser();
        $expires = Tokens::issueValue($user['user_id'], Tokens::DEVICE_REGISTRATION, 'K7QM2XRD9VHPC4TNS', 30);
        $this->assertSame('2026-10-01 12:30:00', $expires);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM auth_token WHERE token_hash = ?', ['K7QM2XRD9VHPC4TNS']));
        $this->assertSame(Tokens::hash('K7QM2XRD9VHPC4TNS'), $this->scalar("SELECT token_hash FROM auth_token WHERE purpose = 'Device Registration'"));
        $this->assertNotNull(Tokens::find('K7QM2XRD9VHPC4TNS', Tokens::DEVICE_REGISTRATION));
    }

    public function testRevokeForDevice(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $tablet = $this->makeDevice($site);
        $other = $this->makeDevice($site);
        $code = Tokens::issue($user['user_id'], Tokens::DEVICE_REGISTRATION, 60, $tablet);
        $grant = Tokens::issue($user['user_id'], Tokens::OFFLINE_GRANT, 60, $tablet);
        $used = Tokens::issue($user['user_id'], Tokens::DEVICE_REGISTRATION, 60, $tablet);
        Tokens::consume((int) Tokens::find($used, Tokens::DEVICE_REGISTRATION)['token_id']);
        $elsewhere = Tokens::issue($user['user_id'], Tokens::OFFLINE_GRANT, 60, $other);
        Tokens::issue($user['user_id'], Tokens::OFFLINE_GRANT, 1, $tablet); // expires before the revocation
        Clock::advance('+2 minutes');
        $this->assertSame(1, Tokens::revokeForDevice($tablet, Tokens::DEVICE_REGISTRATION));
        $this->assertNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION));
        $this->assertNotNull(Tokens::find($grant, Tokens::OFFLINE_GRANT), 'the purpose filter');
        $this->assertSame(1, Tokens::revokeForDevice($tablet), 'the grant; not the redeemed code or the expired grant');
        $this->assertNull($this->scalar("SELECT revoked_at FROM auth_token WHERE purpose = 'Device Registration' AND used_at IS NOT NULL"));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM auth_token WHERE device_id = ? AND revoked_at IS NULL AND expires_at <= ?', [$tablet, Clock::db()]));
        $this->assertNotNull(Tokens::find($elsewhere, Tokens::OFFLINE_GRANT), 'another tablet');
    }

    public function testIssueRecordsCreatedAt(): void
    {
        $user = $this->makeUser();
        $reset = Tokens::issue($user['user_id'], Tokens::PASSWORD_RESET, 60);
        Clock::advance('+90 seconds');
        Tokens::issueValue($user['user_id'], Tokens::DEVICE_REGISTRATION, 'K7QM2XRD9VHPC4TNS', 30);
        $this->assertSame(self::NOW, $this->scalar('SELECT created_at FROM auth_token WHERE token_hash = ?', [Tokens::hash($reset)]));
        $this->assertSame('2026-10-01 12:01:30', $this->scalar('SELECT created_at FROM auth_token WHERE token_hash = ?', [Tokens::hash('K7QM2XRD9VHPC4TNS')]),
            'the frozen clock at issue, whole seconds');
        $this->assertSame('2026-10-01 12:01:30', Tokens::findAny('K7QM2XRD9VHPC4TNS', Tokens::DEVICE_REGISTRATION)['created_at']);
    }

    public function testRevokedGrantIdsListsOnlyThisTabletsRecentGrants(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $tablet = $this->makeDevice($site);
        $other = $this->makeDevice($site);
        $grant = fn(int $deviceId, ?string $revokedAt, string $purpose = Tokens::OFFLINE_GRANT): int => $this->token($user['user_id'], $purpose, $deviceId, $revokedAt);

        $now = $grant($tablet, self::NOW);
        $grant($tablet, null);                                              // live
        $justIn = $grant($tablet, '2026-09-24 12:00:01');                   // 167:59:59 ago
        $grant($tablet, '2026-09-24 12:00:00');                             // exactly 168 hours ago: out
        $grant($tablet, '2026-09-20 08:00:00');                             // long ago
        $grant($other, self::NOW);                                          // another tablet
        $grant($tablet, self::NOW, Tokens::DEVICE_REGISTRATION);            // another purpose
        $earlier = $grant($tablet, '2026-10-01 09:00:00');

        $ids = Tokens::revokedGrantIds($tablet);
        $expected = [$now, $justIn, $earlier];
        sort($expected);
        $this->assertSame($expected, $ids, 'ordered by token_id, as integers');
        $this->assertSame([], Tokens::revokedGrantIds($this->makeDevice($site)), 'a tablet with no grants');
    }

    public function testRevokedGrantIdsIncludeAnExpiredRevokedGrant(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $tablet = $this->makeDevice($site);
        $expired = $this->token($user['user_id'], Tokens::OFFLINE_GRANT, $tablet, '2026-10-01 10:00:00', '2026-10-01 11:00:00');
        $this->assertSame([$expired], Tokens::revokedGrantIds($tablet), 'the tablet still needs to hear of it, whatever the expiry');
    }

    public function testFindAnyReturnsUsedRows(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $tablet = $this->makeDevice($site);
        Tokens::issueValue($user['user_id'], Tokens::DEVICE_REGISTRATION, 'K7QM2XRD9VHPC4TNS', 30, $tablet);
        $id = (int) Tokens::find('K7QM2XRD9VHPC4TNS', Tokens::DEVICE_REGISTRATION)['token_id'];
        $this->assertTrue(Tokens::consume($id));
        $this->assertNull(Tokens::find('K7QM2XRD9VHPC4TNS', Tokens::DEVICE_REGISTRATION), 'find() ignores a used code');

        $row = Tokens::findAny('K7QM2XRD9VHPC4TNS', Tokens::DEVICE_REGISTRATION);
        $this->assertSame(['token_id', 'user_id', 'purpose', 'device_id', 'expires_at', 'used_at', 'revoked_at', 'created_at'], array_keys($row),
            'never the hash or a secret');
        $this->assertSame([$id, $user['user_id'], Tokens::DEVICE_REGISTRATION, $tablet, '2026-10-01 12:30:00', self::NOW, null, self::NOW],
            [(int) $row['token_id'], (int) $row['user_id'], $row['purpose'], (int) $row['device_id'], $row['expires_at'], $row['used_at'], $row['revoked_at'], $row['created_at']]);
    }

    public function testFindAnyReturnsRevokedAndExpiredRows(): void
    {
        $user = $this->makeUser();
        $revoked = Tokens::issue($user['user_id'], Tokens::PASSWORD_RESET, 60);
        Tokens::revokeAll($user['user_id'], Tokens::PASSWORD_RESET);
        $expired = Tokens::issue($user['user_id'], Tokens::PASSWORD_RESET, 1);
        Clock::advance('+2 minutes');
        $this->assertNull(Tokens::find($expired, Tokens::PASSWORD_RESET));
        $this->assertSame(self::NOW, Tokens::findAny($revoked, Tokens::PASSWORD_RESET)['revoked_at']);
        $this->assertSame('2026-10-01 12:01:00', Tokens::findAny($expired, Tokens::PASSWORD_RESET)['expires_at']);
    }

    public function testFindAnyIsPurposeBoundAndRefusesEmptyOrOverlongValues(): void
    {
        $user = $this->makeUser();
        $raw = Tokens::issue($user['user_id'], Tokens::PASSWORD_RESET, 60);
        $this->assertNull(Tokens::findAny($raw, Tokens::TEMPORARY_CREDENTIAL));
        $this->assertNull(Tokens::findAny('', Tokens::PASSWORD_RESET));
        $long = str_repeat('a', 101);
        $hundred = str_repeat('b', 100);
        Tokens::issueValue($user['user_id'], Tokens::PASSWORD_RESET, $long, 60);
        Tokens::issueValue($user['user_id'], Tokens::PASSWORD_RESET, $hundred, 60);
        $this->assertNull(Tokens::findAny($long, Tokens::PASSWORD_RESET), 'over 100 characters is refused, even though a row has its hash');
        $this->assertNotNull(Tokens::findAny($hundred, Tokens::PASSWORD_RESET), '100 characters is still looked up');
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

    public function testReacknowledgementDatesAreOrganisationDates(): void
    {
        $this->setSetting('organisation_time_zone', 'America/New_York');
        $this->setSetting('policy_reack_days', '365');
        $user = $this->makeUser();
        Db::pdo()->exec("INSERT INTO policy_document (doc_type, version, language_code, body, effective_from) VALUES ('Confidentiality Agreement', '1', 'en', 'Text', '2026-01-01')");
        $doc = (int) Db::pdo()->lastInsertId();

        Clock::freeze('2026-10-02 02:00:00'); // 10 pm on 1 October in New York
        Policy::acknowledge($user['user_id'], $doc);
        $this->assertSame('2027-10-01', $this->scalar('SELECT due_again_on FROM policy_acknowledgement WHERE user_id = ?', [$user['user_id']]),
            '365 days from the local date it was accepted, not from the UTC date');
        Clock::freeze('2027-10-01 03:59:59'); // 11:59:59 pm on 30 September in New York
        $this->assertFalse(Policy::acknowledgementRequired($user));
        Clock::freeze('2027-10-01 04:00:00'); // midnight: the due date has begun locally
        $this->assertTrue(Policy::acknowledgementRequired($user), 'due again on the local due date (US-30)');
    }

    public function testAStrayOldTranslationNeverReplacesANewerVersion(): void
    {
        $insert = Db::pdo()->prepare("INSERT INTO policy_document (doc_type, version, language_code, body, effective_from) VALUES ('Retention Notice', ?, ?, ?, ?)");
        $insert->execute(['1', 'en', 'English v1', '2026-01-01']);
        $insert->execute(['2', 'en', 'English v2', '2026-06-01']);
        $insert->execute(['1', 'es', 'Español v1', '2026-07-01']); // translation of the OLD version, dated later
        $this->assertSame('English v2', Policy::current('Retention Notice', 'es')['body'], 'Spanish readers get the version in force, in English');
        $insert->execute(['2', 'es', 'Español v2', '2026-06-01']);
        $this->assertSame('Español v2', Policy::current('Retention Notice', 'es')['body']);
        $insert->execute(['3', 'es', 'Español v3', '2026-09-01']); // no English v3 yet: not in force
        $this->assertSame('Español v2', Policy::current('Retention Notice', 'es')['body']);
    }

    public function testFingerprintChangesWithTheWording(): void
    {
        $doc = ['document_id' => 7, 'body' => 'Keep participant information confidential.'];
        $this->assertSame(Policy::fingerprint($doc), Policy::fingerprint($doc));
        $this->assertNotSame(Policy::fingerprint($doc), Policy::fingerprint(['body' => $doc['body'] . ' Edited.'] + $doc));
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

    /** An auth_token row as a later slice would leave it (e.g. an offline grant revoked at a given time); returns its id. */
    private function token(int $userId, string $purpose, ?int $deviceId, ?string $revokedAt, string $expiresAt = '2026-10-04 12:00:00'): int
    {
        Db::pdo()->prepare('INSERT INTO auth_token (user_id, purpose, token_hash, device_id, expires_at, revoked_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $purpose, Tokens::hash(bin2hex(random_bytes(16))), $deviceId, $expiresAt, $revokedAt, '2026-09-19 12:00:00']);
        return (int) Db::pdo()->lastInsertId();
    }
}
