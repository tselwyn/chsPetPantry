<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Db;

/** SQL for the size_band table. Prepared statements only; returns plain arrays. */
final class SizeBandRepository
{
    public const COLUMNS = 'size_band_id, species_id, name, min_weight_lbs, max_weight_lbs, picture_path';

    /**
     * A band is in use while a pet or a published allotment rule points at it. Figures for it in
     * the draft allotment version do not count: deleting the band removes them from the draft.
     */
    private const IN_USE = '(EXISTS (SELECT 1 FROM pet p WHERE p.size_band_id = sb.size_band_id)
                            OR EXISTS (SELECT 1 FROM allotment_rule r WHERE r.size_band_id = sb.size_band_id AND r.published_at IS NOT NULL))';

    /** The band has figures in the draft allotment version. */
    private const IN_DRAFT = 'EXISTS (SELECT 1 FROM allotment_rule d WHERE d.size_band_id = sb.size_band_id AND d.published_at IS NULL)';

    /** Every band with its species name and whether it is in use, lightest first within each species. @return list<array> */
    public static function all(): array
    {
        return Db::pdo()->query(
            'SELECT sb.size_band_id, sb.species_id, sb.name, sb.min_weight_lbs, sb.max_weight_lbs, sb.picture_path,
                    ' . self::IN_USE . ' AS in_use, ' . self::IN_DRAFT . ' AS in_draft
               FROM size_band sb
              ORDER BY sb.species_id, sb.min_weight_lbs'
        )->fetchAll();
    }

    /** @return list<array> the bands of one species, lightest first */
    public static function forSpecies(int $speciesId): array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM size_band WHERE species_id = ? ORDER BY min_weight_lbs');
        $st->execute([$speciesId]);
        return $st->fetchAll();
    }

    public static function find(int $sizeBandId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM size_band WHERE size_band_id = ?');
        $st->execute([$sizeBandId]);
        return $st->fetch() ?: null;
    }

    public static function inUse(int $sizeBandId): bool
    {
        $st = Db::pdo()->prepare('SELECT ' . self::IN_USE . ' FROM size_band sb WHERE sb.size_band_id = ?');
        $st->execute([$sizeBandId]);
        return (bool) $st->fetchColumn();
    }

    public static function nameTaken(int $speciesId, string $name, ?int $exceptSizeBandId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM size_band WHERE species_id = ? AND name = ? AND size_band_id <> ?');
        $st->execute([$speciesId, $name, $exceptSizeBandId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    /** @param array{species_id: int, name: string, min_weight_lbs: string, max_weight_lbs: ?string, picture_path: ?string} $values */
    public static function insert(array $values): int
    {
        Db::pdo()->prepare('INSERT INTO size_band (species_id, name, min_weight_lbs, max_weight_lbs, picture_path) VALUES (?, ?, ?, ?, ?)')
            ->execute([$values['species_id'], $values['name'], $values['min_weight_lbs'], $values['max_weight_lbs'], $values['picture_path']]);
        return (int) Db::pdo()->lastInsertId();
    }

    /** The species never changes: pets and allotment rules already rely on it. */
    public static function update(int $sizeBandId, array $values): void
    {
        Db::pdo()->prepare('UPDATE size_band SET name = ?, min_weight_lbs = ?, max_weight_lbs = ?, picture_path = ? WHERE size_band_id = ?')
            ->execute([$values['name'], $values['min_weight_lbs'], $values['max_weight_lbs'], $values['picture_path'], $sizeBandId]);
    }

    public static function delete(int $sizeBandId): void
    {
        Db::pdo()->prepare('DELETE FROM size_band WHERE size_band_id = ?')->execute([$sizeBandId]);
    }
}
