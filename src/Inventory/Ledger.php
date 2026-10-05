<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use InvalidArgumentException;
use LogicException;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * The inventory ledger (plan P2A): the only code that changes stock. Every change of
 * site_stock.quantity_on_hand is written with an inventory_transaction row in the same transaction,
 * so the ledger always adds up to the stock on hand (the P2A exit check, UC-14 §4.5). Ledger rows
 * are never changed or deleted: a mistake is corrected by a Reversal or a Count Adjustment.
 *
 * Used by receipts and counts now, and by distributions, reversals and offline replay in P4:
 * - Receipt (+, receipt_line_id), Distribution (−, distribution_line_id), Count Adjustment (any
 *   sign, including 0, count_line_id) and Reversal (either sign, exactly one of the three).
 *   Transfer is not used in R1.
 * - A move that would take a product below zero is refused, unless the caller says the food has
 *   already left (offline replay, late paper slips). A move that raises stock, or leaves it where it
 *   is, is never refused, even when an offline sync has already left the product below zero.
 * - Count offset: a stock count made after a movement already reflects it. A movement that happened
 *   before a later count (a receipt voided after the count, an offline distribution synced after it)
 *   posts an equal and opposite Count Adjustment against that count line, so the stock stays at
 *   what was counted.
 * - Call lock() once per transaction, before any other stock work (it creates any missing row and
 *   locks the rows in product order, so two writers never deadlock); post() locks what it needs.
 *   Everything runs inside the caller's Db::transaction and never commits by itself.
 * - Quantities are whole hundredths internally; the columns are DECIMAL(8,2).
 */
final class Ledger
{
    public const MAX_HUNDREDTHS = 99999999; // DECIMAL(8,2): 999,999.99

    private const REFS = ['receipt_line_id', 'count_line_id', 'distribution_line_id'];
    private const TYPE_REF = ['Receipt' => 'receipt_line_id', 'Distribution' => 'distribution_line_id', 'Count Adjustment' => 'count_line_id'];

    /**
     * Lock the stock rows of these products at a site (creating any missing row at 0) and return the
     * quantity on hand of each, in hundredths.
     * @param list<int> $productIds
     * @return array<int, int>
     */
    public static function lock(int $siteId, array $productIds): array
    {
        if (!Db::inTransaction()) {
            throw new LogicException('Ledger::lock must run inside Db::transaction.');
        }
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        sort($ids);
        if (!$ids) {
            return [];
        }
        // In product order: takes the row locks (no gap locks, the rows exist or are created here).
        $values = implode(', ', array_fill(0, count($ids), '(?, ?, 0)'));
        $args = [];
        foreach ($ids as $id) {
            array_push($args, $siteId, $id);
        }
        Db::pdo()->prepare("INSERT INTO site_stock (site_id, product_id, quantity_on_hand) VALUES $values ON DUPLICATE KEY UPDATE site_id = site_id")
            ->execute($args);
        $st = Db::pdo()->prepare('SELECT product_id, quantity_on_hand FROM site_stock WHERE site_id = ? AND product_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY product_id FOR UPDATE');
        $st->execute([$siteId, ...$ids]);
        $onHand = [];
        foreach ($st->fetchAll() as $row) {
            $onHand[(int) $row['product_id']] = self::toHundredths((string) $row['quantity_on_hand']);
        }
        return $onHand;
    }

    /**
     * Post movements of one type at one site.
     * @param list<array{product_id: int, qty: int|string, receipt_line_id?: ?int, count_line_id?: ?int, distribution_line_id?: ?int,
     *   offset_count_line_id?: int, offset_after_txn?: int, offset_after_time?: string}> $moves
     *   qty is whole units or a decimal string ('-2.50'); offset_count_line_id names the count that
     *   already includes the move; offset_after_txn / offset_after_time find the latest count of the
     *   product at the site made after that ledger row or UTC Clock::db() time (a count posted in the
     *   same second counts as after it), if any; offset_count_line_id must be a posted count line of
     *   the product at the site
     * @param bool $allowNegative the food has already left (offline replay, late entry): record it even below zero
     * @return array<int, array{old: string, new: string, went_negative: bool, offsets: list<int>}> per product
     * @throws InsufficientStockException before anything is written
     */
    public static function post(int $siteId, string $type, array $moves, int $recordedBy, bool $allowNegative = false): array
    {
        if ($type === 'Transfer' || ($type !== 'Reversal' && !isset(self::TYPE_REF[$type]))) {
            throw new InvalidArgumentException("Unknown or unsupported ledger type '$type'.");
        }
        $rows = [];
        foreach ($moves as $i => $move) {
            $qty = self::toHundredths((string) $move['qty']);
            $refs = array_filter(array_intersect_key($move, array_flip(self::REFS)), fn($v) => $v !== null);
            if (count($refs) !== 1 || ($type !== 'Reversal' && !isset($refs[self::TYPE_REF[$type]]))) {
                throw new InvalidArgumentException("Move $i: a $type needs exactly one reference" . ($type === 'Reversal' ? '' : ' (' . self::TYPE_REF[$type] . ')') . '.');
            }
            if (($type === 'Receipt' && $qty <= 0) || ($type === 'Distribution' && $qty >= 0) || ($type === 'Reversal' && $qty === 0)) {
                throw new InvalidArgumentException("Move $i: wrong sign for a $type.");
            }
            $rows[] = ['product_id' => (int) $move['product_id'], 'qty' => $qty, 'type' => $type, 'ref' => $refs] + ['move' => $move];
        }
        $onHand = self::lock($siteId, array_column($rows, 'product_id'));

        // Count offsets, found under the stock lock (a count takes the same lock before it writes).
        $all = [];
        foreach ($rows as $row) {
            $all[] = $row;
            $countLine = self::absorbingCount($siteId, $row['product_id'], $row['move']);
            if ($countLine !== null) {
                $all[] = ['product_id' => $row['product_id'], 'qty' => -$row['qty'], 'type' => 'Count Adjustment', 'ref' => ['count_line_id' => $countLine], 'offset' => true];
            }
        }

        $net = [];
        foreach ($all as $row) {
            $net[$row['product_id']] = ($net[$row['product_id']] ?? 0) + $row['qty'];
        }
        $shortages = [];
        foreach ($net as $productId => $delta) {
            $old = $onHand[$productId];
            $new = $old + $delta;
            if (abs($new) > self::MAX_HUNDREDTHS || abs($delta) > self::MAX_HUNDREDTHS) {
                throw ValidationException::one('_form', 'That quantity is more than the stock records can hold.');
            }
            if (!$allowNegative && $delta < 0 && $new < 0) {
                $shortages[] = ['product_id' => $productId, 'requested' => Validator::fromUnits(-$delta, 2), 'available' => Validator::fromUnits(max(0, $old), 2)];
            }
        }
        if ($shortages) {
            throw new InsufficientStockException($shortages);
        }

        $update = Db::pdo()->prepare('UPDATE site_stock SET quantity_on_hand = quantity_on_hand + ? WHERE site_id = ? AND product_id = ?');
        foreach ($net as $productId => $delta) {
            if ($delta !== 0) {
                $update->execute([Validator::fromUnits($delta, 2), $siteId, $productId]);
            }
        }
        $insert = Db::pdo()->prepare(
            'INSERT INTO inventory_transaction (site_id, product_id, qty_change, txn_type, distribution_line_id, receipt_line_id, count_line_id, recorded_by, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = Clock::db();
        $result = [];
        foreach ($all as $row) {
            $insert->execute([$siteId, $row['product_id'], Validator::fromUnits($row['qty'], 2), $row['type'], $row['ref']['distribution_line_id'] ?? null,
                $row['ref']['receipt_line_id'] ?? null, $row['ref']['count_line_id'] ?? null, $recordedBy, $now]);
            $pid = $row['product_id'];
            $result[$pid] ??= ['old' => Validator::fromUnits($onHand[$pid], 2), 'new' => Validator::fromUnits($onHand[$pid] + $net[$pid], 2),
                'went_negative' => $onHand[$pid] + $net[$pid] < 0 && $onHand[$pid] >= 0, 'offsets' => []];
            if (!empty($row['offset'])) {
                $result[$pid]['offsets'][] = (int) $row['ref']['count_line_id'];
            }
        }
        return $result;
    }

    /**
     * Where the stock records disagree (the P2A exit check, UC-14 §4.5): stock rows whose quantity
     * is not the sum of their ledger rows, ledger rows with no stock row, and site × product pairs
     * with no stock row (these count as 0 and are healed on first use, so they are listed apart).
     * @return array{mismatches: list<array>, orphans: list<array>, missing: int}
     */
    public static function discrepancies(?int $siteId = null): array
    {
        $site = $siteId !== null ? ' AND s.site_id = ?' : '';
        $args = $siteId !== null ? [$siteId] : [];
        $st = Db::pdo()->prepare(
            'SELECT s.site_id, s.product_id, s.quantity_on_hand, COALESCE(SUM(t.qty_change), 0) AS ledger_total
               FROM site_stock s
               LEFT JOIN inventory_transaction t ON t.site_id = s.site_id AND t.product_id = s.product_id
              WHERE 1 = 1' . $site . '
              GROUP BY s.site_id, s.product_id, s.quantity_on_hand
             HAVING s.quantity_on_hand <> COALESCE(SUM(t.qty_change), 0)
              ORDER BY s.site_id, s.product_id'
        );
        $st->execute($args);
        $mismatches = $st->fetchAll();
        $st = Db::pdo()->prepare(
            'SELECT t.site_id, t.product_id, SUM(t.qty_change) AS ledger_total FROM inventory_transaction t
               LEFT JOIN site_stock s ON s.site_id = t.site_id AND s.product_id = t.product_id
              WHERE s.site_id IS NULL' . ($siteId !== null ? ' AND t.site_id = ?' : '') . '
              GROUP BY t.site_id, t.product_id ORDER BY t.site_id, t.product_id'
        );
        $st->execute($args);
        $orphans = $st->fetchAll();
        $st = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM site s CROSS JOIN product p
               LEFT JOIN site_stock ss ON ss.site_id = s.site_id AND ss.product_id = p.product_id
              WHERE ss.site_id IS NULL' . $site
        );
        $st->execute($args);
        return ['mismatches' => $mismatches, 'orphans' => $orphans, 'missing' => (int) $st->fetchColumn()];
    }

    /** '-2.50' → -250, '40' → 4000, 3 → 300. Refuses anything else (never a float). */
    public static function toHundredths(string $value): int
    {
        if (!preg_match('/^\s*(-?)(\d{1,9})(?:\.(\d{1,2}))?\s*$/', $value, $m)) {
            throw new InvalidArgumentException("Not a stock quantity: '$value'.");
        }
        $units = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' ? -$units : $units;
    }

    /**
     * The count line of the latest count of this product at the site that already includes this
     * movement, if the move asks for an offset. Locking reads: they see counts committed while this
     * transaction waited for the stock lock.
     */
    private static function absorbingCount(int $siteId, int $productId, array $move): ?int
    {
        if (isset($move['offset_count_line_id'])) {
            $st = Db::pdo()->prepare('SELECT cl.count_line_id FROM inventory_count_line cl JOIN inventory_count c ON c.count_id = cl.count_id
                                       WHERE cl.count_line_id = ? AND cl.product_id = ? AND c.site_id = ? AND c.posted_at IS NOT NULL FOR UPDATE');
            $st->execute([(int) $move['offset_count_line_id'], $productId, $siteId]);
            if ($st->fetchColumn() === false) {
                throw new InvalidArgumentException('offset_count_line_id must be a posted count line of this product at this site.');
            }
            return (int) $move['offset_count_line_id'];
        }
        if (!isset($move['offset_after_txn']) && !isset($move['offset_after_time'])) {
            return null;
        }
        if (isset($move['offset_after_time']) && (!is_string($move['offset_after_time'])
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $move['offset_after_time']))) {
            // Compared as text with posted_at, so it must be a UTC Clock::db() value (a count in the same second is taken as later).
            throw new InvalidArgumentException("offset_after_time must be a UTC 'Y-m-d H:i:s' time (Clock::db).");
        }
        $st = Db::pdo()->prepare(
            'SELECT cl.count_line_id, c.posted_at FROM inventory_count_line cl JOIN inventory_count c ON c.count_id = cl.count_id
              WHERE c.site_id = ? AND cl.product_id = ? AND c.posted_at IS NOT NULL
              ORDER BY cl.count_line_id DESC LIMIT 1 FOR UPDATE'
        );
        $st->execute([$siteId, $productId]);
        $latest = $st->fetch();
        if (!$latest) {
            return null;
        }
        if (isset($move['offset_after_time'])) {
            return $latest['posted_at'] >= $move['offset_after_time'] ? (int) $latest['count_line_id'] : null;
        }
        // The count's own adjustment is the first ledger row of its line (every counted line has one).
        $st = Db::pdo()->prepare('SELECT txn_id FROM inventory_transaction WHERE count_line_id = ? ORDER BY txn_id LIMIT 1 FOR UPDATE');
        $st->execute([$latest['count_line_id']]);
        $first = $st->fetchColumn();
        return $first !== false && (int) $first > (int) $move['offset_after_txn'] ? (int) $latest['count_line_id'] : null;
    }
}
