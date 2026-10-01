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
     * A POST array (or GET when $fromQuery) such as name="orig[key]" as key => trimmed string;
     * non-string entries are dropped.
     * @return array<string, string>
     */
    public static function array(string $name, bool $fromQuery = false): array
    {
        $value = ($fromQuery ? $_GET : $_POST)[$name] ?? null;
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

    /** The largest body read at all (a push of up to 50 sealed records); every endpoint sets its own smaller limit. */
    public const MAX_BODY = 1048576;

    private static ?string $body = null;
    private static ?string $bodyContentType = null;

    /**
     * The Authorization header: HTTP_AUTHORIZATION, else REDIRECT_HTTP_AUTHORIZATION (Apache CGI/FastCGI after the
     * public/.htaccess rewrite), else getallheaders() (case-insensitive). Tablet credentials travel in it (50-design §5.4).
     */
    public static function authorization(): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                return $_SERVER[$key];
            }
        }
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (is_string($name) && strcasecmp($name, 'Authorization') === 0 && is_string($value)) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** The lower-case media type of the body, without parameters ('' when absent). Only CONTENT_TYPE: HTTP_CONTENT_TYPE is set by php -S alone. */
    public static function contentType(): string
    {
        if (self::$bodyContentType !== null) {
            return self::$bodyContentType;
        }
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        return strtolower(trim(explode(';', $type, 2)[0]));
    }

    /** The entry point's path under public/, with no query, e.g. "api/device/heartbeat.php": what a tablet's proof signs. */
    public static function scriptPath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = self::basePath();
        return str_starts_with($script, $base) ? substr($script, strlen($base)) : ltrim($script, '/');
    }

    /**
     * The raw body, read once (at most MAX_BODY + 1 bytes).
     * @throws HttpException 413 too_large when it is longer than $maxBytes
     */
    public static function rawBody(int $maxBytes = self::MAX_BODY): string
    {
        if (self::$body === null) {
            $declared = $_SERVER['CONTENT_LENGTH'] ?? null;
            if (is_numeric($declared) && (int) $declared > $maxBytes) {
                throw new HttpException(413, '', 'too_large');
            }
            self::$body = (string) @file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        }
        if (strlen(self::$body) > $maxBytes) {
            throw new HttpException(413, '', 'too_large');
        }
        return self::$body;
    }

    /** Hex SHA-256 of the raw body (of '' when there is none): what a tablet's proof covers. */
    public static function bodySha256(): string
    {
        return hash('sha256', self::rawBody());
    }

    /**
     * The JSON object in the body.
     * @return array<mixed>
     * @throws HttpException 413 too_large | 415 unsupported_media_type | 400 bad_json
     */
    public static function json(int $maxBytes = 65536): array
    {
        if (self::contentType() !== 'application/json') {
            throw new HttpException(415, '', 'unsupported_media_type');
        }
        return self::parseJson(self::rawBody(max($maxBytes, 0)), self::contentType(), $maxBytes);
    }

    /**
     * The pure part of json(), for tests.
     * @return array<mixed>
     */
    public static function parseJson(string $raw, string $contentType, int $maxBytes): array
    {
        if ($contentType !== 'application/json') {
            throw new HttpException(415, '', 'unsupported_media_type');
        }
        if (strlen($raw) > $maxBytes) {
            throw new HttpException(413, '', 'too_large');
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new HttpException(400, 'The request was not valid JSON.', 'bad_json');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpException(400, 'The request must be a JSON object.', 'bad_json');
        }
        return $data;
    }

    /** Tests only: the body and content type rawBody() and json() will see (null resets to the real request). */
    public static function useBody(?string $raw, ?string $contentType = 'application/json'): void
    {
        self::$body = $raw;
        self::$bodyContentType = $raw === null ? null : strtolower(trim(explode(';', (string) $contentType, 2)[0]));
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

    /** Where the web root may be: public/ in the repo (dev), or public_html/ beside src/ (SiteGround). */
    private const PUBLIC_DIRS = ['/public', '/public_html'];

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
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        return self::detectedBasePath() ?? rtrim(dirname(str_replace('\\', '/', $script)), '/') . '/';
    }

    /**
     * Whether basePath() is known for this request: configured, or worked out from where the script is in the web root.
     * Entry points in subdirectories (api/device/…) depend on it: a guess from the script's own directory breaks the
     * Station's cookie path and every tablet proof (50-design §5.3).
     */
    public static function basePathIsKnown(): bool
    {
        $configured = Config::get('app.base_path');
        return (is_string($configured) && $configured !== '') || self::detectedBasePath() !== null;
    }

    /**
     * The pure part: the URL prefix of $public, given the request's SCRIPT_NAME and the script's real file, or null when
     * the file is not under $public or the names do not line up.
     */
    public static function basePathFor(string $script, string $file, string $public): ?string
    {
        $script = str_replace('\\', '/', $script);
        $file = str_replace('\\', '/', $file);
        $public = rtrim(str_replace('\\', '/', $public), '/');
        if ($file === '' || $public === '' || !str_starts_with($file, $public . '/')) {
            return null;
        }
        $relative = substr($file, strlen($public)); // e.g. "/api/ping.php"
        return str_ends_with($script, $relative) ? substr($script, 0, strlen($script) - strlen($relative)) . '/' : null;
    }

    /** The base path when the script lies in $root's public/ or public_html/, else null (the part of basePath() tests drive). */
    public static function basePathUnder(string $root, string $script, string $file): ?string
    {
        foreach (self::PUBLIC_DIRS as $dir) {
            $public = realpath($root . $dir);
            if ($public !== false && ($base = self::basePathFor($script, $file, $public)) !== null) {
                return $base;
            }
        }
        return null;
    }

    private static function detectedBasePath(): ?string
    {
        return self::basePathUnder(APP_ROOT, (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'),
            (string) realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
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
