<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Db;

/** SQL for the lookup_value table (configurable choice lists). Prepared statements only; returns plain arrays. */
final class LookupRepository
{
    public const COLUMNS = 'l.lookup_id, l.list_key, l.value_code, l.label, l.species_id, l.picture_path, l.display_order, l.is_active';

    /**
     * Every value in a list, active or not, in display order. Species-specific lists are
     * grouped by species first, because each species has its own order.
     * @return list<array>
     */
    public static function forList(string $listKey): array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ', s.name AS species_name FROM lookup_value l
                                  LEFT JOIN species s ON s.species_id = l.species_id
                                  WHERE l.list_key = ? ORDER BY s.name, l.display_order, l.lookup_id');
        $st->execute([$listKey]);
        return $st->fetchAll();
    }

    /** @return list<array{value_code:string,label:string}> active values only */
    public static function activeOptions(string $listKey, ?int $speciesId): array
    {
        $sql = 'SELECT l.value_code, l.label FROM lookup_value l WHERE l.list_key = ? AND l.is_active = 1';
        $args = [$listKey];
        if ($speciesId !== null) {
            $sql .= ' AND l.species_id = ?';
            $args[] = $speciesId;
        }
        $st = Db::pdo()->prepare($sql . ' ORDER BY l.display_order, l.lookup_id');
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function find(int $lookupId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM lookup_value l WHERE l.lookup_id = ?');
        $st->execute([$lookupId]);
        return $st->fetch() ?: null;
    }

    public static function codeTaken(string $listKey, string $valueCode): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM lookup_value WHERE list_key = ? AND value_code = ?');
        $st->execute([$listKey, $valueCode]);
        return (int) $st->fetchColumn() > 0;
    }

    /** Same label in the same list (and species), ignoring case and accents through the collation. */
    public static function labelTaken(string $listKey, string $label, ?int $speciesId, ?int $exceptLookupId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM lookup_value WHERE list_key = ? AND label = ? AND COALESCE(species_id, 0) = ? AND lookup_id <> ?');
        $st->execute([$listKey, $label, $speciesId ?? 0, $exceptLookupId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function nextOrder(string $listKey, ?int $speciesId): int
    {
        $st = Db::pdo()->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 FROM lookup_value WHERE list_key = ? AND COALESCE(species_id, 0) = ?');
        $st->execute([$listKey, $speciesId ?? 0]);
        return (int) $st->fetchColumn();
    }

    /** @param array<string, mixed> $values */
    public static function insert(array $values): int
    {
        Db::pdo()->prepare('INSERT INTO lookup_value (list_key, value_code, label, species_id, display_order) VALUES (?, ?, ?, ?, ?)')
            ->execute([$values['list_key'], $values['value_code'], $values['label'], $values['species_id'], $values['display_order']]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function setLabel(int $lookupId, string $label): void
    {
        Db::pdo()->prepare('UPDATE lookup_value SET label = ? WHERE lookup_id = ?')->execute([$label, $lookupId]);
    }

    public static function setOrder(int $lookupId, int $displayOrder): void
    {
        Db::pdo()->prepare('UPDATE lookup_value SET display_order = ? WHERE lookup_id = ?')->execute([$displayOrder, $lookupId]);
    }

    public static function setActive(int $lookupId, bool $active): void
    {
        Db::pdo()->prepare('UPDATE lookup_value SET is_active = ? WHERE lookup_id = ?')->execute([$active ? 1 : 0, $lookupId]);
    }

    /** @return list<array{species_id:int,name:string}> active species, for species-specific lists */
    public static function species(): array
    {
        return Db::pdo()->query('SELECT species_id, name FROM species WHERE is_active = 1 ORDER BY name')->fetchAll();
    }
}
