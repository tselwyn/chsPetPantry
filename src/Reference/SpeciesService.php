<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Species (plan P2A, US-13). Dog and Cat are seeded. A species is never deleted: deactivating
 * it only hides it when new pets or products are recorded; existing records keep it. Admins can
 * still manage an inactive species' breeds and size bands.
 */
final class SpeciesService
{
    public const FIELDS = ['name'];

    /** @throws ValidationException */
    public static function create(array $input): int
    {
        $values = self::validate($input, null);
        return Db::transaction(function () use ($values): int {
            $id = SpeciesRepository::insert($values['name']);
            Audit::record('species_create', 'species', $id, details: ['name' => $values['name']]);
            return $id;
        });
    }

    /** @throws ValidationException */
    public static function rename(int $speciesId, array $input): void
    {
        $before = SpeciesRepository::find($speciesId) ?? throw ValidationException::one('_form', 'That species no longer exists.');
        $values = self::validate($input, $speciesId);
        $changes = Audit::diff($before, $values, self::FIELDS);
        if (!$changes) {
            return;
        }
        Db::transaction(function () use ($speciesId, $values, $changes): void {
            SpeciesRepository::rename($speciesId, $values['name']);
            Audit::record('species_update', 'species', $speciesId, changes: $changes);
        });
    }

    /** @throws ValidationException when the species no longer exists */
    public static function setActive(int $speciesId, bool $active): void
    {
        $species = SpeciesRepository::find($speciesId) ?? throw ValidationException::one('_form', 'That species no longer exists.');
        if ((bool) $species['is_active'] === $active) {
            return;
        }
        Db::transaction(function () use ($speciesId, $active): void {
            SpeciesRepository::setActive($speciesId, $active);
            Audit::record($active ? 'species_activate' : 'species_deactivate', 'species', $speciesId,
                changes: ['is_active' => [(int) !$active, (int) $active]]);
        });
    }

    /** @return array{name: string} */
    private static function validate(array $input, ?int $speciesId): array
    {
        $name = Validator::text($input['name'] ?? null, 30);
        if ($name === null) {
            throw ValidationException::one('name', 'Enter the species name (up to 30 characters), e.g. Rabbit.');
        }
        if (SpeciesRepository::nameTaken($name, $speciesId)) {
            throw ValidationException::one('name', 'There is already a species with this name.');
        }
        return ['name' => $name];
    }
}
