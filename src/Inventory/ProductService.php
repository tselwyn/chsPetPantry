<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Reference\SpeciesRepository;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Products (plan P2A catalogue; UC-06 step 5 "food form, species, product or brand, units or
 * weight"; UC-14 pounds distributed).
 *
 * - Every product is one species and one food form; Dry and Wet products need a unit weight (the
 *   allotment is checked in pounds), Treat and Other may have none (reported in units, UC-14
 *   §3.3.2). The weight is copied onto each distribution line, so editing it never changes history.
 * - A duplicate is the same name, brand, species, form and unit weight (the same food in another
 *   bag size is a separate product with its own barcodes).
 * - Once a product has been received, counted or given out, its species and food form are part
 *   of history and are locked; name, brand, category and weight stay editable.
 * - Deactivating removes a product from new receipts and barcode links. Stock it still has can be
 *   given out and counted, so it stays on the stock and count pages while any site holds it; the
 *   ledger and offline replay never check whether a product is active.
 * - Every stock row is created at 0 for every site when the product is created (StockRepository).
 * - All writes run under the catalogue lock; edit forms carry a revision so a stale form is refused.
 */
final class ProductService
{
    public const FIELDS = ['category_id', 'name', 'brand', 'species_id', 'food_form', 'unit_weight', 'weight_unit'];
    public const FORMS = ['Dry', 'Wet', 'Treat', 'Other'];
    public const MAX_WEIGHT_LBS = '200';

    private const AUDITED = ['category_id', 'name', 'brand', 'species_id', 'food_form', 'unit_weight_lbs'];

    /** @throws ValidationException */
    public static function create(array $input, int $actorId): int
    {
        $values = self::validate($input, null);
        return Locks::catalogue(fn(): int => Db::transaction(function () use ($values): int {
            self::recheckDuplicate($values, null);
            $id = ProductRepository::insert($values);
            StockRepository::precreateForProduct($id);
            Audit::record('product_create', 'product', $id, details: $values);
            return $id;
        }));
    }

    /**
     * @param ?string $revision the product as the form showed it (null skips the check, for scripts)
     * @throws ValidationException
     * @throws StaleFormException
     */
    public static function update(int $productId, array $input, ?string $revision, int $actorId): void
    {
        $current = ProductRepository::find($productId) ?? throw ValidationException::one('_form', 'That product no longer exists.');
        $values = self::validate($input, $current);
        Locks::catalogue(fn() => Db::transaction(function () use ($productId, $values, $revision): void {
            $before = ProductRepository::lock($productId) ?? throw ValidationException::one('_form', 'That product no longer exists.');
            if ($revision !== null && !hash_equals(self::revision($before), $revision)) {
                throw new StaleFormException('Someone else changed this product since you opened it. The latest details are shown: make your change again.');
            }
            $changes = Audit::diff($before, $values, self::AUDITED);
            if (!$changes) {
                return;
            }
            if ((isset($changes['species_id']) || isset($changes['food_form'])) && ProductRepository::isUsed($productId)) {
                throw ValidationException::one(isset($changes['species_id']) ? 'species_id' : 'food_form',
                    'This product has already been received, counted or given out, so its species and food form cannot change. Add a new product instead.');
            }
            self::recheckDuplicate($values, $productId);
            ProductRepository::update($productId, $values);
            Audit::record('product_update', 'product', $productId, changes: $changes);
        }));
    }

    /**
     * Activate or deactivate. Deactivating a product that is still in stock somewhere needs
     * $confirmStock: the page shows where it is first.
     * @throws ValidationException 'confirm' when stock remains and it was not confirmed
     */
    public static function setActive(int $productId, bool $active, bool $confirmStock, int $actorId): void
    {
        Locks::catalogue(fn() => Db::transaction(function () use ($productId, $active, $confirmStock): void {
            $product = ProductRepository::lock($productId) ?? throw ValidationException::one('_form', 'That product no longer exists.');
            if ((bool) $product['is_active'] === $active) {
                return;
            }
            if ($active && ($category = ItemCategoryRepository::find((int) $product['category_id'])) !== null && $category['status'] !== 'Active') {
                throw ValidationException::one('_form', "Its category, {$category['name']}, is inactive. Reactivate the category or move the product first.");
            }
            $holdings = $active ? [] : StockRepository::holdings($productId);
            if ($holdings && !$confirmStock) {
                throw ValidationException::one('confirm', 'Still in stock: ' . implode(', ', array_map(
                    fn($h) => $h['site_name'] . ' ' . self::quantity($h['quantity_on_hand']), $holdings)) . '. It will no longer appear on new receipts, but this stock can still be given out and counted.');
            }
            ProductRepository::setActive($productId, $active);
            Audit::record($active ? 'product_activate' : 'product_deactivate', 'product', $productId,
                details: $holdings ? ['still_in_stock' => $holdings] : null,
                changes: ['is_active' => [(int) !$active, (int) $active]]);
        }));
    }

    /** The product as a form shows it; a save from a form showing an older state is refused. */
    public static function revision(array $product): string
    {
        return sha1(json_encode([(int) $product['category_id'], (string) $product['name'], $product['brand'], (int) $product['species_id'],
            (string) $product['food_form'], $product['unit_weight_lbs'], (int) $product['is_active']], JSON_THROW_ON_ERROR));
    }

    /** '40.00' → '40', '-2.50' → '-2.5'; stock is whole units unless an offline sync left a fraction. */
    public static function quantity(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /** @throws ValidationException naming the product that already exists */
    private static function recheckDuplicate(array $values, ?int $productId): void
    {
        $twin = ProductRepository::findDuplicate($values['name'], $values['brand'], $values['species_id'], $values['food_form'], $values['unit_weight_lbs'], $productId);
        if ($twin !== null) {
            throw ValidationException::one('name', 'This product already exists: ' . ProductRepository::label($twin)
                . ((int) $twin['is_active'] === 1 ? '.' : '. Reactivate it instead of adding it again.'));
        }
    }

    /**
     * @param ?array $current the product being edited (its current category and species stay choosable even if inactive)
     * @return array{category_id: int, name: string, brand: ?string, species_id: int, food_form: string, unit_weight_lbs: ?string}
     */
    private static function validate(array $input, ?array $current): array
    {
        $errors = [];
        $categoryId = Validator::wholeNumber((string) ($input['category_id'] ?? ''), 1, PHP_INT_MAX >> 1);
        $category = $categoryId !== null ? ItemCategoryRepository::find($categoryId) : null;
        if ($category === null || ($category['status'] !== 'Active' && $categoryId !== (int) ($current['category_id'] ?? 0))) {
            $errors['category_id'] = 'Choose an active category.';
        }
        $name = Validator::text($input['name'] ?? null, 100);
        if ($name === null) {
            $errors['name'] = 'Enter the product name (up to 100 characters), for example Adult dry dog food.';
        }
        $brandRaw = trim((string) ($input['brand'] ?? ''));
        $brand = $brandRaw === '' ? null : Validator::text($brandRaw, 60);
        if ($brandRaw !== '' && $brand === null) {
            $errors['brand'] = 'The brand can be at most 60 characters.';
        }
        $speciesId = Validator::wholeNumber((string) ($input['species_id'] ?? ''), 1, PHP_INT_MAX >> 1);
        $species = $speciesId !== null ? SpeciesRepository::find($speciesId) : null;
        if ($species === null || (!$species['is_active'] && $speciesId !== (int) ($current['species_id'] ?? 0))) {
            $errors['species_id'] = 'Choose the species this food is for.';
        }
        $form = Validator::oneOf($input['food_form'] ?? null, self::FORMS);
        if ($form === null) {
            $errors['food_form'] = 'Choose dry, wet, treat or other.';
        }
        $weight = self::weight(trim((string) ($input['unit_weight'] ?? '')), (string) ($input['weight_unit'] ?? 'lb'), $weightError);
        if ($weightError !== null) {
            $errors['unit_weight'] = $weightError;
        } elseif ($weight === null && in_array($form, ['Dry', 'Wet'], true)) {
            $errors['unit_weight'] = 'Enter the weight of one bag or can: dry and wet food are counted against the allotment in pounds.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['category_id' => (int) $categoryId, 'name' => (string) $name, 'brand' => $brand, 'species_id' => (int) $speciesId,
            'food_form' => (string) $form, 'unit_weight_lbs' => $weight];
    }

    /**
     * The unit weight in pounds with 2 decimals, from pounds or ounces (cans are labelled in ounces:
     * 5.5 oz = 0.34 lb). Null when left blank.
     */
    private static function weight(string $raw, string $unit, ?string &$error): ?string
    {
        $error = null;
        if ($raw === '') {
            return null;
        }
        if ($unit === 'oz') {
            $oz = Validator::decimal($raw, 2, '3200');
            $hundredths = $oz === null ? 0 : intdiv((int) str_replace('.', '', $oz) + 8, 16); // hundredths of an ounce ÷ 16, rounded
        } else {
            $lb = Validator::decimal($raw, 2, self::MAX_WEIGHT_LBS);
            $hundredths = $lb === null ? 0 : (int) str_replace('.', '', $lb);
        }
        if ($hundredths < 1) {
            $error = 'Enter the weight of one unit, from 0.01 to ' . self::MAX_WEIGHT_LBS . ' lb (or in ounces), with at most 2 decimals.';
            return null;
        }
        return Validator::fromUnits($hundredths, 2);
    }
}
