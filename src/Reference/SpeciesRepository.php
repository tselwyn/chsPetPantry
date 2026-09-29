<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Db;

/** SQL for the species table. Prepared statements only; returns plain arrays. */
final class SpeciesRepository
{
    public const COLUMNS = 'species_id, name, is_active';

    /** Every species with how many breeds and size bands it has. @return list<array> */
    public static function all(): array
    {
        return Db::pdo()->query(
            'SELECT s.species_id, s.name, s.is_active,
                    (SELECT COUNT(*) FROM breed b WHERE b.species_id = s.species_id) AS breed_count,
                    (SELECT COUNT(*) FROM size_band sb WHERE sb.species_id = s.species_id) AS size_band_count
               FROM species s ORDER BY s.is_active DESC, s.name'
        )->fetchAll();
    }

    /** Species for a choice list: active ones, plus $includeId even if it is inactive. @return list<array> */
    public static function choices(?int $includeId = null): array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM species WHERE is_active = 1 OR species_id = ? ORDER BY name');
        $st->execute([$includeId ?? 0]);
        return $st->fetchAll();
    }

    public static function find(int $speciesId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM species WHERE species_id = ?');
        $st->execute([$speciesId]);
        return $st->fetch() ?: null;
    }

    /**
     * Lock one species row until the transaction ends, so two people editing the same
     * species' size bands at once cannot both pass the overlap check.
     */
    public static function lock(int $speciesId): void
    {
        Db::pdo()->prepare('SELECT species_id FROM species WHERE species_id = ? FOR UPDATE')->execute([$speciesId]);
    }

    public static function nameTaken(string $name, ?int $exceptSpeciesId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM species WHERE name = ? AND species_id <> ?');
        $st->execute([$name, $exceptSpeciesId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function insert(string $name): int
    {
        Db::pdo()->prepare('INSERT INTO species (name) VALUES (?)')->execute([$name]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function rename(int $speciesId, string $name): void
    {
        Db::pdo()->prepare('UPDATE species SET name = ? WHERE species_id = ?')->execute([$name, $speciesId]);
    }

    public static function setActive(int $speciesId, bool $active): void
    {
        Db::pdo()->prepare('UPDATE species SET is_active = ? WHERE species_id = ?')->execute([$active ? 1 : 0, $speciesId]);
    }
}
