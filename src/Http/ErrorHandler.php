<?php
declare(strict_types=1);

namespace Pfpms\Http;

use Pfpms\Validation\ValidationException;
use Pfpms\View\View;
use Throwable;

/**
 * Last-resort handler. Users see a plain message (with an incident id for real errors);
 * details go to storage/logs, never to the page outside dev. JSON requests get the error
 * envelope {error, message, …} (50-design §5.2): an HttpException as it says, a ValidationException as 422
 * "invalid", anything else (a JsonException from the server's own encoding included) as a logged 500.
 */
final class ErrorHandler
{
    /** Show exception details on the error page. Set by bootstrap once config says env=dev. */
    public static bool $debug = false;

    public static function register(): void
    {
        set_exception_handler([self::class, 'handle']);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            if ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) {
                return false; // logged by PHP; not worth failing a request over
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public static function handle(Throwable $e): void
    {
        $json = self::wantsJson();
        $status = $json ? self::statusFor($e) : ($e instanceof HttpException ? $e->status : 500);
        $known = $e instanceof HttpException || ($json && $e instanceof ValidationException);
        $message = match (true) {
            $e instanceof HttpException => $e->getMessage(),
            $json && $e instanceof ValidationException => (string) (array_values($e->errors)[0] ?? HttpException::defaultMessage(422)),
            default => HttpException::defaultMessage(500),
        };
        $incident = null;
        if (!$known) {
            $incident = strtoupper(bin2hex(random_bytes(4)));
            self::log($incident, $e);
            if (self::$debug) {
                $message .= ' [' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            }
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: no-store');
        }
        if ($json) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                if ($e instanceof HttpException) {
                    foreach ($e->headers as $name => $value) {
                        header("$name: $value");
                    }
                }
            }
            echo json_encode(self::jsonPayload($e, $message, $incident), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }
        try {
            View::render('pages/error', ['status' => $status, 'message' => $message, 'incident' => $incident], 'layout/public');
        } catch (Throwable) {
            echo '<!doctype html><meta charset="utf-8"><title>Error</title><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
        }
    }

    /** The HTTP status a JSON request answers with for this error. */
    public static function statusFor(Throwable $e): int
    {
        return match (true) {
            $e instanceof HttpException => $e->status,
            $e instanceof ValidationException => 422,
            default => 500,
        };
    }

    /**
     * The error envelope: {"error": "<code>", "message": "<plain text>", …extra}, plus "incident" on a 500.
     * @return array<string, mixed>
     */
    public static function jsonPayload(Throwable $e, string $message, ?string $incident): array
    {
        $payload = match (true) {
            $e instanceof HttpException => ['error' => $e->code(), 'message' => $message] + $e->extra,
            $e instanceof ValidationException => ['error' => 'invalid', 'message' => $message, 'errors' => $e->errors],
            default => ['error' => 'server_error', 'message' => $message],
        };
        if ($incident !== null) {
            $payload['incident'] = $incident;
        }
        return $payload;
    }

    public static function log(string $incident, Throwable $e): void
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $line = sprintf("[%s] incident=%s %s: %s in %s:%d\n%s\n", gmdate('Y-m-d H:i:s'), $incident, get_class($e),
            $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        @file_put_contents($dir . '/app-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    private static function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/')
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }
}
