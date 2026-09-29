<?php
declare(strict_types=1);

namespace Pfpms\Http;

use Pfpms\View\View;
use Throwable;

/**
 * Last-resort handler. Users see a plain message (with an incident id for real errors);
 * details go to storage/logs, never to the page outside dev.
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
        $status = $e instanceof HttpException ? $e->status : 500;
        $message = $e instanceof HttpException ? $e->getMessage() : HttpException::defaultMessage(500);
        $incident = null;
        if (!$e instanceof HttpException) {
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
        if (self::wantsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['error' => $status, 'message' => $message, 'incident' => $incident], JSON_UNESCAPED_UNICODE);
            return;
        }
        try {
            View::render('pages/error', ['status' => $status, 'message' => $message, 'incident' => $incident], 'layout/public');
        } catch (Throwable) {
            echo '<!doctype html><meta charset="utf-8"><title>Error</title><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
        }
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
