<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use Pfpms\Db;

/** SQL for inventory_count and inventory_count_line (stock counts). Prepared statements only. */
final class CountRepository
{
    /**
     * What a count at the site lists: every active product, and any inactive one the site still
     * holds (including below zero after an offline sync), optionally one category, with its stock.
     * @return list<array>
     */
    public static function sheet(int $siteId, ?int $categoryId = null): array
    {
        $st = Db::pdo()->prepare(
            'SELECT ' . ProductRepository::COLUMNS . ', COALESCE(ss.quantity_on_hand, 0) AS quantity_on_hand
               FROM product p
               JOIN item_category c ON c.category_id = p.category_id
               JOIN species sp ON sp.species_id = p.species_id
               LEFT JOIN site_stock ss ON ss.site_id = ? AND ss.product_id = p.product_id
              WHERE (p.is_active = 1 OR COALESCE(ss.quantity_on_hand, 0) <> 0)' . ($categoryId !== null ? ' AND p.category_id = ?' : '') . '
              ORDER BY c.name, p.name, p.brand, p.unit_weight_lbs, p.product_id'
        );
        $st->execute($categoryId !== null ? [$siteId, $categoryId] : [$siteId]);
        return $st->fetchAll();
    }

    /**
     * For each of these products, the latest count at the site dated on or after a day (it may
     * already include goods received that day).
     * @param list<int> $productIds
     * @return array<int, array{count_id: int, count_line_id: int, count_date: string, posted_at: string}>
     */
    public static function latestCountsSince(int $siteId, array $productIds, string $sinceDate): array
    {
        if (!$productIds) {
            return [];
        }
        $st = Db::pdo()->prepare(
            'SELECT cl.product_id, cl.count_line_id, c.count_id, c.count_date, c.posted_at
               FROM inventory_count_line cl JOIN inventory_count c ON c.count_id = cl.count_id
              WHERE c.site_id = ? AND c.count_date >= ? AND cl.product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')
              ORDER BY cl.count_line_id'
        );
        $st->execute([$siteId, $sinceDate, ...array_map('intval', $productIds)]);
        $latest = [];
        foreach ($st->fetchAll() as $row) {
            $latest[(int) $row['product_id']] = $row;
        }
        return $latest;
    }

    /**
     * The earliest count at the site of any of these products dated on or after $from and before
     * $before (a receipt moved back to $from would never have been asked about it).
     * @param list<int> $productIds
     * @return ?array{count_id: int, count_date: string}
     */
    public static function firstCountBetween(int $siteId, array $productIds, string $from, string $before): ?array
    {
        if (!$productIds) {
            return null;
        }
        $st = Db::pdo()->prepare(
            'SELECT c.count_id, c.count_date FROM inventory_count c
              WHERE c.site_id = ? AND c.count_date >= ? AND c.count_date < ?
                AND EXISTS (SELECT 1 FROM inventory_count_line cl WHERE cl.count_id = c.count_id AND cl.product_id IN ('
                . implode(',', array_fill(0, count($productIds), '?')) . '))
              ORDER BY c.count_date, c.count_id LIMIT 1'
        );
        $st->execute([$siteId, $from, $before, ...array_map('intval', $productIds)]);
        return $st->fetch() ?: null;
    }

    /** An event that is Open at the site now: counts wait until it closes. */
    public static function openEvent(int $siteId): ?array
    {
        $st = Db::pdo()->prepare("SELECT event_id, event_date FROM distribution_event WHERE site_id = ? AND status = 'Open' ORDER BY event_id LIMIT 1");
        $st->execute([$siteId]);
        return $st->fetch() ?: null;
    }

    /**
     * Items that tablets at the site hold and have not synced (from their last heartbeat): tablets in
     * service, and retiring tablets that will still upload; not erased tablets or ones told to erase
     * without uploading.
     */
    public static function pendingDeviceItems(int $siteId): int
    {
        $st = Db::pdo()->prepare(
            "SELECT COALESCE(SUM(pending_count), 0) FROM device
              WHERE site_id = ? AND token_hash IS NOT NULL AND wiped_at IS NULL AND (revoked_at IS NULL OR wipe_mode = 'Push Then Wipe')"
        );
        $st->execute([$siteId]);
        return (int) $st->fetchColumn();
    }

    public static function insert(int $siteId, string $location, string $countDate, int $countedBy, string $postedAt): int
    {
        Db::pdo()->prepare('INSERT INTO inventory_count (site_id, location, count_date, counted_by, posted_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$siteId, $location, $countDate, $countedBy, $postedAt]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function insertLine(int $countId, int $productId, int $quantity): int
    {
        Db::pdo()->prepare('INSERT INTO inventory_count_line (count_id, product_id, quantity) VALUES (?, ?, ?)')->execute([$countId, $productId, $quantity]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function find(int $countId): ?array
    {
        $st = Db::pdo()->prepare(
            "SELECT c.count_id, c.site_id, s.name AS site_name, c.location, c.count_date, c.posted_at, CONCAT(u.first_name, ' ', u.last_name) AS counted_by_name
               FROM inventory_count c JOIN site s ON s.site_id = c.site_id JOIN user_account u ON u.user_id = c.counted_by
              WHERE c.count_id = ?"
        );
        $st->execute([$countId]);
        return $st->fetch() ?: null;
    }

    /**
     * A count's lines: what was counted and the change it made (its own Count Adjustment, the first
     * ledger row of the line), so the stock before the count is counted − change.
     * @return list<array>
     */
    public static function lines(int $countId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT cl.count_line_id, cl.quantity AS counted,
                    (SELECT t.qty_change FROM inventory_transaction t WHERE t.count_line_id = cl.count_line_id ORDER BY t.txn_id LIMIT 1) AS change_units, '
                    . ProductRepository::COLUMNS . '
               FROM inventory_count_line cl
               JOIN product p ON p.product_id = cl.product_id
               JOIN item_category c ON c.category_id = p.category_id
               JOIN species sp ON sp.species_id = p.species_id
              WHERE cl.count_id = ? ORDER BY c.name, p.name, p.product_id'
        );
        $st->execute([$countId]);
        return $st->fetchAll();
    }

    /** @return list<array> the latest counts at a site */
    public static function recent(int $siteId, int $limit = 20): array
    {
        $st = Db::pdo()->prepare(
            "SELECT c.count_id, c.location, c.count_date, c.posted_at, CONCAT(u.first_name, ' ', u.last_name) AS counted_by_name,
                    (SELECT COUNT(*) FROM inventory_count_line cl WHERE cl.count_id = c.count_id) AS line_count
               FROM inventory_count c JOIN user_account u ON u.user_id = c.counted_by
              WHERE c.site_id = ? ORDER BY c.count_id DESC LIMIT " . max(1, min(100, $limit))
        );
        $st->execute([$siteId]);
        return $st->fetchAll();
    }
}
