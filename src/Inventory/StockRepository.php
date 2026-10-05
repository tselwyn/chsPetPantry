<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use Pfpms\Db;
use Pfpms\Validation\Validator;

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

    /**
     * Stock on hand at a site: every active product and any inactive one the site still holds
     * (a missing stock row counts as 0), with the last day it was counted and the nearest
     * best-before date of the stock (see shelfExpiry()).
     * @param array{category_id?: ?int, species_id?: ?int, food_form?: ?string, q?: ?string, in_stock?: bool} $filters
     * @return list<array>
     */
    public static function onHand(int $siteId, array $filters = []): array
    {
        $where = ['(p.is_active = 1 OR COALESCE(ss.quantity_on_hand, 0) <> 0)'];
        $args = [$siteId, $siteId];
        foreach (['category_id' => 'p.category_id', 'species_id' => 'p.species_id', 'food_form' => 'p.food_form'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "$column = ?";
                $args[] = $filters[$key];
            }
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.name LIKE ? OR p.brand LIKE ?)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            array_push($args, $like, $like);
        }
        if (!empty($filters['in_stock'])) {
            $where[] = 'COALESCE(ss.quantity_on_hand, 0) <> 0';
        }
        $st = Db::pdo()->prepare(
            'SELECT ' . ProductRepository::COLUMNS . ', COALESCE(ss.quantity_on_hand, 0) AS quantity_on_hand,
                    (SELECT MAX(ic.count_date) FROM inventory_count_line cl JOIN inventory_count ic ON ic.count_id = cl.count_id
                      WHERE ic.site_id = ? AND cl.product_id = p.product_id) AS last_counted
               FROM product p
               JOIN item_category c ON c.category_id = p.category_id
               JOIN species sp ON sp.species_id = p.species_id
               LEFT JOIN site_stock ss ON ss.site_id = ? AND ss.product_id = p.product_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY c.name, p.name, p.brand, p.unit_weight_lbs, p.product_id'
        );
        $st->execute($args);
        $rows = $st->fetchAll();
        $expiry = self::shelfExpiry($siteId);
        foreach ($rows as &$row) {
            $row['quantity_on_hand'] = Validator::fromUnits(Ledger::toHundredths((string) $row['quantity_on_hand']), 2); // '0' when there is no row
            $row['shelf_expiry'] = $expiry[(int) $row['product_id']] ?? null;
        }
        unset($row);
        return $rows;
    }

    /**
     * The nearest best-before date of each product's stock at a site, assuming the stock on hand is
     * what arrived most recently (first in, first out): the site's receipt lines that were not
     * voided, newest delivery first, until they add up to the stock on hand. Only products with
     * stock above zero, and only when one of those lines has a date.
     * @return array<int, string>
     */
    public static function shelfExpiry(int $siteId): array
    {
        $st = Db::pdo()->prepare(
            "SELECT l.product_id, l.quantity, l.expiration, ss.quantity_on_hand
               FROM stock_receipt_line l
               JOIN stock_receipt r ON r.receipt_id = l.receipt_id
               JOIN site_stock ss ON ss.site_id = r.site_id AND ss.product_id = l.product_id AND ss.quantity_on_hand > 0
              WHERE r.site_id = ?
                AND NOT EXISTS (SELECT 1 FROM inventory_transaction v WHERE v.receipt_line_id = l.receipt_line_id AND v.txn_type = 'Reversal')
              ORDER BY l.product_id, r.received_on DESC, l.receipt_line_id DESC"
        );
        $st->execute([$siteId]);
        $covered = [];
        $expiry = [];
        foreach ($st->fetchAll() as $line) {
            $pid = (int) $line['product_id'];
            $covered[$pid] ??= 0;
            if ($covered[$pid] >= Ledger::toHundredths((string) $line['quantity_on_hand'])) {
                continue; // newer deliveries already make up the stock: these goods have gone
            }
            $covered[$pid] += (int) $line['quantity'] * 100;
            if ($line['expiration'] !== null && (!isset($expiry[$pid]) || $line['expiration'] < $expiry[$pid])) {
                $expiry[$pid] = $line['expiration'];
            }
        }
        return $expiry;
    }

    /** The site's stock of one product (0 when it has no row yet). */
    public static function quantity(int $siteId, int $productId): string
    {
        $st = Db::pdo()->prepare('SELECT quantity_on_hand FROM site_stock WHERE site_id = ? AND product_id = ?');
        $st->execute([$siteId, $productId]);
        return (string) ($st->fetchColumn() ?: '0.00');
    }

    /**
     * The latest ledger rows of a product at a site, newest first, with what each came from and the
     * stock after it (worked back from the stock now; every change of stock has a ledger row).
     * A count offset (a movement that an earlier count already included, cancelled against that
     * count) is flagged 'offset'; the movement it cancels has no stock-after of its own, since the
     * two were one change.
     * @return array{rows: list<array>, more: bool}
     */
    public static function history(int $siteId, int $productId, int $limit = 100): array
    {
        $st = Db::pdo()->prepare(
            "SELECT t.txn_id, t.txn_type, t.qty_change, t.recorded_at, CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name,
                    t.receipt_line_id, r.receipt_id, r.name AS receipt_name, t.count_line_id, ic.count_id, ic.count_date, t.distribution_line_id,
                    (SELECT MIN(f.txn_id) FROM inventory_transaction f WHERE f.count_line_id = t.count_line_id) AS count_first_txn
               FROM inventory_transaction t
               JOIN user_account u ON u.user_id = t.recorded_by
               LEFT JOIN stock_receipt_line rl ON rl.receipt_line_id = t.receipt_line_id
               LEFT JOIN stock_receipt r ON r.receipt_id = rl.receipt_id
               LEFT JOIN inventory_count_line cl ON cl.count_line_id = t.count_line_id
               LEFT JOIN inventory_count ic ON ic.count_id = cl.count_id
              WHERE t.site_id = ? AND t.product_id = ?
              ORDER BY t.txn_id DESC LIMIT " . (max(1, min(500, $limit)) + 1)
        );
        $st->execute([$siteId, $productId]);
        $rows = $st->fetchAll();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        // Worked back from the ledger itself up to the newest row shown, so a change committed meanwhile cannot shift the figures.
        $balance = $rows ? self::ledgerTotal($siteId, $productId, (int) $rows[0]['txn_id']) : 0;
        $afterOffset = false;
        foreach ($rows as &$row) {
            $row['offset'] = $row['count_line_id'] !== null && (int) $row['count_first_txn'] < (int) $row['txn_id'];
            $row['balance_after'] = $afterOffset ? null : Validator::fromUnits($balance, 2);
            $afterOffset = $row['offset']; // the next (older) row is the movement it cancels
            $balance -= Ledger::toHundredths((string) $row['qty_change']);
        }
        unset($row);
        return ['rows' => $rows, 'more' => $more];
    }

    /** The sum of a product's ledger rows at a site up to and including one row, in hundredths. */
    private static function ledgerTotal(int $siteId, int $productId, int $upToTxnId): int
    {
        $st = Db::pdo()->prepare('SELECT COALESCE(SUM(qty_change), 0) FROM inventory_transaction WHERE site_id = ? AND product_id = ? AND txn_id <= ?');
        $st->execute([$siteId, $productId, $upToTxnId]);
        return Ledger::toHundredths((string) $st->fetchColumn());
    }
}
