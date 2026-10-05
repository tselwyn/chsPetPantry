<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Pfpms\Config;
use Pfpms\Http\Api;
use Pfpms\Http\HttpException;

/**
 * Whose session a Station request has (the pure Api::contextFor(), steps 8-9 of 50-design §5.1), the 401 code of each
 * ended reason, and the Api::start() refusals that come before any session (maintenance, method).
 */
final class ApiGuardTest extends TestCase
{
    private const USER = ['user_id' => 12, 'username' => 'jdoe', 'role' => 'Volunteer'];

    private array $server;
    private array $saved;
    /** @var list<array{0: array, 1: int}> the calls canUseSite() received */
    private array $siteChecks = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->saved = Config::snapshot();
        $this->siteChecks = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        Config::override($this->saved);
    }

    // Helpers -------------------------------------------------------------------------------

    /** What Page::session() returns for a live session. */
    private static function checked(int|string|null $deviceId, int|string|null $siteId = 3): array
    {
        return ['user' => self::USER, 'session' => ['session_id' => str_repeat('ab', 32), 'user_id' => 12, 'device_id' => $deviceId,
            'site_id' => $siteId, 'auth_method' => 'Password']];
    }

    /** A tablet as DeviceGuard::authenticate() returns it. */
    private static function device(int $id = 7, int $siteId = 3): array
    {
        return ['device_id' => $id, 'site_id' => $siteId, 'label' => 'Front desk 3', 'in_service' => 1, 'site_active' => 1, 'proof' => 'valid'];
    }

    private function canUseSite(bool $answer): callable
    {
        return function (array $user, int $siteId) use ($answer): bool {
            $this->siteChecks[] = [$user, $siteId];
            return $answer;
        };
    }

    private function maintenance(bool $on): void
    {
        $app = $this->saved['app'] ?? [];
        $app['maintenance'] = $on;
        Config::override(['app' => $app] + $this->saved);
    }

    private function refusal(array $options): HttpException
    {
        try {
            Api::start($options);
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('expected Api::start() to refuse');
    }

    // contextFor ------------------------------------------------------------------------------

    public function testAnotherTabletsSessionIsAbsent(): void
    {
        $decision = Api::contextFor(self::checked(8), null, self::device(7), $this->canUseSite(true));
        $this->assertSame(['kind' => 'absent', 'ended' => 'ended', 'end_session' => false], $decision,
            'another tablet\'s session is left alone, never ended from here');
        $this->assertSame([], $this->siteChecks, 'no site is looked at');
    }

    public function testAWebSessionWithATabletIsAbsent(): void
    {
        $decision = Api::contextFor(self::checked(null), null, self::device(7), $this->canUseSite(true));
        $this->assertSame(['kind' => 'absent', 'ended' => 'ended', 'end_session' => false], $decision);
        $this->assertSame([], $this->siteChecks);
    }

    public function testALapsedSiteEndsTheSessionAndIsNeverRePicked(): void
    {
        $decision = Api::contextFor(self::checked(7, 3), null, self::device(7), $this->canUseSite(false));
        $this->assertSame(['kind' => 'absent', 'ended' => 'ended', 'end_session' => true], $decision, 'no other site is chosen instead');
        $this->assertSame([[self::USER, 3]], $this->siteChecks, 'only the session\'s own site is checked');
    }

    public function testATabletSessionWithoutASiteIsEnded(): void
    {
        $decision = Api::contextFor(self::checked(7, null), null, self::device(7), $this->canUseSite(true));
        $this->assertSame(['kind' => 'absent', 'ended' => 'ended', 'end_session' => true], $decision);
        $this->assertSame([], $this->siteChecks);
    }

    public function testATabletSessionKeepsTheTabletsSite(): void
    {
        $decision = Api::contextFor(self::checked(7, 3), null, self::device(7), $this->canUseSite(true));
        $this->assertSame(['kind' => 'tablet', 'ended' => null, 'end_session' => false], $decision);
        $this->assertSame([[self::USER, 3]], $this->siteChecks);
    }

    public function testDatabaseStringIdsCompareAsNumbers(): void
    {
        $decision = Api::contextFor(self::checked('7', '3'), null, self::device(7), $this->canUseSite(true));
        $this->assertSame(['kind' => 'tablet', 'ended' => null, 'end_session' => false], $decision);
        $this->assertSame([[self::USER, 3]], $this->siteChecks, 'the site id reaches canUseSite as an int');
    }

    public function testATabletSessionWithoutATabletGuardStillKeepsItsSite(): void
    {
        // api/session.php has no device guard: a Station session read there is still pinned to the tablet's site.
        $this->assertSame(['kind' => 'tablet', 'ended' => null, 'end_session' => false],
            Api::contextFor(self::checked(7, 3), null, null, $this->canUseSite(true)));
        $this->assertSame(['kind' => 'absent', 'ended' => 'ended', 'end_session' => true],
            Api::contextFor(self::checked(7, 3), null, null, $this->canUseSite(false)));
    }

    public function testAWebSessionWithoutATabletIsAWebContext(): void
    {
        $this->assertSame(['kind' => 'web', 'ended' => null, 'end_session' => false],
            Api::contextFor(self::checked(null), null, null, $this->canUseSite(false)));
        $this->assertSame([], $this->siteChecks, 'a web session picks its site with Page::pickSite(), not here');
    }

    public function testNoSessionKeepsItsEndedReason(): void
    {
        foreach ([null, 'missing', 'timeout', 'account', 'device', 'ended'] as $ended) {
            $this->assertSame(['kind' => 'absent', 'ended' => $ended, 'end_session' => false],
                Api::contextFor(null, $ended, self::device(7), $this->canUseSite(true)), (string) $ended);
        }
    }

    public function testEachEndedReasonHasIts401Code(): void
    {
        $cases = [[null, 'not_signed_in'], ['missing', 'not_signed_in'], ['ended', 'session_ended'], ['timeout', 'session_timeout'],
            ['account', 'account_blocked'], ['device', 'device_revoked'], ['something new', 'not_signed_in']];
        foreach ($cases as [$ended, $code]) {
            $this->assertSame($code, Api::reasonCode($ended), (string) $ended);
        }
    }

    // start() before any session -------------------------------------------------------------

    public function testMaintenanceIs503WithRetryAfter(): void
    {
        $this->maintenance(true);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $e = $this->refusal(['method' => 'GET', 'public' => true, 'session' => false]);
        $this->assertSame([503, 'maintenance', 'The system is being updated. Please try again in a few minutes.', ['Retry-After' => '120'], []],
            [$e->status, $e->code(), $e->getMessage(), $e->headers, $e->extra]);
    }

    public function testMaintenanceComesBeforeTheMethodAndTheTabletGuard(): void
    {
        $this->maintenance(true);
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $e = $this->refusal(['method' => 'POST', 'device' => 'known', 'session' => false]);
        $this->assertSame([503, 'maintenance'], [$e->status, $e->code()], 'not 405, and not 401 device_credential_missing');
    }

    public function testAWrongMethodIs405WithAllow(): void
    {
        $this->maintenance(false);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $e = $this->refusal(['method' => 'POST', 'device' => 'known', 'session' => false]);
        $this->assertSame([405, 'method_not_allowed', 'That action is not allowed here.', ['Allow' => 'POST']],
            [$e->status, $e->code(), $e->getMessage(), $e->headers]);

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $e = $this->refusal(['method' => ['GET', 'POST'], 'public' => true]);
        $this->assertSame([405, ['Allow' => 'GET, POST']], [$e->status, $e->headers], 'every allowed method is listed');
    }

    public function testTheDefaultMethodIsGet(): void
    {
        $this->maintenance(false);
        foreach (['POST', 'HEAD'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            $e = $this->refusal(['public' => true, 'session' => false]);
            $this->assertSame([405, ['Allow' => 'GET']], [$e->status, $e->headers], $method);
        }
    }

    public function testTheMethodIsComparedInUpperCase(): void
    {
        $this->maintenance(false);
        $_SERVER['REQUEST_METHOD'] = 'get';
        $this->assertNull(Api::start(['method' => 'GET', 'public' => true, 'session' => false]));
    }

    public function testASessionlessPublicCallStartsNoSessionAndHasNoTablet(): void
    {
        $this->maintenance(false);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertNull(Api::start(['method' => 'GET', 'public' => true, 'session' => false]), 'ping.php');
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'no PHP session was started');
        $this->expectException(LogicException::class);
        Api::device();
    }
}
