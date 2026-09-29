<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Pfpms\Clock;

/** When an account may sign in or keep a session (UC-01 step 7, §3.3.2). */
final class AccountRules
{
    /** @return null if usable, else 'inactive' | 'locked' | 'expired' | 'not_started' */
    public static function blockReason(array $user): ?string
    {
        if ($user['status'] === 'Inactive') {
            return 'inactive';
        }
        if ($user['status'] === 'Locked') {
            return 'locked';
        }
        $lockedUntil = Clock::fromDb($user['locked_until'] ?? null);
        if ($lockedUntil !== null && $lockedUntil > Clock::now()) {
            return 'locked';
        }
        $today = Clock::now()->format('Y-m-d');
        if (!empty($user['expiry_date']) && $user['expiry_date'] < $today) {
            return 'expired';
        }
        if (!empty($user['start_date']) && $user['start_date'] > $today) {
            return 'not_started';
        }
        return null;
    }

    public static function canHoldSession(array $user): bool
    {
        return self::blockReason($user) === null;
    }

    /** A temporary or expired password must be changed before any menu is shown (UC-01 §3.2.2). */
    public static function mustChangePassword(array $user): bool
    {
        return (int) $user['must_change_password'] === 1 || $user['status'] === 'Pending'
            || PasswordPolicy::isExpired($user['password_changed_at'] ?? null);
    }
}
