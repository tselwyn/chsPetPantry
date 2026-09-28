<?php
declare(strict_types=1);

/*
 * PSR-4 autoloader for the Pfpms\ namespace (src/).
 * Works without Composer; Composer's vendor/autoload.php (PHPMailer, PhpSpreadsheet, ...)
 * is loaded too when it exists.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Pfpms\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require_once APP_ROOT . '/vendor/autoload.php';
}
