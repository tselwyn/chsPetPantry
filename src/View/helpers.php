<?php
declare(strict_types=1);

/*
 * Template helpers, available in every template.
 * Escape everything printed into HTML with e(); never echo raw input.
 */

use Pfpms\Http\Request;
use Pfpms\Security\Csrf;

if (!function_exists('e')) {
    /** HTML-escape a value for text or a quoted attribute. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** URL of an app page, relative to public/ (e.g. url('login.php')). */
    function url(string $path, array $query = []): string
    {
        return Request::basePath() . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
    }

    /**
     * Absolute URL for links sent by email (password reset, invitations). Uses app.base_url,
     * never the request's Host header outside dev/test: a forged Host would otherwise send
     * someone's reset token to an attacker's domain.
     */
    function absolute_url(string $path, array $query = []): string
    {
        $base = \Pfpms\Config::get('app.base_url');
        if (!is_string($base) || $base === '') {
            if (!in_array(\Pfpms\Config::env(), ['dev', 'test'], true)) {
                throw new RuntimeException('app.base_url must be configured to send links by email');
            }
            $base = (Request::isHttps() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . Request::basePath();
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
    }

    /** URL of a static asset with a cache-busting version. */
    function asset(string $path): string
    {
        $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
        $version = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : APP_VERSION;
        return Request::basePath() . 'assets/' . ltrim($path, '/') . '?v=' . $version;
    }

    /** Hidden CSRF field for every POST form. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
    }

    /** Format a UTC DATETIME string for display in a site time zone. */
    function local_time(?string $utc, string $timeZone = 'America/New_York', string $format = 'M j, Y g:i A'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timeZone))->format($format);
    }
}
