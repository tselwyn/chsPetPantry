<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use Pfpms\Db;

/** SQL for product and product_barcode. Prepared statements only; returns plain arrays. */
final class ProductRepository
{
    public const COLUMNS = 'p.product_id, p.category_id, c.name AS category_name, c.units_per_case, c.is_banana_box, p.name, p.brand,
        p.species_id, sp.name AS species_name, p.food_form, p.unit_weight_lbs, p.is_active';
    private const FROM = 'product p JOIN item_category c ON c.category_id = p.category_id JOIN species sp ON sp.species_id = p.species_id';

    /**
     * Products for the catalogue, grouped by category, with their number of barcodes.
     * @param array{species_id?: ?int, food_form?: ?string, status?: ?string, q?: ?string} $filters
     * @return list<array>
     */
    public static function all(array $filters = []): array
    {
        $where = [];
        $args = [];
        if (!empty($filters['species_id'])) {
            $where[] = 'p.species_id = ?';
            $args[] = $filters['species_id'];
        }
        if (!empty($filters['food_form'])) {
            $where[] = 'p.food_form = ?';
            $args[] = $filters['food_form'];
        }
        if (($filters['status'] ?? 'active') !== 'all') {
            $where[] = 'p.is_active = ?';
            $args[] = ($filters['status'] ?? 'active') === 'inactive' ? 0 : 1;
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.name LIKE ? OR p.brand LIKE ?)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            array_push($args, $like, $like);
        }
        $st = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ', (SELECT COUNT(*) FROM product_barcode b WHERE b.product_id = p.product_id) AS barcodes
               FROM ' . self::FROM . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
              ORDER BY c.name, p.name, p.brand, p.unit_weight_lbs, p.product_id'
        );
        $st->execute($args);
        return $st->fetchAll();
    }

    /** @return list<array> active products, plus $includeId even if inactive, for pick lists, grouped by category */
    public static function choices(?int $includeId = null): array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE p.is_active = 1 OR p.product_id = ?
                                  ORDER BY c.name, p.name, p.brand, p.unit_weight_lbs, p.product_id');
        $st->execute([$includeId ?? 0]);
        return $st->fetchAll();
    }

    public static function find(int $productId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE p.product_id = ?');
        $st->execute([$productId]);
        return $st->fetch() ?: null;
    }

    /**
     * Lock the product row for the rest of the transaction and return it as it is now. Receipt,
     * count and ledger rows take a shared lock on it through their foreign keys, so this also
     * waits for any movement of the product in flight (the "used" check then sees it).
     */
    public static function lock(int $productId): ?array
    {
        $st = Db::pdo()->prepare('SELECT product_id FROM product WHERE product_id = ? FOR UPDATE');
        $st->execute([$productId]);
        return $st->fetchColumn() === false ? null : self::find($productId);
    }

    /**
     * Another product with the same name, brand, species, form and unit weight, under the column
     * collation (so case, accents and trailing spaces do not make a difference); NULL brand and
     * weight match NULL.
     */
    public static function findDuplicate(string $name, ?string $brand, int $speciesId, string $form, ?string $weight, ?int $exceptId): ?array
    {
        $st = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . '
              WHERE p.name = ? AND p.brand <=> ? AND p.species_id = ? AND p.food_form = ? AND p.unit_weight_lbs <=> ? AND p.product_id <> ?
              ORDER BY p.is_active DESC, p.product_id LIMIT 1'
        );
        $st->execute([$name, $brand, $speciesId, $form, $weight, $exceptId ?? 0]);
        return $st->fetch() ?: null;
    }

    /**
     * The product has been received, counted, moved or given out, so its species and food form are
     * part of history and can no longer change.
     */
    public static function isUsed(int $productId): bool
    {
        $st = Db::pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM inventory_transaction WHERE product_id = ?)
                 OR EXISTS (SELECT 1 FROM stock_receipt_line WHERE product_id = ?)
                 OR EXISTS (SELECT 1 FROM inventory_count_line WHERE product_id = ?)
                 OR EXISTS (SELECT 1 FROM distribution_line WHERE product_id = ?)
                 OR EXISTS (SELECT 1 FROM unmet_request WHERE product_id = ?)'
        );
        $st->execute(array_fill(0, 5, $productId));
        return (bool) $st->fetchColumn();
    }

    /** @param array{category_id: int, name: string, brand: ?string, species_id: int, food_form: string, unit_weight_lbs: ?string} $v */
    public static function insert(array $v): int
    {
        Db::pdo()->prepare('INSERT INTO product (category_id, name, brand, species_id, food_form, unit_weight_lbs, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([$v['category_id'], $v['name'], $v['brand'], $v['species_id'], $v['food_form'], $v['unit_weight_lbs']]);
        return (int) Db::pdo()->lastInsertId();
    }

    /** @param array{category_id: int, name: string, brand: ?string, species_id: int, food_form: string, unit_weight_lbs: ?string} $v */
    public static function update(int $productId, array $v): void
    {
        Db::pdo()->prepare('UPDATE product SET category_id = ?, name = ?, brand = ?, species_id = ?, food_form = ?, unit_weight_lbs = ? WHERE product_id = ?')
            ->execute([$v['category_id'], $v['name'], $v['brand'], $v['species_id'], $v['food_form'], $v['unit_weight_lbs'], $productId]);
    }

    public static function setActive(int $productId, bool $active): void
    {
        Db::pdo()->prepare('UPDATE product SET is_active = ? WHERE product_id = ?')->execute([$active ? 1 : 0, $productId]);
    }

    // Barcodes ---------------------------------------------------------------------------

    /** @return list<array{barcode: string, product_id: int, linked_by: int, linked_by_name: string, linked_at: string}> */
    public static function barcodes(int $productId): array
    {
        $st = Db::pdo()->prepare(
            "SELECT b.barcode, b.product_id, b.linked_by, CONCAT(u.first_name, ' ', u.last_name) AS linked_by_name, b.linked_at
               FROM product_barcode b JOIN user_account u ON u.user_id = b.linked_by
              WHERE b.product_id = ? ORDER BY b.linked_at, b.barcode"
        );
        $st->execute([$productId]);
        return $st->fetchAll();
    }

    /**
     * The products that own any of these canonical codes, keyed by code.
     * @param list<string> $codes
     * @return array<string, int>
     */
    public static function owners(array $codes): array
    {
        if (!$codes) {
            return [];
        }
        $st = Db::pdo()->prepare('SELECT barcode, product_id FROM product_barcode WHERE barcode IN (' . implode(',', array_fill(0, count($codes), '?')) . ')');
        $st->execute($codes);
        $owners = [];
        foreach ($st->fetchAll() as $row) {
            $owners[(string) $row['barcode']] = (int) $row['product_id'];
        }
        return $owners;
    }

    public static function insertBarcode(string $code, int $productId, int $linkedBy, string $linkedAt): void
    {
        Db::pdo()->prepare('INSERT INTO product_barcode (barcode, product_id, linked_by, linked_at) VALUES (?, ?, ?, ?)')
            ->execute([$code, $productId, $linkedBy, $linkedAt]);
    }

    public static function moveBarcode(string $code, int $toProductId): int
    {
        $st = Db::pdo()->prepare('UPDATE product_barcode SET product_id = ? WHERE barcode = ?');
        $st->execute([$toProductId, $code]);
        return $st->rowCount();
    }

    public static function deleteBarcode(string $code): int
    {
        $st = Db::pdo()->prepare('DELETE FROM product_barcode WHERE barcode = ?');
        $st->execute([$code]);
        return $st->rowCount();
    }

    /** How people see a product in every list: "Kibble · Acme · Dog dry · 30.00 lb". */
    public static function label(array $p): string
    {
        $parts = [$p['name']];
        if (($p['brand'] ?? null) !== null && $p['brand'] !== '') {
            $parts[] = $p['brand'];
        }
        $parts[] = $p['species_name'] . ' ' . strtolower($p['food_form']);
        if ($p['unit_weight_lbs'] !== null) {
            $weight = (string) $p['unit_weight_lbs'];
            $parts[] = (str_contains($weight, '.') ? rtrim(rtrim($weight, '0'), '.') : $weight) . ' lb'; // '30.00' → '30', '0.34' stays
        }
        return implode(' · ', $parts) . ((int) $p['is_active'] === 1 ? '' : ' (inactive)');
    }
}
