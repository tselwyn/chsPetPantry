<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use LogicException;
use Pfpms\Audit\Audit;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\SiteAccess;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Tests\TestCase;

/** UC-01 §4.2 session lifetime, UC-11 §4.4 immediate invalidation, US-28 time-boxed site access, 50-design §5.9 no-touch validation. */
final class SessionTest extends TestCase
{
    /** The configuration before phpSession() pointed PHP's session files at a temporary directory. */
    private ?array $savedConfig = null;

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { // Page::session() restarted the PHP session
            $_SESSION = [];
            session_destroy();
        }
        if ($this->savedConfig !== null) {
            Config::override($this->savedConfig);
            $this->savedConfig = null;
        }
        parent::tearDown();
    }

    public function testIdleTimeout(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        Clock::advance('+29 minutes');
        $this->assertTrue(isset(SessionStore::validate($sid)['user']));
        Clock::advance('+29 minutes');
        $this->assertTrue(isset(SessionStore::validate($sid)['user']), 'activity at +29 min reset the idle timer');
        Clock::advance('+31 minutes');
        $this->assertSame(['ended' => 'timeout'], SessionStore::validate($sid));
        $this->assertSame('Timeout', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$sid]));
    }

    public function testAbsoluteTimeoutEvenWhenActive(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        for ($i = 0; $i < 40; $i++) { // 28-minute steps, active the whole time
            Clock::advance('+28 minutes');
            $result = SessionStore::validate($sid);
            if (isset($result['ended'])) {
                break;
            }
        }
        $this->assertSame(['ended' => 'timeout'], $result);
        $elapsedHours = (Clock::now()->getTimestamp() - Clock::fromDb(self::NOW)->getTimestamp()) / 3600;
        $this->assertTrue($elapsedHours >= 12 && $elapsedHours < 12.5, "ended at the 12-hour limit, not before ($elapsedHours h)");
    }

    public function testDeactivationEndsTheSessionOnTheNextRequest(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        Db::pdo()->prepare("UPDATE user_account SET status = 'Inactive' WHERE user_id = ?")->execute([$user['user_id']]);
        $this->assertSame(['ended' => 'account'], SessionStore::validate($sid));
    }

    public function testRemoteSignOutAndPermissionChange(): void
    {
        $user = $this->makeUser();
        $a = SessionStore::create($user['user_id'], null);
        $b = SessionStore::create($user['user_id'], null);
        $this->assertSame(2, SessionStore::endAllForUser($user['user_id'], 'Permission Change', null));
        $this->assertSame(['ended' => 'ended'], SessionStore::validate($a));
        $this->assertSame(['ended' => 'ended'], SessionStore::validate($b));
    }

    public function testEndAllForDeviceEndsOnlyThatDevicesSessions(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $tablet = $this->makeDevice($site);
        $other = $this->makeDevice($site);
        $on = SessionStore::create($user['user_id'], $site, 'PIN', $tablet);
        $elsewhere = SessionStore::create($user['user_id'], $site, 'PIN', $other);
        $browser = SessionStore::create($user['user_id'], $site);
        $this->assertSame(1, SessionStore::endAllForDevice($tablet, 'Device Revoked', $user['user_id']));
        $this->assertSame('Device Revoked', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$on]));
        $this->assertArrayHasKey('user', SessionStore::validate($elsewhere));
        $this->assertArrayHasKey('user', SessionStore::validate($browser));
        $this->assertSame(0, SessionStore::endAllForDevice($tablet, 'Device Revoked'), 'nothing left to end');
    }

    public function testASessionOnARevokedDeviceEnds(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $tablet = $this->makeDevice($site, ['token_hash' => str_repeat('a', 64), 'is_site_registered' => 1]);
        $sid = SessionStore::create($user['user_id'], $site, 'Password', $tablet); // opened while the tablet was being retired
        $browser = SessionStore::create($user['user_id'], $site);
        Db::pdo()->prepare('UPDATE device SET revoked_at = ? WHERE device_id = ?')->execute([Clock::db(), $tablet]);
        $this->assertSame(['ended' => 'device'], SessionStore::validate($sid));
        $this->assertSame('Device Revoked', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$sid]));
        $checked = SessionStore::validate($browser);
        $this->assertArrayHasKey('user', $checked);
        $this->assertArrayNotHasKey('device_revoked_at', $checked['user'], 'the device check stays out of the user row');
    }

    public function testPeopleSignedOutByARetirementAreToldWhy(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $tablet = $this->makeDevice($site, ['token_hash' => str_repeat('b', 64), 'is_site_registered' => 1]);
        $sid = SessionStore::create($user['user_id'], $site, 'PIN', $tablet);
        SessionStore::endAllForDevice($tablet, 'Device Revoked');
        $this->assertSame(['ended' => 'device'], SessionStore::validate($sid), 'so the sign-in page can say the tablet was taken out of service');
        $other = SessionStore::create($user['user_id'], $site);
        SessionStore::end($other, 'Logout');
        $this->assertSame(['ended' => 'ended'], SessionStore::validate($other));
    }

    public function testASessionOnATabletThatErasedItselfEnds(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $tablet = $this->makeDevice($site, ['token_hash' => str_repeat('c', 64), 'is_site_registered' => 1]);
        $sid = SessionStore::create($user['user_id'], $site, 'Password', $tablet);
        Db::pdo()->prepare('UPDATE device SET wiped_at = ? WHERE device_id = ?')->execute([Clock::db(), $tablet]); // P2B: it wiped after too many failed unlocks
        $this->assertSame(['ended' => 'device'], SessionStore::validate($sid));
    }

    public function testUnknownSession(): void
    {
        $this->assertSame(['ended' => 'missing'], SessionStore::validate(str_repeat('0', 64)));
    }

    public function testSiteGrantsStartAndLapse(): void
    {
        $north = $this->makeSite('Test North');
        $south = $this->makeSite('Test South');
        $admin = $this->makeUser(['role' => 'Administrator']);
        $volunteer = $this->makeUser();
        $grant = Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, starts_at, ends_at, granted_by) VALUES (?, ?, ?, ?, ?)');
        $grant->execute([$volunteer['user_id'], $north, '2026-01-01 00:00:00', null, $admin['user_id']]);
        $grant->execute([$volunteer['user_id'], $south, '2026-10-01 11:00:00', '2026-10-01 16:00:00', $admin['user_id']]); // one shift

        $this->assertContains($north, array_column(SiteAccess::sitesFor($admin), 'site_id'), 'Administrators see every site');
        $this->assertSame([$north, $south], $this->ids(SiteAccess::sitesFor($volunteer)));
        Clock::freeze('2026-10-01 16:00:00');
        $this->assertSame([$north], $this->ids(SiteAccess::sitesFor($volunteer)), 'the shift grant lapses exactly at ends_at');
        Clock::freeze('2026-10-01 10:59:59');
        $this->assertSame([$north], $this->ids(SiteAccess::sitesFor($volunteer)), 'not before starts_at');
    }

    // No-touch validation (50-design §5.9) ---------------------------------------------------------

    public function testValidateWithoutTouchDoesNotExtendTheIdleTimer(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        Clock::advance('+29 minutes');
        $this->assertArrayHasKey('user', SessionStore::validate($sid, false), 'still open at +29 minutes');
        $this->assertSame(self::NOW, $this->scalar('SELECT last_activity_at FROM user_session WHERE session_id = ?', [$sid]),
            'polling never writes last_activity_at');
        Clock::advance('+2 minutes');
        $this->assertSame(['ended' => 'timeout'], SessionStore::validate($sid),
            'idle 31 minutes since the last real activity: the poll at +29 kept nothing alive (compare testIdleTimeout)');
    }

    public function testValidateWithTouchStillRecordsActivity(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        Clock::advance('+20 minutes');
        SessionStore::validate($sid, true);
        $this->assertSame('2026-10-01 12:20:00', $this->scalar('SELECT last_activity_at FROM user_session WHERE session_id = ?', [$sid]),
            'the default path is unchanged');
    }

    public function testValidateWithoutTouchStillEndsATimedOutSession(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        Clock::advance('+31 minutes');
        $this->assertSame(['ended' => 'timeout'], SessionStore::validate($sid, false));
        $st = Db::pdo()->prepare('SELECT ended_at, end_reason, last_activity_at FROM user_session WHERE session_id = ?');
        $st->execute([$sid]);
        $this->assertSame(['ended_at' => '2026-10-01 12:31:00', 'end_reason' => 'Timeout', 'last_activity_at' => self::NOW], $st->fetch(),
            'ending never extends, so it still writes');
    }

    public function testValidateWithoutTouchStillEndsASessionOnARevokedTablet(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $tablet = $this->makeDevice($site, ['token_hash' => str_repeat('d', 64), 'is_site_registered' => 1]);
        $sid = SessionStore::create($user['user_id'], $site, 'Password', $tablet);
        Db::pdo()->prepare('UPDATE device SET revoked_at = ? WHERE device_id = ?')->execute([Clock::db(), $tablet]);
        $this->assertSame(['ended' => 'device'], SessionStore::validate($sid, false));
        $this->assertSame('Device Revoked', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$sid]));
    }

    public function testPageSessionReportsWhyASessionEnded(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $blocked = $this->makeUser();
        $tablet = $this->makeDevice($site, ['token_hash' => str_repeat('e', 64), 'is_site_registered' => 1]);

        $loggedOut = SessionStore::create($user['user_id'], null);
        SessionStore::end($loggedOut, 'Logout');
        $idle = SessionStore::create($user['user_id'], null);
        Db::pdo()->prepare('UPDATE user_session SET last_activity_at = ? WHERE session_id = ?')->execute(['2026-10-01 11:29:00', $idle]);
        $account = SessionStore::create($blocked['user_id'], null);
        Db::pdo()->prepare("UPDATE user_account SET status = 'Inactive' WHERE user_id = ?")->execute([$blocked['user_id']]);
        $onTablet = SessionStore::create($user['user_id'], $site, 'PIN', $tablet);
        Db::pdo()->prepare('UPDATE device SET revoked_at = ? WHERE device_id = ?')->execute([Clock::db(), $tablet]);
        $someoneElses = SessionStore::create($this->makeUser()['user_id'], null);

        $cases = [
            'missing' => [$user['user_id'], str_repeat('0', 64)],
            'ended' => [$user['user_id'], $loggedOut],
            'timeout' => [$user['user_id'], $idle],
            'account' => [$blocked['user_id'], $account],
            'device' => [$user['user_id'], $onTablet],
        ];
        foreach ($cases as $reason => [$uid, $sid]) {
            $this->phpSession(['uid' => $uid, 'sid' => $sid, 'site_id' => null]);
            $ended = 'not set';
            $this->assertNull(Page::session(true, $ended), $reason);
            $this->assertSame($reason, $ended, "the reason from validate(): $reason");
            $this->assertSame([], $_SESSION, "$reason: the PHP session was restarted empty, with no flash");
        }

        $this->phpSession(['uid' => $user['user_id'], 'sid' => $someoneElses, 'site_id' => null]);
        $this->assertNull(Page::session(true, $ended));
        $this->assertSame('ended', $ended, 'a server session of another person counts as ended');
        $this->assertArrayHasKey('user', SessionStore::validate($someoneElses), 'and it is left open for its owner');
    }

    public function testPageSessionWithoutIdsIsNullWithNoReason(): void
    {
        foreach ([[], ['uid' => '5', 'sid' => str_repeat('0', 64)], ['uid' => 5]] as $i => $ids) {
            $this->phpSession($ids);
            $ended = 'not set';
            $this->assertNull(Page::session(true, $ended), "case $i");
            $this->assertNull($ended, "case $i: the PHP session carried no usable ids, so nothing ended");
            $this->assertSame($ids, $_SESSION, "case $i: nothing was restarted");
        }
    }

    public function testPageSessionReturnsTheValidatedSession(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        $this->phpSession(['uid' => $user['user_id'], 'sid' => $sid, 'site_id' => null]);
        $ended = 'not set';
        $checked = Page::session(true, $ended);
        $this->assertNull($ended);
        $this->assertSame($sid, $checked['session']['session_id']);
        $this->assertSame($user['user_id'], (int) $checked['user']['user_id']);
        $this->assertSame(['uid' => $user['user_id'], 'sid' => $sid, 'site_id' => null], $_SESSION, 'an open session is left alone');
    }

    public function testPageSessionWithoutTouchDoesNotExtendTheIdleTimer(): void
    {
        $user = $this->makeUser();
        $sid = SessionStore::create($user['user_id'], null);
        $this->phpSession(['uid' => $user['user_id'], 'sid' => $sid, 'site_id' => null]);
        Clock::advance('+20 minutes');
        $this->assertNotNull(Page::session(false));
        $this->assertSame(self::NOW, $this->scalar('SELECT last_activity_at FROM user_session WHERE session_id = ?', [$sid]), 'touch false is passed through');
        $this->assertNotNull(Page::session());
        $this->assertSame('2026-10-01 12:20:00', $this->scalar('SELECT last_activity_at FROM user_session WHERE session_id = ?', [$sid]), 'the default touches');
    }

    public function testPageResolveStillFlashesAsBefore(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser();
        $blocked = $this->makeUser();
        $tablet = $this->makeDevice($site, ['token_hash' => str_repeat('f', 64), 'is_site_registered' => 1]);
        $loggedOut = SessionStore::create($user['user_id'], null);
        SessionStore::end($loggedOut, 'Remote Sign-out');
        $idle = SessionStore::create($user['user_id'], null);
        Db::pdo()->prepare('UPDATE user_session SET last_activity_at = ? WHERE session_id = ?')->execute(['2026-10-01 11:29:00', $idle]);
        $account = SessionStore::create($blocked['user_id'], null);
        Db::pdo()->prepare("UPDATE user_account SET status = 'Inactive' WHERE user_id = ?")->execute([$blocked['user_id']]);
        $onTablet = SessionStore::create($user['user_id'], $site, 'PIN', $tablet);
        Db::pdo()->prepare('UPDATE device SET revoked_at = ? WHERE device_id = ?')->execute([Clock::db(), $tablet]);
        $someoneElses = SessionStore::create($this->makeUser()['user_id'], null);
        $ended = 'Your session has ended. Please sign in again.';

        $cases = [
            'missing' => [$user['user_id'], str_repeat('0', 64), 'info', $ended],
            'ended' => [$user['user_id'], $loggedOut, 'info', $ended],
            'another person' => [$user['user_id'], $someoneElses, 'info', $ended],
            'timeout' => [$user['user_id'], $idle, 'info', 'You were signed out after a period of inactivity. Please sign in again.'],
            'account' => [$blocked['user_id'], $account, 'error', 'Your account can no longer be used. Please contact an Administrator.'],
            'device' => [$user['user_id'], $onTablet, 'info', 'This tablet was taken out of service, so you were signed out. Ask a Coordinator for another tablet.'],
        ];
        foreach ($cases as $case => [$uid, $sid, $type, $message]) {
            $this->phpSession(['uid' => $uid, 'sid' => $sid, 'site_id' => null]);
            $this->assertNull(Page::resolve(), $case);
            $this->assertSame([['type' => $type, 'message' => $message]], Flash::take(), $case);
        }

        $this->phpSession([]);
        $this->assertNull(Page::resolve());
        $this->assertSame([], Flash::take(), 'no ids: signed out, with nothing to say');
    }

    public function testPageResolvePicksTheOnlySiteAsBefore(): void
    {
        $north = $this->makeSite('Test North');
        $admin = $this->makeUser(['role' => 'Administrator']);
        $user = $this->makeUser();
        Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, starts_at, granted_by) VALUES (?, ?, ?, ?)')
            ->execute([$user['user_id'], $north, '2026-01-01 00:00:00', $admin['user_id']]);
        $sid = SessionStore::create($user['user_id'], null);
        $this->phpSession(['uid' => $user['user_id'], 'sid' => $sid, 'site_id' => null]);

        $ctx = Page::resolve();
        $this->assertNotNull($ctx);
        $this->assertSame([$sid, $north, [$north], null, 'Password'],
            [$ctx->sessionId, $ctx->siteId, array_column($ctx->sites, 'site_id'), $ctx->deviceId, $ctx->authMethod]);
        $this->assertSame($north, $_SESSION['site_id'], 'written back to the PHP session');
        $this->assertSame($north, (int) $this->scalar('SELECT site_id FROM user_session WHERE session_id = ?', [$sid]), 'and to the server session');
        $this->assertSame([], Flash::take());
    }

    public function testPageResolveDropsALapsedSiteAsBefore(): void
    {
        $north = $this->makeSite('Test North');
        $south = $this->makeSite('Test South');
        $admin = $this->makeUser(['role' => 'Administrator']);
        $user = $this->makeUser();
        $grant = Db::pdo()->prepare('INSERT INTO user_site_access (user_id, site_id, starts_at, ends_at, granted_by) VALUES (?, ?, ?, ?, ?)');
        $grant->execute([$user['user_id'], $north, '2026-01-01 00:00:00', null, $admin['user_id']]);
        $grant->execute([$user['user_id'], $south, '2026-01-01 00:00:00', null, $admin['user_id']]);
        $sid = SessionStore::create($user['user_id'], $south);
        $this->phpSession(['uid' => $user['user_id'], 'sid' => $sid, 'site_id' => $south]);
        $this->assertSame($south, Page::resolve()->siteId, 'two sites: the chosen one is kept');

        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$south]);
        $this->assertSame($north, Page::resolve()->siteId, 'the deactivated site is dropped and the only site left is picked (US-28)');
        $this->assertSame($north, (int) $this->scalar('SELECT site_id FROM user_session WHERE session_id = ?', [$sid]));
    }

    public function testPageResolveCarriesTheSessionsTabletAndSignInMethod(): void
    {
        $site = $this->makeSite('Test North');
        $user = $this->makeUser(['role' => 'Administrator']);
        $tablet = $this->makeDevice($site, ['token_hash' => str_repeat('9', 64), 'is_site_registered' => 1]);
        $sid = SessionStore::create($user['user_id'], $site, 'PIN', $tablet);
        $this->phpSession(['uid' => $user['user_id'], 'sid' => $sid, 'site_id' => $site]);
        $ctx = Page::resolve();
        $this->assertSame([$tablet, 'PIN', $site], [$ctx->deviceId, $ctx->authMethod, $ctx->siteId]);
    }

    public function testPageStartWithoutASessionIsOnlyForPublicPages(): void
    {
        $user = $this->makeUser();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // left open by an earlier test class
        }
        Audit::setActor($user['user_id']);
        $this->assertNull(Page::start(['public' => true, 'session' => false]));
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'no PHP session was started');
        $id = Audit::record('test_no_session', 'user_account', $user['user_id']);
        $this->assertNull($this->scalar('SELECT user_id FROM audit_log WHERE audit_id = ?', [$id]), 'the actor was cleared');

        $this->expectException(LogicException::class);
        Page::start(['session' => false]);
    }

    private function ids(array $sites): array
    {
        $ids = array_column($sites, 'site_id');
        sort($ids);
        return $ids;
    }

    /**
     * Put ids in the PHP session, as a signed-in browser would have them. Page::session() restarts PHP's session when
     * the server session has ended, so its files go to a temporary directory, not storage/sessions.
     */
    private function phpSession(array $ids): void
    {
        if ($this->savedConfig === null) {
            $this->savedConfig = Config::snapshot();
            $app = (array) ($this->savedConfig['app'] ?? []);
            Config::override(['app' => ['session_path' => sys_get_temp_dir() . '/pfpms-test-sessions'] + $app] + $this->savedConfig);
        }
        $_SESSION = $ids;
    }
}
