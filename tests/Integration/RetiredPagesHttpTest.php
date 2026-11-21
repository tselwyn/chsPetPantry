<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * NFR-SEC-002 / NFR-SEC-003: retired CCDA pages answer "not found" in a real
 * web server, while Pet Pantry pages and files they include keep working.
 * Runs the app in PHP's built-in server.
 */
final class RetiredPagesHttpTest extends TestCase
{
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$port = random_int(20000, 40000);
        $cmd = sprintf('exec %s -S 127.0.0.1:%d -t %s', escapeshellarg(PHP_BINARY), self::$port, escapeshellarg(PFPMS_ROOT));
        self::$server = proc_open($cmd, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', self::$port)) {
                return;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    /** HTTP status code for a page, without following redirects. */
    private function httpStatus(string $page): int
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $body = @file_get_contents('http://127.0.0.1:' . self::$port . '/' . $page, false, $context);
        if ($body === false || !isset($http_response_header[0])) {
            $this->markTestSkipped('The built-in PHP server did not start');
        }
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $m);
        return (int) $m[1];
    }

    public function testRetiredPagesAreNotFound(): void
    {
        foreach (['insertAdmin.php', 'inventory.php', 'getVolunteers.php', 'deleteBulk.php', 'header.php', 'viewShoppingList.php'] as $page) {
            $this->assertSame(404, $this->httpStatus($page), $page);
        }
    }

    public function testThePetPantryStillWorks(): void
    {
        $this->assertSame(200, $this->httpStatus('login.php'), 'login page (includes the retired header code paths)');
        $this->assertSame(302, $this->httpStatus('participantSearch.php'), 'signed-out users are sent to log in, not shown a 404');
    }
}
