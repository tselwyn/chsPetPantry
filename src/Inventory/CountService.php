<?php
declare(strict_types=1);

namespace Pfpms\Inventory;

use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Reference\SiteRepository;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * Stock counts (plan P2A "inventory_count": refused while an event is Open; 'Count Adjustment'
 * rows; posted_at; legacy viewUpdateInventory / editInventoryEvent). A count sets each counted
 * product's stock at the site to what is on the shelves, in every storage area.
 *
 * - Always the whole site (one figure per product, whichever area it is kept in); a count can cover
 *   one category at a time, so a long count can be posted in sections.
 * - Blank = not counted: that product's stock stays as it is. 0 = counted, none left.
 * - Reviewed before posting; posted in one step, never kept as a draft, so the moment it is posted
 *   is the moment the count was true (the ledger's count offset relies on it). The count date is
 *   the site's today.
 * - Refused while a distribution event is Open at the site (the stock is moving), under the site's
 *   stock lock; P3's opening of an event takes the same lock.
 * - If a counted product's stock changed after the review (goods received meanwhile), the review is
 *   shown again with the new figures instead of posting.
 * - Voiding a count is not offered in R1: a wrong count is put right by counting again.
 */
final class CountService
{
    public const MAX_UNITS = 99999;
    /** A change is flagged for a second look when it is more than half the stock and more than 10 units (hundredths). */
    private const LARGE_CHANGE = 1000;
    private const QUANTITY = '/^-?\d{1,9}(\.\d{1,2})?$/';

    /**
     * Check the figures and describe the count: each counted product's stock now, the count and the
     * change (big changes flagged, and 'moved' when the stock changed after the sheet was opened, so
     * goods received or given out meanwhile may be missing from the count), and the products left
     * blank that have stock.
     * @param array<int|string, string> $cases counted cases by product id
     * @param array<int|string, string> $units counted loose units by product id
     * @param array<int|string, string> $seen the stock of each product when the sheet was opened
     * @return array{lines: list<array>, not_counted: list<array>, pending_items: int}
     * @throws ValidationException
     */
    public static function review(int $siteId, ?int $categoryId, array $cases, array $units, array $seen = []): array
    {
        $errors = [];
        $lines = [];
        $notCounted = [];
        foreach (CountRepository::sheet($siteId, $categoryId) as $p) {
            $pid = (int) $p['product_id'];
            $casesRaw = trim((string) ($cases[$pid] ?? ''));
            $unitsRaw = trim((string) ($units[$pid] ?? ''));
            $book = Ledger::toHundredths((string) $p['quantity_on_hand']);
            if ($casesRaw === '' && $unitsRaw === '') {
                if ($book !== 0) {
                    $notCounted[] = ['product' => $p, 'book' => (string) $p['quantity_on_hand']];
                }
                continue;
            }
            $perCase = (int) $p['units_per_case'];
            $c = $casesRaw === '' ? 0 : Validator::wholeNumber($casesRaw, 0, self::MAX_UNITS);
            $u = $unitsRaw === '' ? 0 : Validator::wholeNumber($unitsRaw, 0, self::MAX_UNITS);
            if ($c === null || ($c > 0 && $perCase <= 1)) {
                $errors["cases[$pid]"] = $c === null ? 'Enter whole cases, or leave it blank.' : 'This product is not counted in cases: enter units.';
                continue;
            }
            if ($u === null) {
                $errors["units[$pid]"] = 'Enter the number of units as a whole number (0 if there are none), or leave it blank if not counted.';
                continue;
            }
            $counted = $c * $perCase + $u;
            if ($counted > self::MAX_UNITS) {
                $errors["units[$pid]"] = 'At most ' . number_format(self::MAX_UNITS) . ' units can be counted for one product.';
                continue;
            }
            $change = $counted * 100 - $book;
            $seenRaw = $seen[$pid] ?? null;
            $moved = $seenRaw !== null && preg_match(self::QUANTITY, (string) $seenRaw) === 1 && Ledger::toHundredths((string) $seenRaw) !== $book;
            $lines[] = ['product' => $p, 'product_id' => $pid, 'book' => (string) $p['quantity_on_hand'], 'cases' => $c, 'units' => $u,
                'counted' => $counted, 'change' => Validator::fromUnits($change, 2), 'large' => abs($change) > max(intdiv(abs($book), 2), self::LARGE_CHANGE),
                'moved' => $moved, 'seen' => $moved ? (string) $seenRaw : null];
        }
        if (!$lines && !$errors) {
            $errors['_form'] = 'Enter the count for at least one product. Leave a product blank only if you did not count it.';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['lines' => $lines, 'not_counted' => $notCounted, 'pending_items' => CountRepository::pendingDeviceItems($siteId)];
    }

    /**
     * What a review showed: the site, the category and each counted product with its counted units
     * (so a case size changed, or a product gone from the sheet, since the review is noticed).
     */
    public static function reviewKey(int $siteId, ?int $categoryId, array $review): string
    {
        return sha1(json_encode([$siteId, $categoryId, array_map(fn($l) => [$l['product_id'], $l['counted']], $review['lines'])], JSON_THROW_ON_ERROR));
    }

    /**
     * Post a reviewed count. $book is the stock of each product as the review showed it.
     * @param array<int|string, string> $cases
     * @param array<int|string, string> $units
     * @param array<int|string, string> $book
     * @param ?string $reviewed the reviewKey() the review showed (null: no review, as scripts do)
     * @throws ValidationException
     * @throws StaleCountException when a counted product's stock, a case size or the sheet changed since the review
     */
    public static function post(int $siteId, ?int $categoryId, array $cases, array $units, array $book, int $actorId, ?string $reviewed = null): int
    {
        $plan = self::review($siteId, $categoryId, $cases, $units);
        if ($reviewed !== null && !hash_equals(self::reviewKey($siteId, $categoryId, $plan), $reviewed)) {
            throw new StaleCountException([], 'The products or case sizes changed since you checked this count. Check the figures again before posting.');
        }
        return Locks::site($siteId, function () use ($siteId, $categoryId, $plan, $book, $actorId): int {
            if (($event = CountRepository::openEvent($siteId)) !== null) {
                Audit::durable('inventory_count_post', 'site', $siteId, 'Denied', 'A distribution event is open', ['event_id' => (int) $event['event_id']]);
                throw ValidationException::one('_form', 'A distribution event is open at this site, so stock is moving. Post the count after the event closes.');
            }
            return Db::transaction(function () use ($siteId, $categoryId, $plan, $book, $actorId): int {
                $onHand = Ledger::lock($siteId, array_column($plan['lines'], 'product_id'));
                $changed = [];
                foreach ($plan['lines'] as $line) {
                    $shown = $book[$line['product_id']] ?? null;
                    if ($shown === null || !preg_match(self::QUANTITY, $shown) || Ledger::toHundredths($shown) !== $onHand[$line['product_id']]) {
                        $changed[] = $line['product_id'];
                    }
                }
                if ($changed) {
                    throw new StaleCountException($changed);
                }
                $site = SiteRepository::find($siteId);
                $category = $categoryId !== null ? ItemCategoryRepository::find($categoryId) : null;
                $countId = CountRepository::insert($siteId, $category !== null ? mb_substr((string) $category['name'], 0, 50) : 'All areas',
                    Clock::localDate($site['time_zone'] ?? 'America/New_York'), $actorId, Clock::db());
                $moves = [];
                $details = [];
                foreach ($plan['lines'] as $line) {
                    $lineId = CountRepository::insertLine($countId, $line['product_id'], $line['counted']);
                    $delta = $line['counted'] * 100 - $onHand[$line['product_id']];
                    $moves[] = ['product_id' => $line['product_id'], 'qty' => Validator::fromUnits($delta, 2), 'count_line_id' => $lineId];
                    $details[] = ['product_id' => $line['product_id'], 'book' => Validator::fromUnits($onHand[$line['product_id']], 2),
                        'counted' => $line['counted'], 'change' => Validator::fromUnits($delta, 2)];
                }
                Ledger::post($siteId, 'Count Adjustment', $moves, $actorId);
                Audit::record('inventory_count_post', 'inventory_count', $countId, details: [
                    'category' => $category['name'] ?? null, 'lines' => $details, 'not_counted' => count($plan['not_counted']),
                ]);
                return $countId;
            });
        });
    }
}
