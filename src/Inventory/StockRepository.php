<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use Pfpms\Db;

/**
 * site_stock rows outside the ledger: they are created at 0 for every site × product when a
 * product or a site is created (plan P2A), so a stock movement always locks an existing row and
 * never takes a gap lock. Only Ledger changes quantity_on_hand.
 */
final class StockRepository
{
    /** A new product gets a zero row at every site, active or not. The product id is new, so no duplicates. */
    public static function precreateForProduct(int $productId): void
    {
        Db::pdo()->prepare('INSERT INTO site_stock (site_id, product_id, quantity_on_hand) SELECT site_id, ?, 0 FROM site')->execute([$productId]);
    }

    /** A new site gets a zero row for every product, active or not. */
    public static function precreateForSite(int $siteId): void
    {
        Db::pdo()->prepare('INSERT INTO site_stock (site_id, product_id, quantity_on_hand) SELECT ?, product_id, 0 FROM product')->execute([$siteId]);
    }

    /**
     * Where a product is in stock (on hand not zero, including negative after an offline sync).
     * @return list<array{site_id: int, site_name: string, site_active: int, quantity_on_hand: string}>
     */
    public static function holdings(int $productId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT s.site_id, s.name AS site_name, s.is_active AS site_active, ss.quantity_on_hand
               FROM site_stock ss JOIN site s ON s.site_id = ss.site_id
              WHERE ss.product_id = ? AND ss.quantity_on_hand <> 0
              ORDER BY s.name'
        );
        $st->execute([$productId]);
        return $st->fetchAll();
    }
}
