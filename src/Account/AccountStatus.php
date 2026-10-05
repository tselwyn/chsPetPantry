<?php
declare(strict_types=1);

namespace Pfpms\Account;

use Pfpms\Auth\AccountRules;
use Pfpms\Clock;

/**
 * What an Administrator sees as an account's state. It follows the sign-in rules
 * (AccountRules), so an account is never shown as Active when the person cannot sign in.
 */
final class AccountStatus
{
    /** Locked by failed sign-ins (a time-limited lock) or set to Locked. */
    public static function isLocked(array $u): bool
    {
        $until = Clock::fromDb($u['locked_until'] ?? null);
        return $u['status'] === 'Locked' || ($until !== null && $until > Clock::now());
    }

    /**
     * Short label for the account list, e.g. "Invited", "Locked", "Ended 2026-09-30",
     * "Deactivated from 2026-11-01", "Active until 2026-12-31". The end date is the last day
     * with access; a deactivation date is the first day without it.
     */
    public static function label(array $u): string
    {
        if ($u['status'] === 'Pending') {
            return 'Invited';
        }
        if (self::isLocked($u)) {
            return 'Locked';
        }
        return match (AccountRules::blockReason($u)) {
            'inactive' => 'Deactivated',
            'expired' => 'Ended ' . $u['expiry_date'],
            'not_started' => 'Starts ' . $u['start_date'],
            default => match (true) {
                !empty($u['deactivation_effective_date']) => 'Deactivated from ' . $u['deactivation_effective_date'],
                !empty($u['expiry_date']) => 'Active until ' . $u['expiry_date'],
                default => 'Active',
            },
        };
    }
}
