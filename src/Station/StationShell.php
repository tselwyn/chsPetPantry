<?php
declare(strict_types=1);

namespace Pfpms\Station;

use Pfpms\Device\DeviceStatus;
use Pfpms\Device\RegistrationSheet;
use Pfpms\Http\SecurityHeaders;

/**
 * The Station's shell page and service worker (docs/design/50-design-station.md §5.7, §7.1, §7.7): their exact bytes
 * and headers. The shell is the same for every tablet (no organisation name, user data or CSRF token), so the service
 * worker can check its SHA-256 and cache it. Everything here is hashed into the build (StationAssets::hash()), so a
 * change to the page, its CSP or the worker's wrapper reaches every tablet.
 */
final class StationShell
{
    /** SecurityHeaders::CSP (bootstrap's) plus worker-src, manifest-src and Trusted Types (the only policy is boot.js's pfpms-sw). */
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; "
        . "connect-src 'self'; worker-src 'self'; manifest-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; "
        . "object-src 'none'; require-trusted-types-for 'script'; trusted-types pfpms-sw";

    /** The worker station.sw_kill serves (D-08): it removes the Station's cached files and unregisters. It never touches stored records. */
    public const KILL_WORKER = <<<'JS'
// PFPMS Station kill worker (config station.sw_kill): removes the Station's cached files and unregisters itself.
// Records stored on the tablet are kept.
'use strict';
self.addEventListener('install', function (event) {
  event.waitUntil(self.skipWaiting());
});
self.addEventListener('activate', function (event) {
  event.waitUntil((async function () {
    var names = await caches.keys();
    for (var i = 0; i < names.length; i++) {
      if (names[i].startsWith('pfpms-shell-')) await caches.delete(names[i]);
    }
    var controlled = await self.clients.matchAll({ type: 'window' });
    var all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    await self.registration.unregister();
    for (var j = 0; j < all.length; j++) all[j].postMessage({ type: 'SW_KILLED' });
    for (var k = 0; k < controlled.length; k++) controlled[k].navigate(controlled[k].url).catch(function () {});
  })());
});

JS;

    /** The shell: html() embeds DeviceStatus::currentBuild(); html('') is the build-free form hashed into the build. */
    public static function html(?string $build = null): string
    {
        $build ??= DeviceStatus::currentBuild();
        $attr = htmlspecialchars($build, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $query = rawurlencode($build);
        $name = htmlspecialchars(RegistrationSheet::APP_NAME, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!doctype html>
<html lang="en" data-build="$attr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1f5f5b">
<meta name="color-scheme" content="light">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="$name">
<title>$name</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192.png">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<link rel="stylesheet" href="css/station.css">
<script type="module" src="boot.js?v=$query"></script>
</head>
<body>
<main id="app" class="app"><p class="starting">Starting…</p></main>
<noscript><p class="noscript">The Station needs JavaScript. Turn it on in the browser's settings.</p></noscript>
</body>
</html>

HTML;
    }

    /**
     * Every header line the shell or the worker is sent with, in order: bootstrap's SecurityHeaders::LINES with the CSP
     * replaced by self::CSP, then the Content-Type. All of it is hashed into the build (D-07), because a cached shell
     * Response keeps the headers it was cached with (Permissions-Policy camera=(self) is what the QR scanner needs).
     * HSTS (HTTPS only) is left out: a browser ignores HSTS on a response the service worker replays.
     * @return list<string>
     */
    public static function headerLines(bool $worker): array
    {
        $lines = [];
        foreach (SecurityHeaders::LINES as $line) {
            $lines[] = str_starts_with($line, 'Content-Security-Policy:') ? 'Content-Security-Policy: ' . self::CSP : $line;
        }
        $lines[] = $worker ? 'Content-Type: text/javascript; charset=utf-8' : 'Content-Type: text/html; charset=utf-8';
        return $lines;
    }

    /** Sends headerLines() after bootstrap's: header() replaces a header of the same name, so only the CSP and Content-Type change. */
    public static function sendHeaders(bool $worker = false): void
    {
        foreach (self::headerLines($worker) as $line) {
            header($line, true);
        }
    }

    /**
     * The service worker: the build and the verified precache list, then js/sw-core.js (a classic script that reads both).
     * @param list<array{path: string, sha256: string, type: string}> $precache
     */
    public static function worker(string $build, array $precache, string $core): string
    {
        return 'const BUILD = ' . json_encode($build, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n"
            . 'const PRECACHE = ' . json_encode($precache, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ";\n"
            . $core;
    }
}
