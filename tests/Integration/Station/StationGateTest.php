<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Station;

use Pfpms\Account\AccountRepository;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\SessionStore;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Security\Crypto;
use Pfpms\Station\OfflineGrants;
use Pfpms\Station\StationGate;
use Pfpms\Tests\Support\StationFixture;
use Pfpms\Tests\TestCase;

/**
 * The Station's gate (50-design D-18, D-59, REQ-30; S3 spec §2.5): who may use the Station on a tablet, the tablet's row
 * lock, the site and session re-reads, and what release() lets leave the server (the vault key and a grant), in which order.
 */
final class StationGateTest extends TestCase
{
    use StationFixture;

    private static ?string $passwordHash = null;

    private int $north;
    private array $tablet;
    private array $volunteer;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stationSetUp();
        $this->north = $this->makeSite('Northside');
        $this->tablet = $this->stationTablet($this->north);
        $this->admin = $this->person(['role' => 'Administrator'], false);
        $this->volunteer = $this->person();
    }

    protected function tearDown(): void
    {
        $this->stationTearDown();
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    /** A person (with access to Northside unless $access is false), as the account row reads them. */
    private function person(array $overrides = [], bool $access = true): array
    {
        self::$passwordHash ??= PasswordPolicy::hash('Correct-Horse-Battery-9');
        $user = $this->makeUser($overrides + ['password_hash' => self::$passwordHash]);
        if ($access) {
            $this->grantSite($user['user_id'], $this->north);
        }
        return $this->account($user['user_id']);
    }

    private function account(int $userId): array
    {
        $row = AccountRepository::find($userId);
        $this->assertNotNull($row);
        return $row;
    }

    /** The tablet's row as the transactions see it after lockDevice() (from the guard's row). */
    private function locked(?array $tablet = null): array
    {
        return StationGate::lockDevice($this->stationDevice($tablet ?? $this->tablet));
    }

    private function written(): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM auth_token WHERE purpose = 'Offline Grant'")
            + (int) $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'offline_grant_issue'");
    }

    private function retire(int $deviceId, string $wipeMode): void
    {
        Db::pdo()->prepare('UPDATE device SET revoked_at = ?, revoked_by = ?, wipe_mode = ? WHERE device_id = ?')
            ->execute([Clock::db(), $this->admin['user_id'], $wipeMode, $deviceId]);
    }

    private function nothing(?string $unavailable = null, ?string $gate = null): array
    {
        return ['gate' => $gate, 'release' => null, 'unavailable' => $unavailable];
    }

    // Capabilities and access -------------------------------------------------------------------------

    public function testBoardHasNoOfflineCapability(): void
    {
        $this->assertSame([], StationGate::offlineCapabilities('Board'), 'Board: no Station sign-in (D-18)');
        $this->assertSame([], StationGate::offlineCapabilities('Nobody'));
        $caps = ['offline.checkin', 'offline.distribute', 'offline.pet_edit', 'offline.register'];
        foreach (['Volunteer', 'Coordinator', 'Administrator'] as $role) {
            $this->assertSame($caps, StationGate::offlineCapabilities($role), "$role: the offline.* capabilities, sorted");
        }
    }

    public function testAccessChecksTheRoleThenTheSite(): void
    {
        $device = $this->locked();
        $this->assertNull(StationGate::access($this->volunteer, $device));
        $this->assertNull(StationGate::access($this->admin, $device), 'site.all');
        $this->assertSame('site', StationGate::access($this->person([], false), $device), 'no grant for the tablet\'s site');

        $board = $this->person(['role' => 'Board'], false);
        $this->assertSame('role', StationGate::access($board, $device), 'Board holds site.all, and still has no Station');
        $this->assertSame('role', StationGate::access($board, ['site_id' => null] + $device), 'the role is checked first');
        $this->assertSame('site', StationGate::access($this->volunteer, ['site_id' => null] + $device), 'a tablet with no site');

        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->north]);
        $this->assertSame('site', StationGate::access($this->volunteer, $device), 'an inactive site is no access');
    }

    // The tablet's row and the re-reads ---------------------------------------------------------------

    public function testLockDeviceRefusesARevokedOrUnregisteredTablet(): void
    {
        foreach (['Push Then Wipe', 'Wipe Now'] as $mode) {
            $tablet = $this->stationTablet($this->north);
            $guard = $this->stationDevice($tablet); // read before the Retire committed
            $this->retire($tablet['id'], $mode);
            $e = $this->assertRefused(403, 'device_revoked', fn() => StationGate::lockDevice($guard));
            $this->assertSame(['This tablet has been taken out of service.', ['directive' => ['wipe' => $mode]]], [$e->getMessage(), $e->extra]);
        }

        $wiped = $this->stationTablet($this->north);
        $guard = $this->stationDevice($wiped);
        $this->retire($wiped['id'], 'Wipe Now');
        Db::pdo()->prepare('UPDATE device SET wiped_at = ? WHERE device_id = ?')->execute([Clock::db(), $wiped['id']]);
        $this->assertSame(['directive' => ['wipe' => 'Wipe Now']], $this->assertRefused(403, 'device_revoked', fn() => StationGate::lockDevice($guard))->extra);

        $unregistered = $this->stationTablet($this->north);
        $guard = $this->stationDevice($unregistered);
        Db::pdo()->prepare('UPDATE device SET is_site_registered = 0 WHERE device_id = ?')->execute([$unregistered['id']]);
        $e = $this->assertRefused(403, 'device_not_registered', fn() => StationGate::lockDevice($guard));
        $this->assertSame(['This tablet is not registered for use.', ['directive' => null]], [$e->getMessage(), $e->extra]);

        $e = $this->assertRefused(403, 'device_not_registered', fn() => StationGate::lockDevice(['device_id' => 2147483647, 'site_name' => null]));
        $this->assertSame(['directive' => null], $e->extra, 'no row: nothing to wipe by');
    }

    public function testLockDeviceKeepsTheGuardsSiteName(): void
    {
        $guard = $this->stationDevice($this->tablet);
        Db::pdo()->prepare('UPDATE device SET offline_enabled = 0, pbkdf2_iterations = 250000 WHERE device_id = ?')->execute([$this->tablet['id']]);
        $d = StationGate::lockDevice($guard);
        $this->assertSame([$this->tablet['id'], $this->north, 'Northside', 1], [(int) $d['device_id'], (int) $d['site_id'], $d['site_name'], (int) $d['in_service']]);
        $this->assertSame([0, 250000], [(int) $d['offline_enabled'], (int) $d['pbkdf2_iterations']], 'the row as locked, not as the guard read it');
        foreach (['vault_key_ciphertext', 'proof_key_ciphertext', 'token_hash'] as $secret) {
            $this->assertArrayNotHasKey($secret, $d);
        }
    }

    public function testRequireSiteActive(): void
    {
        $d = $this->locked();
        StationGate::requireSiteActive($d);
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->north]);
        $e = $this->assertRefused(403, 'device_site_inactive', fn() => StationGate::requireSiteActive($d));
        $this->assertSame(["This tablet's site is not active. Ask a Coordinator.", ['directive' => null]], [$e->getMessage(), $e->extra]);
        $this->assertRefused(403, 'device_site_inactive', fn() => StationGate::requireSiteActive(['site_id' => null] + $d), 'no site');
    }

    public function testRequireSessionOpen(): void
    {
        $sid = SessionStore::create($this->volunteer['user_id'], $this->north, 'Password', $this->tablet['id']);
        $row = StationGate::requireSessionOpen($sid);
        $this->assertSame(['session_id', 'user_id', 'device_id', 'auth_method', 'started_at', 'ended_at'], array_keys($row));
        $this->assertSame([$sid, 'Password', null], [$row['session_id'], $row['auth_method'], $row['ended_at']]);

        SessionStore::end($sid, 'Logout');
        $e = $this->assertRefused(401, 'session_ended', fn() => StationGate::requireSessionOpen($sid));
        $this->assertSame('Your session has ended. Please sign in again.', $e->getMessage());
        $this->assertRefused(401, 'session_ended', fn() => StationGate::requireSessionOpen(str_repeat('0', 64)), 'unknown');
    }

    // release() ------------------------------------------------------------------------------------

    public function testGateOrder(): void
    {
        $d = $this->locked();
        $this->agreement(); // in force, not accepted by anyone
        $forced = $this->person(['must_change_password' => 1]);
        $this->assertSame($this->nothing(null, 'password_change'), StationGate::release($forced, $d), 'the forced change comes first');
        $pending = $this->person(['status' => 'Pending']);
        $this->assertSame($this->nothing(null, 'password_change'), StationGate::release($pending, $d));
        $this->setSetting('password_max_age_days', '30');
        $old = $this->person(['password_changed_at' => '2026-08-01 00:00:00']);
        $this->assertSame($this->nothing(null, 'password_change'), StationGate::release($old, $d), 'an expired password');
        $this->setSetting('password_max_age_days', '0');
        $this->assertSame($this->nothing(null, 'policy_ack'), StationGate::release($this->volunteer, $d), 'then the agreement');
        $this->assertSame(0, $this->written(), 'no grant and no vault key while a gate is pending');

        $this->agreement($this->volunteer);
        $r = StationGate::release($this->volunteer, $d);
        $this->assertSame([null, null], [$r['gate'], $r['unavailable']]);
        $this->assertSame(['dvk', 'grant_id', 'grant_hmac_key', 'grant_issued_at', 'grant_expires_at', 'pbkdf2_iterations', 'offline_allowed'], array_keys($r['release']));
        $grant = OfflineGrants::find($r['release']['grant_id']);
        $this->assertSame(Crypto::b64url($this->tablet['dvk']), $r['release']['dvk']);
        $this->assertSame(43, strlen($r['release']['dvk']));
        $this->assertSame(Crypto::b64url((string) OfflineGrants::secret($grant)), $r['release']['grant_hmac_key']);
        $this->assertSame([$grant['created_at'] . '.000', $grant['expires_at'] . '.000', 600000, true],
            [$r['release']['grant_issued_at'], $r['release']['grant_expires_at'], $r['release']['pbkdf2_iterations'], $r['release']['offline_allowed']]);
        $this->assertSame([(int) $this->volunteer['user_id'], $this->tablet['id']], [(int) $grant['user_id'], (int) $grant['device_id']]);
    }

    public function testReleaseRechecksTheAccountAndAccessFirst(): void
    {
        $d = $this->locked();
        $this->agreement();
        $inactive = $this->person(['status' => 'Inactive', 'must_change_password' => 1]);
        $e = $this->assertRefused(422, 'account_unusable', fn() => StationGate::release($inactive, $d));
        $this->assertSame([StationGate::UNUSABLE, []], [$e->getMessage(), $e->extra]);
        $expired = $this->person(['expiry_date' => '2026-09-30']);
        $this->assertRefused(422, 'account_unusable', fn() => StationGate::release($expired, $d), 'an expired account');

        $noSite = $this->person(['must_change_password' => 1], false);
        $e = $this->assertRefused(403, 'no_station_access', fn() => StationGate::release($noSite, $d));
        $this->assertSame(["You don't have access to Northside, where this tablet is used.", ['reason' => 'site']], [$e->getMessage(), $e->extra],
            "the guard's site name; before the forced-change gate");

        $board = $this->person(['role' => 'Board']);
        $e = $this->assertRefused(403, 'no_station_access', fn() => StationGate::release($board, $d));
        $this->assertSame([StationGate::ROLE, ['reason' => 'role']], [$e->getMessage(), $e->extra]);

        $this->grantSite($noSite['user_id'], $this->north, '2026-01-01 00:00:00', '2026-10-01 11:59:59');
        $this->assertRefused(403, 'no_station_access', fn() => StationGate::release($noSite, $d), 'a grant that ended a second ago');
        $this->assertSame(0, $this->written(), 'nothing written');
        $this->assertSame('site', StationGate::noAccess('site', ['site_name' => null])->extra['reason']);
        $this->assertSame("You don't have access to this site, where this tablet is used.", StationGate::noAccess('site', [])->getMessage());
    }

    public function testReleaseNamesWhyNothingWasReleased(): void
    {
        $seeded = $this->stationTablet($this->north, [], false);
        $this->assertSame($this->nothing('no_vault_key'), StationGate::release($this->volunteer, $this->locked($seeded)), 'a seeded tablet (D-46)');

        $lastDay = $this->person(['expiry_date' => '2026-10-01']);
        $this->assertSame($this->nothing('no_grant_possible'), StationGate::release($lastDay, $this->locked(), Clock::now()->modify('+2 days')),
            'every cap is behind the issue instant');
        $this->assertSame(0, $this->written());

        // A vault key sealed under a key id later removed from crypto.keys: online only, and an incident is logged.
        $saved = Config::snapshot();
        $crypto = (array) ($saved['crypto'] ?? []);
        Config::override(['crypto' => ['active' => 'gone9', 'keys' => (array) ($crypto['keys'] ?? []) + ['gone9' => base64_encode(random_bytes(32))]] + $crypto] + $saved);
        $old = $this->stationTablet($this->north);
        Config::override($saved);
        $log = APP_ROOT . '/storage/logs/app-' . gmdate('Y-m') . '.log';
        $before = is_file($log) ? (int) filesize($log) : 0;
        $this->assertSame($this->nothing('no_vault_key'), StationGate::release($this->volunteer, $this->locked($old)));
        clearstatcache();
        $this->assertStringContainsString("Encryption key 'gone9' is not configured", (string) file_get_contents($log, false, null, $before));
        $this->assertSame(0, $this->written(), 'no grant without a vault key');
    }

    public function testOfflineAllowedNeedsTheTabletAndTheSetting(): void
    {
        $r = StationGate::release($this->volunteer, $this->locked());
        $this->assertSame([true, '2026-10-04 12:00:00.000'], [$r['release']['offline_allowed'], $r['release']['grant_expires_at']]);

        $online = $this->stationTablet($this->north, ['offline_enabled' => 0, 'pbkdf2_iterations' => null]);
        $this->setSetting('offline_pbkdf2_iterations', '300000');
        $r = StationGate::release($this->volunteer, $this->locked($online));
        $this->assertSame([false, '2026-10-02 00:00:00.000', 300000], [$r['release']['offline_allowed'], $r['release']['grant_expires_at'], $r['release']['pbkdf2_iterations']],
            'an online-only tablet: a session-long grant; no calibration stored: the setting');

        $this->setSetting('offline_mode_enabled', '0');
        $r = StationGate::release($this->volunteer, $this->locked());
        $this->assertSame([false, '2026-10-02 00:00:00.000'], [$r['release']['offline_allowed'], $r['release']['grant_expires_at']], 'offline switched off for everyone');
    }

    public function testTheReleaseIsAuditedOnlyWhenAGrantIsIssued(): void
    {
        $d = $this->locked();
        $this->agreement();
        StationGate::release($this->volunteer, $d); // policy_ack
        StationGate::release($this->volunteer, $this->locked($this->stationTablet($this->north, [], false))); // no_vault_key
        StationGate::release($this->person(['expiry_date' => '2026-10-01']), $d, Clock::now()->modify('+2 days')); // no_grant_possible
        $this->assertSame([], $this->auditRows('offline_grant_issue'));

        $this->agreement($this->volunteer);
        $r = StationGate::release($this->volunteer, $d);
        $rows = $this->auditRows('offline_grant_issue');
        $this->assertCount(1, $rows);
        $this->assertSame([$r['release']['grant_id'], 'hours', true], [$rows[0]['details']['grant_id'], $rows[0]['details']['capped_by'], $rows[0]['details']['offline_allowed']]);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM auth_token WHERE purpose = 'Offline Grant'"));
    }
}
