<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use PDOException;
use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Reference\SiteRepository;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;
use RuntimeException;

/**
 * Goods received (plan P2A "inventory_receipts": 'Receipt' ledger rows, void by 'Reversal'; legacy
 * viewAddPallet / viewManagePallets / viewModifyPallet). A receipt posts when it is saved: its lines
 * and their ledger rows are one transaction. Afterwards the name, date and notes can be corrected;
 * lines never change. A mistake is voided (a Reversal for each line, with a reason) and, if needed,
 * entered again.
 *
 * - Quantities are whole units (bags, cans); a line may be entered as cases of the category's case
 *   size plus loose units. At most 99,999 units a line.
 * - A count posted on or after the delivery date may already include the goods. For each such
 *   product the person says whether they arrived after the count (added) or were on the shelves
 *   then (left out of stock and noted on the receipt), so nothing is counted twice. The date cannot
 *   later be moved back before a count that was never asked about.
 * - What is posted is what was reviewed (reviewKey): a count posted, or a case size changed, in
 *   between sends the person back to the review.
 * - Voiding after a later count leaves the stock at what was counted (the ledger's count offset).
 *   A void that would take stock below zero is refused: the goods have been given out.
 * - Receipt names are unique across all sites; left blank (also when corrected), the receipt is
 *   named RCPT-<number> (that prefix is kept for automatic names).
 * - Stock writes run under the site's stock lock, so they never interleave with a count.
 */
final class ReceiptService
{
    public const MAX_UNITS = 99999;
    public const NOTES_MAX = 2000;
    public const BLANK_ROWS = 10;
    public const HEADER_FIELDS = ['name', 'received_on', 'notes'];

    /**
     * Validate a new receipt, or lines to add to one, and describe it: its lines, the total, and the
     * lines a count may already include (keyed by row). Every problem is reported at once.
     * @param array{name?: ?string, received_on?: ?string, notes?: ?string} $header ignored when adding to $receipt
     * @param array<int|string, array{product_id?: ?string, cases?: ?string, units?: ?string, expiration?: ?string}> $rows by row number
     * @return array{header: array{name: ?string, received_on: string, notes: ?string}, lines: array<int, array>, counted: array<int, array>, total: int}
     * @throws ValidationException
     */
    public static function prepare(int $siteId, array $header, array $rows, ?array $receipt = null): array
    {
        $errors = [];
        $header = $receipt !== null
            ? ['name' => $receipt['name'], 'received_on' => $receipt['received_on'], 'notes' => $receipt['notes']]
            : self::validateHeader($siteId, $header, null, $errors);
        $lines = [];
        $seen = [];
        foreach ($rows as $n => $row) {
            $n = (int) $n;
            $productRaw = trim((string) ($row['product_id'] ?? ''));
            $casesRaw = trim((string) ($row['cases'] ?? ''));
            $unitsRaw = trim((string) ($row['units'] ?? ''));
            $expiryRaw = trim((string) ($row['expiration'] ?? ''));
            if ($productRaw === '' && $casesRaw === '' && $unitsRaw === '' && $expiryRaw === '') {
                continue; // an unused row
            }
            $product = ($id = Validator::wholeNumber($productRaw, 1, 999999999)) !== null ? ProductRepository::find($id) : null;
            if ($product === null || !(int) $product['is_active']) {
                $errors["product[$n]"] = 'Choose the product (only active products can be received).';
                continue;
            }
            $rowErrors = [];
            $cases = $casesRaw === '' ? 0 : Validator::wholeNumber($casesRaw, 0, self::MAX_UNITS);
            $units = $unitsRaw === '' ? 0 : Validator::wholeNumber($unitsRaw, 0, self::MAX_UNITS);
            $perCase = (int) $product['units_per_case'];
            if ($cases === null) {
                $rowErrors["cases[$n]"] = 'Enter the number of cases as a whole number, or leave it blank.';
            } elseif ($cases > 0 && $perCase <= 1) {
                $rowErrors["cases[$n]"] = 'This product does not come in cases: enter the number of units.';
            }
            if ($units === null) {
                $rowErrors["units[$n]"] = 'Enter the number of units as a whole number, or leave it blank.';
            }
            $quantity = (int) $cases * $perCase + (int) $units;
            if ($cases !== null && $units !== null && ($quantity < 1 || $quantity > self::MAX_UNITS)) {
                $rowErrors["units[$n]"] = 'Enter how many were received: at least 1 and at most ' . number_format(self::MAX_UNITS) . ' units on one row.';
            }
            $expiry = $expiryRaw === '' ? null : Validator::date($expiryRaw);
            if ($expiryRaw !== '' && $expiry === null) {
                $rowErrors["expiration[$n]"] = 'Enter the best-before date, for example 2027-03-31, or leave it blank.';
            }
            if ($rowErrors) {
                $errors += $rowErrors;
                continue; // compared with other rows once it is correct
            }
            $key = $product['product_id'] . '|' . ($expiry ?? '');
            if (isset($seen[$key])) {
                $errors["product[$n]"] = 'Row ' . ($seen[$key] + 1) . ' already has this product with the same date: put them on one row.';
                continue;
            }
            $seen[$key] = $n;
            $lines[$n] = ['product' => $product, 'product_id' => (int) $product['product_id'], 'cases' => (int) $cases, 'units' => (int) $units,
                'per_case' => $perCase, 'quantity' => $quantity, 'expiration' => $expiry];
        }
        if (!$lines && !$errors) {
            $errors['_form'] = 'Add at least one product.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        $counts = CountRepository::latestCountsSince($siteId, array_values(array_unique(array_column($lines, 'product_id'))), $header['received_on']);
        $counted = [];
        foreach ($lines as $n => $line) {
            if (isset($counts[$line['product_id']])) {
                $counted[$n] = $counts[$line['product_id']];
            }
        }
        return ['header' => $header, 'lines' => $lines, 'counted' => $counted, 'total' => array_sum(array_column($lines, 'quantity'))];
    }

    /**
     * What a review showed: the site, the header, each line's product, quantity and date, and the
     * count each counted question was about. A post whose plan no longer matches (a count posted
     * since, a case size changed, the session now at another site) is refused, so what was
     * reviewed is what is posted and every answer is about the count it names.
     */
    public static function reviewKey(int $siteId, array $plan): string
    {
        $lines = [];
        foreach ($plan['lines'] as $n => $line) {
            $lines[$n] = [$line['product_id'], $line['quantity'], $line['expiration'], (int) ($plan['counted'][$n]['count_line_id'] ?? 0)];
        }
        return sha1(json_encode([$siteId, $plan['header'], $lines], JSON_THROW_ON_ERROR));
    }

    /**
     * Post a new receipt. $decisions answers, for each row a count may already include, 'add' (it
     * arrived after the count) or 'skip' (it was on the shelves when counted: left out of stock).
     * @param array<int|string, string> $decisions
     * @param ?string $reviewed the reviewKey() the review showed (null: posted without a review, as scripts do)
     * @throws ValidationException
     * @throws StaleFormException when the receipt no longer matches the review
     */
    public static function create(int $siteId, array $header, array $rows, array $decisions, int $actorId, ?string $reviewed = null): int
    {
        return Locks::site($siteId, function () use ($siteId, $header, $rows, $decisions, $actorId, $reviewed): int {
            $plan = self::prepare($siteId, $header, $rows); // again under the lock: no count can be posted meanwhile
            self::checkReviewed($siteId, $plan, $reviewed);
            [$add, $skipped] = self::split($plan, $decisions);
            try {
                return Db::transaction(function () use ($siteId, $plan, $add, $skipped, $actorId): int {
                    Ledger::lock($siteId, array_column($add, 'product_id'));
                    $name = $plan['header']['name'];
                    $notes = self::withSkippedNote($plan['header']['notes'], $skipped);
                    $id = ReceiptRepository::insert($siteId, $name ?? ('TMP-' . bin2hex(random_bytes(8))), $plan['header']['received_on'], $actorId, $notes);
                    if ($name === null) {
                        $name = "RCPT-$id";
                        ReceiptRepository::rename($id, $name);
                    }
                    $posted = self::postLines($siteId, $id, $add, $actorId);
                    Audit::record('stock_receipt_create', 'stock_receipt', $id, details: [
                        'name' => $name, 'received_on' => $plan['header']['received_on'], 'lines' => $posted,
                        'left_out_as_counted' => array_values(array_map(fn($l) => ['product_id' => $l['product_id'], 'quantity' => $l['quantity']], $skipped)),
                    ]);
                    return $id;
                });
            } catch (PDOException $e) {
                throw Db::isDuplicateKey($e) ? ValidationException::one('name', self::NAME_TAKEN) : $e;
            }
        });
    }

    /**
     * Add lines to an existing receipt at the site (goods that came with it but were missed).
     * @param array<int|string, string> $decisions as for create()
     * @throws ValidationException
     * @throws StaleFormException when the lines no longer match the review
     */
    public static function addLines(int $receiptId, int $siteId, array $rows, array $decisions, int $actorId, ?string $reviewed = null): void
    {
        Locks::site($siteId, fn() => Db::transaction(function () use ($receiptId, $siteId, $rows, $decisions, $actorId, $reviewed): void {
            $receipt = ReceiptRepository::lock($receiptId);
            if ($receipt === null || (int) $receipt['site_id'] !== $siteId) {
                throw ValidationException::one('_form', 'That receipt no longer exists at this site.');
            }
            $plan = self::prepare($siteId, [], $rows, $receipt);
            self::checkReviewed($siteId, $plan, $reviewed);
            [$add, $skipped] = self::split($plan, $decisions);
            Ledger::lock($siteId, array_column($add, 'product_id'));
            $changes = [];
            if ($skipped) {
                $notes = self::withSkippedNote($receipt['notes'], $skipped);
                ReceiptRepository::updateHeader($receiptId, $receipt['name'], $receipt['received_on'], $notes);
                $changes = Audit::diff($receipt, ['notes' => $notes], ['notes']);
            }
            $posted = self::postLines($siteId, $receiptId, $add, $actorId);
            Audit::record('stock_receipt_add_lines', 'stock_receipt', $receiptId, changes: $changes, details: ['lines' => $posted,
                'left_out_as_counted' => array_values(array_map(fn($l) => ['product_id' => $l['product_id'], 'quantity' => $l['quantity']], $skipped))]);
        }));
    }

    /**
     * Correct the name, delivery date or notes. The lines and stock do not change.
     * @throws ValidationException
     * @throws StaleFormException
     */
    public static function updateHeader(int $receiptId, int $siteId, array $input, ?string $revision, int $actorId): void
    {
        $current = ReceiptRepository::find($receiptId);
        if ($current === null || (int) $current['site_id'] !== $siteId) {
            throw ValidationException::one('_form', 'That receipt no longer exists at this site.');
        }
        $errors = [];
        $values = self::validateHeader($siteId, $input, $current, $errors);
        if (!isset($errors['received_on']) && $values['received_on'] < $current['received_on']) {
            // A count dated before the old delivery date was never asked about: it may already have these goods.
            $productIds = array_values(array_unique(array_map(fn($l) => (int) $l['product_id'],
                array_filter(ReceiptRepository::lines($receiptId), fn($l) => !(int) $l['voided']))));
            $count = CountRepository::firstCountBetween($siteId, $productIds, $values['received_on'], $current['received_on']);
            if ($count !== null) {
                $errors['received_on'] = 'A stock count on ' . $count['count_date'] . ' may already include these goods, so moving the date back before it '
                    . 'could count them twice. Void the lines and enter them again with the right date.';
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        try {
            Db::transaction(function () use ($receiptId, $siteId, $values, $revision): void {
                $before = ReceiptRepository::lock($receiptId);
                if ($before === null || (int) $before['site_id'] !== $siteId) {
                    throw ValidationException::one('_form', 'That receipt no longer exists at this site.');
                }
                if ($revision !== null && !hash_equals(self::revision($before), $revision)) {
                    throw new StaleFormException('Someone else changed this receipt since you opened it. The latest details are shown: make your change again.');
                }
                $name = $values['name'] ?? "RCPT-$receiptId"; // a blank name gives the automatic one back
                $changes = Audit::diff($before, ['name' => $name] + $values, self::HEADER_FIELDS);
                if (!$changes) {
                    return;
                }
                ReceiptRepository::updateHeader($receiptId, $name, $values['received_on'], $values['notes']);
                Audit::record('stock_receipt_update', 'stock_receipt', $receiptId, changes: $changes);
            });
        } catch (PDOException $e) {
            throw Db::isDuplicateKey($e) ? ValidationException::one('name', self::NAME_TAKEN) : $e;
        }
    }

    /**
     * Void lines of a receipt (all when $lineIds is null), with a reason: a Reversal for each. Stock
     * goes back down by what the lines added, unless a later count already corrected it.
     * @param ?list<int> $lineIds
     * @throws ValidationException
     */
    public static function void(int $receiptId, int $siteId, ?array $lineIds, ?string $reason, int $actorId): void
    {
        $reason = Validator::text($reason, 255) ?? throw ValidationException::one('reason', 'Say why (up to 255 characters). It is kept in the audit log.');
        Locks::site($siteId, fn() => Db::transaction(function () use ($receiptId, $siteId, $lineIds, $reason, $actorId): void {
            $receipt = ReceiptRepository::lock($receiptId);
            if ($receipt === null || (int) $receipt['site_id'] !== $siteId) {
                throw ValidationException::one('_form', 'That receipt no longer exists at this site.');
            }
            $lines = array_filter(ReceiptRepository::lines($receiptId), fn($l) => !(int) $l['voided'] && ($lineIds === null || in_array((int) $l['receipt_line_id'], $lineIds, true)));
            if (!$lines || ($lineIds !== null && count($lines) !== count(array_unique($lineIds)))) {
                throw ValidationException::one('_form', 'That line is already voided, or is not on this receipt.');
            }
            Ledger::lock($siteId, array_map(fn($l) => (int) $l['product_id'], $lines));
            $moves = array_values(array_map(fn($l) => ['product_id' => (int) $l['product_id'], 'qty' => -(int) $l['quantity'],
                'receipt_line_id' => (int) $l['receipt_line_id'], 'offset_after_txn' => (int) $l['receipt_txn_id']], $lines));
            try {
                $result = Ledger::post($siteId, 'Reversal', $moves, $actorId);
            } catch (InsufficientStockException $e) {
                $labels = [];
                foreach ($e->shortages as $s) {
                    $p = ProductRepository::find($s['product_id']);
                    $labels[] = ($p ? ProductRepository::label($p) : 'a product') . ': only ' . ProductService::quantity($s['available']) . ' left';
                }
                throw ValidationException::one('_form', 'This cannot be voided because some of it has already been given out (' . implode('; ', $labels)
                    . '). If the stock figures are wrong, post a stock count instead.');
            }
            Audit::record('stock_receipt_void', 'stock_receipt', $receiptId, reason: $reason, details: [
                'lines' => array_values(array_map(fn($l) => ['receipt_line_id' => (int) $l['receipt_line_id'], 'product_id' => (int) $l['product_id'],
                    'quantity' => (int) $l['quantity']], $lines)),
                'kept_as_counted' => array_values(array_filter(array_map(fn($pid, $r) => $r['offsets'] ? $pid : null, array_keys($result), $result))),
            ]);
        }));
    }

    /** The header as a form shows it; a save from a form showing an older state is refused. */
    public static function revision(array $receipt): string
    {
        return sha1(json_encode([(string) $receipt['name'], (string) $receipt['received_on'], $receipt['notes']], JSON_THROW_ON_ERROR));
    }

    /** Posted, Partly voided or Voided, from the list row's line counts. */
    public static function status(array $row): string
    {
        $voided = (int) $row['voided_lines'];
        return match (true) {
            $voided === 0 => 'Posted',
            $voided >= (int) $row['line_count'] => 'Voided',
            default => 'Partly voided',
        };
    }

    /** @throws StaleFormException */
    private static function checkReviewed(int $siteId, array $plan, ?string $reviewed): void
    {
        if ($reviewed !== null && !hash_equals(self::reviewKey($siteId, $plan), $reviewed)) {
            throw new StaleFormException('Something changed since you checked these lines (a stock count was posted, or a case size changed). '
                . 'Check them again before posting.');
        }
    }

    private const NAME_TAKEN ='Another receipt already uses this name (receipt names are shared by every site). Add the site or the date to it, or leave it blank.';

    /**
     * @param ?array $existing the receipt being corrected: its own name (even an automatic one) may stay
     * @param array<string, string> $errors
     * @return array{name: ?string, received_on: string, notes: ?string}
     */
    private static function validateHeader(int $siteId, array $input, ?array $existing, array &$errors): array
    {
        $receiptId = $existing !== null ? (int) $existing['receipt_id'] : null;
        $nameRaw = trim((string) ($input['name'] ?? ''));
        $name = $nameRaw === '' ? null : Validator::text($nameRaw, 50);
        if ($name !== null && $existing !== null && $name === $existing['name']) {
            // unchanged: fine even if it is an automatic RCPT- name
        } elseif ($nameRaw !== '' && $name === null) {
            $errors['name'] = 'The name can be at most 50 characters.';
        } elseif ($name !== null && ReceiptRepository::reservedName($name)) {
            $errors['name'] = 'Names starting with RCPT- are given automatically. Choose another name, or leave it blank.';
        } elseif ($name !== null && ReceiptRepository::nameTaken($name, $receiptId)) {
            $errors['name'] = self::NAME_TAKEN;
        }
        $site = SiteRepository::find($siteId);
        $today = Clock::localDate($site['time_zone'] ?? 'America/New_York');
        $date = Validator::date(trim((string) ($input['received_on'] ?? '')));
        if ($date === null || $date > $today) {
            $errors['received_on'] = 'Enter the day the goods arrived (today or earlier), for example ' . $today . '.';
        }
        $notes = str_replace(["\r\n", "\r"], "\n", trim((string) ($input['notes'] ?? '')));
        if (!mb_check_encoding($notes, 'UTF-8') || mb_strlen($notes) > self::NOTES_MAX) {
            $errors['notes'] = 'Notes can be at most ' . number_format(self::NOTES_MAX) . ' characters.';
        }
        return ['name' => $name, 'received_on' => (string) $date, 'notes' => $notes === '' ? null : $notes];
    }

    /**
     * Split the lines into those to add and those left out because a count already has them.
     * @param array<int|string, string> $decisions
     * @return array{0: array<int, array>, 1: array<int, array>}
     * @throws ValidationException when a line a count may include has no answer, or nothing is left to add
     */
    private static function split(array $plan, array $decisions): array
    {
        $add = [];
        $skipped = [];
        $errors = [];
        foreach ($plan['lines'] as $n => $line) {
            if (isset($plan['counted'][$n])) {
                $answer = $decisions[$n] ?? $decisions[(string) $n] ?? null;
                if ($answer === 'skip') {
                    $skipped[$n] = $line;
                    continue;
                }
                if ($answer !== 'add') {
                    $errors["decision[$n]"] = 'Say whether these were on the shelves when the stock was counted.';
                    continue;
                }
            }
            $add[$n] = $line;
        }
        if ($errors) {
            throw new ValidationException($errors + ['_form' => 'A stock count may already include some of these goods: answer the question on each marked row.']);
        }
        if (!$add) {
            throw ValidationException::one('_form', 'Every line is already in a stock count, so there is nothing to add.');
        }
        return [$add, $skipped];
    }

    /** @param array<int, array> $lines @return list<array{receipt_line_id: int, product_id: int, quantity: int}> */
    private static function postLines(int $siteId, int $receiptId, array $lines, int $actorId): array
    {
        $posted = [];
        foreach ($lines as $line) {
            $lineId = ReceiptRepository::insertLine($receiptId, $line['product_id'], $line['quantity'], $line['expiration']);
            $posted[] = ['receipt_line_id' => $lineId, 'product_id' => $line['product_id'], 'quantity' => $line['quantity']];
        }
        Ledger::post($siteId, 'Receipt', array_map(fn($p) => ['product_id' => $p['product_id'], 'qty' => $p['quantity'], 'receipt_line_id' => $p['receipt_line_id']], $posted), $actorId);
        return $posted;
    }

    /** @param array<int, array> $skipped */
    private static function withSkippedNote(?string $notes, array $skipped): ?string
    {
        if (!$skipped) {
            return $notes;
        }
        $note = 'Not added to stock, already in a stock count: ' . implode('; ', array_map(
            fn($l) => ProductRepository::label($l['product']) . ' × ' . $l['quantity'], $skipped)) . '.';
        $combined = trim(($notes ?? '') . "\n" . $note);
        return mb_strlen($combined) > self::NOTES_MAX ? mb_substr($combined, 0, self::NOTES_MAX) : $combined;
    }
}
