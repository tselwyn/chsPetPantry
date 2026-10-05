<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Config;
use Pfpms\Storage\SecureFileStore;
use RuntimeException;

final class SecureFileStoreTest extends TestCase
{
    private array $saved;
    private string $dir;

    protected function setUp(): void
    {
        $this->saved = Config::snapshot();
        $this->dir = sys_get_temp_dir() . '/pfpms-store-' . bin2hex(random_bytes(4));
        $app = ($this->saved['app'] ?? []) + [];
        $app['secure_storage'] = $this->dir;
        Config::override(['app' => $app] + $this->saved);
    }

    protected function tearDown(): void
    {
        Config::override($this->saved);
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
    }

    public function testRoundTripAndEncryptionAtRest(): void
    {
        $path = SecureFileStore::put('pets', "\x89PNG fake image bytes");
        $this->assertTrue((bool) preg_match('~^pets/\d{4}/\d{2}/[a-f0-9]{32}\.bin$~', $path));
        $this->assertSame("\x89PNG fake image bytes", SecureFileStore::get($path));
        $this->assertFalse(str_contains((string) file_get_contents($this->dir . '/' . $path), 'fake image'), 'stored encrypted');
        SecureFileStore::delete($path);
        $this->expectException(RuntimeException::class);
        SecureFileStore::get($path);
    }

    public function testAFileCopiedUnderAnotherNameDoesNotDecrypt(): void
    {
        $a = SecureFileStore::put('imports', 'row data A');
        $b = SecureFileStore::put('imports', 'row data B');
        copy($this->dir . '/' . $a, $this->dir . '/' . $b);
        $this->expectException(RuntimeException::class);
        SecureFileStore::get($b);
    }

    public function testPathTraversalIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        SecureFileStore::get('../../config/config.php');
    }

    public function testAreaNamesAreValidated(): void
    {
        $this->expectException(RuntimeException::class);
        SecureFileStore::put('../public', 'x');
    }
}
