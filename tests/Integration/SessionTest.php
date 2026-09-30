<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use Pfpms\Auth\SessionStore;
use Pfpms\Auth\SiteAccess;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Tests\TestCase;

/** UC-01 §4.2 session lifetime, UC-11 §4.4 immediate invalidation, US-28 time-boxed site access. */
final class SessionTest extends TestCase
{
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

    private function ids(array $sites): array
    {
        $ids = array_column($sites, 'site_id');
        sort($ids);
        return $ids;
    }
}
