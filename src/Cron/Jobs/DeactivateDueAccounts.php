<?php
declare(strict_types=1);

namespace Pfpms\Cron\Jobs;

use Pfpms\Audit\Audit;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\Tokens;
use Pfpms\Clock;
use Pfpms\Cron\Job;
use Pfpms\Db;
use Pfpms\Station\OfflineGrants;

/**
 * Apply deactivations scheduled for a date (UC-11 §3.2.3). Sign-in is already refused from
 * that date (AccountRules); this sets the status, revokes any offline grant still live, ends any
 * sessions, cancels outstanding invitation and reset links, and records who is gone.
 */
final class DeactivateDueAccounts implements Job
{
    public function name(): string
    {
        return 'accounts:deactivate-due';
    }

    public function description(): string
    {
        return 'Deactivate accounts whose scheduled deactivation date has arrived';
    }

    public function run(): string
    {
        $st = Db::pdo()->prepare(
            "SELECT user_id, status FROM user_account
              WHERE status <> 'Inactive' AND deactivation_effective_date IS NOT NULL AND deactivation_effective_date <= ?"
        );
        $today = Clock::orgToday();
        $st->execute([$today]);
        $done = 0;
        foreach ($st->fetchAll() as $row) {
            $done += Db::transaction(function () use ($row, $today): int {
                // Re-check the date: the account may have been reactivated since the SELECT.
                $upd = Db::pdo()->prepare(
                    "UPDATE user_account SET status = 'Inactive', row_version = row_version + 1
                      WHERE user_id = ? AND status <> 'Inactive' AND deactivation_effective_date IS NOT NULL AND deactivation_effective_date <= ?"
                );
                $upd->execute([$row['user_id'], $today]);
                if ($upd->rowCount() === 0) {
                    return 0;
                }
                Tokens::revokeAll((int) $row['user_id'], Tokens::TEMPORARY_CREDENTIAL);
                Tokens::revokeAll((int) $row['user_id'], Tokens::PASSWORD_RESET);
                Tokens::revokeAll((int) $row['user_id'], Tokens::DEVICE_REGISTRATION);
                OfflineGrants::revokeForUser((int) $row['user_id'], 'deactivate'); // grants before sessions (lock order auth_token → user_session)
                SessionStore::endAllForUser((int) $row['user_id'], 'Deactivated');
                Audit::record('user_deactivate', 'user_account', (int) $row['user_id'], reason: 'Scheduled deactivation date reached',
                    changes: ['status' => [$row['status'], 'Inactive']], actor: ['user_id' => null, 'session_id' => null]);
                return 1;
            });
        }
        return "deactivated $done account(s)";
    }
}
