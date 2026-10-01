<?php
declare(strict_types=1);

namespace Pfpms\Station;

use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Policy;
use Pfpms\Auth\Rbac;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\Context;
use Pfpms\Security\Csrf;
use Pfpms\Settings;

/** What api/session.php tells the Station: its CSRF token and who is signed in (50-design §6.2). */
final class SessionInfo
{
    /** @return array<string, mixed> */
    public static function describe(?Context $ctx): array
    {
        $info = [
            'csrf' => Csrf::token(),
            'organisation_name' => Settings::string('organisation_name', 'CHS Pet Pantry'),
            'server_time' => Clock::dbMillis(),
            'build' => DeviceStatus::currentBuild(),
            'dev_relax' => StationConfig::relaxInstallChecks(),
            'user' => null,
            'session' => null,
            'gate' => null,
        ];
        if ($ctx === null) {
            return $info;
        }
        $info['user'] = ['user_id' => $ctx->userId(), 'username' => (string) $ctx->user['username'], 'display_name' => $ctx->displayName(),
            'role' => $ctx->role(), 'capabilities' => Rbac::capabilities($ctx->role())];
        $info['session'] = self::times($ctx);
        $info['gate'] = match (true) {
            AccountRules::mustChangePassword($ctx->user) => 'password_change',
            Policy::acknowledgementRequired($ctx->user) => 'policy_ack',
            default => null,
        };
        return $info;
    }

    /** @return array{session_ref: string, device_id: ?int, site_id: ?int, auth_method: ?string, idle_seconds_left: int, absolute_seconds_left: int} */
    private static function times(Context $ctx): array
    {
        $st = Db::pdo()->prepare('SELECT started_at, last_activity_at FROM user_session WHERE session_id = ?');
        $st->execute([$ctx->sessionId]);
        $row = $st->fetch() ?: ['started_at' => Clock::db(), 'last_activity_at' => Clock::db()];
        $now = Clock::now()->getTimestamp();
        $idle = max(1, Settings::int('session_idle_minutes', 30)) * 60;
        $absolute = max(1, Settings::int('session_absolute_hours', 12)) * 3600;
        return [
            'session_ref' => $ctx->sessionId,
            'device_id' => $ctx->deviceId,
            'site_id' => $ctx->siteId,
            'auth_method' => $ctx->authMethod,
            'idle_seconds_left' => max(0, Clock::fromDb((string) $row['last_activity_at'])->getTimestamp() + $idle - $now),
            'absolute_seconds_left' => max(0, Clock::fromDb((string) $row['started_at'])->getTimestamp() + $absolute - $now),
        ];
    }
}
