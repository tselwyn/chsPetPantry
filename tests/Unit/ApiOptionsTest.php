<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Pfpms\Config;
use Pfpms\Http\Api;

/** Which Api::start() options need CSRF, and the programming error of an endpoint with neither a session nor a guard (50-design §5.1). */
final class ApiOptionsTest extends TestCase
{
    private array $server;
    private array $saved;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->saved = Config::snapshot();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        Config::override($this->saved);
    }

    public function testPublicPostNeedsCsrf(): void
    {
        $this->assertTrue(Api::needsCsrf(['method' => 'POST', 'public' => true], 'POST'), 'register.php: anonymous, but it uses a session');
        $this->assertTrue(Api::needsCsrf(['method' => ['GET', 'POST'], 'public' => true, 'touch' => true], 'POST'), 'session.php touch');
    }

    public function testSignedInWritesNeedCsrf(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertTrue(Api::needsCsrf(['method' => $method, 'device' => 'in_service', 'proof' => true], $method), $method);
            $this->assertTrue(Api::needsCsrf(['method' => $method, 'session' => true], $method), "$method with an explicit session");
        }
    }

    public function testDeviceOnlyRequestsNeedNoCsrf(): void
    {
        $this->assertFalse(Api::needsCsrf(['method' => 'POST', 'device' => 'known', 'session' => false], 'POST'), 'heartbeat.php');
        $this->assertFalse(Api::needsCsrf(['method' => 'POST', 'public' => true, 'session' => false], 'POST'), 'no session, so no token to check');
    }

    public function testGetNeedsNoCsrf(): void
    {
        $this->assertFalse(Api::needsCsrf(['method' => ['GET', 'POST'], 'public' => true], 'GET'));
        $this->assertFalse(Api::needsCsrf([], 'HEAD'));
        $this->assertFalse(Api::needsCsrf(['method' => 'GET', 'public' => true, 'session' => false], 'GET'), 'ping.php');
    }

    public function testSessionFalseWithoutDeviceOrPublicIsALogicError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach ([['session' => false], ['method' => 'POST', 'session' => false], ['method' => 'POST', 'session' => false, 'public' => false],
            ['method' => 'POST', 'session' => false, 'device' => ''], ['method' => 'POST', 'session' => false, 'proof' => true]] as $options) {
            try {
                Api::start($options);
                $this->fail('expected a LogicException for ' . json_encode($options));
            } catch (LogicException $e) {
                $this->assertSame("Api::start(['session' => false]) needs 'device' or 'public'", $e->getMessage(), json_encode($options));
            }
        }
    }

    public function testTheLogicErrorComesBeforeMaintenanceAndTheMethod(): void
    {
        $app = $this->saved['app'] ?? [];
        $app['maintenance'] = true;
        Config::override(['app' => $app] + $this->saved);
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $this->expectException(LogicException::class);
        Api::start(['method' => 'POST', 'session' => false]);
    }
}
