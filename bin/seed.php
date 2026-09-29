<?php
declare(strict_types=1);

/*
 * Load seed data: seeds/reference/*.sql (client-reviewed reference data), and with --dev
 * also seeds/dev/*.sql (synthetic development data; refused in staging and prod).
 * Seed files must be safe to run repeatedly (use ON DUPLICATE KEY UPDATE).
 *
 *   php bin/seed.php [--dev] [--config=path]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Db\SqlSplitter;

$opts = getopt('', ['dev', 'config:']);
Config::load($opts['config'] ?? null);
$dev = isset($opts['dev']);
if ($dev && !in_array(Config::env(), ['dev', 'test'], true)) {
    fwrite(STDERR, "Refusing to load development seeds when env is '" . Config::env() . "'.\n");
    exit(2);
}

$pdo = Db::pdo();
$dirs = ['reference'];
if ($dev) {
    $dirs[] = 'dev';
}
foreach ($dirs as $dir) {
    foreach (glob(APP_ROOT . "/seeds/$dir/*.sql") ?: [] as $file) {
        $statements = SqlSplitter::split((string) file_get_contents($file));
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        fwrite(STDOUT, "Loaded seeds/$dir/" . basename($file) . ' (' . count($statements) . " statements)\n");
    }
}
