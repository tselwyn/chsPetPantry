<?php
declare(strict_types=1);

namespace Pfpms\Cron;

use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Http\ErrorHandler;
use Throwable;

/**
 * Runs scheduled jobs. SiteGround's fair-use policy allows cron no more often than every
 * 30 minutes, so everything time-critical (reset mail, invitations) is sent inline and cron
 * only retries and tidies up.
 *
 * Each job holds a per-database advisory lock while it runs, so overlapping cron invocations
 * skip a job that is still running instead of doing its work twice.
 */
final class Runner
{
    /** @return array<string, Job> */
    public static function jobs(): array
    {
        $jobs = [];
        foreach ([new Jobs\SendMail(), new Jobs\ExpireSessions(), new Jobs\PurgeRateLimits(), new Jobs\DeactivateDueAccounts(),
            new Jobs\ClearUnconfirmedWipes()] as $job) {
            $jobs[$job->name()] = $job;
        }
        return $jobs;
    }

    /** @return array{status: 'ok'|'skipped'|'failed', summary: string} */
    public static function run(Job $job): array
    {
        $pdo = Db::pdo();
        $lock = Db::lockName($pdo, 'cron:' . $job->name());
        $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $st->execute([$lock]);
        if ((int) $st->fetchColumn() !== 1) {
            return self::logged($job, ['status' => 'skipped', 'summary' => 'already running']);
        }
        try {
            return self::logged($job, ['status' => 'ok', 'summary' => $job->run()]);
        } catch (Throwable $e) {
            $incident = strtoupper(bin2hex(random_bytes(4)));
            ErrorHandler::log($incident, $e);
            return self::logged($job, ['status' => 'failed', 'summary' => get_class($e) . ': ' . $e->getMessage() . " (incident $incident)"]);
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }

    private static function logged(Job $job, array $result): array
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($dir . '/cron-' . Clock::now()->format('Y-m') . '.log',
            sprintf("[%s] %s %s: %s\n", Clock::db(), $job->name(), $result['status'], $result['summary']), FILE_APPEND | LOCK_EX);
        return $result;
    }
}
