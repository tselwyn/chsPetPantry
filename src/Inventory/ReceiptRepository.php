<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use Pfpms\Db;

/** SQL for stock_receipt and stock_receipt_line ("goods received"). Prepared statements only. */
final class ReceiptRepository
{
    private const VOIDED = "EXISTS (SELECT 1 FROM inventory_transaction v WHERE v.receipt_line_id = l.receipt_line_id AND v.txn_type = 'Reversal')";

    /**
     * Receipts at a site, newest first, with their lines, voided lines, and the units and earliest
     * best-before date of the lines that were not voided.
     * @param array{from?: ?string, to?: ?string, q?: ?string} $filters
     * @return list<array>
     */
    public static function list(int $siteId, array $filters = []): array
    {
        $where = ['r.site_id = ?'];
        $args = [$siteId];
        if (!empty($filters['from'])) {
            $where[] = 'r.received_on >= ?';
            $args[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'r.received_on <= ?';
            $args[] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where[] = 'r.name LIKE ?';
            $args[] = '%' . addcslashes($filters['q'], '%_\\') . '%';
        }
        // Voided lines come from a join, not a correlated subquery: MariaDB's ONLY_FULL_GROUP_BY refuses those inside aggregates.
        $st = Db::pdo()->prepare(
            "SELECT r.receipt_id, r.name, r.received_on, CONCAT(u.first_name, ' ', u.last_name) AS received_by_name,
                    COUNT(l.receipt_line_id) AS line_count,
                    COALESCE(SUM(CASE WHEN v.receipt_line_id IS NULL THEN l.quantity ELSE 0 END), 0) AS units,
                    COALESCE(SUM(CASE WHEN v.receipt_line_id IS NULL THEN 0 ELSE 1 END), 0) AS voided_lines,
                    MIN(CASE WHEN v.receipt_line_id IS NULL THEN l.expiration END) AS earliest_expiry
               FROM stock_receipt r
               JOIN user_account u ON u.user_id = r.received_by
               LEFT JOIN stock_receipt_line l ON l.receipt_id = r.receipt_id
               LEFT JOIN (SELECT DISTINCT receipt_line_id FROM inventory_transaction WHERE txn_type = 'Reversal' AND receipt_line_id IS NOT NULL) v
                      ON v.receipt_line_id = l.receipt_line_id
              WHERE " . implode(' AND ', $where) . "
              GROUP BY r.receipt_id, r.name, r.received_on, u.first_name, u.last_name
              ORDER BY r.received_on DESC, r.receipt_id DESC LIMIT 200"
        );
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function find(int $receiptId): ?array
    {
        $st = Db::pdo()->prepare(
            "SELECT r.receipt_id, r.site_id, s.name AS site_name, r.name, r.received_on, r.received_by, CONCAT(u.first_name, ' ', u.last_name) AS received_by_name, r.notes
               FROM stock_receipt r JOIN site s ON s.site_id = r.site_id JOIN user_account u ON u.user_id = r.received_by
              WHERE r.receipt_id = ?"
        );
        $st->execute([$receiptId]);
        return $st->fetch() ?: null;
    }

    /** Lock the receipt for the rest of the transaction (first statement of a void or an added line) and return it. */
    public static function lock(int $receiptId): ?array
    {
        $st = Db::pdo()->prepare('SELECT receipt_id FROM stock_receipt WHERE receipt_id = ? FOR UPDATE');
        $st->execute([$receiptId]);
        return $st->fetchColumn() === false ? null : self::find($receiptId);
    }

    /**
     * The receipt's lines with the product, whether each is voided, the id of its Receipt ledger
     * row, and the site's stock of the product now.
     * @return list<array>
     */
    public static function lines(int $receiptId): array
    {
        $st = Db::pdo()->prepare(
            "SELECT l.receipt_line_id, l.product_id, l.quantity, l.expiration, " . self::VOIDED . " AS voided,
                    (SELECT MIN(t.txn_id) FROM inventory_transaction t WHERE t.receipt_line_id = l.receipt_line_id AND t.txn_type = 'Receipt') AS receipt_txn_id,
                    ss.quantity_on_hand, " . ProductRepository::COLUMNS . "
               FROM stock_receipt_line l
               JOIN stock_receipt r ON r.receipt_id = l.receipt_id
               JOIN product p ON p.product_id = l.product_id
               JOIN item_category c ON c.category_id = p.category_id
               JOIN species sp ON sp.species_id = p.species_id
               LEFT JOIN site_stock ss ON ss.site_id = r.site_id AND ss.product_id = l.product_id
              WHERE l.receipt_id = ? ORDER BY l.receipt_line_id"
        );
        $st->execute([$receiptId]);
        return $st->fetchAll();
    }

    /** Receipt names are unique across all sites (uk_stock_receipt_name), ignoring case, accents and trailing spaces. */
    public static function nameTaken(string $name, ?int $exceptReceiptId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM stock_receipt WHERE name = ? AND receipt_id <> ?');
        $st->execute([$name, $exceptReceiptId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    /** RCPT-... is kept for automatic names; compared with the column collation, so case and look-alike letters count too. */
    public static function reservedName(string $name): bool
    {
        $st = Db::pdo()->prepare("SELECT CAST(? AS CHAR(50)) COLLATE utf8mb4_unicode_520_ci LIKE 'RCPT-%'");
        $st->execute([$name]);
        return (bool) $st->fetchColumn();
    }

    public static function insert(int $siteId, string $name, string $receivedOn, int $receivedBy, ?string $notes): int
    {
        Db::pdo()->prepare('INSERT INTO stock_receipt (site_id, name, received_on, received_by, notes) VALUES (?, ?, ?, ?, ?)')
            ->execute([$siteId, $name, $receivedOn, $receivedBy, $notes]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function rename(int $receiptId, string $name): void
    {
        Db::pdo()->prepare('UPDATE stock_receipt SET name = ? WHERE receipt_id = ?')->execute([$name, $receiptId]);
    }

    public static function updateHeader(int $receiptId, string $name, string $receivedOn, ?string $notes): void
    {
        Db::pdo()->prepare('UPDATE stock_receipt SET name = ?, received_on = ?, notes = ? WHERE receipt_id = ?')->execute([$name, $receivedOn, $notes, $receiptId]);
    }

    public static function insertLine(int $receiptId, int $productId, int $quantity, ?string $expiration): int
    {
        Db::pdo()->prepare('INSERT INTO stock_receipt_line (receipt_id, product_id, quantity, expiration) VALUES (?, ?, ?, ?)')
            ->execute([$receiptId, $productId, $quantity, $expiration]);
        return (int) Db::pdo()->lastInsertId(); // read at once: each line's id, never by offset
    }
}
