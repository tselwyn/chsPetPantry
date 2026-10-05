<?php
declare(strict_types=1);

namespace Pfpms\Http;

/** The security headers bootstrap.php sends on every web response. The Station hashes them into its build (StationShell::headerLines()). */
final class SecurityHeaders
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; "
        . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";

    /** In the order bootstrap sends them. Never cache pages or API responses: SiteGround's cache ignores the session cookie. */
    public const LINES = [
        'Cache-Control: no-store, no-cache, private, max-age=0',
        'Pragma: no-cache',
        'X-Content-Type-Options: nosniff',
        'X-Frame-Options: DENY',
        'Referrer-Policy: same-origin',
        'Permissions-Policy: camera=(self), microphone=(), geolocation=()',
        'Content-Security-Policy: ' . self::CSP,
    ];

    /** Sent on HTTPS only. */
    public const HSTS = 'Strict-Transport-Security: max-age=31536000; includeSubDomains';
}
