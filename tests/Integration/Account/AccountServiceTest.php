<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Account;

use Pfpms\Account\AccountRepository;
use Pfpms\Account\AccountService;
use Pfpms\Account\AccountStatus;
use Pfpms\Account\StaleAccountException;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Auth;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Cron\Jobs\DeactivateDueAccounts;
use Pfpms\Db;
use Pfpms\Mail\Mailer;
use Pfpms\Station\OfflineGrants;
use Pfpms\Tests\Support\QueryLog;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Manage User Accounts (UC-11): invitations, least privilege, last Administrator, self-edit, activation, deactivation. */
final class AccountServiceTest extends TestCase
{
    private const GOOD_PASSWORD = 'Lantern-Orchard-Bicycle-42';
    private const OLD_PASSWORD = 'Correct-Horse-Battery-9'; // makeUser's default
    /** Who makes the changes; a Coordinator so it never counts as an Administrator (the page checks the capability). */
    private int $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Mailer::$sent = [];
        // Only the Administrators a test creates count towards "the last Administrator".
        Db::pdo()->exec("UPDATE user_account SET status = 'Inactive' WHERE role = 'Administrator'");
        $this->actor = $this->makeUser(['role' => 'Coordinator'])['user_id'];
    }

    private function invite(array $overrides = [], array $sites = []): int
    {
        static $n = 0;
        $n++;
        return AccountService::invite($overrides + [
            'username' => "invitee$n." . bin2hex(random_bytes(2)), 'first_name' => 'Rosa', 'last_name' => "Invitee$n",
            'email' => "invitee$n." . bin2hex(random_bytes(2)) . '@example.test', 'role' => 'Volunteer',
        ], $sites, $this->actor)['user_id'];
    }

    /** The raw token from the most recent email (the only place it exists outside the person's hands). */
    private function lastMailedToken(): string
    {
        $this->assertNotEmpty(Mailer::$sent, 'an email was sent');
        $this->assertSame(1, preg_match('/activate\.php\?token=([A-Za-z0-9_%-]+)/', end(Mailer::$sent)['body'], $m), 'the email holds an activation link');
        return rawurldecode($m[1]);
    }

    private function details(array $account, array $changes = []): array
    {
        return $changes + array_intersect_key($account, array_flip(AccountService::DETAIL_FIELDS));
    }

    /** @return array<string, array{0:mixed,1:mixed}> the changes made */
    private function update(int $userId, array $changes, ?array $sites = null, ?int $actorId = null): array
    {
        $account = AccountRepository::find($userId);
        return AccountService::update($userId, $this->details($account, $changes), $sites ?? AccountRepository::standingSiteIds($userId),
            (int) $account['row_version'], $actorId ?? $this->actor)['changes'];
    }

    private function errorsOf(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        $this->fail('expected a ValidationException');
    }

    private function durableCount(string $action, int $entityId): int
    {
        $st = Db::durable()->prepare("SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ? AND outcome = 'Denied'");
        $st->execute([$action, $entityId]);
        return (int) $st->fetchColumn();
    }

    private function valid(string $token): bool
    {
        return Tokens::find($token, Tokens::TEMPORARY_CREDENTIAL) !== null;
    }

    /** A live offline grant for the person on a new tablet at $siteId, issued as a Station sign-in issues it (S3 spec §2.4). */
    private function offlineGrant(int $userId, int $siteId): int
    {
        $device = $this->makeDevice($siteId, ['is_site_registered' => 1]);
        $grant = OfflineGrants::issue(AccountRepository::find($userId), ['device_id' => $device, 'site_id' => $siteId], true);
        $this->assertNotNull($grant, 'a grant was issued');
        return $grant['grant_id'];
    }

    /**
     * A Volunteer with a standing grant to $siteId and a live offline grant there.
     * @return array{0: int, 1: int} [user id, grant id]
     */
    private function personWithGrant(int $siteId, array $overrides = []): array
    {
        $id = $this->makeUser($overrides)['user_id'];
        AccountRepository::grantSite($id, $siteId, $this->actor, 'Test');
        return [$id, $this->offlineGrant($id, $siteId)];
    }

    private function revokedAt(int $grantId): ?string
    {
        $at = $this->scalar("SELECT revoked_at FROM auth_token WHERE token_id = ? AND purpose = 'Offline Grant'", [$grantId]);
        return $at === false || $at === null ? null : (string) $at;
    }

    /** @return list<array<string, mixed>> the offline_grant_revoke details for the person, oldest first, keys sorted */
    private function revocations(int $userId): array
    {
        $st = Db::pdo()->prepare("SELECT details FROM audit_log WHERE action = 'offline_grant_revoke' AND entity_type = 'user_account' AND entity_id = ? ORDER BY audit_id");
        $st->execute([$userId]);
        return array_map(static function (mixed $json): array {
            $details = (array) json_decode((string) $json, true);
            ksort($details);
            return $details;
        }, $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * In one call's statements, the person's grants are revoked before any session is ended (lock order auth_token → user_session).
     * @param list<string> $log
     */
    private function assertGrantsRevokedBeforeSessionsEnd(array $log, string $path): void
    {
        $grants = QueryLog::last($log, "/^UPDATE auth_token SET revoked_at = \\? WHERE purpose = 'Offline Grant' AND user_id = \\?/");
        $sessions = QueryLog::first($log, '/^UPDATE user_session SET ended_at = \?/');
        $this->assertNotNull($grants, "$path revokes the grants");
        $this->assertNotNull($sessions, "$path ends the sessions");
        $this->assertLessThan($sessions, $grants, "$path: grants before sessions");
    }

    public function testInviteCreatesPendingAccountWithGrantsAndMailsALink(): void
    {
        $site = $this->makeSite('Northside');
        $id = $this->invite(['username' => '  Rosa.Diaz ', 'email' => 'Rosa@Example.test', 'phone' => '(843) 555-0101',
            'onboarding_completed_at' => '2026-09-30'], [$site]);
        $account = AccountRepository::find($id);
        $this->assertSame('rosa.diaz', $account['username']);
        $this->assertSame('Pending', $account['status']);
        $this->assertSame(0, (int) $account['must_change_password']);
        $this->assertSame('2026-10-01', $account['start_date'], 'start date defaults to today');
        $this->assertSame('2026-09-30 00:00:00', $account['onboarding_completed_at']);
        $this->assertSame([$site], AccountRepository::standingSiteIds($id));
        $this->assertCount(1, Mailer::$sent);
        $this->assertStringContainsString('rosa.diaz', Mailer::$sent[0]['body']);
        $this->assertTrue($this->valid($this->lastMailedToken()));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'user_create' AND entity_id = ?", [$id]));
        $hash = (string) $this->scalar('SELECT password_hash FROM user_account WHERE user_id = ?', [$id]);
        $this->assertFalse(PasswordPolicy::needsRehash($hash), 'the placeholder is a real hash, so sign-in attempts take as long as any other');
    }

    public function testValidationReportsEveryProblemAtOnce(): void
    {
        $errors = $this->errorsOf(fn() => $this->invite(['username' => 'x', 'email' => 'not-an-email', 'role' => 'Coordinator', 'phone' => '12']));
        $this->assertEqualsCanonicalizing(['username', 'email', 'phone', 'sites'], array_keys($errors));
    }

    public function testUsernameAndEmailMustBeUnused(): void
    {
        $existing = $this->makeUser(['username' => 'taken.name', 'email' => 'taken@example.test']);
        $this->assertArrayHasKey('username', $this->errorsOf(fn() => $this->invite(['username' => 'Taken.Name '])));
        $errors = $this->errorsOf(fn() => $this->invite(['email' => ' TAKEN@example.test']));
        $this->assertArrayHasKey('email', $errors);
        $this->assertSame((string) $existing['user_id'], $errors['existing_user_id'], 'the form can link to the existing account');
        $this->assertArrayHasKey('username', $this->errorsOf(fn() => $this->invite(['username' => 'no spaces allowed'])));
    }

    public function testSitesFollowLeastPrivilege(): void
    {
        $this->setSetting('volunteer_max_sites', '1');
        $a = $this->makeSite('Site A');
        $b = $this->makeSite('Site B');
        $this->assertArrayHasKey('sites', $this->errorsOf(fn() => $this->invite(['role' => 'Volunteer'], [$a, $b])), 'volunteer site limit');
        $this->assertArrayHasKey('sites', $this->errorsOf(fn() => $this->invite(['role' => 'Coordinator'])), 'a Coordinator needs a site');
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$b]);
        $this->assertArrayHasKey('sites', $this->errorsOf(fn() => $this->invite(['role' => 'Coordinator'], [$b])), 'inactive site');

        $coordinator = $this->invite(['role' => 'Coordinator'], [$a]);
        $this->assertSame([$a], AccountRepository::standingSiteIds($coordinator));
        $admin = $this->invite(['role' => 'Administrator'], [$a]);
        $this->assertSame([], AccountRepository::standingSiteIds($admin), 'Administrators see every site through their role');
        $board = $this->invite(['role' => 'Board']);
        $this->assertSame([], AccountRepository::standingSiteIds($board));
    }

    public function testAClosedSiteStaysUntilItIsTakenAway(): void
    {
        $a = $this->makeSite('Site A');
        $closed = $this->makeSite('Site B');
        $id = $this->makeUser(['role' => 'Coordinator'])['user_id'];
        AccountRepository::grantSite($id, $closed, $this->actor, 'Test');
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$closed]);
        $sid = SessionStore::create($id, $closed);

        $changes = $this->update($id, ['phone' => '8435550105'], [$closed]);
        $this->assertSame(['phone'], array_keys($changes), 'an unrelated edit is not a site change and needs no reason');
        $this->assertArrayHasKey('user', SessionStore::validate($sid));
        $this->assertSame([$closed], AccountRepository::standingSiteIds($id));
        $this->assertArrayHasKey('sites', $this->errorsOf(fn() => $this->update($this->makeUser(['role' => 'Coordinator'])['user_id'], ['reason' => 'x'], [$closed])),
            'a closed site cannot be newly granted');
        $this->update($id, ['reason' => 'Site closed'], [$a]);
        $this->assertSame([$a], AccountRepository::standingSiteIds($id));
    }

    public function testIdentifiableExportIsAdministratorOnly(): void
    {
        $site = $this->makeSite('Site A');
        $errors = $this->errorsOf(fn() => $this->invite(['role' => 'Coordinator', 'can_extract_identifiable' => '1'], [$site]));
        $this->assertArrayHasKey('can_extract_identifiable', $errors);
        $admin = $this->invite(['role' => 'Administrator', 'can_extract_identifiable' => '1']);
        $this->assertSame(1, (int) AccountRepository::find($admin)['can_extract_identifiable']);
    }

    public function testLastLastingAdministratorCannotBeRemovedNowOrLater(): void
    {
        $a = $this->makeUser(['role' => 'Administrator'])['user_id'];
        $b = $this->makeUser(['role' => 'Administrator'])['user_id'];
        // B may leave on a date because A stays...
        $this->update($b, ['expiry_date' => '2026-12-31', 'reason' => 'Term ends'], [], $a);
        // ...but then A is the only lasting Administrator, so B cannot demote, expire or deactivate A.
        $denied = $this->durableCount('user_update', $a);
        $this->assertArrayHasKey('role', $this->errorsOf(fn() => $this->update($a, ['role' => 'Coordinator', 'reason' => 'x'], [$this->makeSite('S')], $b)));
        $this->assertSame($denied + 1, $this->durableCount('user_update', $a), 'the refusal is audited');
        $this->assertArrayHasKey('expiry_date', $this->errorsOf(fn() => $this->update($a, ['expiry_date' => '2027-01-31', 'reason' => 'x'], [], $b)));
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AccountService::deactivate($a, 'Leaving', '2026-10-08', $b)));
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AccountService::deactivate($a, 'Leaving', null, $b)));
        // An Administrator who only starts next week does not count yet.
        $future = $this->makeUser(['role' => 'Administrator', 'start_date' => '2026-10-08'])['user_id'];
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AccountService::deactivate($a, 'Leaving', null, $b)));
        // Once someone else is a lasting Administrator, A can go.
        $this->update($future, ['start_date' => '2026-10-01', 'reason' => 'Starts now'], [], $a);
        $this->assertTrue(AccountService::deactivate($a, 'Leaving', null, $future));
        $this->assertSame('Inactive', AccountRepository::find($a)['status']);
    }

    public function testNobodyChangesTheirOwnAccessOrStatus(): void
    {
        $site = $this->makeSite('S');
        $self = $this->makeUser(['role' => 'Administrator'])['user_id'];
        $other = $this->makeUser(['role' => 'Administrator'])['user_id'];
        $before = $this->durableCount('user_update', $self);
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => $this->update($self, ['role' => 'Coordinator', 'reason' => 'x'], [$site], $self)));
        $this->assertSame($before + 1, $this->durableCount('user_update', $self), 'the refusal is audited even though nothing was saved');
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => $this->update($self, ['email' => 'new.self@example.test', 'reason' => 'x'], [], $self)));
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => $this->update($self, ['can_extract_identifiable' => 1, 'reason' => 'x'], [], $self)));

        // Another Administrator schedules this one's departure; they cannot cancel it themselves.
        AccountService::deactivate($self, 'Leaving', '2026-10-15', $other);
        $before = $this->durableCount('user_reactivate', $self);
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AccountService::reactivate($self, 'Staying', $self)));
        $this->assertSame($before + 1, $this->durableCount('user_reactivate', $self));
        $this->assertSame('2026-10-15', AccountRepository::find($self)['deactivation_effective_date']);
        foreach ([fn() => AccountService::deactivate($self, 'x', null, $self), fn() => AccountService::sendReset($self, $self),
                  fn() => AccountService::unlock($self, $self), fn() => AccountService::issueActivationSheet($self, $self)] as $selfAction) {
            $this->assertArrayHasKey('_form', $this->errorsOf($selfAction));
        }
        // Own name and phone are fine.
        $this->assertArrayHasKey('phone', $this->update($self, ['phone' => '8435550102'], [], $self));
    }

    public function testAccessChangesNeedAReasonAndEndSessions(): void
    {
        $a = $this->makeSite('Site A');
        $b = $this->makeSite('Site B');
        $id = $this->invite([], [$a]);
        Db::pdo()->prepare("UPDATE user_account SET status = 'Active' WHERE user_id = ?")->execute([$id]);
        $sid = SessionStore::create($id, $a);

        $this->update($id, ['phone' => '8435550103']);
        $this->assertArrayHasKey('user', SessionStore::validate($sid), 'a phone change leaves the person signed in');

        $this->assertArrayHasKey('reason', $this->errorsOf(fn() => $this->update($id, [], [$b])));
        $changes = $this->update($id, ['reason' => 'Moved to Site B'], [$b]);
        $this->assertArrayHasKey('site_ids', $changes);
        $this->assertSame('Permission Change', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$sid]));
        $this->assertSame([$b], AccountRepository::standingSiteIds($id));
        $this->assertNotNull($this->scalar('SELECT ends_at FROM user_site_access WHERE user_id = ? AND site_id = ?', [$id, $a]),
            'the old grant is ended, not deleted');
    }

    public function testAnAccessChangeCancelsThePersonsRegistrationCodes(): void
    {
        $a = $this->makeSite('Site A');
        $b = $this->makeSite('Site B');
        $id = $this->invite(['role' => 'Coordinator'], [$a]);
        Db::pdo()->prepare("UPDATE user_account SET status = 'Active' WHERE user_id = ?")->execute([$id]);
        $code = Tokens::issue($id, Tokens::DEVICE_REGISTRATION, 60, $this->makeDevice($a));
        $this->update($id, ['phone' => '8435550104']);
        $this->assertNotNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION), 'a phone change leaves it working');
        $this->update($id, ['reason' => 'Moved to Site B'], [$b]);
        $this->assertNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION), 'a tablet code they created stops working');
    }

    public function testDeactivationCancelsThePersonsRegistrationCodes(): void
    {
        $site = $this->makeSite('Site A');
        $now = $this->makeUser(['role' => 'Coordinator']);
        $code = Tokens::issue($now['user_id'], Tokens::DEVICE_REGISTRATION, 600, $this->makeDevice($site));
        AccountService::deactivate($now['user_id'], 'Left', null, $this->actor);
        $this->assertNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION));

        $later = $this->makeUser(['role' => 'Coordinator']);
        $code = Tokens::issue($later['user_id'], Tokens::DEVICE_REGISTRATION, 7 * 24 * 60, $this->makeDevice($site));
        AccountService::deactivate($later['user_id'], 'Leaving', '2026-10-03', $this->actor);
        $this->assertNotNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION), 'until the date');
        Clock::advance('+2 days');
        (new DeactivateDueAccounts())->run();
        $this->assertNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION), 'and through the scheduled job');
    }

    public function testAResetOrAPasswordChangeCancelsThePersonsRegistrationCodes(): void
    {
        $site = $this->makeSite('Site A');
        $reset = $this->makeUser(['role' => 'Coordinator']);
        $code = Tokens::issue($reset['user_id'], Tokens::DEVICE_REGISTRATION, 600, $this->makeDevice($site));
        AccountService::sendReset($reset['user_id'], $this->actor); // the account may be in the wrong hands
        $this->assertNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION), 'after an Administrator reset');

        $sheet = $this->makeUser(['role' => 'Coordinator']);
        $code = Tokens::issue($sheet['user_id'], Tokens::DEVICE_REGISTRATION, 600, $this->makeDevice($site));
        AccountService::issueActivationSheet($sheet['user_id'], $this->actor);
        $this->assertNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION), 'after a printed reset sheet');

        $own = $this->makeUser(['role' => 'Coordinator']);
        $code = Tokens::issue($own['user_id'], Tokens::DEVICE_REGISTRATION, 600, $this->makeDevice($site));
        Auth::setPassword($own['user_id'], self::GOOD_PASSWORD, 'Password Reset');
        $this->assertNull(Tokens::find($code, Tokens::DEVICE_REGISTRATION), 'after changing their own password');
    }

    public function testCorrectingTheEmailCancelsLinksSentToTheOldAddress(): void
    {
        $invited = $this->invite(['role' => 'Board', 'email' => 'typo@exmaple.test']);
        $oldLink = $this->lastMailedToken();
        $result = AccountService::update($invited, $this->details(AccountRepository::find($invited), ['email' => 'right@example.test', 'reason' => 'Typo']), [],
            (int) AccountRepository::find($invited)['row_version'], $this->actor);
        $this->assertFalse($this->valid($oldLink), 'the link sent to the wrong address no longer works');
        $this->assertTrue($result['mailed']);
        $this->assertSame('right@example.test', end(Mailer::$sent)['to'], 'a Pending account is invited again at the new address');
        $this->assertTrue($this->valid($this->lastMailedToken()));

        $active = $this->makeUser(['role' => 'Board'])['user_id'];
        AccountService::sendReset($active, $this->actor);
        $resetLink = $this->lastMailedToken();
        $sent = count(Mailer::$sent);
        $this->update($active, ['email' => 'moved@example.test', 'reason' => 'New address']);
        $this->assertFalse($this->valid($resetLink));
        $this->assertCount($sent, Mailer::$sent, 'an activated account is not re-invited');
    }

    public function testAStaleEditIsRefused(): void
    {
        $id = $this->makeUser()['user_id'];
        $site = $this->makeSite('S');
        AccountRepository::grantSite($id, $site, $this->actor, 'Test');
        $account = AccountRepository::find($id);
        $this->update($id, ['phone' => '8435550104']); // someone else saves first
        $this->expectException(StaleAccountException::class);
        AccountService::update($id, $this->details($account, ['last_name' => 'Changed']), [$site], (int) $account['row_version'], $this->actor);
    }

    public function testActivationSetsThePasswordOnceOnly(): void
    {
        $id = $this->invite(['role' => 'Board']);
        $token = $this->lastMailedToken();
        $this->assertArrayHasKey('new_password', $this->errorsOf(fn() => AccountService::activate($token, self::GOOD_PASSWORD, 'different')));
        $this->assertArrayHasKey('new_password', $this->errorsOf(fn() => AccountService::activate($token, 'short', 'short')));

        $this->assertSame($id, AccountService::activate($token, self::GOOD_PASSWORD, self::GOOD_PASSWORD));
        $account = AccountRepository::find($id);
        $this->assertSame('Active', $account['status']);
        $this->assertSame(0, (int) $account['must_change_password']);
        $this->assertTrue(Auth::verifyPassword($id, self::GOOD_PASSWORD));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'account_activate' AND entity_id = ? AND user_id = ? AND session_id IS NULL", [$id, $id]),
            'recorded as the link holder, not whoever is signed in on the browser');
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AccountService::activate($token, 'Another-Good-Passphrase-7', 'Another-Good-Passphrase-7')));
    }

    public function testActivationLinksExpire(): void
    {
        $this->setSetting('temp_credential_hours', '72');
        $this->invite(['role' => 'Board']);
        $token = $this->lastMailedToken();
        Clock::advance('+71 hours');
        $this->assertTrue($this->valid($token));
        Clock::advance('+2 hours');
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AccountService::activate($token, self::GOOD_PASSWORD, self::GOOD_PASSWORD)));
    }

    public function testEachNewLinkCancelsTheEarlierOnes(): void
    {
        $id = $this->invite(['role' => 'Board']);
        $first = $this->lastMailedToken();
        AccountService::resendInvitation($id, $this->actor);
        $second = $this->lastMailedToken();
        $this->assertFalse($this->valid($first));
        $sheet = AccountService::issueActivationSheet($id, $this->actor);
        $this->assertFalse($this->valid($second));
        $this->assertSame($id, AccountService::activate($sheet, self::GOOD_PASSWORD, self::GOOD_PASSWORD));
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AccountService::resendInvitation($id, $this->actor)), 'no invitation once activated');
    }

    public function testChangingThePasswordAnyOtherWayCancelsOutstandingLinks(): void
    {
        $id = $this->makeUser()['user_id'];
        AccountService::sendReset($id, $this->actor);
        $link = $this->lastMailedToken();
        Auth::setPassword($id, self::GOOD_PASSWORD, 'Password Change'); // e.g. forgot_password / reset_password
        $this->assertFalse($this->valid($link));
    }

    public function testResetStopsTheOldPasswordUnlocksAndEndsSessions(): void
    {
        $id = $this->makeUser(['failed_login_count' => 5, 'locked_until' => '2026-10-01 12:30:00'])['user_id'];
        $sid = SessionStore::create($id, null);
        $this->assertSame('locked', AccountRules::blockReason(AccountRepository::find($id)));

        $this->assertTrue(AccountService::sendReset($id, $this->actor));
        $account = AccountRepository::find($id);
        $this->assertNull(AccountRules::blockReason($account));
        $this->assertSame(0, (int) $account['failed_login_count']);
        $this->assertFalse(Auth::verifyPassword($id, self::OLD_PASSWORD), 'a stolen password stops working at once');
        $this->assertSame('Password Reset', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$sid]));

        AccountService::activate($this->lastMailedToken(), self::GOOD_PASSWORD, self::GOOD_PASSWORD);
        $this->assertSame(0, (int) AccountRepository::find($id)['must_change_password']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'password_reset' AND entity_id = ?", [$id]));
        $invited = $this->invite(['role' => 'Board']);
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AccountService::sendReset($invited, $this->actor)), 'Pending accounts get a new invitation instead');
    }

    public function testAPrintedSheetForAnActivatedAccountIsAResetThePersonIsToldAbout(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        $sheet = AccountService::issueActivationSheet($user['user_id'], $this->actor);
        $this->assertFalse(Auth::verifyPassword($user['user_id'], self::OLD_PASSWORD));
        $this->assertSame('Password Reset', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$sid]));
        $this->assertCount(1, Mailer::$sent);
        $this->assertSame($user['email'], Mailer::$sent[0]['to'], 'the holder is told');
        $this->assertStringNotContainsString('token=', Mailer::$sent[0]['body'], 'the link itself is only on paper');
        $this->assertSame($user['user_id'], AccountService::activate($sheet, self::GOOD_PASSWORD, self::GOOD_PASSWORD));
    }

    public function testUnlockKeepsThePasswordAndTellsThePerson(): void
    {
        $user = $this->makeUser(['failed_login_count' => 5, 'locked_until' => '2026-10-01 12:30:00']);
        $this->assertTrue(AccountService::unlock($user['user_id'], $this->actor));
        $this->assertNull(AccountRules::blockReason(AccountRepository::find($user['user_id'])));
        $this->assertTrue(Auth::verifyPassword($user['user_id'], self::OLD_PASSWORD));
        $this->assertSame($user['email'], Mailer::$sent[0]['to']);
    }

    public function testAFailedEmailIsReportedAndQueued(): void
    {
        $saved = Config::snapshot();
        try {
            Config::override(['mail' => ['transport' => 'smtp', 'from_email' => 'x@example.test', 'smtp' => ['host' => '127.0.0.1', 'port' => 1, 'secure' => '']]] + $saved);
            $result = AccountService::invite(['username' => 'mail.fails', 'first_name' => 'M', 'last_name' => 'F', 'email' => 'mf@example.test', 'role' => 'Board'], [], $this->actor);
        } finally {
            Config::override($saved);
        }
        $this->assertFalse($result['mailed']);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM outbound_message WHERE template_key = 'user_invitation' AND user_id = ?", [$result['user_id']]));
    }

    public function testScheduledDeactivationTakesEffectOnItsDate(): void
    {
        $user = $this->makeUser();
        $id = $user['user_id'];
        $this->assertArrayHasKey('deactivate_reason', $this->errorsOf(fn() => AccountService::deactivate($id, ' ', null, $this->actor)));
        $this->assertArrayHasKey('effective_date', $this->errorsOf(fn() => AccountService::deactivate($id, 'Leaving', '2026-09-30', $this->actor)));

        AccountService::sendReset($id, $this->actor); // an outstanding link, to see what happens to it
        $link = $this->lastMailedToken();
        $sid = SessionStore::create($id, null);
        $idle = SessionStore::create($id, null);
        $this->assertFalse(AccountService::deactivate($id, 'Moving away', '2026-10-04', $this->actor));
        $this->assertSame('Active', AccountRepository::find($id)['status']);
        $this->assertTrue($this->valid($link), 'links keep working until the date');
        $this->assertNull($this->scalar('SELECT ended_at FROM user_session WHERE session_id = ?', [$sid]), 'still signed in until the date');

        Clock::advance('+3 days');
        $this->assertSame('inactive', AccountRules::blockReason(AccountRepository::find($id)), 'sign-in refused from the date, before cron runs');
        Db::pdo()->prepare('UPDATE user_session SET started_at = ?, last_activity_at = ? WHERE session_id = ?')->execute([Clock::db(), Clock::db(), $sid]);
        $this->assertSame(['ended' => 'account'], SessionStore::validate($sid), 'an open session is refused on its next request');
        $job = new DeactivateDueAccounts();
        $this->assertSame('deactivated 1 account(s)', $job->run());
        $this->assertSame('Inactive', AccountRepository::find($id)['status']);
        $this->assertSame('Deactivated', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$idle]));
        $this->assertFalse($this->valid($link), 'links are cancelled when it takes effect');
        $this->assertSame('deactivated 0 account(s)', $job->run(), 'running again does nothing');
    }

    public function testSignInIsRefusedFromTheDeactivationDate(): void
    {
        $user = $this->makeUser();
        AccountService::deactivate($user['user_id'], 'Moving away', '2026-10-02', $this->actor);
        $this->assertTrue(Auth::attempt($user['username'], self::OLD_PASSWORD, '127.0.0.1')['ok']);
        Clock::advance('+1 day');
        $this->assertSame('inactive', Auth::attempt($user['username'], self::OLD_PASSWORD, '127.0.0.1')['code']);
    }

    public function testAccountDatesUseTheOrganisationsDate(): void
    {
        $this->setSetting('organisation_time_zone', 'America/New_York');
        Clock::freeze('2026-10-02 02:00:00'); // 10 pm on 1 October in Charleston
        $tonight = $this->makeUser()['user_id'];
        $this->assertTrue(AccountService::deactivate($tonight, 'Last shift', '2026-10-01', $this->actor), "the local 'today' is accepted and applies now");
        $tomorrow = $this->makeUser(['expiry_date' => '2026-10-01'])['user_id'];
        $this->assertNull(AccountRules::blockReason(AccountRepository::find($tomorrow)), 'an end date lasts to the end of the local day');
        $this->assertFalse(AccountService::deactivate($tomorrow, 'Leaving', '2026-10-02', $this->actor), 'local tomorrow is scheduled, not applied');
        $this->assertNull(AccountRules::blockReason(AccountRepository::find($tomorrow)));
    }

    public function testListLabelsAndFiltersFollowTheSignInRules(): void
    {
        $locked = $this->makeUser(['last_name' => 'Lockedperson', 'locked_until' => '2026-10-01 12:30:00'])['user_id'];
        $ended = $this->makeUser(['expiry_date' => '2026-09-30']);
        $future = $this->makeUser(['start_date' => '2026-10-05']);
        $leaving = $this->makeUser();
        AccountService::deactivate($leaving['user_id'], 'Leaving', '2026-11-01', $this->actor);
        $until = $this->makeUser(['expiry_date' => '2026-12-31']);

        $lockedIds = array_map(fn($u) => (int) $u['user_id'], AccountRepository::list(['status' => 'Locked']));
        $activeIds = array_map(fn($u) => (int) $u['user_id'], AccountRepository::list(['status' => 'Active']));
        $this->assertContains($locked, $lockedIds);
        $this->assertNotContains($locked, $activeIds);
        $labels = [];
        foreach (AccountRepository::list([]) as $u) {
            $labels[(int) $u['user_id']] = AccountStatus::label($u);
        }
        $this->assertSame('Locked', $labels[$locked]);
        $this->assertSame('Ended 2026-09-30', $labels[$ended['user_id']]);
        $this->assertSame('Starts 2026-10-05', $labels[$future['user_id']]);
        $this->assertSame('Deactivated from 2026-11-01', $labels[$leaving['user_id']]);
        $this->assertSame('Active until 2026-12-31', $labels[$until['user_id']]);
        $this->assertSame('Active', $labels[$this->actor]);
    }

    public function testReactivation(): void
    {
        $activated = $this->makeUser()['user_id'];
        AccountService::deactivate($activated, 'Left', null, $this->actor);
        $this->assertSame('Inactive', AccountRepository::find($activated)['status']);
        $this->assertArrayHasKey('reactivate_reason', $this->errorsOf(fn() => AccountService::reactivate($activated, '', $this->actor)));
        $this->assertNull(AccountService::reactivate($activated, 'Back for spring', $this->actor));
        $account = AccountRepository::find($activated);
        $this->assertSame('Active', $account['status']);
        $this->assertNull($account['deactivated_reason']);
        $this->assertSame('Back for spring', $this->scalar("SELECT reason FROM audit_log WHERE action = 'user_reactivate' AND entity_id = ?", [$activated]));

        $invited = $this->invite(['role' => 'Board']);
        $oldLink = $this->lastMailedToken();
        AccountService::deactivate($invited, 'Never started', null, $this->actor);
        $this->assertFalse($this->valid($oldLink), 'deactivation cancels links');
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => AccountService::issueActivationSheet($invited, $this->actor)));
        Mailer::$sent = [];
        $this->assertTrue(AccountService::reactivate($invited, 'Starting after all', $this->actor));
        $this->assertSame('Pending', AccountRepository::find($invited)['status'], 'never activated, so invited again');
        $this->assertTrue($this->valid($this->lastMailedToken()));

        $scheduled = $this->makeUser()['user_id'];
        AccountService::deactivate($scheduled, 'Leaving', '2026-11-01', $this->actor);
        AccountService::reactivate($scheduled, 'Staying on', $this->actor);
        $this->assertNull(AccountRepository::find($scheduled)['deactivation_effective_date'], 'a scheduled deactivation can be cancelled');
    }

    public function testPasswordChangeRevokesOfflineGrants(): void
    {
        $site = $this->makeSite('Site A');
        [$id, $first] = $this->personWithGrant($site);
        $second = $this->offlineGrant($id, $site); // on another tablet
        $sid = SessionStore::create($id, $site);

        Auth::setPassword($id, self::GOOD_PASSWORD, 'Password Reset'); // change_password.php, reset_password.php, activate, the Station
        $this->assertSame([self::NOW, self::NOW], [$this->revokedAt($first), $this->revokedAt($second)], 'on every tablet');
        $this->assertSame([['cause' => 'password', 'count' => 2]], $this->revocations($id), 'one row, with no secret');
        $this->assertSame('Password Reset', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$sid]));

        Clock::advance('+1 minute');
        Auth::setPassword($id, 'Another-Good-Passphrase-7', 'Password Reset');
        $this->assertSame(self::NOW, $this->revokedAt($first), 'a revoked grant keeps its time');
        $this->assertCount(1, $this->revocations($id), 'nothing left to revoke: no row');
    }

    public function testAccessChangeRevokesOfflineGrants(): void
    {
        $a = $this->makeSite('Site A');
        $b = $this->makeSite('Site B');
        [$moved, $movedGrant] = $this->personWithGrant($a);
        $sid = SessionStore::create($moved, $a);
        $this->update($moved, ['reason' => 'Moved to Site B'], [$b]);
        $this->assertSame(self::NOW, $this->revokedAt($movedGrant));
        $this->assertSame([['cause' => 'access', 'count' => 1]], $this->revocations($moved));
        $this->assertSame('Permission Change', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$sid]));

        [$ending, $endingGrant] = $this->personWithGrant($a);
        $this->update($ending, ['expiry_date' => '2026-12-31', 'reason' => 'Season ends']);
        $this->assertSame(self::NOW, $this->revokedAt($endingGrant), 'an end date is an access change too');
        $this->assertSame([['cause' => 'access', 'count' => 1]], $this->revocations($ending));
    }

    public function testAPhoneChangeKeepsOfflineGrants(): void
    {
        $site = $this->makeSite('Site A');
        [$id, $grant] = $this->personWithGrant($site);
        $sid = SessionStore::create($id, $site);
        $this->assertSame(['phone'], array_keys($this->update($id, ['phone' => '8435550106'])));
        $this->assertNull($this->revokedAt($grant), 'the tablet keeps working offline for them');
        $this->assertSame([], $this->revocations($id));
        $this->assertArrayHasKey('user', SessionStore::validate($sid));
    }

    public function testDeactivationRevokesOfflineGrants(): void
    {
        $site = $this->makeSite('Site A');
        [$id, $grant] = $this->personWithGrant($site);
        $sid = SessionStore::create($id, $site);
        $this->assertTrue(AccountService::deactivate($id, 'Left', null, $this->actor));
        $this->assertSame(self::NOW, $this->revokedAt($grant));
        $this->assertSame([['cause' => 'deactivate', 'count' => 1]], $this->revocations($id));
        $this->assertSame('Deactivated', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$sid]));
    }

    public function testAFutureDeactivationDateRevokesGrantsButKeepsSessions(): void
    {
        $site = $this->makeSite('Site A');
        [$id, $grant] = $this->personWithGrant($site);
        $sid = SessionStore::create($id, $site);
        $this->assertFalse(AccountService::deactivate($id, 'Moving away', '2026-10-15', $this->actor));
        $this->assertSame(self::NOW, $this->revokedAt($grant), 'no offline work for someone who is leaving, from now');
        $this->assertSame([['cause' => 'deactivate', 'count' => 1]], $this->revocations($id));
        $this->assertNull($this->scalar('SELECT ended_at FROM user_session WHERE session_id = ?', [$sid]), 'still signed in online until the date');
        $this->assertSame('Active', AccountRepository::find($id)['status']);
    }

    public function testCredentialResetRevokesGrantsAndClearsThePin(): void
    {
        $site = $this->makeSite('Site A');
        $resets = [
            'sendReset' => fn(int $id) => AccountService::sendReset($id, $this->actor),
            'issueActivationSheet' => fn(int $id) => AccountService::issueActivationSheet($id, $this->actor),
        ];
        $pin = Db::pdo()->prepare('SELECT pin_hash, pin_failed_count FROM user_account WHERE user_id = ?');
        foreach ($resets as $path => $reset) {
            [$id, $grant] = $this->personWithGrant($site, ['pin_hash' => 'p1.k1.not-a-real-hash', 'pin_failed_count' => 2]);
            $reset($id);
            $pin->execute([$id]);
            $row = $pin->fetch();
            $this->assertSame([null, 0], [$row['pin_hash'], (int) $row['pin_failed_count']], "$path clears the PIN: whoever had the account may know it");
            $this->assertSame(self::NOW, $this->revokedAt($grant), $path);
            $this->assertSame([['cause' => 'reset', 'count' => 1]], $this->revocations($id), $path);
        }
    }

    public function testUnlockResetsThePinCounter(): void
    {
        $user = $this->makeUser(['failed_login_count' => 5, 'locked_until' => '2026-10-01 12:30:00', 'pin_hash' => 'p1.k1.kept', 'pin_failed_count' => 3]);
        AccountService::unlock($user['user_id'], $this->actor);
        $st = Db::pdo()->prepare('SELECT pin_hash, pin_failed_count, failed_login_count FROM user_account WHERE user_id = ?');
        $st->execute([$user['user_id']]);
        $row = $st->fetch();
        $this->assertSame(['p1.k1.kept', 0, 0], [$row['pin_hash'], (int) $row['pin_failed_count'], (int) $row['failed_login_count']],
            'the online PIN counter is cleared; the PIN itself stays');
    }

    public function testScheduledDeactivationRevokesOfflineGrants(): void
    {
        $site = $this->makeSite('Site A');
        $id = $this->makeUser()['user_id'];
        AccountService::deactivate($id, 'Moving away', '2026-10-03', $this->actor);
        $this->assertSame([], $this->revocations($id), 'nothing to revoke when it was scheduled');
        // A grant that outlives the date (issue() caps new grants at it; this row stands in for one that was not capped).
        Db::pdo()->prepare("INSERT INTO auth_token (user_id, purpose, token_hash, device_id, expires_at, created_at) VALUES (?, 'Offline Grant', ?, ?, ?, ?)")
            ->execute([$id, Tokens::hash(random_bytes(32)), $this->makeDevice($site, ['is_site_registered' => 1]), '2026-12-01 00:00:00', Clock::db()]);
        $grant = (int) Db::pdo()->lastInsertId();

        Clock::advance('+1 day');
        (new DeactivateDueAccounts())->run();
        $this->assertNull($this->revokedAt($grant), 'not before the date');
        Clock::advance('+1 day');
        $this->assertSame('deactivated 1 account(s)', (new DeactivateDueAccounts())->run());
        $this->assertSame('2026-10-03 12:00:00', $this->revokedAt($grant));
        $this->assertSame([['cause' => 'deactivate', 'count' => 1]], $this->revocations($id));
    }

    public function testGrantsAreRevokedBeforeSessionsEnd(): void
    {
        $a = $this->makeSite('Site A');
        $b = $this->makeSite('Site B');

        [$moved] = $this->personWithGrant($a);
        SessionStore::create($moved, $a);
        $this->assertGrantsRevokedBeforeSessionsEnd(QueryLog::during(fn() => $this->update($moved, ['reason' => 'Moved to Site B'], [$b])), 'update (access)');

        [$gone] = $this->personWithGrant($a);
        SessionStore::create($gone, $a);
        $this->assertGrantsRevokedBeforeSessionsEnd(QueryLog::during(fn() => AccountService::deactivate($gone, 'Left', null, $this->actor)), 'deactivate');

        [$reset] = $this->personWithGrant($a);
        SessionStore::create($reset, $a);
        $this->assertGrantsRevokedBeforeSessionsEnd(QueryLog::during(fn() => AccountService::sendReset($reset, $this->actor)), 'sendReset');

        [$own] = $this->personWithGrant($a);
        SessionStore::create($own, $a);
        $this->assertGrantsRevokedBeforeSessionsEnd(QueryLog::during(fn() => Auth::setPassword($own, self::GOOD_PASSWORD, 'Password Reset')), 'Auth::setPassword');

        [$leaving] = $this->personWithGrant($a);
        AccountService::deactivate($leaving, 'Leaving', '2026-10-02', $this->actor);
        Db::pdo()->prepare("UPDATE auth_token SET revoked_at = NULL, expires_at = '2026-12-01 00:00:00' WHERE user_id = ? AND purpose = 'Offline Grant'")
            ->execute([$leaving]); // a grant still live on the date
        SessionStore::create($leaving, $a);
        Clock::advance('+1 day');
        $this->assertGrantsRevokedBeforeSessionsEnd(QueryLog::during(fn() => (new DeactivateDueAccounts())->run()), 'the scheduled job');
        $this->assertSame(Clock::db(), $this->scalar("SELECT revoked_at FROM auth_token WHERE user_id = ? AND purpose = 'Offline Grant'", [$leaving]));
    }
}
