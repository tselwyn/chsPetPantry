<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Db;

/** SQL for the site table. Prepared statements only; returns plain arrays. */
final class SiteRepository
{
    public const COLUMNS = 'site_id, name, street_address, city, state, postal_code, time_zone, is_active';

    /** @return list<array> */
    public static function all(): array
    {
        return Db::pdo()->query('SELECT ' . self::COLUMNS . ' FROM site ORDER BY is_active DESC, name')->fetchAll();
    }

    public static function find(int $siteId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM site WHERE site_id = ?');
        $st->execute([$siteId]);
        return $st->fetch() ?: null;
    }

    public static function nameTaken(string $name, ?int $exceptSiteId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM site WHERE name = ? AND site_id <> ?');
        $st->execute([$name, $exceptSiteId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function activeCount(): int
    {
        return (int) Db::pdo()->query('SELECT COUNT(*) FROM site WHERE is_active = 1')->fetchColumn();
    }

    /** @param array<string, mixed> $values */
    public static function insert(array $values): int
    {
        Db::pdo()->prepare('INSERT INTO site (name, street_address, city, state, postal_code, time_zone) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$values['name'], $values['street_address'], $values['city'], $values['state'], $values['postal_code'], $values['time_zone']]);
        return (int) Db::pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $values */
    public static function update(int $siteId, array $values): void
    {
        Db::pdo()->prepare('UPDATE site SET name = ?, street_address = ?, city = ?, state = ?, postal_code = ?, time_zone = ? WHERE site_id = ?')
            ->execute([$values['name'], $values['street_address'], $values['city'], $values['state'], $values['postal_code'], $values['time_zone'], $siteId]);
    }

    public static function setActive(int $siteId, bool $active): void
    {
        Db::pdo()->prepare('UPDATE site SET is_active = ? WHERE site_id = ?')->execute([$active ? 1 : 0, $siteId]);
    }
}
