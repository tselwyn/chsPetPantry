<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Station;

use Pfpms\Account\AccountRepository;
use Pfpms\Account\AccountService;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Policy;
use Pfpms\Auth\SessionStore;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\Api;
use Pfpms\Http\Context;
use Pfpms\Security\Crypto;
use Pfpms\Station\OfflineGrants;
use Pfpms\Station\StationAuth;
use Pfpms\Station\StationGate;
use Pfpms\Tests\Support\QueryLog;
use Pfpms\Tests\Support\StationFixture;
use Pfpms\Tests\TestCase;

/**
 * The in-app confidentiality agreement (50-design §6.10, D-54; S3 spec §2.7, §2.8): the exact wording, acceptance releasing
 * the grant held back at sign-in (and only then), decline, the proof, the order of the gates, and the access re-check.
 */
final class StationPolicyTest extends TestCase
{
    use StationFixture;

    private const PASSWORD = 'Correct-Horse-Battery-9';
    private const IP = '203.0.113.30';
    private const CHANGED = 'The agreement was updated while you were reading it. Please read the current version.';
    /** api/auth/policy.php's options (S3 spec §2.8). */
    private const POLICY = ['method' => ['GET', 'POST'], 'device' => 'in_service', 'proof' => true, 'policy_ack' => true];

    private static ?string $passwordHash = null;

    private int $north;
    private array $tablet;
    /** The agreement in force, which nobody has accepted yet. */
    private array $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stationSetUp();
        $this->north = $this->makeSite('Northside');
        $this->tablet = $this->stationTablet($this->north);
        $this->doc = $this->agreement();
    }

    protected function tearDown(): void
    {
        $this->stationTearDown();
        parent::tearDown();
    }

    // Helpers -------------------------------------------------------------------------------

    private function person(array $overrides = [], ?array $sites = null): array
    {
        self::$passwordHash ??= PasswordPolicy::hash(self::PASSWORD);
        $user = $this->makeUser($overrides + ['password_hash' => self::$passwordHash]);
        foreach ($sites ?? [$this->north] as $siteId) {
            $this->grantSite($user['user_id'], $siteId);
        }
        $row = AccountRepository::find($user['user_id']);
        $this->assertNotNull($row);
        return $row;
    }

    /** The Context of a session on the tablet, as Api::start() builds it for the Station. */
    private function ctxFor(array $user, string $sid, ?array $tablet = null, string $method = 'Password'): Context
    {
        $tablet ??= $this->tablet;
        $row = AccountRepository::find((int) $user['user_id']);
        $this->assertNotNull($row);
        return new Context($row, $sid, $this->north, [], $tablet['id'], $method, $this->stationDevice($tablet));
    }

    /**
     * A password sign-in held at the agreement gate (no grant, no vault key).
     * @return array{user: array, sid: string, ctx: Context}
     */
    private function gated(?array $user = null, ?array $tablet = null): array
    {
        $user ??= $this->person();
        $tablet ??= $this->tablet;
        $r = StationAuth::login((string) $user['username'], self::PASSWORD, self::IP, $this->stationDevice($tablet));
        $this->assertSame(['policy_ack', null], [$r['body']['gate'], $r['body']['release']]);
        return ['user' => $user, 'sid' => $r['session_id'], 'ctx' => $this->ctxFor($user, $r['session_id'], $tablet)];
    }

    /** Api::start(<policy options>) for the session, as policy.php starts (POST with the acceptance's body, or GET). */
    private function apiCtx(array $user, string $sid, string $method = 'POST', ?array $tablet = null): Context
    {
        $tablet ??= $this->tablet;
        $body = $method === 'GET' ? null : ['document_id' => (int) $this->doc['document_id'], 'fingerprint' => Policy::fingerprint($this->doc), 'decision' => 'accept'];
        $this->stationRequest($tablet, 'api/auth/policy.php', $body, $method);
        $this->stationSession(['uid' => (int) $user['user_id'], 'sid' => $sid, 'site_id' => $this->north]);
        $ctx = Api::start(self::POLICY);
        $this->assertNotNull($ctx);
        $this->assertSame($sid, $ctx->sessionId);
        return $ctx;
    }

    private function decide(Context $ctx, string $decision = 'accept', ?array $doc = null, ?array $tablet = null): array
    {
        $doc ??= $this->doc;
        return StationAuth::decidePolicy($ctx, $this->stationDevice($tablet ?? $this->tablet), (int) $doc['document_id'], Policy::fingerprint($doc), $decision);
    }

    private function acks(array $user): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM policy_acknowledgement WHERE user_id = ?', [$user['user_id']]);
    }

    /** Nothing was accepted or released for $user. */
    private function assertNothingWritten(array $user, string $message = ''): void
    {
        $this->assertSame(0, $this->acks($user), "no acknowledgement row $message");
        $this->assertSame([], $this->grantsOf((int) $user['user_id']), "no Offline Grant row $message");
        $this->assertSame([], $this->auditRows('offline_grant_issue'), "no grant issued $message");
        $this->assertSame([], $this->auditRows('policy_acknowledge'), $message);
    }

    /** The endpoint's own Api::start([...]) option text equals $options (so the tests below test the endpoint's guard). */
    private function assertEndpointOptions(string $endpoint, array $options): void
    {
        $code = (string) file_get_contents(APP_ROOT . '/public/' . $endpoint);
        $this->assertSame(1, preg_match('~Api::start\((\[[^;]*\])\);~', $code, $m), "$endpoint calls Api::start([...])");
        $this->assertSame(self::literal($options), (string) preg_replace('/\s+/', ' ', trim($m[1])), "$endpoint: Api::start's options");
    }

    private static function literal(mixed $value): string
    {
        if (!is_array($value)) {
            return var_export($value, true);
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = (array_is_list($value) ? '' : var_export($key, true) . ' => ') . self::literal($item);
        }
        return '[' . implode(', ', $parts) . ']';
    }

    // The document ----------------------------------------------------------------------------

    public function testTheDocumentIsShownWithItsFingerprint(): void
    {
        ['user' => $user, 'sid' => $sid, 'ctx' => $ctx] = $this->gated();
        $shown = StationAuth::policyDocument($ctx);
        $this->assertSame(['required' => true, 'document' => [
            'document_id' => (int) $this->doc['document_id'],
            'version' => '1',
            'language' => 'en',
            'body' => (string) $this->doc['body'],
            'fingerprint' => Policy::fingerprint($this->doc),
        ], 'server_time' => '2026-10-01 12:00:00.000'], $shown);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $shown['document']['fingerprint']);

        $this->assertEndpointOptions('api/auth/policy.php', self::POLICY);
        $apiCtx = $this->apiCtx($user, $sid, 'GET'); // at the gate the GET is allowed, its proof signing the empty body
        $this->assertSame($shown, StationAuth::policyDocument($apiCtx));

        Policy::acknowledge((int) $user['user_id'], (int) $this->doc['document_id']);
        $this->assertFalse(StationAuth::policyDocument($ctx)['required']);
    }

    public function testOnlyTheExactWordingIsAccepted(): void
    {
        ['user' => $user, 'sid' => $sid, 'ctx' => $ctx] = $this->gated();
        $device = $this->stationDevice($this->tablet);
        $id = (int) $this->doc['document_id'];
        $fingerprint = Policy::fingerprint($this->doc);
        $attempts = [
            'another fingerprint' => [$id, str_repeat('0', 64), 'accept'],
            'the fingerprint in capitals' => [$id, strtoupper($fingerprint), 'accept'],
            'no fingerprint' => [$id, '', 'accept'],
            'another document' => [$id + 1000, $fingerprint, 'accept'],
            'no document' => [null, $fingerprint, 'accept'],
            'a decline of another text' => [$id, str_repeat('a', 64), 'decline'],
        ];
        foreach ($attempts as $name => [$documentId, $sent, $decision]) {
            $e = $this->assertRefused(409, 'policy_changed', fn() => StationAuth::decidePolicy($ctx, $device, $documentId, $sent, $decision), $name);
            $this->assertSame(self::CHANGED, $e->getMessage());
        }

        $v2 = $this->agreement(null, '2'); // a newer version published while the person was reading
        $this->assertRefused(409, 'policy_changed', fn() => StationAuth::decidePolicy($ctx, $device, $id, $fingerprint, 'accept'), 'the version read is no longer current');
        $this->assertNothingWritten($user);
        $this->assertNull($this->sessionRow($sid)['ended_at']);

        $r = $this->decide($ctx, 'accept', $v2);
        $this->assertNotNull($r['body']['release'], 'the current wording is accepted');
    }

    // Accept ------------------------------------------------------------------------------------

    public function testAcceptanceReleasesTheGrant(): void
    {
        ['user' => $user, 'ctx' => $ctx] = $this->gated();
        $r = $this->decide($ctx);
        $this->assertFalse($r['signed_out']);
        $body = $r['body'];
        $this->assertSame(['ok', 'gate', 'release', 'release_unavailable', 'revoked_grants', 'server_time'], array_keys($body));
        $this->assertSame([true, null, null, [], '2026-10-01 12:00:00.000'],
            [$body['ok'], $body['gate'], $body['release_unavailable'], $body['revoked_grants'], $body['server_time']]);
        $this->assertNotNull($body['release']);
        $grants = $this->grantsOf((int) $user['user_id']);
        $this->assertCount(1, $grants);
        $this->assertSame((int) $grants[0]['token_id'], $body['release']['grant_id']);
        $this->assertSame($this->tablet['dvk'], Crypto::unb64urlStrict($body['release']['dvk'], 32));

        $this->assertSame(1, $this->acks($user));
        $this->assertSame((int) $this->doc['document_id'], (int) $this->scalar('SELECT document_id FROM policy_acknowledgement WHERE user_id = ?', [$user['user_id']]));
        $rows = $this->auditRows('policy_acknowledge');
        $this->assertCount(1, $rows);
        $this->assertSame(['Success', 'policy_document', (int) $this->doc['document_id']], [$rows[0]['outcome'], $rows[0]['entity_type'], (int) $rows[0]['entity_id']]);
        $this->assertSame(['doc_type' => Policy::CONFIDENTIALITY, 'language' => 'en', 'source' => 'station', 'version' => '1'], $rows[0]['details']);
        $this->assertFalse(Policy::acknowledgementRequired($user));
        $this->assertCount(1, $this->auditRows('offline_grant_issue'));
    }

    public function testAcceptingAnAgreementAlreadyAcceptedReleasesOnly(): void
    {
        ['user' => $user, 'ctx' => $ctx] = $this->gated();
        Policy::acknowledge((int) $user['user_id'], (int) $this->doc['document_id']); // accepted on the web meanwhile
        $r = $this->decide($ctx);
        $this->assertNotNull($r['body']['release'], 'the gated sign-in still gets its release');
        $this->assertSame(1, $this->acks($user), 'no second acknowledgement row');
        $this->assertSame([], $this->auditRows('policy_acknowledge'));
    }

    public function testAnAcceptanceFromAPinSessionReleasesNothing(): void
    {
        $user = $this->person();
        Clock::freeze('2026-10-01 11:00:00');
        $grant = $this->stationGrant($user, $this->tablet); // the password sign-in that opened the PIN window
        Clock::freeze('2026-10-01 11:40:00');
        $sid = SessionStore::create((int) $user['user_id'], $this->north, 'PIN', $this->tablet['id']); // a PIN switch since: no grant after it
        Clock::freeze(self::NOW);
        $this->assertFalse(OfflineGrants::issuedSince((int) $user['user_id'], $this->tablet['id'], '2026-10-01 11:40:00'), 'only the PIN rule refuses here');
        $r = $this->decide($this->ctxFor($user, $sid, null, 'PIN'));
        $this->assertSame(['ok' => true, 'gate' => null, 'release' => null, 'release_unavailable' => 'password_needed', 'revoked_grants' => [],
            'server_time' => '2026-10-01 12:00:00.000'], $r['body']);
        $this->assertCount(1, $this->grantsOf((int) $user['user_id']), 'no grant minted');
        $this->assertSame($grant['grant_id'], OfflineGrants::pinWindow((int) $user['user_id'], $this->tablet['id'], 12)['token_id'] ?? null,
            'the PIN window is still the password sign-in\'s');
        $this->assertSame(1, $this->acks($user), 'the acceptance itself is recorded');
    }

    public function testAnAcceptanceAfterAReleasedSignInReleasesNothing(): void
    {
        $user = $this->person();
        Policy::acknowledge((int) $user['user_id'], (int) $this->doc['document_id']);
        $r = StationAuth::login((string) $user['username'], self::PASSWORD, self::IP, $this->stationDevice($this->tablet));
        $this->assertNotNull($r['body']['release'], 'released at sign-in');
        $answer = $this->decide($this->ctxFor($user, $r['session_id']))['body'];
        $this->assertSame([null, 'password_needed'], [$answer['release'], $answer['release_unavailable']]);
        $this->assertCount(1, $this->grantsOf((int) $user['user_id']));
        $this->assertSame(1, $this->acks($user));

        ['user' => $gatedUser, 'ctx' => $ctx] = $this->gated(); // a gate released once cannot be released again
        $this->assertNotNull($this->decide($ctx)['body']['release']);
        $again = $this->decide($ctx)['body'];
        $this->assertSame([null, 'password_needed'], [$again['release'], $again['release_unavailable']]);
        $this->assertCount(1, $this->grantsOf((int) $gatedUser['user_id']));
    }

    public function testAnAcceptanceLongAfterTheSignInReleasesNothing(): void
    {
        $this->assertSame(21, StationAuth::GATE_RELEASE_MINUTES);
        ['ctx' => $first] = $this->gated();
        Clock::advance('+21 minutes');
        $this->assertNotNull($this->decide($first)['body']['release'], '21 minutes after the sign-in, the limit itself');

        $other = $this->stationTablet($this->north);
        ['user' => $late, 'ctx' => $ctx] = $this->gated(null, $other);
        Clock::advance('+21 minutes +1 second');
        $body = $this->decide($ctx, 'accept', null, $other)['body'];
        $this->assertSame([null, null, 'password_needed'], [$body['gate'], $body['release'], $body['release_unavailable']],
            'after it: the tablet itself gave up (10 minutes at a gate, 10 more after a password change), so nothing is released');
        $this->assertSame([], $this->grantsOf((int) $late['user_id']));
        $this->assertSame(1, $this->acks($late), 'the acceptance is still recorded');
    }

    public function testTheReleaseWindowIsTheTabletsOwnGateBound(): void
    {
        // session.js keeps the KEK GATE_KEK_MS at a gate, and once GATE_KEK_MS more after a forced password change: the server
        // releases to an acceptance no later than that, with at most one minute for the requests themselves.
        $js = (string) file_get_contents(APP_ROOT . '/public/station/js/session.js');
        $this->assertSame(1, preg_match('/^export const GATE_KEK_MS = (\d+);/m', $js, $m), 'session.js exports GATE_KEK_MS');
        $bound = 2 * (int) $m[1];
        $window = StationAuth::GATE_RELEASE_MINUTES * 60000;
        $this->assertGreaterThanOrEqual($bound, $window, 'a genuine acceptance at the last moment is still released');
        $this->assertLessThanOrEqual($bound + 60000, $window, 'no longer than the tablet keeps the gate open, plus a minute');
    }

    // Decline -----------------------------------------------------------------------------------

    public function testDeclineEndsTheSessionAndReleasesNothing(): void
    {
        ['user' => $user, 'sid' => $sid, 'ctx' => $ctx] = $this->gated();
        Clock::advance('+1 minute');
        $r = $this->decide($ctx, 'decline');
        $this->assertSame(['signed_out' => true, 'body' => ['ok' => true, 'signed_out' => true, 'server_time' => '2026-10-01 12:01:00.000']], $r);
        $row = $this->sessionRow($sid);
        $this->assertSame(['2026-10-01 12:01:00', 'Logout', (int) $user['user_id']], [$row['ended_at'], $row['end_reason'], (int) $row['ended_by']]);
        $rows = $this->auditRows('policy_decline');
        $this->assertCount(1, $rows);
        $this->assertSame(['Denied', 'Declined the confidentiality agreement', 'policy_document', (int) $this->doc['document_id'], ['source' => 'station']],
            [$rows[0]['outcome'], $rows[0]['reason'], $rows[0]['entity_type'], (int) $rows[0]['entity_id'], $rows[0]['details']]);
        $this->assertNothingWritten($user);
        $this->assertTrue(Policy::acknowledgementRequired($user));
    }

    public function testADeclineLocksTheTabletThenTheAccountBeforeEndingTheSession(): void
    {
        ['ctx' => $ctx] = $this->gated();
        $log = QueryLog::during(fn() => $this->decide($ctx, 'decline'));
        $device = QueryLog::first($log, '/FROM device d WHERE d\.device_id = \? LOCK IN SHARE MODE$/');
        $account = QueryLog::first($log, "/FROM user_account WHERE user_id = \\? AND username <> 'system' LOCK IN SHARE MODE$/");
        $audit = QueryLog::first($log, '/^INSERT INTO audit_log\b/');
        $session = QueryLog::first($log, '/^UPDATE user_session SET ended_at\b/');
        $this->assertNotNull($device, json_encode($log));
        $this->assertNotNull($account);
        $this->assertNotNull($audit);
        $this->assertNotNull($session);
        $this->assertTrue($device < $account && $account < $audit && $account < $session, 'device S → account S → audit, session: ' . json_encode($log));
    }

    public function testAnUnknownDecisionIs400(): void
    {
        ['user' => $user, 'sid' => $sid, 'ctx' => $ctx] = $this->gated();
        foreach (['', 'maybe', 'ACCEPT', 'accept '] as $decision) {
            $e = $this->assertRefused(400, 'bad_request', fn() => $this->decide($ctx, $decision), json_encode($decision));
            $this->assertSame(['Unknown decision.', ['field' => 'decision']], [$e->getMessage(), $e->extra]);
        }
        $this->assertNothingWritten($user);
        $this->assertNull($this->sessionRow($sid)['ended_at']);
    }

    // The guard and the gates -------------------------------------------------------------------

    public function testTheProofIsRequired(): void
    {
        $this->assertEndpointOptions('api/auth/policy.php', self::POLICY);
        ['user' => $user, 'sid' => $sid] = $this->gated();
        $ids = ['uid' => (int) $user['user_id'], 'sid' => $sid, 'site_id' => $this->north];
        $accept = ['document_id' => (int) $this->doc['document_id'], 'fingerprint' => Policy::fingerprint($this->doc), 'decision' => 'accept'];
        foreach (['GET' => null, 'POST' => $accept] as $method => $body) {
            $this->stationRequest($this->tablet, 'api/auth/policy.php', $body, $method, false);
            $this->stationSession($ids);
            $this->assertRefused(401, 'device_proof_invalid', fn() => Api::start(self::POLICY), "$method without a proof");
            $this->apiCtx($user, $sid, $method); // signed: passes
        }
    }

    public function testAPendingPasswordChangeComesFirst(): void
    {
        $user = $this->person(['must_change_password' => 1]);
        $r = StationAuth::login((string) $user['username'], self::PASSWORD, self::IP, $this->stationDevice($this->tablet));
        $this->assertSame('password_change', $r['body']['gate']);
        $ids = ['uid' => (int) $user['user_id'], 'sid' => $r['session_id'], 'site_id' => $this->north];
        $accept = ['document_id' => (int) $this->doc['document_id'], 'fingerprint' => Policy::fingerprint($this->doc), 'decision' => 'accept'];
        foreach (['GET' => null, 'POST' => $accept] as $method => $body) {
            $this->stationRequest($this->tablet, 'api/auth/policy.php', $body, $method);
            $this->stationSession($ids);
            $e = $this->assertRefused(403, 'password_change_required', fn() => Api::start(self::POLICY), $method);
            $this->assertSame('You must choose a new password first.', $e->getMessage());
        }
        $this->assertNothingWritten($user);
    }

    public function testAnAccessChangeBeforeTheAcceptanceReleasesNothing(): void
    {
        $south = $this->makeSite('Southside');
        $admin = $this->person(['role' => 'Administrator']);
        $user = $this->person([], [$this->north, $south]);
        ['sid' => $sid] = $this->gated($user);
        $ctx = $this->apiCtx($user, $sid); // the request's own checks pass

        // The site grant ends after the request's own check: release()'s re-check sees it.
        Db::pdo()->prepare('UPDATE user_site_access SET ends_at = ? WHERE user_id = ? AND site_id = ?')->execute(['2026-10-01 11:59:59', $user['user_id'], $this->north]);
        $e = $this->assertRefused(403, 'no_station_access', fn() => $this->decide($ctx));
        $this->assertSame(["You don't have access to Northside, where this tablet is used.", ['reason' => 'site']], [$e->getMessage(), $e->extra]);
        $this->assertNothingWritten($user, '(site)');
        $this->grantSite($user['user_id'], $this->north);

        // The role changes to one without the Station.
        Db::pdo()->prepare("UPDATE user_account SET role = 'Board' WHERE user_id = ?")->execute([$user['user_id']]);
        $e = $this->assertRefused(403, 'no_station_access', fn() => $this->decide($ctx));
        $this->assertSame([StationGate::ROLE, ['reason' => 'role']], [$e->getMessage(), $e->extra]);
        $this->assertNothingWritten($user, '(role)');
        Db::pdo()->prepare("UPDATE user_account SET role = 'Volunteer' WHERE user_id = ?")->execute([$user['user_id']]);

        // An Administrator removes the tablet's site, which ends the person's sessions.
        $account = AccountRepository::find((int) $user['user_id']);
        $this->assertNotNull($account);
        AccountService::update((int) $user['user_id'], ['reason' => 'Moved to Southside'] + array_intersect_key($account, array_flip(AccountService::DETAIL_FIELDS)),
            [$south], (int) $account['row_version'], (int) $admin['user_id']);
        $this->assertSame('Permission Change', $this->sessionRow($sid)['end_reason']);
        $e = $this->assertRefused(401, 'session_ended', fn() => $this->decide($ctx));
        $this->assertSame('Your session has ended. Please sign in again.', $e->getMessage());
        $this->assertNothingWritten($user, '(sessions ended)');
    }

    // The tablet and its site, re-checked in the transaction -----------------------------------

    public function testATabletRetiredAfterTheGuardReadReleasesNothing(): void
    {
        ['user' => $user, 'sid' => $sid, 'ctx' => $ctx] = $this->gated();
        $guard = $this->stationDevice($this->tablet); // the guard read it in service
        $admin = $this->person(['role' => 'Administrator']);
        Db::pdo()->prepare("UPDATE device SET revoked_at = ?, revoked_by = ?, wipe_mode = 'Push Then Wipe' WHERE device_id = ?")
            ->execute([Clock::db(), $admin['user_id'], $this->tablet['id']]);
        $e = $this->assertRefused(403, 'device_revoked', fn() => StationAuth::decidePolicy($ctx, $guard, (int) $this->doc['document_id'],
            Policy::fingerprint($this->doc), 'accept'), 'the acceptance transaction starts with lockDevice()');
        $this->assertSame(['This tablet has been taken out of service.', ['directive' => ['wipe' => 'Push Then Wipe']]], [$e->getMessage(), $e->extra]);
        $this->assertNothingWritten($user, '(retired)');
        $this->assertNull($this->sessionRow($sid)['ended_at']);
    }

    public function testASiteDeactivatedAfterTheGuardReadReleasesNothing(): void
    {
        ['user' => $user, 'sid' => $sid, 'ctx' => $ctx] = $this->gated();
        $guard = $this->stationDevice($this->tablet); // the guard read the site active
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$this->north]);
        $e = $this->assertRefused(403, 'device_site_inactive', fn() => StationAuth::decidePolicy($ctx, $guard, (int) $this->doc['document_id'],
            Policy::fingerprint($this->doc), 'accept'), 'requireSiteActive(), before the acknowledgement and release()');
        $this->assertSame(['directive' => null], $e->extra);
        $this->assertNothingWritten($user, '(site inactive)');
        $this->assertNull($this->sessionRow($sid)['ended_at']);
    }
}
