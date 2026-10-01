<?php
declare(strict_types=1);

/*
 * First line of every web entry point: require __DIR__ . '/../src/bootstrap.php';
 * Loads config, sets UTC and error handling, and sends the security headers.
 * The page then calls Page::start() (or Api::start()) before reading any input.
 */

require __DIR__ . '/autoload.php';
require __DIR__ . '/View/helpers.php';

use Pfpms\Config;
use Pfpms\Http\ErrorHandler;
use Pfpms\Http\Request;

define('APP_VERSION', trim((string) @file_get_contents(APP_ROOT . '/VERSION')) ?: 'dev');

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1'); // stack traces in the error log never carry passwords, codes or tokens
ini_set('display_errors', '0');
ErrorHandler::register();

Config::load();
ErrorHandler::$debug = Config::env() === 'dev';
if (Config::isProd() && Config::get('app.secure_cookies') === false) {
    throw new RuntimeException('Refusing to run in prod with app.secure_cookies = false');
}
if (Config::get('station.dev_relax_install') === true && !in_array(Config::env(), ['dev', 'test'], true)) {
    throw new RuntimeException('Refusing to run with station.dev_relax_install outside dev/test');
}
if (PHP_SAPI !== 'cli' && !in_array(Config::env(), ['dev', 'test'], true) && !Request::basePathIsKnown()) {
    // A wrong guess would give the Station's endpoints in api/device/ the wrong cookie path and break every tablet proof.
    throw new RuntimeException('Cannot tell where the web root is: set app.base_path in config (e.g. \'/\')');
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header_remove('X-Powered-By');
    // Never cache pages or API responses: SiteGround's cache ignores the session cookie.
    header('Cache-Control: no-store, no-cache, private, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; "
        . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    if (Request::isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
