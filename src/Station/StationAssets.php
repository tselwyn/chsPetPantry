<?php
declare(strict_types=1);

namespace Pfpms\Station;

use Pfpms\Http\Request;

/**
 * The Station's static files and the content hash that names its build (docs/design/50-design-station.md D-07, §7.1).
 * Hashing contents (not mtimes) survives FTP deploys; the shell, its headers, the worker and both PHP entry points are
 * hashed in as well, so any change the tablets must receive changes DeviceStatus::currentBuild() and with it the service
 * worker's bytes.
 */
final class StationAssets
{
    /**
     * Every file under public/station/ except index.php and sw.php, relative to it, sorted by byte value (sort SORT_STRING).
     * StationAssetsTest fails when a file exists there and is not listed, or is listed and missing. S3-S5 add their files.
     */
    public const FILES = [
        'boot.js',
        'css/station.css',
        'icons/apple-touch-icon.png',
        'icons/icon-192.png',
        'icons/icon-512.png',
        'icons/icon-maskable-512.png',
        'js/api.js',
        'js/app.js',
        'js/calc.js',
        'js/canonical.js',
        'js/clock.js',
        'js/copy.js',
        'js/db.js',
        'js/device.js',
        'js/dom.js',
        'js/env.js',
        'js/fold.js',
        'js/proof.js',
        'js/registration_code.js',
        'js/router.js',
        'js/sw-core.js',
        'js/update.js',
        'js/vault.js',
        'js/views/about.js',
        'js/views/chrome.js',
        'js/views/device.js',
        'js/views/elsewhere.js',
        'js/views/login.js',
        'js/views/starting.js',
        'js/views/wipe.js',
        'manifest.json',
    ];

    /** The worker's own code: served inside sw.php, so it is hashed but never precached. */
    public const WORKER_CORE = 'js/sw-core.js';

    /** IndexedDB schema version of js/db.js; part of the build so a schema change always ships as a new build. */
    public const DB_VERSION = 1;

    /** Content-Type families the service worker requires, by extension (the shell './' is 'text/html'). */
    private const TYPES = ['js' => 'javascript', 'css' => 'text/css', 'json' => 'json', 'png' => 'image/png'];

    private static ?string $hash = null;
    /** @var array<string, string> path => hex SHA-256 of a real Station file, memoised for hash() and precache() */
    private static array $digests = [];

    /**
     * The Station's directory on disk: station/ in the web root, which is public/ in the repo and public_html/ on SiteGround.
     * @param ?string $appRoot the application root (APP_ROOT when null), as Request::publicDir() takes it
     */
    public static function root(?string $appRoot = null): string
    {
        return Request::publicDir($appRoot) . '/station';
    }

    /** The bytes of a Station file ('' when it is missing: the build still answers, and the tests and smoke script catch it). */
    public static function read(string $path): string
    {
        $file = self::root() . '/' . $path;
        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    /** The build hash (hex SHA-256), computed once per request; always equal to hashOf(FILES, root(), extraInputs()). */
    public static function hash(): string
    {
        if (self::$hash === null) {
            $input = '';
            foreach (self::FILES as $path) {
                $input .= $path . "\0" . self::digest($path) . "\n";
            }
            foreach (self::extraInputs() as $name => $content) {
                $input .= $name . "\0" . hash('sha256', $content) . "\n";
            }
            self::$hash = hash('sha256', $input . 'idb:' . self::DB_VERSION . "\n");
        }
        return self::$hash;
    }

    /** Hex SHA-256 of a real Station file's bytes (of '' when it is missing), memoised per request. */
    public static function digest(string $path): string
    {
        return self::$digests[$path] ??= hash('sha256', self::read($path));
    }

    /**
     * SHA-256 (hex) of: for each file, "<path>\0<hex sha256 of its bytes>\n"; then the same line for each extra input
     * (name => content), in order; then "idb:<DB_VERSION>\n". A missing file counts as empty.
     * @param list<string> $files
     * @param array<string, string> $extra
     */
    public static function hashOf(array $files, string $root, array $extra = []): string
    {
        $input = '';
        foreach ($files as $path) {
            $file = rtrim($root, '/\\') . '/' . $path;
            $input .= $path . "\0" . hash('sha256', is_file($file) ? (string) file_get_contents($file) : '') . "\n";
        }
        foreach ($extra as $name => $content) {
            $input .= $name . "\0" . hash('sha256', $content) . "\n";
        }
        return hash('sha256', $input . 'idb:' . self::DB_VERSION . "\n");
    }

    /**
     * The inputs besides FILES (D-07): the build-free shell, both full header sets (bootstrap's SecurityHeaders::LINES
     * with the Station CSP and the Content-Type, see StationShell::headerLines()), the worker wrapper, the kill worker, and
     * the bytes of sw.php and index.php, so a change to either entry point (a header it adds, a status it sets) reaches
     * the tablets, whose cached shell keeps the headers it was cached with. The build itself is never an input (no cycle).
     * @return array<string, string>
     */
    public static function extraInputs(): array
    {
        return [
            '<shell>' => StationShell::html(''),
            '<headers:shell>' => implode("\n", StationShell::headerLines(false)),
            '<headers:worker>' => implode("\n", StationShell::headerLines(true)),
            '<worker>' => StationShell::worker('', [], ''),
            '<kill>' => StationShell::KILL_WORKER,
            'sw.php' => self::read('sw.php'),
            'index.php' => self::read('index.php'),
        ];
    }

    /**
     * What the service worker fetches and verifies at install: the shell ('./', the SHA-256 of html() with the current
     * build) and every listed file except the worker's own code, in FILES order.
     * @return list<array{path: string, sha256: string, type: string}>
     */
    public static function precache(): array
    {
        $list = [['path' => './', 'sha256' => hash('sha256', StationShell::html()), 'type' => 'text/html']];
        foreach (self::FILES as $path) {
            if ($path !== self::WORKER_CORE) {
                $list[] = ['path' => $path, 'sha256' => self::digest($path), 'type' => self::typeOf($path)];
            }
        }
        return $list;
    }

    /** The Content-Type family of a listed file. @throws \LogicException for an extension the Station does not serve */
    public static function typeOf(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::TYPES[$ext] ?? throw new \LogicException("No Content-Type family for $path");
    }

    /** Tests only: forget the memoised hash and digests. */
    public static function reset(): void
    {
        self::$hash = null;
        self::$digests = [];
    }
}
