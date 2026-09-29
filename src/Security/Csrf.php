<?php
declare(strict_types=1);

namespace Pfpms\Security;

use Pfpms\Config;
use Pfpms\Http\HttpException;
use Pfpms\Http\Request;

/**
 * CSRF protection for every state-changing request, signed in or not (login CSRF).
 *
 * Two independent checks:
 * 1. a per-session secret token, sent as the "_csrf" form field or the X-CSRF-Token header;
 * 2. the browser's origin: Sec-Fetch-Site must be same-origin (or absent), and Origin, when
 *    present, must match the application's origin. Safari before 16.4 sends no
 *    Sec-Fetch-Site, so the Origin check is the fallback.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function rotate(): void
    {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function verify(): void
    {
        $sent = Request::string('_csrf') ?? Request::header('X-CSRF-Token');
        $expected = $_SESSION['csrf'] ?? null;
        if (!is_string($sent) || !is_string($expected) || !hash_equals($expected, $sent)) {
            throw new HttpException(400, 'This form has expired or was not sent from this site. Please reload the page and try again.');
        }
        self::verifyOrigin();
    }

    private static function verifyOrigin(): void
    {
        $fetchSite = Request::header('Sec-Fetch-Site');
        if ($fetchSite !== null && !in_array($fetchSite, ['same-origin', 'none'], true)) {
            throw new HttpException(400, 'Cross-site request refused.');
        }
        $origin = Request::header('Origin');
        if ($origin !== null && $origin !== 'null' && strcasecmp(rtrim($origin, '/'), self::appOrigin()) !== 0) {
            throw new HttpException(400, 'Cross-site request refused.');
        }
    }

    private static function appOrigin(): string
    {
        $base = Config::get('app.base_url');
        if (is_string($base) && preg_match('~^(https?://[^/]+)~i', $base, $m)) {
            return $m[1];
        }
        return (Request::isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
