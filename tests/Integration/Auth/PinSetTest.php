<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Auth;

use Pfpms\Account\AccountRepository;
use Pfpms\Account\AccountService;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Pin;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Http\Api;
use Pfpms\Http\Context;
use Pfpms\Station\OfflineGrants;
use Pfpms\Tests\Support\QueryLog;
use Pfpms\Tests\Support\StationFixture;
use Pfpms\Tests\TestCase;

/**
 * Setting a Station PIN (50-design §6.7, D-22, D-25; S3 spec §2.6): the rules, the peppered Argon2id hash with its key id,
 * the password re-authentication and its bucket, the grants a change revokes, the audit row, and the endpoint's guard.
 */
final class PinSetTest extends TestCase
{
    use StationFixture;

    private const PASSWORD = 'Correct-Horse-Battery-9';
    private const GUESSABLE = 'Choose a PIN that is harder to guess than 1234 or 0000.';
    /** api/auth/pin_set.php's options (S3 spec §2.8). */
    private const OPTIONS = ['method' => 'POST', 'device' => 'in_service', 'capability' => 'auth.pin_switch'];
    /** The request's one read of the stored password (Auth::verifiedHash), before the transaction. */
    private const PASSWORD_READ = '/^SELECT password_hash FROM user_account WHERE user_id = \?$/';

    private static ?string $passwordHash = null;

    private int $north;
    private array $tablet;
    private array $other;
    private array $volunteer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stationSetUp();
        $this->north = $this->makeSite('Northside');
        $this->tablet = $this->stationTablet($this->north);
        $this->other = $this->stationTablet($this->north);
        $this->volunteer = $this->person();
    }

    protected function tearDown(): void
    {
        $this->stationTearDown();
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    private function person(array $overrides = []): array
    {
        self::$passwordHash ??= PasswordPolicy::hash(self::PASSWORD);
        $user = $this->makeUser($overrides + ['password_hash' => self::$passwordHash]);
        $this->grantSite($user['user_id'], $this->north);
        $row = AccountRepository::find($user['user_id']);
        $this->assertNotNull($row);
        return $row;
    }

    /** The Context of $user's Password session on the tablet, as Api::start() builds it. */
    private function ctx(?array $user = null, ?array $tablet = null): Context
    {
        $user ??= $this->volunteer;
        $tablet ??= $this->tablet;
        $sid = SessionStore::create((int) $user['user_id'], $this->north, 'Password', $tablet['id']);
        return new Context($user, $sid, $this->north, [], $tablet['id'], 'Password', $this->stationDevice($tablet));
    }

    private function set(string $pin, ?string $confirm = null, string $password = self::PASSWORD, ?array $user = null, ?array $tablet = null): array
    {
        $tablet ??= $this->tablet;
        return Pin::set($this->ctx($user, $tablet), $this->stationDevice($tablet), $password, $pin, $confirm ?? $pin);
    }

    private function pinHash(int $userId): ?string
    {
        $hash = $this->scalar('SELECT pin_hash FROM user_account WHERE user_id = ?', [$userId]);
        return $hash === false || $hash === null ? null : (string) $hash;
    }

    /** @return list<string> the first column of $sql over this test's audit rows (its one placeholder: the audit mark) */
    private function column(string $sql): array
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute([$this->stationAuditMark]);
        return array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function revokedAt(int $grantId): ?string
    {
        $at = $this->scalar('SELECT revoked_at FROM auth_token WHERE token_id = ?', [$grantId]);
        return $at === false || $at === null ? null : (string) $at;
    }

    // The rules ------------------------------------------------------------------------------------

    public function testPinMustBeDigitsWithinTheSettings(): void
    {
        $this->assertSame([4, 6], Pin::digitsRange());
        foreach (['12a4', '123', '1234567', '', ' 4821', '4821 ', '-4821', '48.2', "4821\n"] as $bad) {
            $this->assertSame(['pin' => 'Use 4 to 6 digits.'], Pin::problems($bad, $bad), json_encode($bad));
        }
        $this->assertSame([], Pin::problems('4821', '4821'));
        $this->assertSame([], Pin::problems('482193', '482193'));

        $this->setSetting('pin_min_digits', '5');
        $this->setSetting('pin_max_digits', '5');
        $this->assertSame([5, 5], Pin::digitsRange());
        $this->assertSame(['pin' => 'Use 5 digits.'], Pin::problems('4821', '4821'));
        $this->assertSame(['pin' => 'Use 5 digits.'], Pin::problems('482193', '482193'));
        $this->assertSame([], Pin::problems('48213', '48213'));

        $this->setSetting('pin_min_digits', '2');
        $this->setSetting('pin_max_digits', '9');
        $this->assertSame([4, 6], Pin::digitsRange(), 'clamped to 4..6 whatever is stored');
        $this->setSetting('pin_min_digits', '6');
        $this->setSetting('pin_max_digits', '4');
        $this->assertSame([6, 6], Pin::digitsRange(), 'the maximum is never below the minimum');

        $this->setSetting('pin_min_digits', '4');
        $this->setSetting('pin_max_digits', '6');
        $e = $this->assertRefused(422, 'pin_rules', fn() => $this->set('12a4'));
        $this->assertSame(['Use 4 to 6 digits.', ['errors' => ['pin' => 'Use 4 to 6 digits.']]], [$e->getMessage(), $e->extra]);
        $this->assertNull($this->pinHash($this->volunteer['user_id']));
    }

    public function testTrivialPinsAreRefused(): void
    {
        foreach (['0000', '1234', '4321', '0123', '9876', '123456', '654321', '111111', '99999'] as $pin) {
            $this->assertTrue(Pin::guessable($pin), $pin);
            $this->assertSame(['pin' => self::GUESSABLE], Pin::problems($pin, $pin), $pin);
        }
        foreach (['8901', '1243', '1123', '4821', '2468', '0987', '135790'] as $pin) {
            $this->assertFalse(Pin::guessable($pin), $pin);
            $this->assertSame([], Pin::problems($pin, $pin), $pin);
        }
        $e = $this->assertRefused(422, 'pin_rules', fn() => $this->set('1234'));
        $this->assertSame(['errors' => ['pin' => self::GUESSABLE]], $e->extra);
        $this->assertNull($this->pinHash($this->volunteer['user_id']));
    }

    public function testTheRepeatMustMatch(): void
    {
        $this->assertSame(['pin' => 'The two PINs are different.'], Pin::problems('4821', '4822'));
        $this->assertSame(['pin' => 'The two PINs are different.'], Pin::problems('4821', ''));
        $this->assertSame(['pin' => 'Use 4 to 6 digits.'], Pin::problems('12', '4821'), 'the digits first');
        $this->assertSame(['pin' => self::GUESSABLE], Pin::problems('1234', '4821'), 'then guessable, then the repeat');
        $e = $this->assertRefused(422, 'pin_rules', fn() => $this->set('4821', '4812'));
        $this->assertSame(['The two PINs are different.', ['errors' => ['pin' => 'The two PINs are different.']]], [$e->getMessage(), $e->extra]);
        $this->assertNull($this->pinHash($this->volunteer['user_id']));
    }

    public function testTheCurrentPasswordIsRequiredAndRateLimited(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        for ($i = 1; $i <= 5; $i++) {
            $e = $this->assertRefused(422, 'login_failed', fn() => $this->set('4821', null, 'Not-The-Password-1'), "attempt $i");
            $this->assertSame('That password is not correct.', $e->getMessage());
        }
        $rows = $this->auditRows('pin_set');
        $this->assertCount(5, $rows);
        $this->assertSame(['Failed', 'Wrong password', 'user_account', $uid, null], [$rows[0]['outcome'], $rows[0]['reason'], $rows[0]['entity_type'], (int) $rows[0]['entity_id'], $rows[0]['details']]);

        $e = $this->assertRefused(429, 'rate_limited', fn() => $this->set('4821'), 'the bucket counts before the password');
        $this->assertSame(['Retry-After' => '900'], $e->headers);
        $this->assertNull($this->pinHash($uid));
        $this->assertSame(6, (int) $this->scalar('SELECT hits FROM rate_limit_bucket WHERE bucket = ?', ["pin_set:user:$uid"]));

        Clock::advance('+15 minutes');
        $this->assertTrue($this->set('4821')['ok']);
        $this->assertNotNull($this->pinHash($uid));
    }

    // The hash ------------------------------------------------------------------------------------

    public function testThePinIsPepperedArgon2idNeverSha256(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $this->set('4821');
        $stored = (string) $this->pinHash($uid);
        $kid = (string) Config::get('crypto.active');
        $this->assertMatchesRegularExpression('/^p1\.' . preg_quote($kid, '/') . '\.\$argon2id\$/', $stored);
        $this->assertLessThanOrEqual(255, strlen($stored));
        $this->assertNotSame(hash('sha256', '4821'), $stored);
        $this->assertFalse(str_contains($stored, hash('sha256', '4821')), 'never Tokens::hash');
        $this->assertFalse(str_contains($stored, Tokens::hash('4821')));
        $argon = substr($stored, strlen("p1.$kid."));
        $this->assertFalse(password_verify('4821', $argon), 'not a plain Argon2id of the PIN: peppered first');
        $this->assertFalse(password_verify($uid . '|4821', $argon));
        $this->assertTrue(Pin::verify($uid, '4821', $stored));
        $this->assertFalse(Pin::verify($uid, '4822', $stored));
        $this->assertFalse(Pin::verify($this->person()['user_id'], '4821', $stored), "bound to the person's id");
        $this->assertFalse(Pin::verify($uid, '4821', $argon), 'another format');
        $this->assertFalse(Pin::verify($uid, '4821', hash('sha256', '4821')));
    }

    public function testVerifyUsesTheKeyIdInTheHash(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $saved = Config::snapshot();
        $crypto = (array) ($saved['crypto'] ?? []);
        $kid = (string) ($crypto['active'] ?? '');
        $keys = (array) ($crypto['keys'] ?? []);
        $first = Pin::hash($uid, '4821');
        $this->assertStringStartsWith("p1.$kid.", $first);

        Config::override(['crypto' => ['active' => 'pin2', 'keys' => $keys + ['pin2' => base64_encode(random_bytes(32))]] + $crypto] + $saved);
        $this->assertTrue(Pin::verify($uid, '4821', $first), 'the old key id is still in the ring');
        $second = Pin::hash($uid, '4821');
        $this->assertStringStartsWith('p1.pin2.', $second);
        $this->assertTrue(Pin::verify($uid, '4821', $second));

        $without = $keys + ['pin2' => base64_encode(random_bytes(32))];
        unset($without[$kid]);
        Config::override(['crypto' => ['active' => 'pin2', 'keys' => $without] + $crypto] + $saved);
        $log = APP_ROOT . '/storage/logs/app-' . gmdate('Y-m') . '.log';
        clearstatcache();
        $before = is_file($log) ? (int) filesize($log) : 0;
        $this->assertFalse(Pin::verify($uid, '4821', $first), 'its key id is gone: the person sets the PIN again');
        clearstatcache();
        $this->assertStringContainsString("Encryption key '$kid' is not configured", (string) file_get_contents($log, false, null, $before), 'logged');
        $this->assertFalse(Pin::verify($uid, '4821', 'p1..' . substr($first, strlen("p1.$kid."))), 'a missing key id fails closed');
        Config::override($saved);
    }

    // Grants and the audit row -------------------------------------------------------------------

    public function testChangingAPinRevokesGrantsOnOtherTabletsOnly(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $this->set('4821');
        $here = $this->stationGrant($this->volunteer, $this->tablet);
        $there = $this->stationGrant($this->volunteer, $this->other);
        $someoneElse = $this->stationGrant($this->person(), $this->other);
        Clock::advance('+1 minute');

        $answer = $this->set('8901');
        $this->assertSame(['ok' => true, 'revoked_grants' => [], 'server_time' => '2026-10-01 12:01:00.000'], $answer, "this tablet's grants stay: it stores the new verifier");
        $this->assertNull($this->revokedAt($here['grant_id']));
        $this->assertSame('2026-10-01 12:01:00', $this->revokedAt($there['grant_id']), 'the verifier there is now stale');
        $this->assertNull($this->revokedAt($someoneElse['grant_id']));
        $this->assertSame([$there['grant_id']], Tokens::revokedGrantIds($this->other['id']));
        $this->assertTrue(Pin::verify($uid, '8901', (string) $this->pinHash($uid)));
        $this->assertFalse(Pin::verify($uid, '4821', (string) $this->pinHash($uid)));

        $revokes = $this->auditRows('offline_grant_revoke');
        $this->assertCount(1, $revokes);
        $this->assertSame(['cause' => 'pin_change', 'count' => 1], $revokes[0]['details']);
        $this->assertSame([false, true], array_map(fn(array $r) => $r['details']['changed'], $this->auditRows('pin_set')));

        $this->assertSame([$there['grant_id']], $this->set('2468', null, self::PASSWORD, null, $this->other)['revoked_grants'],
            'a change on the other tablet lists its own revoked grants (and revokes the grant here)');
        $this->assertNotNull($this->revokedAt($here['grant_id']));
    }

    public function testSettingTheFirstPinRevokesNothing(): void
    {
        $here = $this->stationGrant($this->volunteer, $this->tablet);
        $there = $this->stationGrant($this->volunteer, $this->other);
        $this->assertSame([], $this->set('482193')['revoked_grants']);
        $this->assertSame([null, null], [$this->revokedAt($here['grant_id']), $this->revokedAt($there['grant_id'])]);
        $this->assertSame([], $this->auditRows('offline_grant_revoke'));
        $this->assertSame(['changed' => false, 'digits' => 6], $this->auditRows('pin_set')[0]['details']);
        $this->assertNotNull(OfflineGrants::pinWindow((int) $this->volunteer['user_id'], $this->other['id'], 12), 'the window elsewhere stays open');
    }

    public function testTheAuditRowCarriesNoPin(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $this->refusal(fn() => $this->set('4821', null, 'Not-The-Password-1'));
        $this->set('4821');
        $rows = $this->auditRows('pin_set');
        $this->assertSame(['Failed', 'Success'], array_column($rows, 'outcome'));
        $this->assertNull($rows[0]['details']);
        $this->assertSame(['user_account', $uid, null, ['changed' => false, 'digits' => 4]],
            [$rows[1]['entity_type'], (int) $rows[1]['entity_id'], $rows[1]['reason'], $rows[1]['details']]);

        $stored = (string) $this->pinHash($uid);
        $haystack = implode("\n", array_merge(
            $this->column("SELECT CONCAT_WS('|', action, reason, details, snapshot) FROM audit_log WHERE audit_id > ?"),
            $this->column("SELECT CONCAT_WS('|', field_name, old_value, new_value) FROM audit_field_change WHERE audit_id > ?"),
        ));
        foreach (['4821', $stored, substr($stored, -20), self::PASSWORD, 'Not-The-Password-1', '[redacted]'] as $secret) {
            $this->assertFalse(str_contains($haystack, $secret), "the audit trail never holds $secret's kind");
        }
    }

    public function testASetResetsTheOnlinePinCounter(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        Db::pdo()->prepare('UPDATE user_account SET pin_failed_count = 2 WHERE user_id = ?')->execute([$uid]);
        $this->set('4821');
        $this->assertSame(0, (int) $this->scalar('SELECT pin_failed_count FROM user_account WHERE user_id = ?', [$uid]));
    }

    // The endpoint and the transaction -----------------------------------------------------------

    public function testTheEndpointNeedsASessionOfThisTabletAndTheCapability(): void
    {
        $body = ['password' => self::PASSWORD, 'pin' => '4821', 'pin_confirm' => '4821'];
        $this->stationRequest($this->tablet, 'api/auth/pin_set.php', $body);
        $this->stationSession();
        $this->assertRefused(401, 'not_signed_in', fn() => Api::start(self::OPTIONS), 'nobody signed in');

        $this->stationRequest($this->tablet, 'api/auth/pin_set.php', $body);
        $this->stationSignedIn($this->volunteer, $this->other);
        $this->assertRefused(401, 'session_ended', fn() => Api::start(self::OPTIONS), "another tablet's session");

        $board = $this->person(['role' => 'Board']);
        $this->stationRequest($this->tablet, 'api/auth/pin_set.php', $body);
        $this->stationSignedIn($board, $this->tablet);
        $this->assertRefused(403, 'forbidden', fn() => Api::start(self::OPTIONS), 'Board does not hold auth.pin_switch');

        $this->stationRequest($this->tablet, 'api/auth/pin_set.php', $body);
        $sid = $this->stationSignedIn($this->volunteer, $this->tablet);
        $ctx = Api::start(self::OPTIONS);
        $this->assertNotNull($ctx);
        $this->assertSame([(int) $this->volunteer['user_id'], $sid, $this->tablet['id']], [$ctx->userId(), $ctx->sessionId, $ctx->deviceId]);
        $this->assertTrue(Pin::set($ctx, Api::device(), self::PASSWORD, '4821', '4821')['ok']);
    }

    public function testAPinSetOnATabletRetiredDuringTheRequestIsRefused(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $this->set('4821');
        $before = $this->pinHash($uid);
        $ctx = $this->ctx();
        $guard = $this->stationDevice($this->tablet); // the guard read it in service
        Db::pdo()->prepare("UPDATE device SET revoked_at = ?, revoked_by = ?, wipe_mode = 'Push Then Wipe' WHERE device_id = ?")
            ->execute([Clock::db(), $uid, $this->tablet['id']]);
        $e = $this->assertRefused(403, 'device_revoked', fn() => Pin::set($ctx, $guard, self::PASSWORD, '8901', '8901'));
        $this->assertSame(['directive' => ['wipe' => 'Push Then Wipe']], $e->extra);
        $this->assertSame($before, $this->pinHash($uid), 'the PIN is unchanged');
        $this->assertCount(1, array_filter($this->auditRows('pin_set'), fn(array $r) => $r['outcome'] === 'Success'), 'only the first set');
    }

    public function testThePinSetTransactionStartsWithTheTablet(): void
    {
        $ctx = $this->ctx();
        $device = $this->stationDevice($this->tablet);
        $log = QueryLog::during(fn() => Pin::set($ctx, $device, self::PASSWORD, '4821', '4821'));
        $password = QueryLog::first($log, self::PASSWORD_READ);
        $lock = QueryLog::first($log, '/FROM device d WHERE d\.device_id = \? LOCK IN SHARE MODE$/');
        $old = QueryLog::first($log, "/, password_hash, pin_hash FROM user_account WHERE user_id = \\? AND username <> 'system' FOR UPDATE$/");
        $session = QueryLog::first($log, '/^SELECT session_id, user_id, device_id, auth_method, started_at, ended_at FROM user_session WHERE session_id = \?$/');
        $update = QueryLog::first($log, '/^UPDATE user_account SET pin_hash = \?/');
        $this->assertNotNull($password);
        $this->assertNotNull($lock);
        $this->assertNotNull($old);
        $this->assertNotNull($update);
        $this->assertTrue($password < $lock && $lock < $old && $old < $update, 'the password outside; then device S, then the account: ' . json_encode($log));
        $this->assertSame([$lock + 1, $lock + 2, $lock + 3], [$old, $session, $update],
            'device S, the account X, the session (the first plain read: it sees a reset committed meanwhile), then the PIN');
    }

    public function testAResetCommittedDuringThePinSetWins(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $admin = $this->person(['role' => 'Administrator']);
        $this->set('4821'); // the PIN the reset clears
        $ctx = $this->ctx();
        $device = $this->stationDevice($this->tablet);
        // The Administrator's reset commits after the request read the password, while it verifies it and hashes the new PIN.
        $e = $this->assertRefused(401, 'session_ended', fn() => QueryLog::after(self::PASSWORD_READ,
            fn() => AccountService::sendReset($uid, (int) $admin['user_id']), fn() => Pin::set($ctx, $device, self::PASSWORD, '8901', '8901')));
        $this->assertSame('Your session has ended. Please sign in again.', $e->getMessage());
        $this->assertNull($this->pinHash($uid), 'the PIN the reset cleared stays cleared: no PIN chosen by the session it ended');
        $this->assertSame('Password Reset', $this->scalar('SELECT end_reason FROM user_session WHERE session_id = ?', [$ctx->sessionId]));
        $this->assertCount(1, array_filter($this->auditRows('pin_set'), fn(array $r) => $r['outcome'] === 'Success'), 'only the first set');
    }

    public function testAPinSetAfterThePasswordChangedIsRefused(): void
    {
        $uid = (int) $this->volunteer['user_id'];
        $ctx = $this->ctx();
        $device = $this->stationDevice($this->tablet);
        $other = PasswordPolicy::hash('Meadow-Copper-Sparrow-17');
        $this->assertRefused(401, 'session_ended', fn() => QueryLog::after(self::PASSWORD_READ, static function () use ($uid, $other): void {
            Db::pdo()->prepare('UPDATE user_account SET password_hash = ? WHERE user_id = ?')->execute([$other, $uid]); // the session stays open
        }, fn() => Pin::set($ctx, $device, self::PASSWORD, '8901', '8901')), 'the password verified is no longer the password');
        $this->assertNull($this->pinHash($uid));
    }
}
