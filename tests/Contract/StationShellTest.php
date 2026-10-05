<?php
declare(strict_types=1);

namespace Pfpms\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\SecurityHeaders;
use Pfpms\Station\StationAssets;
use Pfpms\Station\StationShell;

/**
 * The Station's shell page and service worker (50-design §5.7, §5.8, §7.1, §7.7): their exact bytes and headers, no
 * inline code, no session, and the kill worker that never touches the tablet's records.
 */
final class StationShellTest extends TestCase
{
    private const STATION = APP_ROOT . '/public/station';

    /** The eight lines src/bootstrap.php sent at a4098f8 (before SecurityHeaders), byte for byte, in order. */
    private const BOOTSTRAP_AT_S1 = [
        'Cache-Control: no-store, no-cache, private, max-age=0',
        'Pragma: no-cache',
        'X-Content-Type-Options: nosniff',
        'X-Frame-Options: DENY',
        'Referrer-Policy: same-origin',
        'Permissions-Policy: camera=(self), microphone=(), geolocation=()',
        "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; "
            . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'",
        'Strict-Transport-Security: max-age=31536000; includeSubDomains',
    ];

    public function testShellHasNoInlineScriptStyleOrHandlers(): void
    {
        foreach (['', 'test+0123456789', DeviceStatus::currentBuild()] as $build) {
            $html = StationShell::html($build);
            $this->assertSame(1, preg_match_all('~<script\b([^>]*)>~i', $html, $m), 'one script element');
            $this->assertMatchesRegularExpression('~^ type="module" src="boot\.js\?v=[^"]*"$~', $m[1][0]);
            $this->assertMatchesRegularExpression('~<script\b[^>]*></script>~', $html, 'the script element is empty');
            $this->assertDoesNotMatchRegularExpression('~<style\b~i', $html);
            $this->assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $html);
            $this->assertDoesNotMatchRegularExpression('~\son[a-z]+\s*=~i', $html, 'no inline event handlers');
            $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
            $this->assertStringNotContainsString('viewport-fit', $html, 'content stays inside the safe area (no viewport-fit=cover)');

            preg_match_all('~\s(?:href|src)="([^"]*)"~', $html, $links);
            $this->assertCount(5, $links[1], 'the manifest, two icons, the stylesheet and boot.js, nothing else');
            foreach ($links[1] as $link) {
                $path = explode('?', $link, 2)[0];
                $this->assertContains($path, StationAssets::FILES, "the shell links $link, which must be a listed (precached) file");
            }
        }
        $this->assertStringNotContainsString("\r", StationShell::html('x'), 'LF only');
    }

    public function testShellAndWorkerNeedNoSession(): void
    {
        foreach (['index.php', 'sw.php'] as $name) {
            $code = (string) file_get_contents(self::STATION . "/$name");
            $this->assertStringContainsString("Page::start(['public' => true, 'session' => false]);", $code, "$name is public and sessionless");
            foreach (['WebSession', 'Api::start', '$_SESSION', 'Csrf', 'session_start', 'setcookie'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "station/$name must not use $forbidden");
            }
        }
        foreach (['StationShell', 'StationAssets'] as $class) {
            $code = (string) file_get_contents(APP_ROOT . "/src/Station/$class.php");
            foreach (['WebSession', '$_SESSION', 'Csrf', 'Settings::', 'Db::', 'Context'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "$class must not depend on $forbidden: the same bytes for every tablet");
            }
        }
    }

    public function testWorkerIsServedAsJavaScript(): void
    {
        $this->assertSame([...$this->bootstrapLinesWithStationCsp(), 'Content-Type: text/javascript; charset=utf-8'], StationShell::headerLines(true));
        $this->assertCount(8, StationShell::headerLines(true));
    }

    public function testShellHeadersAreExact(): void
    {
        $this->assertSame([...$this->bootstrapLinesWithStationCsp(), 'Content-Type: text/html; charset=utf-8'], StationShell::headerLines(false));
        $this->assertCount(8, StationShell::headerLines(false));
        foreach ([false, true] as $worker) {
            foreach (StationShell::headerLines($worker) as $line) {
                $this->assertStringNotContainsStringIgnoringCase('Set-Cookie', $line);
                $this->assertStringNotContainsStringIgnoringCase('Service-Worker-Allowed', $line);
                $this->assertStringNotContainsStringIgnoringCase('Strict-Transport-Security', $line, 'HSTS is left out of the hashed set');
            }
        }
    }

    public function testBootstrapSendsOnlySecurityHeaders(): void
    {
        $code = (string) file_get_contents(APP_ROOT . '/src/bootstrap.php');
        $this->assertStringNotContainsString("header('", $code, 'bootstrap sends no literal header: they are SecurityHeaders constants, hashed into the build');
        $this->assertStringNotContainsString('header("', $code);
        $this->assertStringContainsString('use Pfpms\Http\SecurityHeaders;', $code);
        $this->assertMatchesRegularExpression('~foreach \(SecurityHeaders::LINES as \$line\) \{\s*header\(\$line\);\s*\}~', $code);
        $this->assertStringContainsString('header(SecurityHeaders::HSTS);', $code);
        $this->assertSame(1, substr_count($code, 'header($line)'), 'one header() loop');
        $this->assertSame(self::BOOTSTRAP_AT_S1, [...SecurityHeaders::LINES, SecurityHeaders::HSTS], 'the bytes sent are those of S1');
        $this->assertSame('Content-Security-Policy: ' . SecurityHeaders::CSP, SecurityHeaders::LINES[6]);
    }

    public function testShellHtmlIsDeterministic(): void
    {
        $this->assertSame(StationShell::html('x'), StationShell::html('x'));
        $this->assertSame(StationShell::html(DeviceStatus::currentBuild()), StationShell::html());
        $this->assertNotSame(StationShell::html('a'), StationShell::html('b'));
    }

    public function testShellEmbedsTheBuild(): void
    {
        $html = StationShell::html('0.1.0-dev+abc');
        $this->assertStringContainsString('<html lang="en" data-build="0.1.0-dev+abc">', $html);
        $this->assertStringContainsString('<script type="module" src="boot.js?v=0.1.0-dev%2Babc"></script>', $html);
        $this->assertStringContainsString('data-build="' . DeviceStatus::currentBuild() . '"', StationShell::html());
        $odd = StationShell::html('a"<b>&');
        $this->assertStringContainsString('data-build="a&quot;&lt;b&gt;&amp;"', $odd, 'the attribute is escaped');
        $this->assertStringContainsString('boot.js?v=a%22%3Cb%3E%26"', $odd, 'the query is URL-encoded');
        $this->assertSame($this->exactShell('0.1.0-dev+69cf216687', '0.1.0-dev%2B69cf216687'), StationShell::html('0.1.0-dev+69cf216687'));
    }

    public function testShellHoldsNoOrganisationNameOrToken(): void
    {
        $html = StationShell::html('x');
        foreach (['CHS', 'csrf', '_token', 'token', 'nonce', 'PFPMSST', 'PFPMSSESS', 'organisation'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $html, "the shell is the same for every tablet: no $forbidden");
        }
        $this->assertStringContainsString('<title>Pet Pantry Station</title>', $html, 'only the app name');
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="Pet Pantry Station">', $html);
    }

    public function testKillWorkerNeverMentionsIndexedDb(): void
    {
        $kill = StationShell::KILL_WORKER;
        $this->assertStringNotContainsStringIgnoringCase('indexedDB', $kill, 'the kill worker never touches the records stored on the tablet');
        $this->assertStringNotContainsStringIgnoringCase('deleteDatabase', $kill);
        $this->assertStringContainsString("{ type: 'SW_KILLED' }", $kill);
        $this->assertStringContainsString('self.registration.unregister()', $kill);
        $this->assertStringContainsString("startsWith('pfpms-shell-')", $kill, 'only the Station shell caches');
        $this->assertStringNotContainsString('clients.claim', $kill, 'it never claims');
        $this->assertStringNotContainsString('importScripts', $kill);
        $this->assertStringNotContainsString("\r", $kill);
        $this->assertStringEndsWith("});\n", $kill);
    }

    public function testStationCspHasTrustedTypes(): void
    {
        $directives = $this->directives(StationShell::CSP);
        $this->assertSame("'script'", $directives['require-trusted-types-for'] ?? null);
        $this->assertSame('pfpms-sw', $directives['trusted-types'] ?? null, 'the only policy is boot.js\'s pfpms-sw');
        $this->assertSame("'self'", $directives['worker-src'] ?? null);
        $this->assertSame("'self'", $directives['manifest-src'] ?? null);
        $this->assertStringEndsWith("require-trusted-types-for 'script'; trusted-types pfpms-sw", StationShell::CSP);
    }

    public function testStationCspKeepsEveryBootstrapDirective(): void
    {
        $station = $this->directives(StationShell::CSP);
        $bootstrap = $this->directives(SecurityHeaders::CSP);
        $this->assertCount(10, $bootstrap);
        foreach ($bootstrap as $name => $value) {
            $this->assertSame($value, $station[$name] ?? null, "the Station CSP keeps $name $value unchanged");
        }
        $this->assertSame(['worker-src', 'manifest-src', 'require-trusted-types-for', 'trusted-types'], array_values(array_diff(array_keys($station), array_keys($bootstrap))),
            'and adds only these');
    }

    public function testShellStartingTextIsCopyStarting(): void
    {
        $copy = (string) @file_get_contents(self::STATION . '/js/copy.js');
        $this->assertSame(1, preg_match("~^\\s*starting:\\s*'([^'\\\\]*)',~m", $copy, $m), 'js/copy.js has COPY.starting');
        $this->assertSame(1, preg_match('~<p class="starting">([^<]*)</p>~u', StationShell::html('x'), $shell));
        $this->assertSame($m[1], $shell[1], 'the shell says what the starting view says (COPY.starting)');
        // The <noscript> sentence is the one people-facing string outside copy.js: no script runs to show it.
        $this->assertStringContainsString('<noscript><p class="noscript">The Station needs JavaScript. Turn it on in the browser\'s settings.</p></noscript>',
            StationShell::html('x'));
    }

    public function testWorkerWrapperIsExact(): void
    {
        $entry = ['path' => 'js/app.js', 'sha256' => str_repeat('ab', 32), 'type' => 'javascript'];
        $this->assertSame('const BUILD = "b";' . "\n"
            . 'const PRECACHE = [{"path":"js/app.js","sha256":"' . str_repeat('ab', 32) . '","type":"javascript"}];' . "\n"
            . "core\n", StationShell::worker('b', [$entry], "core\n"));
        $this->assertSame("const BUILD = \"\";\nconst PRECACHE = [];\n", StationShell::worker('', [], ''));
        $this->assertSame('const BUILD = "test+0123456789";', explode("\n", StationShell::worker('test+0123456789', [], ''))[0], 'the + is not escaped');
    }

    public function testWorkerCoreDeclaresNeitherBuildNorPrecache(): void
    {
        $core = (string) @file_get_contents(self::STATION . '/' . StationAssets::WORKER_CORE);
        $this->assertStringContainsString('var PFPMS_SW', $core, 'js/sw-core.js is the worker core');
        $this->assertDoesNotMatchRegularExpression('~\b(?:const|let|var)\s+(?:BUILD|PRECACHE)\b~', $core, 'sw.php declares BUILD and PRECACHE before it');
        $this->assertDoesNotMatchRegularExpression('~\bfunction\s+(?:BUILD|PRECACHE)\b~', $core);
        $this->assertStringNotContainsString('indexedDB', $core, 'the worker never opens the database');
        $this->assertDoesNotMatchRegularExpression('~^\s*(?:import|export)\b~m', $core, 'a classic script');
    }

    public function testIndexPhpPrintsOnlyTheShell(): void
    {
        $code = (string) file_get_contents(self::STATION . '/index.php');
        $this->assertStringNotContainsString('?>', $code, 'nothing outside PHP');
        $this->assertStringEndsWith("StationShell::sendHeaders();\necho StationShell::html();\n", $code);
        $this->assertSame(1, preg_match_all('~\b(?:echo|print|printf|readfile|fpassthru)\b~', $code), 'one echo, of html()');
        $this->assertStringContainsString("Response::redirect('station/index.php', 301);", $code, 'php -S serves /station without the slash');
        $worker = (string) file_get_contents(self::STATION . '/sw.php');
        $this->assertStringNotContainsString('?>', $worker);
        $this->assertSame(1, preg_match_all('~\b(?:echo|print|printf|readfile|fpassthru)\b~', $worker), 'sw.php echoes the worker or the kill worker only');
        $this->assertStringContainsString('StationShell::sendHeaders(true);', $worker);
    }

    public function testSwPhpAnswers503WhileMaintenanceIsOn(): void
    {
        // A deploy copies public/ before src/ (10-design d5): in between, the files on disk would make a worker that
        // verifies against them and installs a build that lacks the new modules. Under maintenance no worker is served.
        [$status, $body] = $this->runSwPhp(['env' => 'test', 'app' => ['maintenance' => true]]);
        $this->assertSame(503, $status, 'maintenance: the update check fails and every tablet keeps the worker it has');
        $this->assertSame('', $body, 'no worker bytes at all');

        [$status, $body] = $this->runSwPhp(['env' => 'test', 'app' => ['maintenance' => true], 'station' => ['sw_kill' => true]]);
        $this->assertNotSame(503, $status, 'the kill switch wins over maintenance');
        $this->assertSame(StationShell::KILL_WORKER, $body, 'the kill worker reads no Station file, so it is safe mid-deploy');

        [$status, $body] = $this->runSwPhp(['env' => 'test', 'app' => ['maintenance' => false]]);
        $this->assertNotSame(503, $status);
        $this->assertStringStartsWith('const BUILD = "', $body, 'maintenance off: the worker');
        $this->assertStringContainsString("\nconst PRECACHE = [{\"path\":\"./\",", $body);
        $this->assertStringContainsString('var PFPMS_SW', $body);

        $code = (string) file_get_contents(self::STATION . '/sw.php');
        $this->assertMatchesRegularExpression("~if \\(!StationConfig::swKill\\(\\) && Config::get\\('app\\.maintenance'\\) === true\\) \\{[^}]*"
            . "http_response_code\\(503\\);\\s*header\\('Retry-After: 120'\\);\\s*exit;\\s*\\}\\s*StationShell::sendHeaders\\(true\\);~", $code,
            'the 503 carries Retry-After (as Api::start\'s maintenance answer does) and comes before any Station header');
    }

    // Helpers -----------------------------------------------------------------------------------

    /**
     * Runs public/station/sw.php in a PHP subprocess (CLI, so no header is sent) with only the given config.
     * @param array<string, mixed> $config
     * @return array{0: int|false, 1: string} the response code it set (false: none, i.e. 200) and the body it printed
     */
    private function runSwPhp(array $config): array
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/pfpms-sw-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            file_put_contents("$dir/config.php", '<?php return ' . var_export($config, true) . ";\n");
            file_put_contents("$dir/run.php", "<?php\nregister_shutdown_function(static function (): void {\n"
                . "    fwrite(STDERR, 'status=' . json_encode(http_response_code()));\n});\n"
                . 'require ' . var_export(self::STATION . '/sw.php', true) . ";\n");
            $env = ['PFPMS_CONFIG' => "$dir/config.php"] + getenv();
            $proc = proc_open([PHP_BINARY, "$dir/run.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
            $this->assertIsResource($proc);
            $body = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            $this->assertSame(1, preg_match('~status=(false|\d+)$~', $err, $m), "sw.php ran to its end (exit $exit): $err");
            return [$m[1] === 'false' ? false : (int) $m[1], $body];
        } finally {
            @unlink("$dir/config.php");
            @unlink("$dir/run.php");
            @rmdir($dir);
        }
    }

    /** @return list<string> bootstrap's lines with the Station CSP in place of its own */
    private function bootstrapLinesWithStationCsp(): array
    {
        return [
            'Cache-Control: no-store, no-cache, private, max-age=0',
            'Pragma: no-cache',
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: same-origin',
            'Permissions-Policy: camera=(self), microphone=(), geolocation=()',
            'Content-Security-Policy: ' . StationShell::CSP,
        ];
    }

    /** @return array<string, string> directive name => its value, in order */
    private function directives(string $csp): array
    {
        $out = [];
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            [$name, $value] = explode(' ', $part, 2) + [1 => ''];
            $this->assertArrayNotHasKey($name, $out, "$name appears once");
            $out[$name] = $value;
        }
        return $out;
    }

    /** The shell exactly as spec'd (s2_spec §2.2), for one build. */
    private function exactShell(string $attr, string $query): string
    {
        return "<!doctype html>\n"
            . "<html lang=\"en\" data-build=\"$attr\">\n"
            . "<head>\n"
            . "<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "<meta name=\"theme-color\" content=\"#1f5f5b\">\n"
            . "<meta name=\"color-scheme\" content=\"light\">\n"
            . "<meta name=\"mobile-web-app-capable\" content=\"yes\">\n"
            . "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n"
            . "<meta name=\"apple-mobile-web-app-title\" content=\"Pet Pantry Station\">\n"
            . "<title>Pet Pantry Station</title>\n"
            . "<link rel=\"manifest\" href=\"manifest.json\">\n"
            . "<link rel=\"icon\" type=\"image/png\" sizes=\"192x192\" href=\"icons/icon-192.png\">\n"
            . "<link rel=\"apple-touch-icon\" href=\"icons/apple-touch-icon.png\">\n"
            . "<link rel=\"stylesheet\" href=\"css/station.css\">\n"
            . "<script type=\"module\" src=\"boot.js?v=$query\"></script>\n"
            . "</head>\n"
            . "<body>\n"
            . "<main id=\"app\" class=\"app\"><p class=\"starting\">Starting\u{2026}</p></main>\n"
            . "<noscript><p class=\"noscript\">The Station needs JavaScript. Turn it on in the browser's settings.</p></noscript>\n"
            . "</body>\n"
            . "</html>\n";
    }
}
