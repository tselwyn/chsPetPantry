<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use PDOException;
use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Product categories (plan P2A catalogue; legacy viewItemCategories / viewAddItemCategory /
 * viewModifyItemCategory): a display group with a case size. Stock is always kept in units;
 * units_per_case lets receipt and count forms take "cases + loose units" and the stock page show
 * cases. is_banana_box marks mixed boxes rather than manufacturer cases. Categories are never
 * deleted; one with active products cannot be deactivated.
 */
final class ItemCategoryService
{
    public const FIELDS = ['name', 'is_banana_box', 'units_per_case'];
    public const MAX_UNITS_PER_CASE = 999;

    /** @throws ValidationException */
    public static function create(array $input): int
    {
        $values = self::validate($input, null);
        return Locks::catalogue(function () use ($values): int {
            try {
                return Db::transaction(function () use ($values): int {
                    self::recheckName($values['name'], null);
                    $id = ItemCategoryRepository::insert($values);
                    Audit::record('item_category_create', 'item_category', $id, details: $values);
                    return $id;
                });
            } catch (PDOException $e) {
                throw Db::isDuplicateKey($e) ? ValidationException::one('name', 'Another category already has this name.') : $e;
            }
        });
    }

    /**
     * @param ?string $revision the category as the form showed it (null skips the check, for scripts)
     * @throws ValidationException
     * @throws StaleFormException when someone else changed it since the form was opened
     */
    public static function update(int $categoryId, array $input, ?string $revision): void
    {
        $values = self::validate($input, $categoryId);
        Locks::catalogue(function () use ($categoryId, $values, $revision): void {
            try {
                Db::transaction(function () use ($categoryId, $values, $revision): void {
                    $before = ItemCategoryRepository::lock($categoryId) ?? throw ValidationException::one('_form', 'That category no longer exists.');
                    if ($revision !== null && !hash_equals(self::fingerprint($before), $revision)) {
                        throw new StaleFormException('Someone else changed this category since you opened it. The latest details are shown: make your change again.');
                    }
                    $changes = Audit::diff($before, $values, self::FIELDS);
                    if (!$changes) {
                        return;
                    }
                    self::recheckName($values['name'], $categoryId);
                    ItemCategoryRepository::update($categoryId, $values);
                    Audit::record('item_category_update', 'item_category', $categoryId, changes: $changes);
                });
            } catch (PDOException $e) {
                throw Db::isDuplicateKey($e) ? ValidationException::one('name', 'Another category already has this name.') : $e;
            }
        });
    }

    /** @throws ValidationException when deactivating a category that still has active products */
    public static function setActive(int $categoryId, bool $active): void
    {
        Locks::catalogue(fn() => Db::transaction(function () use ($categoryId, $active): void {
            $category = ItemCategoryRepository::lock($categoryId) ?? throw ValidationException::one('_form', 'That category no longer exists.');
            $status = $active ? 'Active' : 'Inactive';
            if ($category['status'] === $status) {
                return;
            }
            if (!$active && ($n = ItemCategoryRepository::activeProducts($categoryId)) > 0) {
                throw ValidationException::one('_form', "This category still has $n active product" . ($n === 1 ? '' : 's')
                    . '. Move them to another category or deactivate them first.');
            }
            ItemCategoryRepository::setStatus($categoryId, $status);
            Audit::record($active ? 'item_category_activate' : 'item_category_deactivate', 'item_category', $categoryId,
                changes: ['status' => [$category['status'], $status]]);
        }));
    }

    /** The category as a form showed it; a save from a form showing an older state is refused. */
    public static function revision(array $category): string
    {
        return self::fingerprint($category);
    }

    private static function fingerprint(array $c): string
    {
        return sha1(json_encode([(string) $c['name'], (int) $c['is_banana_box'], (int) $c['units_per_case'], (string) $c['status']], JSON_THROW_ON_ERROR));
    }

    private static function recheckName(string $name, ?int $exceptId): void
    {
        if (ItemCategoryRepository::nameTaken($name, $exceptId)) {
            throw ValidationException::one('name', 'Another category already has this name.');
        }
    }

    /** @return array{name: string, is_banana_box: int, units_per_case: int} */
    private static function validate(array $input, ?int $categoryId): array
    {
        $errors = [];
        $name = Validator::text($input['name'] ?? null, 50);
        if ($name === null) {
            $errors['name'] = 'Enter the category name (up to 50 characters), for example Dry dog food.';
        } elseif (ItemCategoryRepository::nameTaken($name, $categoryId)) {
            $errors['name'] = 'Another category already has this name.';
        }
        $units = Validator::wholeNumber((string) ($input['units_per_case'] ?? ''), 1, self::MAX_UNITS_PER_CASE);
        if ($units === null) {
            $errors['units_per_case'] = 'Enter how many units come in a case: a whole number from 1 (sold singly) to ' . self::MAX_UNITS_PER_CASE . '.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['name' => (string) $name, 'is_banana_box' => empty($input['is_banana_box']) ? 0 : 1, 'units_per_case' => (int) $units];
    }
}
