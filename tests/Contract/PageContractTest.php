<?php
declare(strict_types=1);

namespace Pfpms\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Pfpms\Station\StationShell;

/**
 * Every web entry point follows the page skeleton (plan §4): bootstrap first, then the
 * guard (Page::start or Api::start) before any input is read, and every page that handles
 * a POST verifies the CSRF token.
 */
final class PageContractTest extends TestCase
{
    /** Each api/auth/ endpoint's Api::start() options, exactly (S3 spec §2.8). */
    private const STATION_AUTH_GUARDS = [
        'api/auth/login.php' => "['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'public' => true, 'touch' => false]",
        'api/auth/pin.php' => "['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'public' => true, 'touch' => false]",
        'api/auth/pin_set.php' => "['method' => 'POST', 'device' => 'in_service', 'capability' => 'auth.pin_switch']",
        'api/auth/logout.php' => "['method' => 'POST', 'device' => 'known', 'public' => true, 'touch' => false]",
        'api/auth/policy.php' => "['method' => ['GET', 'POST'], 'device' => 'in_service', 'proof' => true, 'policy_ack' => true]",
        'api/auth/password.php' => "['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'password_change' => true, 'policy_ack' => true]",
    ];

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

    public function testThePasswordChangePagesGoThroughTheirServices(): void
    {
        // The current password is checked before the transaction and the new one written in it: they meet only under the
        // account lock (Auth::lockVerified), so a reset committed in between wins. The pages never check or set a password.
        $root = dirname(__DIR__, 2);
        foreach (['public/change_password.php' => 'Auth::changeOwnPassword(', 'public/api/auth/password.php' => 'StationAuth::changePassword(',
            'public/api/auth/pin_set.php' => 'Pin::set('] as $name => $service) {
            $code = (string) file_get_contents("$root/$name");
            $this->assertSame(1, substr_count($code, $service), "$name calls $service");
            $this->assertDoesNotMatchRegularExpression('/Auth::(verifyPassword|verifiedHash|setPassword|setPasswordHash|lockVerified)\(|Db::transaction\(|PasswordPolicy::/',
                $code, "$name: the service checks, hashes and writes");
        }
        // A change that lost to a reset, a deactivation or another change comes back as null (AuthTest drives those races): the
        // page reloads so that Page::start() signs the person out, and null never reads as a success.
        $page = (string) file_get_contents("$root/public/change_password.php");
        $this->assertMatchesRegularExpression('~\$errors = Auth::changeOwnPassword\([^;]*\);\s*if \(\$errors === null\) \{\s*Response::redirect\(\'change_password\.php\'\);~',
            $page, 'change_password.php reloads itself when changeOwnPassword() returns null');
        $this->assertStringContainsString('if ($errors === []) {', $page, 'only [] is a success');
        $this->assertDoesNotMatchRegularExpression('/!\s*\$errors\b|empty\(\$errors\)|catch \(/', $page, 'null must not read as a success; the service maps the refusals');
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

    // JSON endpoints under public/api/ (50-design §5.8) -----------------------------------------------

    public function testApiEndpointsUseApiStartWithAMethod(): void
    {
        foreach ($this->apiEndpoints() as $name => $code) {
            $this->assertTrue((bool) preg_match('~^\s*(\$ctx\s*=\s*)?Api::start\(\[[^;]*\'method\'\s*=>~', $this->afterImports($code)),
                "$name: the first statement after the imports must be Api::start([... 'method' => ...])");
        }
    }

    public function testPublicApiPostsVerifyCsrf(): void
    {
        $checked = [];
        foreach ($this->apiEndpoints() as $name => $code) {
            $options = $this->apiOptions($code);
            if (preg_match("~'public'\s*=>\s*true~", $options) && in_array('POST', $this->methods($options), true)) {
                $this->assertStringContainsString('Csrf::verify()', $code, "$name accepts an anonymous POST without an explicit Csrf::verify()");
                $checked[] = $name;
            }
        }
        $this->assertContains('api/device/register.php', $checked, 'the rule found the public POST endpoints');
        $this->assertContains('api/session.php', $checked);
        $this->assertContains('api/auth/login.php', $checked);
        $this->assertContains('api/auth/pin.php', $checked);
        $this->assertContains('api/auth/logout.php', $checked);
    }

    public function testApiEndpointsNeverTestIsPost(): void
    {
        foreach ($this->apiEndpoints() as $name => $code) {
            $this->assertStringNotContainsString('Request::isPost()', $code, "$name: the method is Api::start's 'method' option, never Request::isPost()");
        }
    }

    public function testSessionlessApiEndpointsNameADeviceOrPublic(): void
    {
        $sessionless = [];
        foreach ($this->apiEndpoints() as $name => $code) {
            $options = $this->apiOptions($code);
            if (preg_match("~'session'\s*=>\s*false~", $options)) {
                $this->assertTrue(preg_match("~'device'\s*=>~", $options) === 1 || preg_match("~'public'\s*=>\s*true~", $options) === 1,
                    "$name: an endpoint with no session must authenticate a tablet or be deliberately public");
                $sessionless[] = $name;
            }
        }
        $this->assertContains('api/ping.php', $sessionless, 'the rule found the sessionless endpoints');
        $this->assertContains('api/device/heartbeat.php', $sessionless);
    }

    public function testSessionEndpointTouchesOnlyOnPost(): void
    {
        // GET api/session.php is the Station's poll and must never extend the idle timer; POST {"action":"touch"} is the
        // keep-alive (50-design §5.9, §6.2, D-05).
        $endpoints = $this->apiEndpoints();
        $this->assertArrayHasKey('api/session.php', $endpoints);
        $options = $this->apiOptions($endpoints['api/session.php']);
        $this->assertNotSame('', $options, 'api/session.php calls Api::start([...])');
        $this->assertMatchesRegularExpression("~'touch'\s*=>\s*Request::method\(\)\s*===\s*'POST'\s*[,\]]~", $options,
            "api/session.php: Api::start's options must contain 'touch' => Request::method() === 'POST'");
        $this->assertSame(1, preg_match_all("~'touch'\s*=>~", $options), 'one touch option, not overridden by another');
        $this->assertSame(['GET', 'POST'], $this->methods($options));
        $this->assertMatchesRegularExpression("~'public'\s*=>\s*true~", $options);
    }

    // The Station's sign-in endpoints (50-design §6.5-§6.10, S3 spec §2.8) -----------------------------

    public function testStationAuthEndpointsHaveTheirExactGuards(): void
    {
        $endpoints = $this->apiEndpoints();
        foreach (self::STATION_AUTH_GUARDS as $name => $expected) {
            $this->assertArrayHasKey($name, $endpoints, "$name exists");
            $this->assertSame(1, substr_count($endpoints[$name], 'Api::start('), "$name: one guard");
            $this->assertSame($this->normalised($expected), $this->normalised($this->apiOptions($endpoints[$name])), "$name: Api::start's options (S3 spec §2.8)");
        }
    }

    public function testKeyReleasingEndpointsRequireTheProof(): void
    {
        // A sign-in, an acceptance or a password change can release the tablet's vault key and an offline grant; a PIN switch
        // starts a session on the tablet. Only a request the tablet itself signed may do either (50-design §5.1, D-59).
        $endpoints = $this->apiEndpoints();
        foreach (['api/auth/login.php', 'api/auth/pin.php', 'api/auth/policy.php', 'api/auth/password.php'] as $name) {
            $this->assertArrayHasKey($name, $endpoints);
            $options = $this->apiOptions($endpoints[$name]);
            $this->assertMatchesRegularExpression("~'device'\s*=>\s*'in_service'\s*[,\]]~", $options, "$name: only a tablet in service");
            $this->assertMatchesRegularExpression("~'proof'\s*=>\s*true\s*[,\]]~", $options, "$name: the tablet's proof is required");
            $this->assertSame(2, preg_match_all("~'(device|proof)'\s*=>~", $options), "$name: one device and one proof option");
        }
    }

    public function testSignInAndPinSwitchRegenerateTheSessionAfterTheService(): void
    {
        $endpoints = $this->apiEndpoints();
        foreach (['api/auth/login.php' => 'StationAuth::login(', 'api/auth/pin.php' => 'StationAuth::pinSwitch('] as $name => $service) {
            $code = $endpoints[$name] ?? '';
            $at = strpos($code, $service);
            $login = strpos($code, 'WebSession::login(');
            $this->assertNotFalse($at, "$name calls $service");
            $this->assertNotFalse($login, "$name calls WebSession::login()");
            $this->assertGreaterThan($at, $login, "$name: a new PHP session id and CSRF token after the service succeeded");
            $this->assertSame(1, substr_count($code, 'WebSession::'), "$name: WebSession::login() only");
        }

        $policy = $endpoints['api/auth/policy.php'] ?? '';
        $this->assertMatchesRegularExpression('~if \(\$r\[\'signed_out\'\]\) \{\s*WebSession::restart\(\);\s*\} else \{\s*WebSession::regenerate\(\);\s*\}~', $policy,
            'policy.php: a decline restarts the session, an acceptance regenerates it');
        $this->assertGreaterThan(strpos($policy, 'StationAuth::decidePolicy('), strpos($policy, 'WebSession::'), 'policy.php: after the service');
        $password = $endpoints['api/auth/password.php'] ?? '';
        $this->assertGreaterThan(strpos($password, 'StationAuth::changePassword('), strpos($password, 'WebSession::regenerate();'), 'password.php: after the service');

        // logout.php restarts the PHP session only when the session it carries was ended: a replayed End shift must never sign
        // out the person who signed in after it (S3 spec §2.7 ended_current).
        $logout = $endpoints['api/auth/logout.php'] ?? '';
        $this->assertSame(1, preg_match('~if \(\$r\[\'ended_current\'\]\) \{(.*?)\n\}~s', $logout, $m), 'logout.php tests ended_current');
        $this->assertStringContainsString('WebSession::restart();', $m[1]);
        $this->assertSame(1, substr_count($logout, 'WebSession::'), 'logout.php: its one WebSession call is the restart inside that if');
        $this->assertGreaterThan(strpos($logout, 'StationAuth::logout('), strpos($logout, 'WebSession::restart();'));
    }

    public function testSignInEndpointsNeverTouchThePreviousSession(): void
    {
        // The cookie may still carry the previous person's session: a sign-in, a PIN switch or a logout attempt is not their
        // activity, so validating it must not extend their idle timer (S3 spec §7 #12).
        $endpoints = $this->apiEndpoints();
        foreach (['api/auth/login.php', 'api/auth/pin.php', 'api/auth/logout.php'] as $name) {
            $options = $this->apiOptions($endpoints[$name] ?? '');
            $this->assertMatchesRegularExpression("~'touch'\s*=>\s*false\s*[,\]]~", $options, "$name: 'touch' => false");
            $this->assertSame(1, preg_match_all("~'touch'\s*=>~", $options), "$name: one touch option");
        }
    }

    public function testNothingSendsCorsHeaders(): void
    {
        $root = dirname(__DIR__, 2);
        $scanned = 0;
        foreach (['src', 'public', 'templates'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $scanned++;
                $this->assertStringNotContainsStringIgnoringCase('Access-Control-Allow', (string) file_get_contents($file->getPathname()),
                    substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1) . ': the API is same-origin only; no CORS header');
            }
        }
        $this->assertGreaterThan(0, $scanned);
        $this->assertFileExists("$root/public/.htaccess", '.htaccess is scanned too (Header set would be another way to send one)');
    }

    public function testNothingHardCodesTheWebRootDirectory(): void
    {
        // The web root is public/ in the repo and public_html/ on SiteGround: code that runs on the host finds it through
        // Request::publicDir(), never by naming public/ (s2 F1; a hard-coded public/ only fails on the host).
        $root = dirname(__DIR__, 2);
        $patterns = [
            '~\bAPP_ROOT\s*\.\s*[\'"]/public\b~',
            '~\b__DIR__\s*\.\s*[\'"](?:/\.\.)+/public\b~',
            '~\bdirname\(__DIR__(?:\s*,\s*\d+)?\)\s*\.\s*[\'"]/public\b~',
        ];
        $scanned = 0;
        foreach (['src', 'public', 'templates'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $scanned++;
                $code = (string) file_get_contents($file->getPathname());
                $name = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
                foreach ($patterns as $pattern) {
                    $this->assertSame(0, preg_match($pattern, $code, $m), "$name names public/ on disk (" . ($m[0] ?? '') . '): use Request::publicDir() (public_html/ on SiteGround)');
                }
            }
        }
        $this->assertGreaterThan(100, $scanned, 'src/, public/ and templates/ were scanned');
        foreach (["APP_ROOT . '/public/notifications.php'", "__DIR__ . '/../public/x'", "dirname(__DIR__, 2) . '/public'"] as $sample) {
            $this->assertTrue(array_reduce($patterns, static fn(bool $hit, string $p): bool => $hit || preg_match($p, $sample) === 1, false),
                "the rule catches $sample");
        }
        $this->assertSame(0, preg_match($patterns[0], "Request::publicDir() . '/notifications.php'"), 'publicDir() passes');
    }

    // The Station shell and its worker (50-design §5.8, S2) ----------------------------------------------

    public function testStationShellFilesStartNoSession(): void
    {
        $root = dirname(__DIR__, 2) . '/public/station';
        $files = glob("$root/*.php") ?: [];
        sort($files);
        $this->assertSame(['index.php', 'sw.php'], array_map('basename', $files), 'public/station/ has exactly the shell and the worker as PHP');
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $this->assertMatchesRegularExpression("~^\\s*Page::start\\(\\['public' => true, 'session' => false\\]\\);~", $this->afterImports($code),
                'station/' . basename($file) . ": the first statement must be Page::start(['public' => true, 'session' => false]);");
            foreach (['WebSession', 'Api::start', '$_SESSION', 'Csrf::'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, 'station/' . basename($file) . " must not use $forbidden");
            }
        }
    }

    public function testStationShellHasNoInlineScriptOrStyle(): void
    {
        foreach (['', 'test+0123456789'] as $build) {
            $html = StationShell::html($build);
            $this->assertSame(1, preg_match_all('~<script\b([^>]*)>~i', $html, $m), 'the shell has one script element');
            $this->assertMatchesRegularExpression('~\btype="module"~', $m[1][0]);
            $this->assertMatchesRegularExpression('~\bsrc="boot\.js\?v=[^"]*"~', $m[1][0]);
            $this->assertDoesNotMatchRegularExpression('~<script\b[^>]*>\s*[^<\s]~i', $html, 'no inline script body');
            $this->assertDoesNotMatchRegularExpression('~<style\b~i', $html);
            $this->assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $html);
            $this->assertDoesNotMatchRegularExpression('~\son[a-z]+\s*=~i', $html, 'no inline event handlers');
            $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        }
        foreach (glob(dirname(__DIR__, 2) . '/public/station/*.php') ?: [] as $file) {
            $this->assertStringNotContainsString('<script', (string) file_get_contents($file), basename($file) . ' prints the shell through StationShell only');
            $this->assertStringNotContainsString('?>', (string) file_get_contents($file), basename($file) . ' prints nothing outside PHP');
        }
    }

    /** @return array<string, string> every PHP file under public/api/, by its path under public/ */
    private function apiEndpoints(): array
    {
        $root = dirname(__DIR__, 2) . '/public/';
        $files = [];
        foreach ($this->entryPoints() as $path) {
            if (str_starts_with($path, str_replace('\\', '/', $root) . 'api/')) {
                $files[substr($path, strlen($root))] = (string) file_get_contents($path);
            }
        }
        $this->assertNotEmpty($files, 'public/api/ has endpoints');
        return $files;
    }

    /** The code after the bootstrap require, the imports and any leading line comments (as the first test strips them). */
    private function afterImports(string $code): string
    {
        $body = (string) preg_replace('~^.*?src/bootstrap\.php\';~s', '', $code);
        $body = (string) preg_replace('~^\s*(use [^;]+;\s*)+~', '', $body);
        return (string) preg_replace('~^\s*(//[^\n]*\n\s*)+~', '', $body);
    }

    /** Option text with its whitespace normalised: runs collapsed, none inside the brackets, no trailing comma. */
    private function normalised(string $options): string
    {
        $text = (string) preg_replace('/\s+/', ' ', trim($options));
        return (string) preg_replace(['/\[\s+/', '/\s+\]/', '/,\s*\]/'], ['[', ']', ']'], $text);
    }

    /** The options array literal of the file's Api::start([...]) call, or '' when there is none. */
    private function apiOptions(string $code): string
    {
        return preg_match('~Api::start\((\[[^;]*\])\);~', $code, $m) ? $m[1] : '';
    }

    /** @return list<string> the methods named by 'method' => 'X' or 'method' => ['X', 'Y'] */
    private function methods(string $options): array
    {
        if (!preg_match("~'method'\s*=>\s*(\[[^\]]*\]|'[A-Z]+')~", $options, $m)) {
            return ['GET']; // Api::start's default
        }
        preg_match_all("~'([A-Z]+)'~", $m[1], $names);
        return $names[1];
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
