<?php
declare(strict_types=1);

namespace Pfpms\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Every web entry point follows the page skeleton (plan §4): bootstrap first, then the
 * guard (Page::start or Api::start) before any input is read, and every page that handles
 * a POST verifies the CSRF token.
 */
final class PageContractTest extends TestCase
{
    public function testEveryEntryPointStartsWithBootstrapThenTheGuard(): void
    {
        foreach ($this->entryPoints() as $file) {
            $code = (string) file_get_contents($file);
            $name = substr($file, strlen(dirname(__DIR__, 2)) + 1);
            $this->assertTrue((bool) preg_match('~^<\?php\s+declare\(strict_types=1\);\s+require __DIR__ \. \'/(\.\./)+src/bootstrap\.php\';~', $code),
                "$name must start with declare(strict_types=1) and the bootstrap require");
            $body = (string) preg_replace('~^.*?src/bootstrap\.php\';~s', '', $code);
            $body = (string) preg_replace('~^\s*(use [^;]+;\s*)+~', '', $body);
            $body = (string) preg_replace('~^\s*(//[^\n]*\n\s*)+~', '', $body);
            $this->assertTrue((bool) preg_match('~^\s*(\$ctx\s*=\s*)?(Page|Api)::start\(~', $body),
                "$name: the first statement after the imports must be Page::start() or Api::start()");
        }
    }

    public function testPagesThatHandlePostVerifyCsrf(): void
    {
        foreach ($this->entryPoints() as $file) {
            $code = (string) file_get_contents($file);
            if (str_contains($code, 'Request::isPost()')) {
                $this->assertStringContainsString('Csrf::verify()', $code, basename($file) . ' handles POST without Csrf::verify()');
            }
        }
    }

    public function testNothingIncludesLegacyCode(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['src', 'public', 'bin', 'templates'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $this->assertFalse((bool) preg_match('~(require|include)(_once)?[^;]*legacy/~', (string) file_get_contents($file->getPathname())),
                        $file->getFilename() . ' includes code from legacy/');
                }
            }
        }
    }

    /** @return list<string> */
    private function entryPoints(): array
    {
        $root = dirname(__DIR__, 2) . '/public';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() === 'php' && !str_contains($path, '/assets/')) {
                $files[] = $path;
            }
        }
        sort($files);
        $this->assertNotEmpty($files);
        return $files;
    }
}
