<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use InvalidArgumentException;
use Normalizer;
use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Configurable choice lists (plan P2A, lookup_value): delete and deactivation reasons,
 * emergency and decline reasons, referral sources, proof of residence, pet colours and
 * body types. Records store the value_code, which never changes once a value exists, so
 * renaming a label keeps every stored reference valid. Values are retired by
 * deactivating them, never deleted.
 */
final class LookupService
{
    /** list_key => [what the list is called on screen, whether each value belongs to one species] */
    public const LISTS = [
        'participant_delete_reason' => ['Reasons for deleting a participant', false],
        'pet_delete_reason' => ['Reasons for deleting a pet', false],
        'participant_deactivation_reason' => ['Reasons for deactivating a participant', false],
        'emergency_reason' => ['Emergency distribution reasons', false],
        'decline_reason' => ['Reasons a product was declined', false], // US-17: a participant turns down a product at a distribution
        'referral_source' => ['How participants heard about us', false],
        'proof_of_residence' => ['Proof of residence', false],
        'pet_colour' => ['Pet colours', false],
        'body_type' => ['Body types', true],
    ];

    /**
     * Longest code a list may use: value_code is VARCHAR(40), but some records store the code
     * in a narrower column (pet.body_type is VARCHAR(30)).
     */
    private const CODE_MAX = ['body_type' => 30];
    private const DEFAULT_CODE_MAX = 40;

    public static function isList(string $listKey): bool
    {
        return isset(self::LISTS[$listKey]);
    }

    public static function isSpeciesSpecific(string $listKey): bool
    {
        return self::definition($listKey)[1];
    }

    /**
     * Active values for a form's drop-down, in display order. For a species-specific list,
     * pass the pet's species to get only that species' values.
     * @return list<array{value_code:string,label:string}>
     */
    public static function options(string $listKey, ?int $speciesId = null): array
    {
        return LookupRepository::activeOptions($listKey, self::isSpeciesSpecific($listKey) ? $speciesId : null);
    }

    /** @throws ValidationException */
    public static function create(string $listKey, array $input): int
    {
        $speciesSpecific = self::isSpeciesSpecific($listKey);
        $errors = [];
        $speciesId = null;
        if ($speciesSpecific) {
            $speciesId = self::speciesId($input['species_id'] ?? null);
            if ($speciesId === null) {
                $errors['species_id'] = 'Choose the species this value is for.';
            }
        }
        $label = Validator::text(self::str($input['label'] ?? null), 100);
        if ($label === null) {
            $errors['label'] = 'Enter the wording people will see (up to 100 characters).';
        } elseif (LookupRepository::labelTaken($listKey, $label, $speciesId, null)) {
            $errors['label'] = 'This list already has a value with that wording.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return Db::transaction(function () use ($listKey, $label, $speciesId): int {
            $code = self::uniqueCode($listKey, self::slug($label, self::codeMax($listKey)));
            $id = LookupRepository::insert(['list_key' => $listKey, 'value_code' => $code, 'label' => $label, 'species_id' => $speciesId,
                'display_order' => LookupRepository::nextOrder($listKey, $speciesId)]);
            Audit::record('lookup_create', 'lookup_value', $id, details: ['list' => $listKey, 'value_code' => $code, 'label' => $label, 'species_id' => $speciesId]);
            return $id;
        });
    }

    /** Change the wording only; the stored code stays the same. @throws ValidationException */
    public static function rename(int $lookupId, array $input): void
    {
        $before = self::existing($lookupId);
        $speciesId = $before['species_id'] === null ? null : (int) $before['species_id'];
        $label = Validator::text(self::str($input['label'] ?? null), 100);
        if ($label === null) {
            throw ValidationException::one('label', 'Enter the wording people will see (up to 100 characters).');
        }
        if (LookupRepository::labelTaken($before['list_key'], $label, $speciesId, $lookupId)) {
            throw ValidationException::one('label', 'This list already has a value with that wording.');
        }
        $changes = Audit::diff($before, ['label' => $label], ['label']);
        if (!$changes) {
            return;
        }
        Db::transaction(function () use ($lookupId, $label, $before, $changes): void {
            LookupRepository::setLabel($lookupId, $label);
            Audit::record('lookup_rename', 'lookup_value', $lookupId, details: ['list' => $before['list_key'], 'value_code' => $before['value_code']], changes: $changes);
        });
    }

    /**
     * Move a value one place up or down within its list (and, for body types, its species).
     * Moving the first value up or the last one down does nothing.
     * @throws ValidationException
     */
    public static function move(int $lookupId, string $direction): void
    {
        $value = self::existing($lookupId);
        $group = array_values(array_filter(LookupRepository::forList($value['list_key']),
            static fn(array $v): bool => (int) ($v['species_id'] ?? 0) === (int) ($value['species_id'] ?? 0)));
        [$neighbour, $newOrders] = self::swap($group, 'lookup_id', $lookupId, $direction);
        if ($neighbour === null) {
            return;
        }
        Db::transaction(function () use ($lookupId, $value, $direction, $neighbour, $newOrders): void {
            foreach ($newOrders as $id => $order) {
                LookupRepository::setOrder($id, $order);
            }
            $changes = isset($newOrders[$lookupId]) ? ['display_order' => [(int) $value['display_order'], $newOrders[$lookupId]]] : [];
            Audit::record('lookup_reorder', 'lookup_value', $lookupId,
                details: ['list' => $value['list_key'], 'direction' => $direction, 'swapped_with' => $neighbour], changes: $changes);
        });
    }

    /**
     * Swap a row with its neighbour in an ordered group. The two display_order values are
     * exchanged; when the stored numbers have ties or gaps out of order, the group is
     * renumbered 1..n so the new order is exact.
     * @param list<array> $rows in display order
     * @return array{0:?int,1:array<int,int>} [neighbour id or null when there is none, id => new display_order for rows that change]
     */
    public static function swap(array $rows, string $idKey, int $id, string $direction): array
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException("Unknown direction '$direction'");
        }
        $ids = array_map('intval', array_column($rows, $idKey));
        $orders = array_map('intval', array_column($rows, 'display_order'));
        $i = array_search($id, $ids, true);
        $j = $i === false ? -1 : ($direction === 'up' ? $i - 1 : $i + 1);
        if ($i === false || !isset($ids[$j])) {
            return [null, []];
        }
        $increasing = true;
        for ($k = 1, $n = count($orders); $k < $n; $k++) {
            $increasing = $increasing && $orders[$k] > $orders[$k - 1];
        }
        $positions = $increasing ? $orders : range(1, count($orders));
        $old = array_combine($ids, $orders);
        $neighbour = $ids[$j];
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $changed = [];
        foreach ($ids as $position => $rowId) {
            if ($old[$rowId] !== $positions[$position]) {
                $changed[$rowId] = $positions[$position];
            }
        }
        return [$neighbour, $changed];
    }

    /** @throws ValidationException */
    public static function setActive(int $lookupId, bool $active): void
    {
        $value = self::existing($lookupId);
        if ((bool) $value['is_active'] === $active) {
            return;
        }
        Db::transaction(function () use ($lookupId, $active, $value): void {
            LookupRepository::setActive($lookupId, $active);
            Audit::record($active ? 'lookup_activate' : 'lookup_deactivate', 'lookup_value', $lookupId,
                details: ['list' => $value['list_key'], 'value_code' => $value['value_code']], changes: ['is_active' => [(int) !$active, (int) $active]]);
        });
    }

    /** A lowercase ASCII code made from a label, e.g. "Owner's request" => "owners_request". */
    public static function slug(string $label, int $maxLength = self::DEFAULT_CODE_MAX): string
    {
        if (class_exists(Normalizer::class)) {
            $label = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($label, Normalizer::FORM_D));
        }
        // Some iconv builds return false when a character cannot be transliterated; keep the label then.
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) : false;
        $ascii = (string) preg_replace('/[\'"`^~]/', '', $ascii === false ? $label : $ascii); // marks some iconv builds add ("e -> 'e)
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($ascii)), '_');
        return $slug === '' ? 'value' : rtrim(substr($slug, 0, $maxLength), '_');
    }

    /** The slug itself, or slug_2, slug_3 ... when the list already uses it (codes are never reused). */
    private static function uniqueCode(string $listKey, string $slug): string
    {
        $max = self::codeMax($listKey);
        $code = $slug;
        for ($n = 2; LookupRepository::codeTaken($listKey, $code); $n++) {
            $suffix = '_' . $n;
            $code = rtrim(substr($slug, 0, $max - strlen($suffix)), '_') . $suffix;
        }
        return $code;
    }

    private static function codeMax(string $listKey): int
    {
        return self::CODE_MAX[$listKey] ?? self::DEFAULT_CODE_MAX;
    }

    /** @return array{0:string,1:bool} */
    private static function definition(string $listKey): array
    {
        return self::LISTS[$listKey] ?? throw new InvalidArgumentException("Unknown lookup list '$listKey'");
    }

    private static function existing(int $lookupId): array
    {
        return LookupRepository::find($lookupId) ?? throw ValidationException::one('_form', 'That list value no longer exists.');
    }

    private static function speciesId(mixed $raw): ?int
    {
        $raw = trim((string) (is_scalar($raw) ? $raw : ''));
        if (!preg_match('/^\d{1,10}$/', $raw)) {
            return null;
        }
        return in_array((int) $raw, array_map('intval', array_column(LookupRepository::species(), 'species_id')), true) ? (int) $raw : null;
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
