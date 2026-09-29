<?php
declare(strict_types=1);

/*
 * Scheduled jobs. Command line only.
 *
 *   php bin/cron.php all            run every job (schedule this every 30 minutes)
 *   php bin/cron.php mail:send      run one job
 *   php bin/cron.php list           list the jobs
 *
 * SiteGround Site Tools > Devs > Cron Jobs, every 30 minutes:
 *   php /home/customer/www/<domain>/bin/cron.php all
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

use Pfpms\Audit\Audit;
use Pfpms\Config;
use Pfpms\Cron\Runner;
use Pfpms\Db;

$opts = getopt('', ['config:'], $rest);
$command = $argv[$rest] ?? 'list';
Config::load($opts['config'] ?? null);
date_default_timezone_set('UTC');

$jobs = Runner::jobs();
if ($command === 'list') {
    foreach ($jobs as $name => $job) {
        printf("%-18s %s\n", $name, $job->description());
    }
    exit(0);
}
$selected = $command === 'all' ? array_values($jobs) : (isset($jobs[$command]) ? [$jobs[$command]] : null);
if ($selected === null) {
    fwrite(STDERR, "Unknown job '$command'. Run: php bin/cron.php list\n");
    exit(2);
}

$system = (int) Db::pdo()->query("SELECT user_id FROM user_account WHERE username = 'system'")->fetchColumn();
Audit::setActor($system ?: null);
$exit = 0;
foreach ($selected as $job) {
    $result = Runner::run($job);
    printf("%-18s %-7s %s\n", $job->name(), $result['status'], $result['summary']);
    $exit = $result['status'] === 'failed' ? 1 : $exit;
}
exit($exit);
