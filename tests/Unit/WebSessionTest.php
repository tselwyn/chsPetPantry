<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Auth\WebSession;
use Pfpms\Config;

/**
 * The Station's own session cookie (50-design §5.5, D-52), over the pure WebSession::cookieFor(). The session_* calls of
 * start(), restart() and destroy() are not run here (the shim runner has no process isolation; E2E A6 and A7 cover them).
 */
final class WebSessionTest extends TestCase
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

    private function useBasePath(string $basePath): void
    {
        $app = $this->saved['app'] ?? [];
        $app['base_path'] = $basePath;
        Config::override(['app' => $app] + $this->saved);
    }

    /** No configured base path: it is worked out from the running script, as under XAMPP's htdocs. */
    private function runningScript(string $scriptName, string $publicRelative): void
    {
        $this->useBasePath('');
        $_SERVER['SCRIPT_NAME'] = $scriptName;
        $_SERVER['SCRIPT_FILENAME'] = APP_ROOT . '/public/' . $publicRelative;
    }

    public function testTheStationCookieIsPfpmsstUnderApi(): void
    {
        $this->useBasePath('/pfpms/');
        $this->assertSame(['name' => 'PFPMSST', 'path' => '/pfpms/api/'], WebSession::cookieFor(true));
        $this->assertSame('PFPMSST', WebSession::STATION_COOKIE);
    }

    public function testTheWebCookieIsUnchanged(): void
    {
        $this->useBasePath('/pfpms/');
        $this->assertSame(['name' => 'PFPMSSID', 'path' => '/pfpms/'], WebSession::cookieFor(false));
        $this->assertSame('PFPMSSID', WebSession::COOKIE);
    }

    public function testAtTheWebRootTheStationCookieIsUnderSlashApi(): void
    {
        $this->useBasePath('/');
        $this->assertSame(['name' => 'PFPMSST', 'path' => '/api/'], WebSession::cookieFor(true));
        $this->assertSame(['name' => 'PFPMSSID', 'path' => '/'], WebSession::cookieFor(false));
    }

    public function testEveryApiEndpointSetsTheSameStationCookiePath(): void
    {
        // The cookie set by api/session.php must reach api/device/register.php (and back), so both give one path.
        $this->runningScript('/chsPetPantry/public/api/session.php', 'api/session.php');
        $fromSession = WebSession::cookieFor(true);
        $this->runningScript('/chsPetPantry/public/api/device/register.php', 'api/device/register.php');
        $fromRegister = WebSession::cookieFor(true);
        $this->assertSame(['name' => 'PFPMSST', 'path' => '/chsPetPantry/public/api/'], $fromSession);
        $this->assertSame($fromSession, $fromRegister);
    }

    public function testAWebSessionReadFromAnApiEndpointKeepsTheWebPath(): void
    {
        // A web tab's PFPMSSID cookie is scoped to the app, never re-scoped to api/ by an API call.
        $this->runningScript('/chsPetPantry/public/api/session.php', 'api/session.php');
        $this->assertSame(['name' => 'PFPMSSID', 'path' => '/chsPetPantry/public/'], WebSession::cookieFor(false));
        $this->runningScript('/chsPetPantry/public/index.php', 'index.php');
        $this->assertSame(['name' => 'PFPMSSID', 'path' => '/chsPetPantry/public/'], WebSession::cookieFor(false), 'the same as a web page sets');
    }
}
