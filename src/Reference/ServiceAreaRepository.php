<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Db;

/** SQL for the service_area_postal_code table. Prepared statements only; returns plain arrays. */
final class ServiceAreaRepository
{
    /** @return list<array> each ZIP with its nearest site's name and status */
    public static function all(): array
    {
        return Db::pdo()->query(
            'SELECT a.postal_code, a.site_id, a.is_active, s.name AS site_name, s.is_active AS site_is_active
               FROM service_area_postal_code a LEFT JOIN site s ON s.site_id = a.site_id
              ORDER BY a.is_active DESC, a.postal_code'
        )->fetchAll();
    }

    public static function find(string $postalCode): ?array
    {
        $st = Db::pdo()->prepare('SELECT postal_code, site_id, is_active FROM service_area_postal_code WHERE postal_code = ?');
        $st->execute([$postalCode]);
        return $st->fetch() ?: null;
    }

    /** The active site assigned to an active ZIP, or null. */
    public static function activeSiteFor(string $postalCode): ?int
    {
        $st = Db::pdo()->prepare(
            'SELECT a.site_id FROM service_area_postal_code a JOIN site s ON s.site_id = a.site_id
              WHERE a.postal_code = ? AND a.is_active = 1 AND s.is_active = 1'
        );
        $st->execute([$postalCode]);
        $siteId = $st->fetchColumn();
        return $siteId === false || $siteId === null ? null : (int) $siteId;
    }

    public static function insert(string $postalCode, ?int $siteId): void
    {
        Db::pdo()->prepare('INSERT INTO service_area_postal_code (postal_code, site_id, is_active) VALUES (?, ?, 1)')->execute([$postalCode, $siteId]);
    }

    public static function setSite(string $postalCode, ?int $siteId): void
    {
        Db::pdo()->prepare('UPDATE service_area_postal_code SET site_id = ? WHERE postal_code = ?')->execute([$siteId, $postalCode]);
    }

    public static function setActive(string $postalCode, bool $active): void
    {
        Db::pdo()->prepare('UPDATE service_area_postal_code SET is_active = ? WHERE postal_code = ?')->execute([$active ? 1 : 0, $postalCode]);
    }
}
