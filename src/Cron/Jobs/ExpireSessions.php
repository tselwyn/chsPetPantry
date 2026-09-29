<?php
declare(strict_types=1);

namespace Pfpms\Cron\Jobs;

use Pfpms\Clock;
use Pfpms\Cron\Job;
use Pfpms\Db;
use Pfpms\Settings;

/**
 * Close sessions that have passed the idle or absolute limit but were never seen again
 * (the browser was simply closed). Requests already enforce the limits; this keeps the
 * "who is signed in" roster (US-02) accurate.
 */
final class ExpireSessions implements Job
{
    public function name(): string
    {
        return 'sessions:expire';
    }

    public function description(): string
    {
        return 'Mark idle and over-age sessions as timed out';
    }

    public function run(): string
    {
        $now = Clock::now();
        $idle = max(1, Settings::int('session_idle_minutes', 30));
        $absolute = max(1, Settings::int('session_absolute_hours', 12));
        $st = Db::pdo()->prepare(
            "UPDATE user_session SET ended_at = ?, end_reason = 'Timeout'
              WHERE ended_at IS NULL AND (last_activity_at <= ? OR started_at <= ?)"
        );
        $st->execute([Clock::db($now), Clock::db($now->modify("-$idle minutes")), Clock::db($now->modify("-$absolute hours"))]);
        return 'timed out ' . $st->rowCount() . ' session(s)';
    }
}
