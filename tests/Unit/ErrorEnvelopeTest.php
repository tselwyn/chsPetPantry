<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;
use Pfpms\Http\ErrorHandler;
use Pfpms\Http\HttpException;
use Pfpms\Http\Request;
use Pfpms\Validation\ValidationException;
use RuntimeException;

/** The JSON error envelope {"error", "message", …extra} and its statuses (50-design §5.2). */
final class ErrorEnvelopeTest extends TestCase
{
    private array $server;
    private bool $debug;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->debug = ErrorHandler::$debug;
        ErrorHandler::$debug = false;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        ErrorHandler::$debug = $this->debug;
    }

    /** The exact text ErrorHandler::handle() prints for a JSON request (an unknown error also writes its incident to the log). */
    private function printed(\Throwable $e): string
    {
        $_SERVER['SCRIPT_NAME'] = '/pfpms/api/device/heartbeat.php';
        unset($_SERVER['HTTP_ACCEPT']);
        ob_start();
        try {
            ErrorHandler::handle($e);
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    /** What ErrorHandler::handle() prints for a JSON request, decoded. */
    private function handled(\Throwable $e): array
    {
        return json_decode($this->printed($e), true, 64, JSON_THROW_ON_ERROR);
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

    /** The 500 envelope: server_error, the plain message and an 8-hex incident (which is returned). */
    private function assertServerError(array $payload, string $message = ''): string
    {
        $this->assertSame(['error', 'message', 'incident'], array_keys($payload), $message);
        $this->assertSame('server_error', $payload['error'], $message);
        $this->assertSame('Something went wrong.', $payload['message'], $message);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{8}$/D', (string) $payload['incident'], $message);
        return (string) $payload['incident'];
    }

    public function testHttpExceptionBecomesTheEnvelopeWithExtraAndHeaders(): void
    {
        $e = new HttpException(403, 'This tablet has been taken out of service.', 'device_revoked', ['directive' => ['wipe' => 'Wipe Now']],
            ['Clear-Site-Data' => '"cache", "storage"']);
        $this->assertSame(403, ErrorHandler::statusFor($e));
        $this->assertSame(['error' => 'device_revoked', 'message' => 'This tablet has been taken out of service.', 'directive' => ['wipe' => 'Wipe Now']],
            ErrorHandler::jsonPayload($e, $e->getMessage(), null), 'no incident on a known error');
        $this->assertSame(['Clear-Site-Data' => '"cache", "storage"'], $e->headers, 'the headers handle() sends');
    }

    public function testExtraCannotReplaceTheErrorOrMessage(): void
    {
        $e = new HttpException(401, 'Wait.', 'device_proof_stale', ['error' => 'ok', 'message' => 'fine', 'server_time' => '2026-10-01 12:00:00.000']);
        $this->assertSame(['error' => 'device_proof_stale', 'message' => 'Wait.', 'server_time' => '2026-10-01 12:00:00.000'],
            ErrorHandler::jsonPayload($e, $e->getMessage(), null));
    }

    public function testAnHttpExceptionWithoutACodeUsesItsStatusCode(): void
    {
        $e = new HttpException(429);
        $this->assertSame(['error' => 'rate_limited', 'message' => 'Too many attempts. Please wait a few minutes and try again.'],
            ErrorHandler::jsonPayload($e, $e->getMessage(), null));
    }

    public function testHandlePrintsTheEnvelopeForAnApiRequest(): void
    {
        $e = new HttpException(410, '', 'wiped', ['status' => 'wiped'], ['Clear-Site-Data' => '"cache", "storage"']);
        $this->assertSame(['error' => 'wiped', 'message' => 'This is no longer available.', 'status' => 'wiped'], $this->handled($e));
    }

    public function testValidationExceptionIs422Invalid(): void
    {
        $e = new ValidationException(['code' => 'Check the code.', 'label' => 'Enter a name.']);
        $this->assertSame(422, ErrorHandler::statusFor($e));
        $this->assertSame(['error' => 'invalid', 'message' => 'Check the code.', 'errors' => ['code' => 'Check the code.', 'label' => 'Enter a name.']],
            ErrorHandler::jsonPayload($e, 'Check the code.', null));
        $this->assertSame(['error' => 'invalid', 'message' => 'Check the code.', 'errors' => ['code' => 'Check the code.', 'label' => 'Enter a name.']],
            $this->handled($e), 'handle() uses the first error as the message');
    }

    public function testARawJsonExceptionIsALogged500WhileBadClientJsonIs400(): void
    {
        // A JsonException that reaches the handler comes from the server's own encoding (D-03): a server fault.
        $e = new JsonException('Malformed UTF-8 characters, possibly incorrectly encoded');
        $this->assertSame(500, ErrorHandler::statusFor($e));
        $out = $this->printed($e);
        $this->assertServerError(json_decode($out, true, 64, JSON_THROW_ON_ERROR), 'a raw JsonException');
        $this->assertStringNotContainsString('Malformed', $out, "the encoder's own text never reaches the tablet");

        // The tablet's bad JSON never arrives as a raw JsonException: Request::parseJson() turns it into a 400 bad_json.
        $client = $this->refusal(fn() => Request::parseJson('{bad', 'application/json', 100));
        $this->assertSame([400, 'bad_json'], [$client->status, $client->code()]);
        $this->assertSame(400, ErrorHandler::statusFor($client));
        $this->assertSame(['error' => 'bad_json', 'message' => 'The request was not valid JSON.'], $this->handled($client),
            "a client error: no incident, and the parser's own text never reaches the tablet");
    }

    public function testHandleHidesTheDetailOfAServerErrorFromAnApiRequest(): void
    {
        $out = $this->printed(new RuntimeException('SQLSTATE[HY000] secret detail'));
        $incident = $this->assertServerError(json_decode($out, true, 64, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('SQLSTATE', $out);
        $this->assertStringNotContainsString('secret detail', $out);
        $this->assertStringNotContainsString('RuntimeException', $out, 'not even the class name outside dev');

        $log = APP_ROOT . '/storage/logs/app-' . gmdate('Y-m') . '.log';
        $this->assertFileExists($log, 'the detail goes to the log instead');
        $this->assertStringContainsString("incident=$incident RuntimeException: SQLSTATE[HY000] secret detail", (string) file_get_contents($log),
            'under the incident the page shows');
    }

    public function testOtherErrorsAre500WithAnIncident(): void
    {
        foreach ([new RuntimeException('SQLSTATE[HY000] secret detail'), new \PDOException('boom'), new \Error('typo')] as $e) {
            $this->assertSame(500, ErrorHandler::statusFor($e), get_class($e));
            $this->assertSame(['error' => 'server_error', 'message' => 'Something went wrong.', 'incident' => '0A1B2C3D'],
                ErrorHandler::jsonPayload($e, HttpException::defaultMessage(500), '0A1B2C3D'), get_class($e));
        }
    }

    public function testEveryStatusHasADefaultCode(): void
    {
        // The codeFor() of each status of the §5.2 table (400 and 503 differ from the table's first code; see the report).
        $expected = [400 => 'bad_request', 401 => 'not_signed_in', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'busy',
            410 => 'wiped', 413 => 'too_large', 415 => 'unsupported_media_type', 422 => 'invalid', 429 => 'rate_limited', 500 => 'server_error',
            503 => 'unavailable'];
        foreach ($expected as $status => $code) {
            $this->assertSame($code, HttpException::codeFor($status), (string) $status);
            $this->assertSame($code, (new HttpException($status))->code(), "an HttpException($status) without a code");
        }
        $this->assertSame('server_error', HttpException::codeFor(418), 'an unlisted status');
        $this->assertSame('csrf_failed', (new HttpException(400, '', 'csrf_failed'))->code(), 'a given code wins');
    }

    public function testTheNewStatusesHaveTheirDefaultMessages(): void
    {
        $this->assertSame('This is no longer available.', HttpException::defaultMessage(410));
        $this->assertSame('That is too much to send at once.', HttpException::defaultMessage(413));
        $this->assertSame('The request was sent in the wrong format.', HttpException::defaultMessage(415));
        $this->assertSame('That is too much to send at once.', (new HttpException(413))->getMessage(), 'an empty message takes the default');
    }
}
