<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Station;

use DateTimeImmutable;
use Pfpms\Account\AccountRepository;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Policy;
use Pfpms\Auth\SiteAccess;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Device\DeviceRepository;
use Pfpms\Reference\PolicyService;
use Pfpms\Security\Crypto;
use Pfpms\Station\OfflineGrants;
use Pfpms\Tests\Support\StationFixture;
use Pfpms\Tests\TestCase;
use RuntimeException;

/**
 * Offline grants (50-design D-19..D-21, D-24, §3.7; S3 spec §2.4): what a grant is capped by, how it is stored, when it is
 * valid, how it is revoked, and the online PIN window it opens. The clock is frozen at 2026-10-01 12:00:00 UTC, 08:00 in
 * the organisation's zone (America/New_York, EDT).
 */
final class OfflineGrantsTest extends TestCase
{
    use StationFixture;

    private static ?string $passwordHash = null;

    private int $north;
    private int $south;
    private array $tablet;
    private array $other;
    private array $volunteer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stationSetUp();
        $this->setSetting('organisation_time_zone', 'America/New_York');
        $this->north = $this->makeSite('Northside');
        $this->south = $this->makeSite('Southside');
        $this->tablet = $this->stationTablet($this->north);
        $this->other = $this->stationTablet($this->south);
        $this->volunteer = $this->person();
    }

    protected function tearDown(): void
    {
        $this->stationTearDown();
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    /** A person with site access to Northside and Southside (unless $sites says otherwise), as the account row reads them. */
    private function person(array $overrides = [], ?array $sites = null): array
    {
        self::$passwordHash ??= PasswordPolicy::hash('Correct-Horse-Battery-9');
        $user = $this->makeUser($overrides + ['password_hash' => self::$passwordHash]);
        foreach ($sites ?? [$this->north, $this->south] as $siteId) {
            $this->grantSite($user['user_id'], $siteId);
        }
        return $this->account($user['user_id']);
    }

    private function account(int $userId): array
    {
        $row = AccountRepository::find($userId);
        $this->assertNotNull($row);
        return $row;
    }

    /** The device keys issue() reads. */
    private function device(array $tablet): array
    {
        return ['device_id' => $tablet['id'], 'site_id' => (int) $this->scalar('SELECT site_id FROM device WHERE device_id = ?', [$tablet['id']])];
    }

    private function issue(array $user, ?array $tablet = null, bool $offline = true, ?DateTimeImmutable $at = null): ?array
    {
        return OfflineGrants::issue($user, $this->device($tablet ?? $this->tablet), $offline, $at);
    }

    /** @return array{0: string, 1: string} expiry()'s instant (Clock::db) and rule for $user on the Northside tablet */
    private function cap(array $user, bool $offline = true): array
    {
        $e = OfflineGrants::expiry($user, $this->device($this->tablet), $offline);
        return [Clock::db($e['at']), $e['capped_by']];
    }

    private function grantRow(int $grantId): array
    {
        $st = Db::pdo()->prepare('SELECT * FROM auth_token WHERE token_id = ?');
        $st->execute([$grantId]);
        return $st->fetch();
    }

    private function revoke(int $grantId, string $at): void
    {
        Db::pdo()->prepare('UPDATE auth_token SET revoked_at = ? WHERE token_id = ?')->execute([$at, $grantId]);
    }

    private function grantCount(int $userId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM auth_token WHERE user_id = ? AND purpose = 'Offline Grant'", [$userId]);
    }

    private function policyText(string $version, string $language, string $effectiveFrom): int
    {
        Db::pdo()->prepare('INSERT INTO policy_document (doc_type, version, language_code, body, effective_from) VALUES (?, ?, ?, ?, ?)')
            ->execute([Policy::CONFIDENTIALITY, $version, $language, "Agreement $version ($language)", $effectiveFrom]);
        return (int) Db::pdo()->lastInsertId();
    }

    private function setDue(int $userId, ?string $due): void
    {
        Db::pdo()->prepare('UPDATE policy_acknowledgement SET due_again_on = ? WHERE user_id = ?')->execute([$due, $userId]);
    }

    private function at(string $db): DateTimeImmutable
    {
        return Clock::fromDb($db) ?? throw new RuntimeException('bad time');
    }

    // Issue and supersede -------------------------------------------------------------------------

    public function testANewGrantSupersedesButDoesNotRevokeTheOlder(): void
    {
        $first = $this->issue($this->volunteer);
        Clock::advance('+5 minutes');
        $second = $this->issue($this->volunteer);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first['grant_id'], $second['grant_id']);
        $rows = $this->grantsOf($this->volunteer['user_id']);
        $this->assertSame([null, null], array_column($rows, 'revoked_at'), 'a lost answer must not turn an offline shift into held records');
        foreach ($rows as $row) {
            $this->assertTrue(OfflineGrants::validAt($row, Clock::now()), 'both stay valid');
            $this->assertSame([(int) $this->volunteer['user_id'], $this->tablet['id']], [(int) $row['user_id'], (int) $row['device_id']]);
        }
        $this->assertSame([], Tokens::revokedGrantIds($this->tablet['id']));
    }

    public function testTheSecretDecryptsOnlyWithItsRow(): void
    {
        $a = $this->issue($this->volunteer);
        $b = $this->issue($this->volunteer);
        $rowA = OfflineGrants::find($a['grant_id']);
        $rowB = OfflineGrants::find($b['grant_id']);
        $this->assertSame(32, strlen($a['secret']));
        $this->assertSame($a['secret'], OfflineGrants::secret($rowA));
        $this->assertSame($b['secret'], OfflineGrants::secret($rowB));
        $this->assertNotSame($a['secret'], $b['secret']);
        $this->assertStringStartsWith('g1.', (string) $rowA['secret_ciphertext'], 'sealed with the config key ring');
        $this->assertFalse(str_contains((string) $rowA['secret_ciphertext'], Crypto::b64url($a['secret'])));

        $swapped = ['secret_ciphertext' => $rowA['secret_ciphertext']] + $rowB;
        try {
            OfflineGrants::secret($swapped);
            $this->fail("another row's sealed secret must not open: the AAD names the row");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Decryption failed', $e->getMessage());
        }
        $this->assertNull(OfflineGrants::secret(['secret_ciphertext' => null] + $rowA), 'shredded');
    }

    public function testIssueTakesTheGivenInstant(): void
    {
        $at = Clock::now();
        Clock::advance('+2 seconds');
        $grant = $this->issue($this->volunteer, null, true, $at);
        $this->assertSame(['2026-10-01 12:00:00.000', '2026-10-04 12:00:00.000', 'hours'], [$grant['issued_at'], $grant['expires_at'], $grant['capped_by']]);
        $row = $this->grantRow($grant['grant_id']);
        $this->assertSame(['2026-10-01 12:00:00', '2026-10-04 12:00:00'], [$row['created_at'], $row['expires_at']], 'created_at and the hours cap from $at');

        $now = $this->issue($this->volunteer);
        $this->assertSame(['2026-10-01 12:00:02.000', '2026-10-04 12:00:02.000'], [$now['issued_at'], $now['expires_at']], 'without $at: now');
    }

    public function testIssuedSinceSeesEveryGrantFromThatTime(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $t = Clock::db();
        $this->issue($this->volunteer, null, true, Clock::now()->modify('-1 second'));
        $this->issue($this->volunteer, $this->other);
        $this->issue($this->person(), $this->tablet);
        $this->assertFalse(OfflineGrants::issuedSince($uid, $this->tablet['id'], $t), "a second earlier, another tablet's, another person's: none counts");
        $this->assertTrue(OfflineGrants::issuedSince($uid, $this->tablet['id'], '2026-10-01 11:59:59'));
        $this->assertTrue(OfflineGrants::issuedSince($uid, $this->other['id'], $t));

        $grant = $this->issue($this->volunteer);
        $this->revoke($grant['grant_id'], Clock::db());
        $this->assertTrue(OfflineGrants::issuedSince($uid, $this->tablet['id'], $t), 'a revoked grant counts: whatever its state');
    }

    // The caps (D-19) -------------------------------------------------------------------------------

    public function testExpiryIsCappedBySiteAccessEnd(): void
    {
        $user = $this->person([], []);
        $this->grantSite($user['user_id'], $this->north, '2026-01-01 00:00:00', '2026-10-02 18:30:00');
        $this->assertSame(['2026-10-02 18:30:00', 'site_access'], $this->cap($user));
        $grant = $this->issue($user);
        $this->assertSame(['2026-10-02 18:30:00.000', 'site_access'], [$grant['expires_at'], $grant['capped_by']]);
        $this->assertSame('2026-10-02 18:30:00', $this->grantRow($grant['grant_id'])['expires_at']);
    }

    public function testNoGrantForSomeoneWhoseAccessJustEnded(): void
    {
        $user = $this->person([], []);
        $this->grantSite($user['user_id'], $this->north, '2026-01-01 00:00:00', '2026-10-01 11:59:59');
        $this->assertSame('2026-10-01 12:00:00', SiteAccess::accessEnd($user, $this->north), 'no live grant: now, never "no end"');
        $this->assertSame(['2026-10-01 12:00:00', 'site_access'], $this->cap($user));
        $this->assertNull($this->issue($user));
        $this->assertSame(0, $this->grantCount($user['user_id']), 'nothing written');
        $this->assertSame([], $this->auditRows('offline_grant_issue'));
    }

    public function testExpiryIsCappedByAScheduledAgreementVersion(): void
    {
        $this->agreement($this->volunteer, '1');
        $v2 = $this->policyText('2', 'en', '2026-10-03');
        $this->assertSame('2026-10-03', Policy::nextVersionFrom());
        $this->assertSame(['2026-10-03 04:00:00', 'agreement_new'], $this->cap($this->volunteer), '00:00 New York on the day version 2 takes effect');

        Db::pdo()->prepare('DELETE FROM policy_document WHERE document_id = ?')->execute([$v2]);
        $this->policyText('1', 'es', '2026-10-03');
        $this->assertNull(Policy::nextVersionFrom());
        $this->assertSame(['2026-10-04 12:00:00', 'hours'], $this->cap($this->volunteer), 'a translation of the current version caps nothing');
    }

    public function testANewVersionCapsOnlyTheGrantsIssuedAfterItIsPublished(): void
    {
        // The residual D-19 accepts: agreement_new is computed at issue. Publishing a version re-caps no live grant (the tablets
        // learn only revocations, never a changed expiry), so a grant issued before it stays valid offline to its own expiry.
        $this->agreement($this->volunteer, '1');
        $grant = $this->issue($this->volunteer);
        $this->assertNotNull($grant);
        $this->assertSame(['2026-10-04 12:00:00.000', 'hours'], [$grant['expires_at'], $grant['capped_by']]);
        $before = $this->grantRow($grant['grant_id']);

        PolicyService::create(['doc_type' => Policy::CONFIDENTIALITY, 'version' => '2', 'language_code' => 'en', 'effective_from' => '2026-10-02',
            'body' => 'Version two of the agreement.']);
        $this->assertSame('2026-10-02', Policy::nextVersionFrom());
        $after = $this->grantRow($grant['grant_id']);
        $this->assertSame($before, $after, 'the earlier grant row is untouched');
        $this->assertTrue(OfflineGrants::validAt($after, $this->at('2026-10-03 12:00:00')), 'still valid offline after version 2 takes effect');
        $this->assertSame([], Tokens::revokedGrantIds($this->tablet['id']));
        $this->assertSame(['2026-10-02 04:00:00', 'agreement_new'], $this->cap($this->volunteer), 'a grant issued now is capped by it');
    }

    public function testExpiryIsCappedByAccountExpiryInTheOrganisationZone(): void
    {
        $user = $this->person(['expiry_date' => '2026-10-01']);
        $this->assertSame(['2026-10-02 04:00:00', 'account_expiry'], $this->cap($user), 'usable through today in New York: refused from 00:00 New York tomorrow');
        $this->setSetting('organisation_time_zone', 'Europe/London');
        $this->assertSame(['2026-10-01 23:00:00', 'account_expiry'], $this->cap($user), '00:00 BST is 23:00 UTC');
    }

    public function testExpiryIsCappedByAScheduledDeactivation(): void
    {
        $user = $this->person(['deactivation_effective_date' => '2026-10-03']);
        $this->assertSame(['2026-10-03 04:00:00', 'deactivation'], $this->cap($user));
        $grant = $this->issue($user);
        $this->assertSame(['2026-10-03 04:00:00.000', 'deactivation'], [$grant['expires_at'], $grant['capped_by']]);
    }

    public function testExpiryIsCappedByAgreementDueDate(): void
    {
        $this->agreement($this->volunteer);
        $this->setDue($this->volunteer['user_id'], '2026-10-02');
        $this->assertSame('2026-10-02', Policy::dueAgainOn($this->volunteer));
        $this->assertSame(['2026-10-02 04:00:00', 'agreement_due'], $this->cap($this->volunteer));
        $this->setDue($this->volunteer['user_id'], null); // policy_reack_days 0: never due again
        $this->assertSame(['2026-10-04 12:00:00', 'hours'], $this->cap($this->volunteer));
    }

    public function testExpiryIsCappedByPasswordAge(): void
    {
        $user = $this->person(['password_changed_at' => '2026-09-02 09:00:00']);
        $this->assertSame(['2026-10-04 12:00:00', 'hours'], $this->cap($user), 'password_max_age_days 0: no cap');
        $this->setSetting('password_max_age_days', '30');
        $this->assertSame(['2026-10-02 09:00:00', 'password_age'], $this->cap($user));
    }

    public function testSiteAllRolesHaveNoAccessCap(): void
    {
        $admin = $this->person(['role' => 'Administrator'], []);
        $this->assertNull(SiteAccess::accessEnd($admin, $this->north), 'site.all: no access end');
        $this->assertSame(['2026-10-04 12:00:00', 'hours'], $this->cap($admin));
        $this->assertNull(SiteAccess::accessEnd($this->volunteer, $this->north), 'an open-ended grant has no end either');
    }

    public function testNoGrantWhenTheCapIsNotInTheFuture(): void
    {
        $expired = $this->person(['expiry_date' => '2026-09-30']);
        $this->assertSame(['2026-10-01 04:00:00', 'account_expiry'], $this->cap($expired));
        $this->assertNull($this->issue($expired));

        $this->setSetting('password_max_age_days', '30');
        $exactly = $this->person(['password_changed_at' => '2026-09-01 12:00:00']);
        $this->assertSame(['2026-10-01 12:00:00', 'password_age'], $this->cap($exactly));
        $this->assertNull($this->issue($exactly), 'a cap at now is not in the future');

        $this->assertSame(0, $this->grantCount($expired['user_id']) + $this->grantCount($exactly['user_id']), 'no row');
        $this->assertSame([], $this->auditRows('offline_grant_issue'));
    }

    public function testTheHoursFollowTheTabletsOfflineState(): void
    {
        $this->assertSame(['2026-10-04 12:00:00', 'hours'], $this->cap($this->volunteer, true), 'offline_grant_hours (72)');
        $this->assertSame(['2026-10-02 00:00:00', 'hours'], $this->cap($this->volunteer, false), 'online only: session_absolute_hours (12)');
        $this->setSetting('offline_grant_hours', '24');
        $this->setSetting('session_absolute_hours', '8');
        $this->assertSame(['2026-10-02 12:00:00', 'hours'], $this->cap($this->volunteer, true));
        $this->assertSame(['2026-10-01 20:00:00', 'hours'], $this->cap($this->volunteer, false));
        $this->setSetting('offline_grant_hours', '0');
        $this->assertSame(['2026-10-01 13:00:00', 'hours'], $this->cap($this->volunteer, true), 'at least an hour');

        $grant = $this->issue($this->volunteer, null, false);
        $this->assertSame('2026-10-01 20:00:00.000', $grant['expires_at']);
        $this->assertFalse($this->auditRows('offline_grant_issue')[0]['details']['offline_allowed']);
    }

    public function testTiesKeepTheFirstRule(): void
    {
        $user = $this->person([], []);
        $this->grantSite($user['user_id'], $this->north, '2026-01-01 00:00:00', '2026-10-04 12:00:00');
        $this->assertSame(['2026-10-04 12:00:00', 'hours'], $this->cap($user), 'hours before site_access');

        $user = $this->person(['expiry_date' => '2026-10-01'], []);
        $this->grantSite($user['user_id'], $this->north, '2026-01-01 00:00:00', '2026-10-02 04:00:00');
        $this->assertSame(['2026-10-02 04:00:00', 'site_access'], $this->cap($user), 'site_access before account_expiry');

        $admin = $this->person(['role' => 'Administrator', 'expiry_date' => '2026-10-02', 'deactivation_effective_date' => '2026-10-03'], []);
        $this->assertSame(['2026-10-03 04:00:00', 'account_expiry'], $this->cap($admin), 'account_expiry before deactivation');

        $admin = $this->person(['role' => 'Administrator', 'deactivation_effective_date' => '2026-10-03'], []);
        $this->agreement($admin);
        $this->setDue($admin['user_id'], '2026-10-03');
        $this->assertSame(['2026-10-03 04:00:00', 'deactivation'], $this->cap($admin), 'deactivation before agreement_due');

        $admin = $this->person(['role' => 'Administrator', 'password_changed_at' => '2026-09-03 04:00:00'], []);
        $this->agreement($admin);
        $this->setDue($admin['user_id'], '2026-10-03');
        $this->policyText('2', 'en', '2026-10-03');
        $this->assertSame(['2026-10-03 04:00:00', 'agreement_due'], $this->cap($admin), 'agreement_due before agreement_new');

        $this->setDue($admin['user_id'], null);
        $this->setSetting('password_max_age_days', '30');
        $this->assertSame(['2026-10-03 04:00:00', 'agreement_new'], $this->cap($admin), 'agreement_new before password_age');
        $this->setSetting('password_max_age_days', '0');
        $this->assertSame(['2026-10-03 04:00:00', 'agreement_new'], $this->cap($admin));
    }

    // Validity, find, revocation ---------------------------------------------------------------------

    public function testValidAtBoundaries(): void
    {
        $grant = ['created_at' => '2026-10-01 12:00:00', 'expires_at' => '2026-10-01 13:00:00', 'revoked_at' => null];
        $this->assertTrue(OfflineGrants::validAt($grant, $this->at('2026-10-01 12:00:00')), 'created_at itself');
        $this->assertFalse(OfflineGrants::validAt($grant, $this->at('2026-10-01 11:59:59')), 'a second before');
        $this->assertTrue(OfflineGrants::validAt($grant, $this->at('2026-10-01 12:59:59.999')));
        $this->assertFalse(OfflineGrants::validAt($grant, $this->at('2026-10-01 13:00:00')), 'expires_at itself');

        $revoked = ['revoked_at' => '2026-10-01 12:30:00'] + $grant;
        $this->assertTrue(OfflineGrants::validAt($revoked, $this->at('2026-10-01 12:30:00')), 'revoked_at itself');
        $this->assertFalse(OfflineGrants::validAt($revoked, $this->at('2026-10-01 12:30:00.001')), 'a millisecond after');
        $this->assertTrue(OfflineGrants::validAt($revoked, $this->at('2026-10-01 12:10:00')));

        $this->assertFalse(OfflineGrants::validAt(['created_at' => null] + $grant, $this->at('2026-10-01 12:10:00')), 'a row made before 0013 never is');
        $this->assertFalse(OfflineGrants::validAt(['expires_at' => null] + $grant, $this->at('2026-10-01 12:10:00')));
    }

    public function testFindReturnsTheGrantWhateverItsState(): void
    {
        $live = $this->issue($this->volunteer);
        $revoked = $this->issue($this->volunteer);
        $this->revoke($revoked['grant_id'], '2026-10-01 12:00:00');
        $expired = $this->issue($this->volunteer, null, true, Clock::now()->modify('-80 hours'));
        $shredded = $this->issue($this->volunteer);
        Db::pdo()->prepare('UPDATE auth_token SET secret_ciphertext = NULL WHERE token_id = ?')->execute([$shredded['grant_id']]);

        foreach ([$live, $revoked, $expired, $shredded] as $grant) {
            $row = OfflineGrants::find($grant['grant_id']);
            $this->assertNotNull($row);
            $this->assertSame(['token_id', 'user_id', 'device_id', 'token_hash', 'secret_ciphertext', 'created_at', 'expires_at', 'revoked_at'], array_keys($row));
            $this->assertSame($grant['grant_id'], (int) $row['token_id']);
        }
        $this->assertSame('2026-10-01 12:00:00', OfflineGrants::find($revoked['grant_id'])['revoked_at']);
        $this->assertSame('2026-10-01 04:00:00', OfflineGrants::find($expired['grant_id'])['expires_at']);
        $this->assertNull(OfflineGrants::secret(OfflineGrants::find($shredded['grant_id'])));
        $this->assertSame($expired['secret'], OfflineGrants::secret(OfflineGrants::find($expired['grant_id'])), 'S5 can still check an expired grant');

        Tokens::issue($this->volunteer['user_id'], Tokens::PASSWORD_RESET, 60);
        $this->assertNull(OfflineGrants::find((int) Db::pdo()->lastInsertId()), 'another purpose');
        $this->assertNull(OfflineGrants::find(2147483647));
    }

    public function testUsedAtIsNeverSet(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $a = $this->issue($this->volunteer);
        $this->issue($this->volunteer, $this->other);
        OfflineGrants::find($a['grant_id']);
        OfflineGrants::secret(OfflineGrants::find($a['grant_id']));
        OfflineGrants::validAt(OfflineGrants::find($a['grant_id']), Clock::now());
        OfflineGrants::pinWindow($uid, $this->tablet['id'], 12);
        OfflineGrants::issuedSince($uid, $this->tablet['id'], Clock::db());
        OfflineGrants::revokeForUser($uid, 'access', $this->tablet['id']);
        OfflineGrants::revokeForUser($uid, 'deactivate');
        $this->assertSame([null, null], array_column($this->grantsOf($uid), 'used_at'));
    }

    public function testIssueAndRevocationAreAuditedWithoutSecrets(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $a = $this->issue($this->volunteer);
        $b = $this->issue($this->volunteer, $this->other, false);

        $rows = $this->auditRows('offline_grant_issue');
        $this->assertCount(2, $rows);
        $this->assertSame(['user_account', $uid, 'Success', null, $uid, null],
            [$rows[0]['entity_type'], (int) $rows[0]['entity_id'], $rows[0]['outcome'], $rows[0]['reason'], (int) $rows[0]['user_id'], $rows[0]['session_id']]);
        $this->assertSame(['capped_by', 'expires_at', 'grant_id', 'offline_allowed'], array_keys($rows[0]['details']));
        $this->assertSame(['capped_by' => 'hours', 'expires_at' => '2026-10-04 12:00:00', 'grant_id' => $a['grant_id'], 'offline_allowed' => true], $rows[0]['details']);
        $this->assertSame(['capped_by' => 'hours', 'expires_at' => '2026-10-02 00:00:00', 'grant_id' => $b['grant_id'], 'offline_allowed' => false], $rows[1]['details']);

        $this->assertSame(0, OfflineGrants::revokeForUser($this->person()['user_id'], 'access'), 'nothing to revoke');
        $this->assertSame([], $this->auditRows('offline_grant_revoke'), 'no row at count 0');
        $this->assertSame(2, OfflineGrants::revokeForUser($uid, 'access'));
        $this->assertSame(0, OfflineGrants::revokeForUser($uid, 'access'), 'already revoked');
        $revokes = $this->auditRows('offline_grant_revoke');
        $this->assertCount(1, $revokes);
        $this->assertSame(['user_account', $uid, 'Success'], [$revokes[0]['entity_type'], (int) $revokes[0]['entity_id'], $revokes[0]['outcome']]);
        $this->assertSame(['cause' => 'access', 'count' => 2], $revokes[0]['details']);

        $st = Db::pdo()->prepare("SELECT CONCAT_WS('|', action, reason, details, snapshot) FROM audit_log WHERE audit_id > ?");
        $st->execute([$this->stationAuditMark]);
        $haystack = implode("\n", $st->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertFalse(str_contains($haystack, '[redacted]'), 'no detail key is swallowed by the redaction');
        foreach ([$a, $b] as $grant) {
            $row = $this->grantRow($grant['grant_id']);
            foreach ([Crypto::b64url($grant['secret']), bin2hex($grant['secret']), (string) $row['token_hash'], (string) $row['secret_ciphertext']] as $secret) {
                $this->assertFalse(str_contains($haystack, $secret), 'no secret, token hash or ciphertext in the audit trail');
            }
        }
    }

    public function testRevokeForUserCanSpareOneTablet(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $here = $this->issue($this->volunteer);
        $there = $this->issue($this->volunteer, $this->other);
        $old = $this->issue($this->volunteer, $this->other, true, Clock::now()->modify('-80 hours')); // expired
        $someoneElse = $this->issue($this->person(), $this->other);

        Clock::advance('+1 minute');
        $this->assertSame(1, OfflineGrants::revokeForUser($uid, 'pin_change', $this->tablet['id']));
        $this->assertNull($this->grantRow($here['grant_id'])['revoked_at'], 'the tablet in hand is spared');
        $this->assertSame('2026-10-01 12:01:00', $this->grantRow($there['grant_id'])['revoked_at']);
        $this->assertNull($this->grantRow($old['grant_id'])['revoked_at'], 'an expired grant is left as it is');
        $this->assertNull($this->grantRow($someoneElse['grant_id'])['revoked_at']);
        $this->assertSame([$there['grant_id']], Tokens::revokedGrantIds($this->other['id']));
        $this->assertSame([], Tokens::revokedGrantIds($this->tablet['id']));

        $this->assertSame(1, OfflineGrants::revokeForUser($uid, 'password'), 'every tablet');
        $this->assertNotNull($this->grantRow($here['grant_id'])['revoked_at']);
        $this->assertSame(['cause' => 'pin_change', 'count' => 1], $this->auditRows('offline_grant_revoke')[0]['details']);
    }

    // The online PIN window (D-24) ---------------------------------------------------------------------

    public function testThePinWindowNeedsAGrantNewerThanEndShift(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $first = $this->issue($this->volunteer);
        $this->assertSame($first['grant_id'], OfflineGrants::pinWindow($uid, $this->tablet['id'], 12)['token_id'] ?? null, 'never ended: open');
        DeviceRepository::setShiftEnded($this->tablet['id'], Clock::db());
        $this->assertNull(OfflineGrants::pinWindow($uid, $this->tablet['id'], 12), 'a grant in the same second as End shift is not after it');

        Clock::advance('+1 second');
        $second = $this->issue($this->volunteer);
        $this->assertSame(['token_id' => $second['grant_id'], 'created_at' => '2026-10-01 12:00:01', 'expires_at' => '2026-10-04 12:00:01'],
            OfflineGrants::pinWindow($uid, $this->tablet['id'], 12));

        Clock::advance('+5 seconds');
        DeviceRepository::setShiftEnded($this->tablet['id'], Clock::db());
        $this->assertNull(OfflineGrants::pinWindow($uid, $this->tablet['id'], 12));
        $this->issue($this->volunteer, $this->other);
        $this->assertNotNull(OfflineGrants::pinWindow($uid, $this->other['id'], 12), "another tablet's End shift does not close this one");
    }

    public function testThePinWindowIsTheNewestLiveGrantWithinPinShiftHours(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $now = Clock::now();
        $h13 = $this->issue($this->volunteer, null, true, $now->modify('-13 hours'));
        $h11 = $this->issue($this->volunteer, null, true, $now->modify('-11 hours'));
        $h1 = $this->issue($this->volunteer, null, true, $now->modify('-1 hour'));
        $this->issue($this->volunteer, null, false, $now->modify('-12 hours')); // online only: expired at 12:00 exactly
        $this->issue($this->person(), null, true, $now->modify('-10 minutes')); // someone else
        $this->issue($this->volunteer, $this->other, true, $now->modify('-5 minutes')); // another tablet

        $this->assertSame($h1['grant_id'], OfflineGrants::pinWindow($uid, $this->tablet['id'], 12)['token_id'], 'the newest');
        $this->revoke($h1['grant_id'], Clock::db());
        $this->assertSame($h11['grant_id'], OfflineGrants::pinWindow($uid, $this->tablet['id'], 12)['token_id'], 'a revoked grant does not count');
        $this->revoke($h11['grant_id'], Clock::db());
        $this->assertNull(OfflineGrants::pinWindow($uid, $this->tablet['id'], 12), '13 hours ago is outside pin_shift_hours');
        $this->assertSame($h13['grant_id'], OfflineGrants::pinWindow($uid, $this->tablet['id'], 14)['token_id']);
        $this->assertSame($h13['grant_id'], OfflineGrants::pinWindow($uid, $this->tablet['id'], 24)['token_id'], 'the expired online-only grant never counts');
    }
}
