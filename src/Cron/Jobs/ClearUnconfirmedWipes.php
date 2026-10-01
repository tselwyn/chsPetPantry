<?php
declare(strict_types=1);

namespace Pfpms\Cron\Jobs;

use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Cron\Job;
use Pfpms\Db;
use Pfpms\Device\DeviceRepository;
use Pfpms\Settings;

/**
 * A tablet taken out of service that never confirmed its erase (lost for good, or thrown away) keeps its vault
 * key on the server, which could open a copy of its old storage, and its offline grants' secrets. After
 * sync_payload_retention_days both are cleared (50-design D-55). Its proof key is kept: it opens nothing, and a
 * tablet that turns up later can still confirm its erase with a signed heartbeat.
 */
final class ClearUnconfirmedWipes implements Job
{
    public function name(): string
    {
        return 'devices:clear-unconfirmed-wipes';
    }

    public function description(): string
    {
        return 'Clear the server-side keys of retired tablets that never confirmed their erase';
    }

    public function run(): string
    {
        $days = max(1, Settings::int('sync_payload_retention_days', 90));
        $cleared = 0;
        foreach (DeviceRepository::unconfirmedWipes(Clock::db(Clock::now()->modify("-$days days"))) as $id) {
            $cleared += Db::transaction(static function () use ($id): int {
                if (!DeviceRepository::clearKeys($id)) {
                    return 0; // confirmed or cleared meanwhile
                }
                $grants = DeviceRepository::shredGrantSecrets($id);
                Audit::record('device_vault_key_cleared', 'device', $id, 'Success', 'Never confirmed its erase', ['grant_keys_cleared' => $grants],
                    actor: ['user_id' => null, 'session_id' => null]);
                return 1;
            });
        }
        return "cleared the keys of $cleared tablet(s)";
    }
}
