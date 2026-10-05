<?php
declare(strict_types=1);

/*
 * Apply database migrations.
 *
 *   php bin/migrate.php [--config=path] [--with-optional] [--retry-skipped] [--status] [--confirm-backup]
 *
 * Connects with db.migrate_user / db.migrate_pass when set (the DDL-capable owner account),
 * otherwise with db.user. In prod, --confirm-backup is required.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Db\Migrator;

$known = ['config:', 'with-optional', 'retry-skipped', 'status', 'confirm-backup', 'help'];
$opts = getopt('', $known);
$allowed = array_map(fn($o) => rtrim($o, ':'), $known);
foreach (array_slice($argv, 1) as $arg) {
    $name = preg_replace('/^--([^=]+).*$/', '$1', $arg);
    if (!str_starts_with($arg, '--') || !in_array($name, $allowed, true)) {
        fwrite(STDERR, "Unknown argument: $arg (see --help)\n");
        exit(2);
    }
}
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/migrate.php [--config=path] [--with-optional] [--retry-skipped] [--status] [--confirm-backup]\n");
    exit(0);
}

try {
    Config::load($opts['config'] ?? null);
    if (Config::isProd() && !isset($opts['confirm-backup']) && !isset($opts['status'])) {
        fwrite(STDERR, "Refusing to migrate prod without --confirm-backup (take a mysqldump --no-tablespaces --single-transaction first).\n");
        exit(2);
    }
    $db = Config::require('db');
    if (!empty($db['migrate_user'])) {
        $db['user'] = $db['migrate_user'];
        $db['pass'] = $db['migrate_pass'] ?? '';
    }
    // Emulated prepares: MySQL cannot server-prepare SHOW WARNINGS (error 1295), and PDO's failed
    // attempt replaces the warnings the runner needs to inspect after each statement.
    $pdo = Db::connect($db, [PDO::ATTR_EMULATE_PREPARES => true]);
    $migrator = new Migrator($pdo, APP_ROOT . '/migrations', static fn(string $line) => fwrite(STDOUT, $line . "\n"));

    if (isset($opts['status'])) {
        $recorded = $pdo->query("SHOW TABLES LIKE 'schema_version'")->fetchColumn() ? $migrator->recorded() : [];
        foreach ($migrator->discover(true) as $m) {
            $r = $recorded[$m['version']] ?? null;
            printf("%s  %-45s %-9s %s\n", $m['version'], basename($m['file']), $r['status'] ?? 'Pending',
                $r ? "{$r['statements_applied']}/{$r['statements_total']}" . ($r['note'] ? "  {$r['note']}" : '') : '');
        }
        exit(0);
    }

    exit($migrator->run(isset($opts['with-optional']), isset($opts['retry-skipped'])) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration error: ' . $e->getMessage() . "\n");
    exit(1);
}
