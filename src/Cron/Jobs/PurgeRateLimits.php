<?php
declare(strict_types=1);

namespace Pfpms\Cron\Jobs;

use Pfpms\Clock;
use Pfpms\Cron\Job;
use Pfpms\Db;

/** Delete rate-limit windows that ended more than a day ago. */
final class PurgeRateLimits implements Job
{
    public function name(): string
    {
        return 'ratelimit:purge';
    }

    public function description(): string
    {
        return 'Delete rate-limit counters older than one day';
    }

    public function run(): string
    {
        $st = Db::pdo()->prepare('DELETE FROM rate_limit_bucket WHERE window_start < ? AND (blocked_until IS NULL OR blocked_until < ?)');
        $cutoff = Clock::db(Clock::now()->modify('-1 day'));
        $st->execute([$cutoff, Clock::db()]);
        return 'deleted ' . $st->rowCount() . ' counter(s)';
    }
}
