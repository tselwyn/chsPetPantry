<?php
declare(strict_types=1);

namespace Pfpms\Cron;

/** A scheduled job run by bin/cron.php. */
interface Job
{
    /** Short name used on the command line, e.g. "mail:send". */
    public function name(): string;

    /** One-line description for `php bin/cron.php list`. */
    public function description(): string;

    /** Do the work and return a one-line summary for the cron log. */
    public function run(): string;
}
