<?php
declare(strict_types=1);

namespace Pfpms\Http;

use Pfpms\Config;

/**
 * Typed access to the current request. Values are trimmed strings or null; nothing is
 * HTML- or SQL-escaped here (escape on output with e(), and use prepared statements).
 */
final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /** A POST value (or GET when $fromQuery), trimmed; null if absent or not a string. */
    public static function string(string $name, bool $fromQuery = false): ?string
    {
        $source = $fromQuery ? $_GET : $_POST;
        $value = $source[$name] ?? null;
        return is_string($value) ? trim($value) : null;
    }

    public static function query(string $name): ?string
    {
        return self::string($name, true);
    }

    /** Raw POST value without trimming (passwords). */
    public static function raw(string $name): ?string
    {
        $value = $_POST[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public static function int(string $name, bool $fromQuery = false): ?int
    {
        $value = self::string($name, $fromQuery);
        return $value !== null && preg_match('/^-?\d{1,10}$/', $value) ? (int) $value : null;
    }

    public static function bool(string $name): bool
    {
        return in_array(self::string($name), ['1', 'on', 'yes', 'true'], true);
    }

    /**
     * A POST array such as name="orig[key]" as key => trimmed string; non-string entries are dropped.
     * @return array<string, string>
     */
    public static function array(string $name): array
    {
        $value = $_POST[$name] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $out[(string) $key] = trim($item);
            }
        }
        return $out;
    }

    /** @return array<string, string|null> */
    public static function only(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[$name] = self::string($name);
        }
        return $out;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Client IP. X-Forwarded-For is honoured only when the direct peer is listed in
     * app.trusted_proxies (SiteGround's front end, if it proxies), never otherwise.
     */
    public static function ip(): string
    {
        $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $trusted = (array) Config::get('app.trusted_proxies', []);
        if ($trusted && in_array($peer, $trusted, true) && ($fwd = self::header('X-Forwarded-For'))) {
            $hops = array_map('trim', explode(',', $fwd));
            return (string) end($hops);
        }
        return $peer;
    }

    public static function userAgent(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * URL path prefix of the public/ directory, e.g. "/" on SiteGround or
     * "/chsPetPantry/public/" when the repo sits inside XAMPP's htdocs.
     */
    public static function basePath(): string
    {
        $configured = Config::get('app.base_path');
        if (is_string($configured) && $configured !== '') {
            return '/' . trim($configured, '/') . ($configured === '/' ? '' : '/');
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $file = str_replace('\\', '/', (string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
        $public = str_replace('\\', '/', (string) realpath(APP_ROOT . '/public'));
        if ($file !== '' && $public !== '' && str_starts_with($file, $public . '/')) {
            $relative = substr($file, strlen($public)); // e.g. "/api/ping.php"
            if (str_ends_with($script, $relative)) {
                return substr($script, 0, strlen($script) - strlen($relative)) . '/';
            }
        }
        return rtrim(dirname($script), '/') . '/';
    }

    /** The current page as an app-relative path with query string, for ?next= links. */
    public static function appPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $base = self::basePath();
        $path = str_starts_with($uri, $base) ? substr($uri, strlen($base)) : ltrim($uri, '/');
        return self::safeAppPath($path) ?? 'index.php';
    }

    /**
     * Accept only same-app relative paths like "participant_view.php?id=3".
     * Rejects absolute URLs, scheme-relative "//host", backslashes and control characters,
     * so a ?next= parameter can never become an open redirect.
     */
    public static function safeAppPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (!preg_match('~^[A-Za-z0-9_\-][A-Za-z0-9_\-./]*\.php(\?[A-Za-z0-9_\-.=&%+]*)?$~', $path) || str_contains($path, '..')) {
            return null;
        }
        return $path;
    }
}
