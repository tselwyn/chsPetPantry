<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Pfpms\Config;
use Pfpms\Http\Request;
use Pfpms\Security\Csrf;
use Pfpms\Settings;
use RuntimeException;

/**
 * PHP's native session, hardened: strict mode (no attacker-chosen ids), cookie only,
 * HttpOnly, SameSite=Lax, Secure outside dev/test, stored under storage/sessions.
 * The session only carries ids; everything else is re-read and re-checked each request.
 *
 * The Station has its own cookie (PFPMSST, path <base>api/), so a PIN switch on a tablet never changes the
 * user of a web tab in the same browser (50-design D-52).
 */
final class WebSession
{
    public const COOKIE = 'PFPMSSID';
    public const STATION_COOKIE = 'PFPMSST';

    /** Whether this request's session is the Station's (remembered for restart()). */
    private static bool $station = false;

    /** @return array{name: string, path: string} the cookie a web page or the Station uses */
    public static function cookieFor(bool $station): array
    {
        return ['name' => $station ? self::STATION_COOKIE : self::COOKIE, 'path' => Request::basePath() . ($station ? 'api/' : '')];
    }

    public static function start(bool $station = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = (string) Config::get('app.session_path', APP_ROOT . '/storage/sessions');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create session directory $dir");
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (max(1, Settings::int('session_absolute_hours', 12)) * 3600));
        session_save_path($dir);
        session_cache_limiter(''); // keep bootstrap's Cache-Control header instead of PHP's
        self::$station = $station;
        $cookie = self::cookieFor($station);
        session_name($cookie['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookie['path'],
            'secure' => self::secureCookies(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** After a successful sign-in: new PHP session id, fresh CSRF token, ids stored. */
    public static function login(int $userId, string $sessionId, ?int $siteId): void
    {
        session_regenerate_id(true);
        $_SESSION = ['uid' => $userId, 'sid' => $sessionId, 'site_id' => $siteId];
        Csrf::rotate();
    }

    /** New PHP session id (site switch, password change, policy acknowledgement). */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'], 'secure' => $params['secure'],
            'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
    }

    /** Start a fresh anonymous session after destroying one, so a flash message can survive. */
    public static function restart(): void
    {
        self::destroy();
        self::start(self::$station);
        session_regenerate_id(true);
    }

    public static function secureCookies(): bool
    {
        $configured = Config::get('app.secure_cookies');
        return is_bool($configured) ? $configured : !in_array(Config::env(), ['dev', 'test'], true);
    }
}
