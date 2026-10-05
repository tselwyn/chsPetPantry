<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * bin/station-smoke.php's step 5 (S2) against a scripted host: a php -S router that answers like a PFPMS server, with
 * one precached file stale (a proxy serving an old copy) and js/app.js sent without a revalidation header. A PASS line
 * says what was seen and never a failure hint; a FAIL line carries the hint. With maintenance on, sw.php's 503 is
 * explained once and the checks that need the worker are skipped.
 */
final class StationSmokeTest extends TestCase
{
    private const SCRIPT = APP_ROOT . '/bin/station-smoke.php';

    /** Every hint step 5 prints on a FAIL: none may appear on a PASS line. */
    private const HINTS = [
        'the server did not find its Station files',
        'a cache or proxy is serving another copy',
        'a proxy or optimiser may be rewriting the page',
        'NGINX Direct Delivery may serve it',
        'set app.base_path in config',
        'app.maintenance is on',
    ];

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
    }

    public function testPassLinesShowWhatWasSeenAndFailLinesTheHint(): void
    {
        $lines = $this->smoke(false);
        $this->assertContains('PASS  the worker carries its code (js/sw-core.js)', $lines);
        $this->assertContains('PASS  the worker lists its precache  (4 files)', $lines, 'a PASS keeps its observed detail');
        $this->assertContains('PASS  station/js/good.js is the file the worker verifies', $lines);
        $this->assertContains('FAIL  station/js/stale.js is the file the worker verifies  (a cache or proxy is serving another copy, or the server '
            . 'reads its files from the wrong directory)', $lines, 'a FAIL carries the hint');
        $this->assertContains('PASS  the shell is exactly the bytes the worker verifies', $lines);
        $this->assertContains('PASS  no precache file hashes as empty (the server found public/station or public_html/station)', $lines);
        $this->assertContains('FAIL  station/js/app.js revalidates (the .htaccess rule is reached)  (no Cache-Control: NGINX Direct Delivery may '
            . 'serve it (switch it off))', $lines);
        $passes = array_filter($lines, static fn(string $line): bool => str_starts_with($line, 'PASS  '));
        $this->assertGreaterThan(8, count($passes));
        foreach ($passes as $line) {
            foreach (self::HINTS as $hint) {
                $this->assertStringNotContainsString($hint, $line, 'a passing check never prints a failure hint');
            }
        }
    }

    public function testMaintenanceExplainsTheWorker503(): void
    {
        $lines = $this->smoke(true);
        $all = implode("\n", $lines);
        $matches = array_values(array_filter($lines, static fn(string $line): bool => str_starts_with($line, 'FAIL  station/sw.php is JavaScript')));
        $this->assertCount(1, $matches, $all);
        $this->assertStringContainsString('status 503', $matches[0]);
        $this->assertStringEndsWith(': app.maintenance is on, so no worker is served; run this again once the deploy is finished)', $matches[0]);
        // Said once, and nothing that needs the worker runs: those checks would all fail for the same reason, some with a
        // hint that blames a proxy or the Station files instead.
        $this->assertCount(1, array_filter($lines, static fn(string $line): bool => str_contains($line, 'app.maintenance is on')), $all);
        $this->assertContains('WARN  the worker, precache and shell checks  (skipped: station/sw.php answered 503)', $lines);
        $after = array_slice($lines, (int) array_search($matches[0], $lines, true) + 1);
        $this->assertSame([], array_values(array_filter($after, static fn(string $line): bool => str_starts_with($line, 'FAIL  '))),
            "no FAIL after the 503 one\n$all");
        foreach ($lines as $line) {
            foreach (array_diff(self::HINTS, ['app.maintenance is on']) as $hint) {
                $this->assertStringNotContainsString($hint, $line, 'no hint that blames another cause');
            }
            $this->assertStringNotContainsString('station/js/', $line, 'no precached file is fetched');
            $this->assertStringNotContainsString('NULL vs NULL', $line);
        }
    }

    /**
     * Runs the smoke script against a php -S host scripted by router(); returns its output lines.
     * @return list<string>
     */
    private function smoke(bool $maintenance): array
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/pfpms-smoke-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/router.php', self::router($maintenance));
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($probe, "a free port: $errstr");
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $log = $this->dir . '/server.log';
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $this->dir . '/router.php'], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, $this->dir);
        $this->assertIsResource($server);
        try {
            $up = false;
            for ($i = 0; $i < 100 && !$up; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($socket !== false) {
                    fclose($socket);
                    $up = true;
                } else {
                    usleep(50000);
                }
            }
            $this->assertTrue($up, 'php -S started: ' . @file_get_contents($log));
            $run = proc_open([PHP_BINARY, self::SCRIPT, "http://127.0.0.1:$port/"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $out, $this->dir);
            $this->assertIsResource($run);
            $stdout = (string) stream_get_contents($out[1]);
            $stderr = (string) stream_get_contents($out[2]);
            fclose($out[1]);
            fclose($out[2]);
            $this->assertSame(1, proc_close($run), "the stale file fails the run\n$stdout$stderr");
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
        return explode("\n", rtrim(str_replace("\r\n", "\n", $stdout), "\n"));
    }

    /** A php -S router that answers like a PFPMS host: ping, sw.php (or 503 under maintenance), the shell and two files. */
    private static function router(bool $maintenance): string
    {
        $flag = var_export($maintenance, true);
        return <<<PHP
<?php
\$path = (string) parse_url((string) \$_SERVER['REQUEST_URI'], PHP_URL_PATH);
\$build = 'test+0123456789';
\$shell = "<!doctype html>\\n<html lang=\"en\" data-build=\"\$build\"></html>\\n";
\$good = "export const a = 1;\\n";
\$precache = [
    ['path' => './', 'sha256' => hash('sha256', \$shell), 'type' => 'text/html'],
    ['path' => 'js/good.js', 'sha256' => hash('sha256', \$good), 'type' => 'javascript'],
    ['path' => 'js/stale.js', 'sha256' => hash('sha256', "the new bytes\\n"), 'type' => 'javascript'],
    ['path' => 'js/app.js', 'sha256' => hash('sha256', "export {};\\n"), 'type' => 'javascript'],
];
\$csp = "Content-Security-Policy: default-src 'self'; worker-src 'self'; require-trusted-types-for 'script'; trusted-types pfpms-sw";
header('Cache-Control: no-store, no-cache, private, max-age=0');
header('X-Content-Type-Options: nosniff');
if (\$path === '/api/ping.php') {
    header('Content-Type: application/json');
    echo json_encode(['build' => \$build, 'script_path' => 'api/ping.php']);
} elseif (\$path === '/station/sw.php' && $flag) {
    http_response_code(503);
    header('Retry-After: 120');
} elseif (\$path === '/station/sw.php') {
    header('Content-Type: text/javascript; charset=utf-8');
    header(\$csp);
    echo 'const BUILD = ' . json_encode(\$build) . ";\\nconst PRECACHE = " . json_encode(\$precache, JSON_UNESCAPED_SLASHES) . ";\\nvar PFPMS_SW = {};\\n";
} elseif (\$path === '/station/') {
    header('Content-Type: text/html; charset=utf-8');
    header(\$csp);
    echo \$shell;
} elseif (\$path === '/station/js/good.js') {
    header('Content-Type: text/javascript');
    echo \$good;
} elseif (\$path === '/station/js/stale.js') {
    header('Content-Type: text/javascript');
    echo "the old bytes\\n";
} elseif (\$path === '/station/js/app.js') {
    header_remove('Cache-Control'); // the revalidation rule is not reached: that check fails with its hint
    header('Content-Type: text/javascript');
    echo "export {};\\n";
} else {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"not_found"}';
}

PHP;
    }
}
