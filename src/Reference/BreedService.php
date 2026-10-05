<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Breeds of each species (plan P2A, US-13). A breed name is unique within its species.
 * Breeds are never deleted: deactivating one hides it from the pet form, and pets already
 * recorded with it keep it. Volunteers can still type a breed that is not on the list.
 */
final class BreedService
{
    public const FIELDS = ['name'];

    /** @throws ValidationException */
    public static function create(int $speciesId, array $input): int
    {
        if (SpeciesRepository::find($speciesId) === null) {
            throw ValidationException::one('_form', 'That species no longer exists.');
        }
        $values = self::validate($speciesId, $input, null);
        return Db::transaction(function () use ($speciesId, $values): int {
            $id = BreedRepository::insert($speciesId, $values['name']);
            Audit::record('breed_create', 'breed', $id, details: ['species_id' => $speciesId, 'name' => $values['name']]);
            return $id;
        });
    }

    /** @throws ValidationException */
    public static function rename(int $breedId, array $input): void
    {
        $before = BreedRepository::find($breedId) ?? throw ValidationException::one('_form', 'That breed no longer exists.');
        $values = self::validate((int) $before['species_id'], $input, $breedId);
        $changes = Audit::diff($before, $values, self::FIELDS);
        if (!$changes) {
            return;
        }
        Db::transaction(function () use ($breedId, $values, $changes): void {
            BreedRepository::rename($breedId, $values['name']);
            Audit::record('breed_update', 'breed', $breedId, changes: $changes);
        });
    }

    /** @throws ValidationException when the breed no longer exists */
    public static function setActive(int $breedId, bool $active): void
    {
        $breed = BreedRepository::find($breedId) ?? throw ValidationException::one('_form', 'That breed no longer exists.');
        if ((bool) $breed['is_active'] === $active) {
            return;
        }
        Db::transaction(function () use ($breedId, $active): void {
            BreedRepository::setActive($breedId, $active);
            Audit::record($active ? 'breed_activate' : 'breed_deactivate', 'breed', $breedId,
                changes: ['is_active' => [(int) !$active, (int) $active]]);
        });
    }

    /** @return array{name: string} */
    private static function validate(int $speciesId, array $input, ?int $breedId): array
    {
        $name = Validator::text($input['name'] ?? null, 60);
        if ($name === null) {
            throw ValidationException::one('name', 'Enter the breed name (up to 60 characters).');
        }
        if (BreedRepository::nameTaken($speciesId, $name, $breedId)) {
            throw ValidationException::one('name', 'This species already has a breed with this name.');
        }
        return ['name' => $name];
    }
}
