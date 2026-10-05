<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\Request;
use Pfpms\Http\SecurityHeaders;
use Pfpms\Station\StationAssets;
use Pfpms\Station\StationShell;

/**
 * The Station's file list, the content hash behind its build and the precache list (50-design D-07, §7.1, §7.7).
 * The hash layout and what changes it are tested over temporary directories; the real tree is checked for completeness.
 */
final class StationAssetsTest extends TestCase
{
    /** @var list<string> temporary directories to remove */
    private array $temp = [];

    protected function setUp(): void
    {
        StationAssets::reset();
    }

    protected function tearDown(): void
    {
        StationAssets::reset();
        foreach ($this->temp as $dir) {
            self::remove($dir);
        }
    }

    // The build -------------------------------------------------------------------------------

    public function testBuildIsAtMost40CharactersAndStartsWithTheVersion(): void
    {
        $build = DeviceStatus::currentBuild();
        $this->assertMatchesRegularExpression('/^test\+[0-9a-f]{10}$/D', $build, 'APP_VERSION is "test" under the test bootstrap');
        $this->assertLessThanOrEqual(40, strlen($build));
        $this->assertSame('test+' . substr(StationAssets::hash(), 0, 10), $build);
    }

    public function testBuildMatchesTheHeartbeatBuildFormat(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z.+_-]{1,40}$/D', DeviceStatus::currentBuild(), 'what DeviceHeartbeat accepts as app_build');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', StationAssets::hash());
    }

    public function testHashLayoutIsExact(): void
    {
        $dir = $this->tempDir();
        mkdir("$dir/js");
        file_put_contents("$dir/a.js", 'A');
        file_put_contents("$dir/js/b.css", "B\n");
        $byHand = hash('sha256', "a.js\0" . hash('sha256', 'A') . "\n"
            . "js/b.css\0" . hash('sha256', "B\n") . "\n"
            . "<x>\0" . hash('sha256', 'X') . "\n"
            . 'idb:' . StationAssets::DB_VERSION . "\n");
        $this->assertSame($byHand, StationAssets::hashOf(['a.js', 'js/b.css'], $dir, ['<x>' => 'X']));
        $this->assertSame($byHand, StationAssets::hashOf(['a.js', 'js/b.css'], $dir . '/', ['<x>' => 'X']), 'a trailing slash on the root changes nothing');
        $this->assertSame(1, StationAssets::DB_VERSION, 'js/db.js opens schema v1');
    }

    public function testBuildChangesWhenAnyListedFileChanges(): void
    {
        $dir = $this->tempDir();
        mkdir("$dir/css");
        file_put_contents("$dir/a.js", "export const a = 1;\n");
        file_put_contents("$dir/css/b.css", "p { margin: 0; }\n");
        $files = ['a.js', 'css/b.css'];
        $base = StationAssets::hashOf($files, $dir);
        $this->assertSame($base, StationAssets::hashOf($files, $dir), 'the same files hash the same');

        file_put_contents("$dir/a.js", "export const a = 2;\n");
        $this->assertNotSame($base, StationAssets::hashOf($files, $dir), 'one byte changed');
        file_put_contents("$dir/a.js", "export const a = 1;\n");
        $this->assertSame($base, StationAssets::hashOf($files, $dir), 'and changed back');

        rename("$dir/a.js", "$dir/c.js");
        $this->assertNotSame($base, StationAssets::hashOf($files, $dir), 'a listed file renamed away (now missing)');
        $this->assertNotSame($base, StationAssets::hashOf(['c.js', 'css/b.css'], $dir), 'the same bytes under another name');
        rename("$dir/c.js", "$dir/a.js");

        file_put_contents("$dir/d.js", '');
        $this->assertNotSame($base, StationAssets::hashOf([...$files, 'd.js'], $dir), 'a path added');
        $this->assertNotSame($base, StationAssets::hashOf(['a.js'], $dir), 'a path removed');
        $this->assertNotSame($base, StationAssets::hashOf(['css/b.css', 'a.js'], $dir), 'the order is part of the hash');
    }

    public function testBuildChangesWhenTheShellOrItsCspChanges(): void
    {
        $extra = StationAssets::extraInputs();
        $this->assertSame(['<shell>', '<headers:shell>', '<headers:worker>', '<worker>', '<kill>', 'sw.php', 'index.php'], array_keys($extra));
        $this->assertSame(StationShell::html(''), $extra['<shell>']);
        $this->assertSame(implode("\n", StationShell::headerLines(false)), $extra['<headers:shell>']);
        $this->assertSame(implode("\n", StationShell::headerLines(true)), $extra['<headers:worker>']);
        $this->assertSame(StationShell::worker('', [], ''), $extra['<worker>']);
        $this->assertSame(StationShell::KILL_WORKER, $extra['<kill>']);
        $this->assertSame(StationAssets::read('sw.php'), $extra['sw.php']);
        $this->assertStringContainsString('StationShell::worker(', $extra['sw.php'], 'sw.php itself is read from the web root');
        // A header index.php sends before StationShell::sendHeaders() (one headerLines() does not replace) is cached with
        // the shell, so its bytes are an input too.
        $this->assertSame(StationAssets::read('index.php'), $extra['index.php']);
        $this->assertStringContainsString('echo StationShell::html();', $extra['index.php'], 'index.php itself is read from the web root');

        $base = StationAssets::hashOf(StationAssets::FILES, StationAssets::root(), $extra);
        foreach (array_keys($extra) as $name) {
            $changed = $extra;
            $changed[$name] .= ' ';
            $this->assertNotSame($base, StationAssets::hashOf(StationAssets::FILES, StationAssets::root(), $changed), "a change to $name changes the build");
            $without = $extra;
            unset($without[$name]);
            $this->assertNotSame($base, StationAssets::hashOf(StationAssets::FILES, StationAssets::root(), $without), "$name is an input");
        }
        $csp = $extra;
        $csp['<headers:shell>'] = str_replace(" trusted-types pfpms-sw", '', $csp['<headers:shell>']);
        $this->assertNotSame($base, StationAssets::hashOf(StationAssets::FILES, StationAssets::root(), $csp), 'dropping the Trusted Types directives changes the build');
    }

    public function testTheBuildIsNotAnInputOfItself(): void
    {
        $prefix = substr(StationAssets::hash(), 0, 10);
        $build = DeviceStatus::currentBuild();
        foreach (StationAssets::extraInputs() as $name => $content) {
            $this->assertStringNotContainsString($prefix, $content, "$name holds the build's hash");
            $this->assertStringNotContainsString($build, $content, "$name holds the build");
        }
        $this->assertStringContainsString('data-build=""', StationAssets::extraInputs()['<shell>'], 'the shell is hashed in its build-free form');
        $this->assertStringStartsWith("const BUILD = \"\";\nconst PRECACHE = [];\n", StationAssets::extraInputs()['<worker>']);
    }

    public function testHashEqualsHashOfTheRealFiles(): void
    {
        $this->assertSame(StationAssets::hashOf(StationAssets::FILES, StationAssets::root(), StationAssets::extraInputs()), StationAssets::hash());
        $this->assertSame(StationAssets::hash(), StationAssets::hash(), 'memoised');
    }

    // The file list ---------------------------------------------------------------------------

    public function testEveryStationFileIsListed(): void
    {
        $root = APP_ROOT . '/public/station';
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $found[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
        }
        $found = array_values(array_diff($found, ['index.php', 'sw.php']));
        sort($found, SORT_STRING);
        $this->assertSame(StationAssets::FILES, $found, 'every file under public/station/ (but index.php and sw.php) is in StationAssets::FILES, and only those');
    }

    public function testEveryListedFileExists(): void
    {
        $missing = array_values(array_filter(StationAssets::FILES, static fn(string $path): bool => !is_file(StationAssets::root() . '/' . $path)));
        $this->assertSame([], $missing, 'listed in StationAssets::FILES but missing from public/station/');
        $this->assertFileExists(StationAssets::root() . '/index.php');
        $this->assertFileExists(StationAssets::root() . '/sw.php');
    }

    public function testFilesAreSortedUniqueAndRelative(): void
    {
        $sorted = StationAssets::FILES;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, StationAssets::FILES, 'sorted by byte value');
        $this->assertSame(StationAssets::FILES, array_values(array_unique(StationAssets::FILES)), 'unique');
        foreach (StationAssets::FILES as $path) {
            $this->assertMatchesRegularExpression('~^[a-z0-9_-]+(/[a-z0-9_-]+)*\.[a-z]+$~D', $path, "$path is relative, lower case, with no . or .. segment");
            $this->assertNotContains($path, ['index.php', 'sw.php'], 'the PHP entry points are hashed apart');
        }
        $this->assertContains(StationAssets::WORKER_CORE, StationAssets::FILES, 'the worker core is hashed like every file');
        $this->assertContains('manifest.json', StationAssets::FILES);
    }

    // The precache list -------------------------------------------------------------------------

    public function testPrecacheListHasEveryFileWithItsSha256AndTypeAndTheShell(): void
    {
        $precache = StationAssets::precache();
        $this->assertSame(['path' => './', 'sha256' => hash('sha256', StationShell::html()), 'type' => 'text/html'], $precache[0], 'the shell comes first');
        $this->assertSame(StationShell::html(), StationShell::html(DeviceStatus::currentBuild()), 'with the current build');
        $this->assertCount(count(StationAssets::FILES), $precache, 'the shell plus every file but the worker core');
        $expected = [];
        foreach (StationAssets::FILES as $path) {
            if ($path !== StationAssets::WORKER_CORE) {
                $file = StationAssets::root() . '/' . $path;
                $bytes = is_file($file) ? (string) file_get_contents($file) : '';
                $expected[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'type' => StationAssets::typeOf($path)];
            }
        }
        $this->assertSame($expected, array_slice($precache, 1), 'in FILES order, each with the SHA-256 of its bytes and its type family');
        foreach ($precache as $entry) {
            $this->assertSame(['path', 'sha256', 'type'], array_keys($entry));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $entry['sha256']);
        }
        $json = json_encode($precache, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->assertStringStartsWith('[{"path":"./","sha256":"', $json, 'as sw.php prints it');
        $this->assertStringContainsString('{"path":"css/station.css","sha256":"', $json, 'slashes unescaped');
    }

    public function testPrecacheLeavesOutTheWorkerCore(): void
    {
        $paths = array_column(StationAssets::precache(), 'path');
        $this->assertNotContains(StationAssets::WORKER_CORE, $paths, 'sw-core.js is served inside sw.php, never cached');
        $this->assertNotContains('sw.php', $paths);
        $this->assertNotContains('index.php', $paths);
        $this->assertSame('js/sw-core.js', StationAssets::WORKER_CORE);
    }

    public function testTypeOfKnowsEveryListedExtension(): void
    {
        foreach (StationAssets::FILES as $path) {
            $this->assertContains(StationAssets::typeOf($path), ['javascript', 'text/css', 'json', 'image/png'], $path);
        }
        $this->assertSame('javascript', StationAssets::typeOf('js/app.js'));
        $this->assertSame('text/css', StationAssets::typeOf('css/station.css'));
        $this->assertSame('json', StationAssets::typeOf('manifest.json'));
        $this->assertSame('image/png', StationAssets::typeOf('icons/icon-192.png'));
        $this->assertSame('image/png', StationAssets::typeOf('icons/UPPER.PNG'), 'the extension is matched in lower case');
        $this->expectException(\LogicException::class);
        StationAssets::typeOf('icons/paw.svg');
    }

    public function testAMissingFileHashesAsEmpty(): void
    {
        $dir = $this->tempDir();
        file_put_contents("$dir/empty.js", '');
        $this->assertSame(StationAssets::hashOf(['x.js'], $dir), StationAssets::hashOf(['x.js'], $this->tempDir()), 'missing in both');
        $this->assertSame(hash('sha256', "x.js\0" . hash('sha256', '') . "\nidb:1\n"), StationAssets::hashOf(['x.js'], $dir));
        $this->assertSame('', StationAssets::read('js/no-such-file.js'));
        $this->assertSame(hash('sha256', ''), StationAssets::digest('js/no-such-file.js'));
        $this->assertSame('', StationAssets::read('js'), 'a directory reads as missing');
    }

    // The web root ------------------------------------------------------------------------------

    public function testPublicDirFindsTheSiteGroundLayout(): void
    {
        $base = $this->tempDir();
        mkdir("$base/repo/public", 0700, true);
        mkdir("$base/siteground/public_html", 0700, true);
        mkdir("$base/both/public", 0700, true);
        mkdir("$base/both/public_html", 0700, true);
        mkdir("$base/neither", 0700, true);
        mkdir("$base/file", 0700);
        file_put_contents("$base/file/public", 'not a directory');
        $this->assertSame("$base/repo/public", Request::publicDir("$base/repo"), 'only public/');
        $this->assertSame("$base/siteground/public_html", Request::publicDir("$base/siteground"), 'only public_html/ (SiteGround)');
        $this->assertSame("$base/both/public", Request::publicDir("$base/both"), 'both: public/ first');
        $this->assertSame("$base/neither/public", Request::publicDir("$base/neither"), 'neither: public/');
        $this->assertSame("$base/file/public", Request::publicDir("$base/file"), 'a file named public is no web root, and nothing else is either');
        $this->assertSame(APP_ROOT . '/public', Request::publicDir(), 'the repo');
    }

    public function testRootIsInTheWebRoot(): void
    {
        $this->assertSame(Request::publicDir() . '/station', StationAssets::root());
        $this->assertSame(APP_ROOT . '/public/station', StationAssets::root(), 'in the repo');
        $this->assertSame((string) file_get_contents(APP_ROOT . '/public/station/manifest.json'), StationAssets::read('manifest.json'));
        // In the repo public/ is the web root either way, so only a SiteGround tree tells a hard-coded public/ apart.
        $base = $this->tempDir();
        mkdir("$base/siteground/public_html/station", 0700, true);
        mkdir("$base/repo/public/station", 0700, true);
        $this->assertSame("$base/siteground/public_html/station", StationAssets::root("$base/siteground"), 'SiteGround: public_html/station');
        $this->assertSame("$base/repo/public/station", StationAssets::root("$base/repo"));
        $this->assertSame(StationAssets::root(APP_ROOT), StationAssets::root(), 'APP_ROOT by default');
    }

    public function testHeaderSetsCoverEverySecurityHeader(): void
    {
        $shell = StationShell::headerLines(false);
        $worker = StationShell::headerLines(true);
        $covered = 0;
        foreach (SecurityHeaders::LINES as $line) {
            if (str_starts_with($line, 'Content-Security-Policy:')) {
                continue;
            }
            $this->assertContains($line, $shell, "the shell's headers (hashed into the build) include $line");
            $this->assertContains($line, $worker, "the worker's headers (hashed into the build) include $line");
            $covered++;
        }
        $this->assertSame(6, $covered, 'every bootstrap header but the CSP, which the Station replaces');
        $this->assertContains('Content-Security-Policy: ' . StationShell::CSP, $shell);
        $this->assertContains('Content-Security-Policy: ' . StationShell::CSP, $worker);
        $extra = StationAssets::extraInputs();
        foreach (SecurityHeaders::LINES as $line) {
            $name = explode(':', $line, 2)[0];
            $this->assertStringContainsString($name . ':', $extra['<headers:shell>'], "$name is hashed into the build");
            $this->assertStringContainsString($name . ':', $extra['<headers:worker>'], "$name is hashed into the build");
        }
    }

    // Helpers -----------------------------------------------------------------------------------

    private function tempDir(): string
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/pfpms-assets-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $this->temp[] = $dir;
        return $dir;
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
