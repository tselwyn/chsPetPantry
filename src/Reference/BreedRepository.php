<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Db;

/** SQL for the breed table. Prepared statements only; returns plain arrays. */
final class BreedRepository
{
    public const COLUMNS = 'breed_id, species_id, name, is_active';

    /** @return list<array> */
    public static function forSpecies(int $speciesId): array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM breed WHERE species_id = ? ORDER BY is_active DESC, name');
        $st->execute([$speciesId]);
        return $st->fetchAll();
    }

    public static function find(int $breedId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM breed WHERE breed_id = ?');
        $st->execute([$breedId]);
        return $st->fetch() ?: null;
    }

    public static function nameTaken(int $speciesId, string $name, ?int $exceptBreedId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM breed WHERE species_id = ? AND name = ? AND breed_id <> ?');
        $st->execute([$speciesId, $name, $exceptBreedId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function insert(int $speciesId, string $name): int
    {
        Db::pdo()->prepare('INSERT INTO breed (species_id, name) VALUES (?, ?)')->execute([$speciesId, $name]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function rename(int $breedId, string $name): void
    {
        Db::pdo()->prepare('UPDATE breed SET name = ? WHERE breed_id = ?')->execute([$name, $breedId]);
    }

    public static function setActive(int $breedId, bool $active): void
    {
        Db::pdo()->prepare('UPDATE breed SET is_active = ? WHERE breed_id = ?')->execute([$active ? 1 : 0, $breedId]);
    }
}
