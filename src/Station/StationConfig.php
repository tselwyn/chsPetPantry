<?php
declare(strict_types=1);

namespace Pfpms\Station;

use Pfpms\Config;
use Pfpms\Settings;

/** Station switches from config, and the settings the tablet keeps a copy of (50-design §6, D-08, D-12). */
final class StationConfig
{
    /**
     * dev/test only (bootstrap refuses it elsewhere): treat a browser tab as installed with kept storage, so the
     * Station can be tried in a normal tab. The tablet's own report is still stored as it is.
     */
    public static function relaxInstallChecks(): bool
    {
        return in_array(Config::env(), ['dev', 'test'], true) && Config::get('station.dev_relax_install') === true;
    }

    /** Emergency only: sw.php serves a worker that removes the Station's cached files (tablets keep their data). */
    public static function swKill(): bool
    {
        return Config::get('station.sw_kill') === true;
    }

    /**
     * The settings the tablet needs, sent with every heartbeat and sign-in.
     * @return array<string, int|string|bool>
     */
    public static function client(): array
    {
        return [
            'organisation_name' => Settings::string('organisation_name', 'CHS Pet Pantry'),
            'session_idle_minutes' => Settings::int('session_idle_minutes', 30),
            'session_absolute_hours' => Settings::int('session_absolute_hours', 12),
            'pin_min_digits' => Settings::int('pin_min_digits', 4),
            'pin_max_digits' => Settings::int('pin_max_digits', 6),
            'pin_max_failed' => Settings::int('pin_max_failed', 3),
            'pin_shift_hours' => Settings::int('pin_shift_hours', 12),
            'offline_grant_hours' => Settings::int('offline_grant_hours', 72),
            'offline_max_failed_unlocks' => Settings::int('offline_max_failed_unlocks', 10),
            'sync_clock_skew_minutes' => Settings::int('sync_clock_skew_minutes', 10),
            'offline_mode_enabled' => Settings::bool('offline_mode_enabled', true),
        ];
    }
}
