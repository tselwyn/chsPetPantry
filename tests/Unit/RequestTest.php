<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Config;
use Pfpms\Http\HttpException;
use Pfpms\Http\Json;
use Pfpms\Http\Request;

/** The request primitives the Station API reads (50-design §5.3): Authorization, media type, script path and the JSON body. */
final class RequestTest extends TestCase
{
    private array $server;
    private array $saved;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->saved = Config::snapshot();
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE'],
            $_SERVER['CONTENT_LENGTH']);
        Request::useBody(null);
    }

    protected function tearDown(): void
    {
        Request::useBody(null);
        $_SERVER = $this->server;
        Config::override($this->saved);
    }

    // Helpers -------------------------------------------------------------------------------

    private function useBasePath(string $basePath): void
    {
        $app = $this->saved['app'] ?? [];
        $app['base_path'] = $basePath;
        Config::override(['app' => $app] + $this->saved);
    }

    /** The HttpException $fn throws. */
    private function refusal(callable $fn): HttpException
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('expected an HttpException');
    }

    private function assertRefused(int $status, string $code, callable $fn, string $message = ''): HttpException
    {
        $e = $this->refusal($fn);
        $this->assertSame([$status, $code], [$e->status, $e->code()], $message);
        return $e;
    }

    // Authorization ---------------------------------------------------------------------------

    public function testAuthorizationFallsBackToTheRedirectVariable(): void
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'PFPMS-Device pfd1_redirected';
        $this->assertSame('PFPMS-Device pfd1_redirected', Request::authorization());
    }

    public function testAuthorizationPrefersHttpAuthorization(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'PFPMS-Device pfd1_direct';
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'PFPMS-Device pfd1_redirected';
        $this->assertSame('PFPMS-Device pfd1_direct', Request::authorization());
    }

    public function testAuthorizationIsNullWithoutTheHeader(): void
    {
        $this->assertFalse(function_exists('getallheaders'), 'the CLI has no getallheaders(), so only $_SERVER is read here');
        $this->assertNull(Request::authorization());
    }

    public function testAuthorizationIgnoresANonStringValue(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = ['PFPMS-Device pfd1_array'];
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'PFPMS-Device pfd1_redirected';
        $this->assertSame('PFPMS-Device pfd1_redirected', Request::authorization(), 'only a string counts');
    }

    // Content type ----------------------------------------------------------------------------

    public function testContentTypeIgnoresParametersAndHttpContentType(): void
    {
        $_SERVER['CONTENT_TYPE'] = ' Application/JSON ; charset=UTF-8';
        $this->assertSame('application/json', Request::contentType());

        unset($_SERVER['CONTENT_TYPE']);
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $this->assertSame('', Request::contentType(), 'HTTP_CONTENT_TYPE (php -S only) is never read');
    }

    public function testJsonAcceptsTheCharsetParameterButRefusesTextPlain(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json; charset=utf-8';
        // The CLI's php://input is empty: getting past the type check to bad_json shows the type was accepted.
        $this->assertRefused(400, 'bad_json', fn() => Request::json(), 'the charset parameter does not make it another type');
        Request::useBody(null);
        $_SERVER['CONTENT_TYPE'] = 'text/plain;charset=UTF-8';
        $this->assertRefused(415, 'unsupported_media_type', fn() => Request::json(), 'a no-cors fetch sends text/plain');
    }

    // Script path -----------------------------------------------------------------------------

    public function testScriptPathDropsTheBasePathAndQuery(): void
    {
        $this->useBasePath('/pfpms/');
        $_SERVER['SCRIPT_NAME'] = '/pfpms/api/device/heartbeat.php';
        $_SERVER['REQUEST_URI'] = '/pfpms/api/device/heartbeat.php?retry=1';
        $_SERVER['QUERY_STRING'] = 'retry=1';
        $this->assertSame('api/device/heartbeat.php', Request::scriptPath());

        $this->useBasePath('/');
        $_SERVER['SCRIPT_NAME'] = '/api/ping.php';
        $this->assertSame('api/ping.php', Request::scriptPath(), 'at the web root');
    }

    public function testScriptPathOutsideTheBasePathOnlyLosesItsLeadingSlash(): void
    {
        $this->useBasePath('/pfpms/');
        $_SERVER['SCRIPT_NAME'] = '/elsewhere/api/ping.php';
        $this->assertSame('elsewhere/api/ping.php', Request::scriptPath());
    }

    public function testScriptPathUsesForwardSlashes(): void
    {
        $this->useBasePath('/pfpms/');
        $_SERVER['SCRIPT_NAME'] = '\\pfpms\\api\\device\\register.php';
        $this->assertSame('api/device/register.php', Request::scriptPath());
    }

    public function testScriptPathOfANestedEndpointUnderADerivedBasePath(): void
    {
        $this->useBasePath('');
        $_SERVER['SCRIPT_NAME'] = '/chsPetPantry/public/api/device/heartbeat.php';
        $_SERVER['SCRIPT_FILENAME'] = APP_ROOT . '/public/api/device/heartbeat.php';
        $this->assertSame('/chsPetPantry/public/', Request::basePath(), 'the base is public/, not the endpoint\'s own directory');
        $this->assertSame('api/device/heartbeat.php', Request::scriptPath(), 'what the tablet signs');

        $_SERVER['SCRIPT_NAME'] = '/api/device/heartbeat.php'; // php -S from public/ (dev, localhost:8088)
        $this->assertSame('api/device/heartbeat.php', Request::scriptPath());
    }

    // Base path -------------------------------------------------------------------------------

    public function testBasePathForTheSiteGroundLayout(): void
    {
        // SiteGround: the web root is public_html/ beside src/, served at the domain's root.
        $root = '/home/customer/www/pantry.example.org';
        $this->assertSame('/', Request::basePathFor('/api/device/heartbeat.php', "$root/public_html/api/device/heartbeat.php", "$root/public_html"));
        $this->assertSame('/', Request::basePathFor('/api/ping.php', "$root/public_html/api/ping.php", "$root/public_html"));
        $this->assertSame('/', Request::basePathFor('/index.php', "$root/public_html/index.php", "$root/public_html/"), 'a trailing slash on the web root');
        $this->assertNull(Request::basePathFor('/api/ping.php', "$root/public_html/api/ping.php", "$root/public"),
            'public/ is not a prefix of public_html/');
    }

    public function testBasePathForTheXamppHtdocsLayout(): void
    {
        $public = 'C:/xampp/htdocs/chsPetPantry/public';
        $this->assertSame('/chsPetPantry/public/',
            Request::basePathFor('/chsPetPantry/public/api/device/heartbeat.php', "$public/api/device/heartbeat.php", $public),
            'the base is public/, not the endpoint\'s own directory');
        $this->assertSame('/chsPetPantry/public/', Request::basePathFor('/chsPetPantry/public/index.php', "$public/index.php", $public));
        $this->assertSame('/', Request::basePathFor('/api/session.php', "$public/api/session.php", $public), 'php -S from public/ (dev)');
    }

    public function testBasePathForAFileOutsideTheWebRootIsNull(): void
    {
        $root = '/home/customer/www/pantry.example.org';
        $public = "$root/public_html";
        $this->assertNull(Request::basePathFor('/bin/cron.php', "$root/bin/cron.php", $public), 'beside the web root');
        $this->assertNull(Request::basePathFor('/api/ping.php', "{$public}_old/api/ping.php", $public), 'a sibling whose name starts the same');
        $this->assertNull(Request::basePathFor('/api/ping.php', $public, $public), 'the web root itself is not a script in it');
        $this->assertNull(Request::basePathFor('/other/ping.php', "$public/api/ping.php", $public), 'the URL does not end with the file\'s path');
        $this->assertNull(Request::basePathFor('/api/ping.php', '', $public), 'no file (realpath() failed)');
        $this->assertNull(Request::basePathFor('/api/ping.php', "$public/api/ping.php", ''), 'no web root');
    }

    public function testBasePathForNormalisesBackslashes(): void
    {
        $this->assertSame('/chsPetPantry/public/', Request::basePathFor('/chsPetPantry/public/api/ping.php',
            'C:\\xampp\\htdocs\\chsPetPantry\\public\\api\\ping.php', 'C:\\xampp\\htdocs\\chsPetPantry\\public\\'), 'Windows paths');
        $this->assertSame('/chsPetPantry/public/', Request::basePathFor('\\chsPetPantry\\public\\api\\device\\register.php',
            'C:\\xampp\\htdocs\\chsPetPantry\\public\\api\\device\\register.php', 'C:/xampp/htdocs/chsPetPantry/public'), 'and a backslashed SCRIPT_NAME');
        $this->assertNull(Request::basePathFor('/chsPetPantry/public/api/ping.php', 'C:\\xampp\\htdocs\\chsPetPantry\\src\\ping.php',
            'C:\\xampp\\htdocs\\chsPetPantry\\public'), 'still outside the web root');
    }

    public function testBasePathIsKnownWhenConfigured(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/api/device/heartbeat.php';
        $_SERVER['SCRIPT_FILENAME'] = APP_ROOT . '/src/bootstrap.php'; // nothing to work it out from
        $this->useBasePath('/pfpms/');
        $this->assertTrue(Request::basePathIsKnown());
        $this->assertSame('/pfpms/', Request::basePath());
        $this->useBasePath('/');
        $this->assertTrue(Request::basePathIsKnown(), 'the web root');
        $this->assertSame('/', Request::basePath());
    }

    public function testBasePathIsKnownWhenTheScriptIsInTheWebRoot(): void
    {
        $this->useBasePath('');
        $_SERVER['SCRIPT_NAME'] = '/chsPetPantry/public/api/device/heartbeat.php';
        $_SERVER['SCRIPT_FILENAME'] = APP_ROOT . '/public/api/device/heartbeat.php';
        $this->assertTrue(Request::basePathIsKnown(), 'worked out from public/');

        $_SERVER['SCRIPT_FILENAME'] = APP_ROOT . '/src/bootstrap.php';
        $this->assertFalse(Request::basePathIsKnown(), 'neither configured nor found');
        $this->assertSame('/chsPetPantry/public/api/device/', Request::basePath(), 'basePath() then only guesses the script\'s own directory');
    }

    // Body ------------------------------------------------------------------------------------

    public function testParseJsonRefusesAnotherMediaType(): void
    {
        foreach (['text/plain', '', 'application/x-www-form-urlencoded', 'application/json-patch+json'] as $type) {
            $this->assertRefused(415, 'unsupported_media_type', fn() => Request::parseJson('{"a":1}', $type, 100), "'$type'");
        }
        $e = $this->refusal(fn() => Request::parseJson('{"a":1}', 'text/plain', 100));
        $this->assertSame('The request was sent in the wrong format.', $e->getMessage());
    }

    public function testParseJsonRefusesOversizeBodies(): void
    {
        $body = '{"a":"' . str_repeat('x', 10) . '"}'; // 18 bytes
        $this->assertSame(['a' => str_repeat('x', 10)], Request::parseJson($body, 'application/json', 18), 'exactly the limit is accepted');
        $e = $this->assertRefused(413, 'too_large', fn() => Request::parseJson($body, 'application/json', 17));
        $this->assertSame('That is too much to send at once.', $e->getMessage());
    }

    public function testParseJsonRefusesBadJson(): void
    {
        foreach (['{"a":', '', '{a:1}', "{'a':1}", '{"a":1,}', '{"a":NaN}'] as $raw) {
            $e = $this->assertRefused(400, 'bad_json', fn() => Request::parseJson($raw, 'application/json', 1000), "'$raw'");
            $this->assertSame('The request was not valid JSON.', $e->getMessage());
        }
    }

    public function testParseJsonRefusesNestingDeeperThan64(): void
    {
        $deep = str_repeat('{"a":', 64) . '1' . str_repeat('}', 64);
        $this->assertRefused(400, 'bad_json', fn() => Request::parseJson($deep, 'application/json', 10000));
        $ok = str_repeat('{"a":', 63) . '1' . str_repeat('}', 63);
        $this->assertSame(63, substr_count(json_encode(Request::parseJson($ok, 'application/json', 10000)), '{'), '63 levels are allowed');
    }

    public function testParseJsonRefusesAListOrScalar(): void
    {
        foreach (['[1,2]', '[{"a":1}]', '5', '"text"', 'true', 'null'] as $raw) {
            $e = $this->assertRefused(400, 'bad_json', fn() => Request::parseJson($raw, 'application/json', 1000), $raw);
            $this->assertSame('The request must be a JSON object.', $e->getMessage());
        }
        $this->assertSame([], Request::parseJson('{}', 'application/json', 1000), 'an empty object');
        $this->assertSame(['a' => [1, 2]], Request::parseJson('{"a":[1,2]}', 'application/json', 1000));
    }

    public function testParseJsonKeepsBigIntegersAsStrings(): void
    {
        $data = Request::parseJson('{"n":12345678901234567890,"small":41}', 'application/json', 1000);
        $this->assertSame('12345678901234567890', $data['n']);
        $this->assertSame(41, $data['small']);
        $this->assertNull(Json::int($data, 'n'), 'and Json::int refuses the string');
    }

    public function testBodySha256OfTheEmptyBody(): void
    {
        Request::useBody('');
        $this->assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', Request::bodySha256());
        Request::useBody('{"wiped":true}');
        $this->assertSame(hash('sha256', '{"wiped":true}'), Request::bodySha256(), 'of the exact bytes');
    }

    public function testRawBodyLongerThanTheLimitIs413(): void
    {
        Request::useBody(str_repeat('x', 11));
        $this->assertSame(str_repeat('x', 11), Request::rawBody(11));
        $this->assertRefused(413, 'too_large', fn() => Request::rawBody(10));
    }

    public function testADeclaredContentLengthOverTheLimitIs413BeforeReading(): void
    {
        $_SERVER['CONTENT_LENGTH'] = (string) (Request::MAX_BODY + 1);
        $this->assertRefused(413, 'too_large', fn() => Request::rawBody());
        $_SERVER['CONTENT_LENGTH'] = '70000';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $this->assertRefused(413, 'too_large', fn() => Request::json(), 'json() applies its 64 KiB limit to the declared length');
    }

    public function testJsonReadsTheBodyWithItsType(): void
    {
        Request::useBody('{"action":"touch"}', 'application/json');
        $this->assertSame(['action' => 'touch'], Request::json());
        Request::useBody('{"action":"touch"}', 'text/plain');
        $this->assertRefused(415, 'unsupported_media_type', fn() => Request::json());
    }

    public function testJsonAppliesItsOwnSmallerLimit(): void
    {
        $body = '{"a":"' . str_repeat('x', 65536) . '"}';
        Request::useBody($body);
        $this->assertRefused(413, 'too_large', fn() => Request::json(), 'over the default 64 KiB');
        $this->assertSame(65536, strlen(Request::json(70000)['a']), 'a larger limit where the endpoint allows it');
    }

    public function testJsonChecksTheTypeBeforeTheSize(): void
    {
        Request::useBody(str_repeat('x', 100), 'text/html');
        $this->assertRefused(415, 'unsupported_media_type', fn() => Request::json(10), 'a captive portal page is the wrong format, not too large');
    }
    public function testBasePathUnderFindsThePublicHtmlWebRootOfSiteGround(): void
    {
        $root = sys_get_temp_dir() . "/pfpms-basepath-" . bin2hex(random_bytes(4));
        $endpoint = "$root/public_html/api/device/heartbeat.php";
        mkdir(dirname($endpoint), 0700, true);
        file_put_contents($endpoint, "<?php\n");
        try {
            $this->assertSame("/", Request::basePathUnder($root, "/api/device/heartbeat.php", (string) realpath($endpoint)),
                "public_html/ beside src/ (the SiteGround layout) is the web root");
            $this->assertNull(Request::basePathUnder($root, "/api/device/heartbeat.php", (string) realpath($root)), "a file outside both web roots");
            mkdir("$root/public/api", 0700, true);
            file_put_contents("$root/public/api/ping.php", "<?php\n");
            $this->assertSame("/chs/public/", Request::basePathUnder($root, "/chs/public/api/ping.php", (string) realpath("$root/public/api/ping.php")),
                "public/ (the repo layout) still works");
        } finally {
            foreach (["$root/public/api/ping.php", $endpoint] as $file) {
                @unlink($file);
            }
            foreach (["$root/public/api", "$root/public", dirname($endpoint), dirname($endpoint, 2), "$root/public_html", $root] as $dir) {
                @rmdir($dir);
            }
        }
    }
}
