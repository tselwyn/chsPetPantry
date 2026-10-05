<?php
declare(strict_types=1);

/*
 * Test bootstrap (PHPUnit and tests/run.php). Loads the test config, refuses any database
 * whose name does not end in _test, empties it and rebuilds it from migrations/.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../src/View/helpers.php';

use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Db\Migrator;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pfpms\\Tests\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Pfpms\\Tests\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

if (!defined('APP_VERSION')) {
    define('APP_VERSION', 'test');
}
date_default_timezone_set('UTC');
error_reporting(E_ALL);

$configPath = getenv('PFPMS_TEST_CONFIG') ?: APP_ROOT . '/config/config.test.php';
Config::load($configPath);
$db = Config::require('db');
if (!str_ends_with((string) $db['name'], '_test')) {
    fwrite(STDERR, "Refusing to run tests against '{$db['name']}': the test database name must end in _test.\n");
    exit(2);
}

// Empty the test database and rebuild it from the migrations.
$owner = $db;
if (!empty($db['migrate_user'])) {
    $owner['user'] = $db['migrate_user'];
    $owner['pass'] = $db['migrate_pass'] ?? '';
}
$admin = Db::connect($owner, [PDO::ATTR_EMULATE_PREPARES => true]);
$admin->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($admin->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $trigger) {
    $admin->exec("DROP TRIGGER `$trigger`");
}
foreach ($admin->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $admin->exec("DROP TABLE `$table`");
}
$admin->exec('SET FOREIGN_KEY_CHECKS = 1');
$quiet = static function (string $line): void {
    if (str_contains($line, 'Failed') || str_contains($line, 'Skipped')) {
        fwrite(STDERR, $line . "\n");
    }
};
if (!(new Migrator($admin, APP_ROOT . '/migrations', $quiet))->run(false)) {
    fwrite(STDERR, "Migrations failed on the test database.\n");
    exit(1);
}
$admin = null;

Db::use(Db::connect($db), Db::connect($db));
