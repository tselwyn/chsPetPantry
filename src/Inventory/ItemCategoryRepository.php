<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use Pfpms\Db;

/** SQL for item_category. Prepared statements only; returns plain arrays. */
final class ItemCategoryRepository
{
    public const COLUMNS = 'category_id, name, is_banana_box, units_per_case, status';

    /** @return list<array> every category with its number of active and all products, by name */
    public static function all(): array
    {
        return Db::pdo()->query(
            'SELECT c.category_id, c.name, c.is_banana_box, c.units_per_case, c.status,
                    (SELECT COUNT(*) FROM product p WHERE p.category_id = c.category_id AND p.is_active = 1) AS active_products,
                    (SELECT COUNT(*) FROM product p WHERE p.category_id = c.category_id) AS products
               FROM item_category c ORDER BY c.name'
        )->fetchAll();
    }

    /** @return list<array> active categories, plus $includeId even if inactive (for a product's current category) */
    public static function choices(?int $includeId = null): array
    {
        $st = Db::pdo()->prepare("SELECT " . self::COLUMNS . " FROM item_category WHERE status = 'Active' OR category_id = ? ORDER BY name");
        $st->execute([$includeId ?? 0]);
        return $st->fetchAll();
    }

    public static function find(int $categoryId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM item_category WHERE category_id = ?');
        $st->execute([$categoryId]);
        return $st->fetch() ?: null;
    }

    /** Lock the row for the rest of the transaction and return it as it is now. */
    public static function lock(int $categoryId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM item_category WHERE category_id = ? FOR UPDATE');
        $st->execute([$categoryId]);
        return $st->fetch() ?: null;
    }

    /** The name is taken, ignoring case, accents and trailing spaces (the column collation). */
    public static function nameTaken(string $name, ?int $exceptCategoryId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM item_category WHERE name = ? AND category_id <> ?');
        $st->execute([$name, $exceptCategoryId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    public static function activeProducts(int $categoryId): int
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM product WHERE category_id = ? AND is_active = 1');
        $st->execute([$categoryId]);
        return (int) $st->fetchColumn();
    }

    /** @param array{name: string, is_banana_box: int, units_per_case: int} $v */
    public static function insert(array $v): int
    {
        Db::pdo()->prepare("INSERT INTO item_category (name, is_banana_box, units_per_case, status) VALUES (?, ?, ?, 'Active')")
            ->execute([$v['name'], $v['is_banana_box'], $v['units_per_case']]);
        return (int) Db::pdo()->lastInsertId();
    }

    /** @param array{name: string, is_banana_box: int, units_per_case: int} $v */
    public static function update(int $categoryId, array $v): void
    {
        Db::pdo()->prepare('UPDATE item_category SET name = ?, is_banana_box = ?, units_per_case = ? WHERE category_id = ?')
            ->execute([$v['name'], $v['is_banana_box'], $v['units_per_case'], $categoryId]);
    }

    public static function setStatus(int $categoryId, string $status): void
    {
        Db::pdo()->prepare('UPDATE item_category SET status = ? WHERE category_id = ?')->execute([$status, $categoryId]);
    }
}
