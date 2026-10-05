<?php
declare(strict_types=1);

namespace Pfpms\Auth;

use Pfpms\Clock;
use Pfpms\Db;

/**
 * Which sites a user may work in right now (UC-02 §4.5, UC-11, US-28).
 * Roles with site.all see every active site; everyone else needs a user_site_access row
 * that has started and not ended. A time-limited grant stops working the moment it lapses,
 * because this is checked on every request.
 */
final class SiteAccess
{
    /** @return list<array{site_id:int, name:string, time_zone:string}> */
    public static function sitesFor(array $user): array
    {
        if (Rbac::can($user['role'], 'site.all')) {
            $st = Db::pdo()->query('SELECT site_id, name, time_zone FROM site WHERE is_active = 1 ORDER BY name');
        } else {
            $st = Db::pdo()->prepare(
                'SELECT DISTINCT s.site_id, s.name, s.time_zone FROM user_site_access a JOIN site s ON s.site_id = a.site_id
                  WHERE a.user_id = ? AND s.is_active = 1 AND a.starts_at <= ? AND (a.ends_at IS NULL OR a.ends_at > ?)
                  ORDER BY s.name'
            );
            $now = Clock::db();
            $st->execute([$user['user_id'], $now, $now]);
        }
        return array_map(fn($r) => ['site_id' => (int) $r['site_id'], 'name' => $r['name'], 'time_zone' => $r['time_zone']], $st->fetchAll());
    }

    public static function canUseSite(array $user, int $siteId): bool
    {
        return in_array($siteId, array_column(self::sitesFor($user), 'site_id'), true);
    }

    /**
     * When the person's access to $siteId ends (UTC Clock::db text): null for site.all or a live grant with no end; now itself
     * when no grant is live (an access removed a moment ago), so OfflineGrants::expiry() caps at now and no grant is issued.
     */
    public static function accessEnd(array $user, int $siteId): ?string
    {
        if (Rbac::can($user['role'], 'site.all')) {
            return null;
        }
        $now = Clock::db();
        $st = Db::pdo()->prepare(
            "SELECT MAX(COALESCE(ends_at, '9999-12-31 23:59:59')) FROM user_site_access
              WHERE user_id = ? AND site_id = ? AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?)"
        );
        $st->execute([$user['user_id'], $siteId, $now, $now]);
        $end = $st->fetchColumn();
        if ($end === false || $end === null) {
            return $now; // no live grant: never "no end"
        }
        return (string) $end === '9999-12-31 23:59:59' ? null : (string) $end;
    }
}
