<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** NFR-SEC-009: the local XAMPP defaults are only ever used on a laptop, never on SiteGround. */
final class LocalDatabaseDefaultsTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function local(string $name, string $addr): bool
    {
        $_SERVER['SERVER_NAME'] = $name;
        $_SERVER['SERVER_ADDR'] = $addr;
        return pfpms_is_local_machine();
    }

    public function testALaptopOpenedAsLocalhostIsLocal(): void
    {
        $this->assertTrue($this->local('localhost', '127.0.0.1'));
        $this->assertTrue($this->local('127.0.0.1', '127.0.0.1'));
        $this->assertTrue($this->local('localhost', '::1'));
    }

    public function testSiteGroundIsNotLocal(): void
    {
        $this->assertFalse($this->local('jenniferp248.sg-host.com', '35.1.2.3'));
    }

    public function testAFakedHostNameOnARealServerIsNotLocal(): void
    {
        $this->assertFalse($this->local('localhost', '35.1.2.3'));
    }
}
