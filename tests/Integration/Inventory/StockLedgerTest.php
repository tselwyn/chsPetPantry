<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Inventory;

use InvalidArgumentException;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Inventory\CountRepository;
use Pfpms\Inventory\CountService;
use Pfpms\Inventory\InsufficientStockException;
use Pfpms\Inventory\ItemCategoryRepository;
use Pfpms\Inventory\ItemCategoryService;
use Pfpms\Inventory\Ledger;
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;
use Pfpms\Inventory\ReceiptRepository;
use Pfpms\Inventory\ReceiptService;
use Pfpms\Inventory\StaleCountException;
use Pfpms\Inventory\StaleFormException;
use Pfpms\Inventory\StockRepository;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * The stock ledger (plan P2A): receipts, voids, counts and the P2A exit check that, for every
 * site × product, the ledger adds up to the stock on hand.
 */
final class StockLedgerTest extends TestCase
{
    private const TODAY = '2026-10-01'; // NOW in America/New_York

    private int $admin;
    private int $site;
    private int $dry;
    private int $cans;
    private int $kibble;
    private int $tins;
    private int $chews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(['role' => 'Administrator'])['user_id'];
        $this->site = $this->makeSite('Northside');
        $dog = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Dog'");
        $this->dry = ItemCategoryService::create(['name' => 'Dry dog food', 'units_per_case' => '1']);
        $this->cans = ItemCategoryService::create(['name' => 'Wet dog food', 'units_per_case' => '24']);
        $make = fn(string $name, int $category, string $form, string $weight) => ProductService::create(['category_id' => (string) $category, 'name' => $name,
            'brand' => 'Acme', 'species_id' => (string) $dog, 'food_form' => $form, 'unit_weight' => $weight, 'weight_unit' => 'lb'], $this->admin);
        $this->kibble = $make('Adult kibble', $this->dry, 'Dry', '30');
        $this->tins = $make('Chunks in gravy', $this->cans, 'Wet', '0.8');
        $this->chews = $make('Chews', $this->dry, 'Treat', '');
    }

    // Helpers -------------------------------------------------------------------------------

    /** @param array<int, array{0: int, 1: int|string, 2?: string, 3?: string}> $lines [product, units, cases, expiration] */
    private function rows(array $lines): array
    {
        return array_map(fn($l) => ['product_id' => (string) $l[0], 'units' => (string) $l[1], 'cases' => $l[2] ?? '', 'expiration' => $l[3] ?? ''], $lines);
    }

    private function receive(array $lines, array $header = [], array $decisions = [], ?int $site = null): int
    {
        return ReceiptService::create($site ?? $this->site, $header + ['name' => '', 'received_on' => self::TODAY, 'notes' => ''],
            $this->rows($lines), $decisions, $this->admin);
    }

    /** Post a count of whole units by product id (as the review showed the stock), returning the count id. */
    private function postCount(array $units, ?int $category = null, ?int $site = null, array $cases = []): int
    {
        $site ??= $this->site;
        $review = CountService::review($site, $category, $cases, $units);
        return CountService::post($site, $category, $cases, $units, array_column($review['lines'], 'book', 'product_id'), $this->admin);
    }

    private function onHand(int $product, ?int $site = null): string
    {
        return StockRepository::quantity($site ?? $this->site, $product);
    }

    private function lineIds(int $receipt): array
    {
        return array_map(fn($l) => (int) $l['receipt_line_id'], ReceiptRepository::lines($receipt));
    }

    private function errorsOf(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors;
        }
        $this->fail('expected a ValidationException');
    }

    private function audits(string $action, string $entity, int $id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_type = ? AND entity_id = ?', [$action, $entity, $id]);
    }

    private function assertReconciles(?int $site = null): void
    {
        $d = Ledger::discrepancies($site);
        $this->assertSame([[], []], [$d['mismatches'], $d['orphans']], 'the ledger adds up to the stock on hand');
    }

    // Ledger ----------------------------------------------------------------------------------

    public function testTheLedgerRefusesMalformedMoves(): void
    {
        $receipt = $this->receive([[$this->kibble, 5]]);
        $line = $this->lineIds($receipt)[0];
        $bad = [
            ['Transfer', ['product_id' => $this->kibble, 'qty' => 1, 'receipt_line_id' => $line]],
            ['Receipt', ['product_id' => $this->kibble, 'qty' => 0, 'receipt_line_id' => $line]],
            ['Receipt', ['product_id' => $this->kibble, 'qty' => -1, 'receipt_line_id' => $line]],
            ['Receipt', ['product_id' => $this->kibble, 'qty' => 1]],
            ['Receipt', ['product_id' => $this->kibble, 'qty' => 1, 'count_line_id' => 1]],
            ['Reversal', ['product_id' => $this->kibble, 'qty' => 0, 'receipt_line_id' => $line]],
            ['Reversal', ['product_id' => $this->kibble, 'qty' => -1, 'receipt_line_id' => $line, 'count_line_id' => 1]],
            ['Distribution', ['product_id' => $this->kibble, 'qty' => 1, 'distribution_line_id' => 1]],
            ['Receipt', ['product_id' => $this->kibble, 'qty' => '1.234', 'receipt_line_id' => $line]],
        ];
        $rows = (int) $this->scalar('SELECT COUNT(*) FROM inventory_transaction');
        foreach ($bad as $i => [$type, $move]) {
            try {
                Ledger::post($this->site, $type, [$move], $this->admin);
                $this->fail("case $i was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($rows, (int) $this->scalar('SELECT COUNT(*) FROM inventory_transaction'));
        $this->assertSame('5.00', $this->onHand($this->kibble));
    }

    public function testOnlyAMoveThatLowersStockBelowZeroIsRefused(): void
    {
        $line = $this->lineIds($this->receive([[$this->kibble, 5], [$this->tins, 2]]));
        $rows = (int) $this->scalar('SELECT COUNT(*) FROM inventory_transaction');
        try {
            Ledger::post($this->site, 'Reversal', [['product_id' => $this->kibble, 'qty' => -8, 'receipt_line_id' => $line[0]],
                ['product_id' => $this->tins, 'qty' => -3, 'receipt_line_id' => $line[1]]], $this->admin);
            $this->fail('expected a shortage');
        } catch (InsufficientStockException $e) {
            $this->assertSame([['product_id' => $this->kibble, 'requested' => '8.00', 'available' => '5.00'],
                ['product_id' => $this->tins, 'requested' => '3.00', 'available' => '2.00']], $e->shortages, 'every shortage at once');
        }
        $this->assertSame($rows, (int) $this->scalar('SELECT COUNT(*) FROM inventory_transaction'), 'nothing written');

        // Food that has already left (offline replay, P4) is recorded even below zero.
        $result = Ledger::post($this->site, 'Reversal', [['product_id' => $this->kibble, 'qty' => '-7.50', 'receipt_line_id' => $line[0]]], $this->admin, allowNegative: true);
        $this->assertSame(['old' => '5.00', 'new' => '-2.50', 'went_negative' => true, 'offsets' => []], $result[$this->kibble]);

        // Raising stock is never refused, even while it is still below zero; lowering it further is.
        $this->receive([[$this->kibble, 2]]);
        $this->assertSame('-0.50', $this->onHand($this->kibble));
        try {
            Ledger::post($this->site, 'Reversal', [['product_id' => $this->kibble, 'qty' => -1, 'receipt_line_id' => $line[0]]], $this->admin);
            $this->fail('expected a shortage');
        } catch (InsufficientStockException $e) {
            $this->assertSame('0.00', $e->shortages[0]['available'], 'nothing is available below zero');
        }
        $this->assertReconciles();
    }

    public function testAMissingStockRowIsCreatedAtZeroOnFirstUse(): void
    {
        Db::pdo()->prepare('DELETE FROM site_stock WHERE site_id = ? AND product_id = ?')->execute([$this->site, $this->chews]);
        $this->assertSame(1, Ledger::discrepancies($this->site)['missing']);
        $this->assertSame([$this->chews => 0], Db::transaction(fn() => Ledger::lock($this->site, [$this->chews])));
        $this->receive([[$this->chews, 3]]);
        $this->assertSame('3.00', $this->onHand($this->chews));
        $this->assertSame(0, Ledger::discrepancies($this->site)['missing']);
        $this->assertReconciles();
    }

    // Receipts --------------------------------------------------------------------------------

    public function testAReceiptPostsItsLinesToStock(): void
    {
        $id = $this->receive([[$this->kibble, 3, '', '2027-03-31'], [$this->tins, 5, '2'], [$this->kibble, 1, '', '2027-06-30']], ['notes' => ' From the food drive ']);
        $receipt = ReceiptRepository::find($id);
        $this->assertSame(["RCPT-$id", self::TODAY, 'From the food drive'], [$receipt['name'], $receipt['received_on'], $receipt['notes']]);
        $this->assertSame(['4.00', '53.00'], [$this->onHand($this->kibble), $this->onHand($this->tins)], '2 cases of 24 + 5');
        $this->assertSame(3, (int) $this->scalar("SELECT COUNT(*) FROM inventory_transaction t JOIN stock_receipt_line l ON l.receipt_line_id = t.receipt_line_id
                                                   WHERE l.receipt_id = ? AND t.txn_type = 'Receipt' AND t.site_id = ?", [$id, $this->site]));
        $this->assertSame(1, $this->audits('stock_receipt_create', 'stock_receipt', $id));
        $list = ReceiptRepository::list($this->site);
        $this->assertSame([3, 57, 'Posted', '2027-03-31'], [(int) $list[0]['line_count'], (int) $list[0]['units'], ReceiptService::status($list[0]), $list[0]['earliest_expiry']]);
        $this->assertSame([], ReceiptRepository::list($this->makeSite('Southside')), 'receipts belong to their site');
        $this->assertReconciles();
    }

    public function testEveryProblemWithAReceiptIsReportedAndNothingIsWritten(): void
    {
        ProductService::setActive($this->chews, false, false, $this->admin);
        $errors = $this->errorsOf(fn() => ReceiptService::create($this->site, ['name' => 'rcpt-12', 'received_on' => '2026-10-02', 'notes' => ''], [
            ['product_id' => (string) $this->kibble, 'cases' => '2', 'units' => ''],
            ['product_id' => (string) $this->tins, 'units' => '0'],
            ['product_id' => (string) $this->tins, 'units' => '1', 'expiration' => 'soon'],
            ['product_id' => (string) $this->chews, 'units' => '1'],
            ['product_id' => (string) $this->tins, 'units' => '100000'],
            ['product_id' => '', 'units' => '', 'cases' => '', 'expiration' => ''],
            ['product_id' => (string) $this->kibble, 'units' => '1.5'],
            ['product_id' => (string) $this->tins, 'cases' => 'x', 'units' => '3', 'expiration' => '2027-01-01'],
            ['product_id' => (string) $this->tins, 'cases' => '1.5', 'expiration' => '2027-01-02'],
            ['product_id' => (string) $this->tins, 'cases' => '100000', 'expiration' => '2027-01-03'],
        ], [], $this->admin));
        $this->assertEqualsCanonicalizing(['name', 'received_on', 'cases[0]', 'units[1]', 'expiration[2]', 'product[3]', 'units[4]', 'units[6]',
            'cases[7]', 'cases[8]', 'cases[9]'], array_keys($errors));

        $errors = $this->errorsOf(fn() => $this->receive([[$this->tins, 1, '', '2027-01-01'], [$this->tins, 2, '', '2027-01-01'], [$this->tins, 3]]));
        $this->assertSame(['product[1]'], array_keys($errors), 'the same product and date twice');
        $this->assertSame(['_form'], array_keys($this->errorsOf(fn() => $this->receive([]))));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM stock_receipt'));
        $this->assertSame('0.00', $this->onHand($this->tins));
    }

    public function testReceiptNamesAreSharedByEverySite(): void
    {
        $south = $this->makeSite('Southside');
        $drive = $this->receive([[$this->kibble, 1]], ['name' => 'Food drive']);
        $this->assertArrayHasKey('name', $this->errorsOf(fn() => $this->receive([[$this->kibble, 1]], ['name' => 'FOOD DRÍVE '], [], $south)));
        $other = $this->receive([[$this->kibble, 1]], [], [], $south);
        $auto = ReceiptRepository::find($other);
        $this->assertArrayHasKey('name', $this->errorsOf(fn() => ReceiptService::updateHeader($other, $south, ['name' => 'food drive', 'received_on' => self::TODAY],
            ReceiptService::revision($auto), $this->admin)));
        $this->assertArrayHasKey('name', $this->errorsOf(fn() => ReceiptService::updateHeader($drive, $this->site, ['name' => "RCPT-$other", 'received_on' => self::TODAY],
            ReceiptService::revision(ReceiptRepository::find($drive)), $this->admin)), 'automatic names are kept for automatic receipts');
        ReceiptService::updateHeader($other, $south, ['name' => $auto['name'], 'received_on' => self::TODAY, 'notes' => 'Checked'], ReceiptService::revision($auto), $this->admin);
        $this->assertSame([$auto['name'], 'Checked'], [ReceiptRepository::find($other)['name'], ReceiptRepository::find($other)['notes']], 'its own automatic name may stay');
    }

    public function testACountMayAlreadyIncludeGoodsReceivedThatDay(): void
    {
        $this->postCount([$this->kibble => '10']);
        $errors = $this->errorsOf(fn() => $this->receive([[$this->kibble, 4], [$this->tins, 6]]));
        $this->assertSame(['decision[0]', '_form'], array_keys($errors), 'only the counted product needs an answer');
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM stock_receipt'));

        $skipped = $this->receive([[$this->kibble, 4], [$this->tins, 6]], [], [0 => 'skip']);
        $this->assertSame(['10.00', '6.00'], [$this->onHand($this->kibble), $this->onHand($this->tins)], 'the count already has the kibble');
        $this->assertCount(1, ReceiptRepository::lines($skipped), 'a line left out is not on the receipt');
        $this->assertStringContainsString('Not added to stock, already in a stock count: Adult kibble', (string) ReceiptRepository::find($skipped)['notes']);

        $this->receive([[$this->kibble, 4]], ['received_on' => '2026-09-30'], [0 => 'add']);
        $this->assertSame('14.00', $this->onHand($this->kibble), 'goods from yesterday that arrived after today\'s count');
        $this->assertSame(['_form'], array_keys($this->errorsOf(fn() => $this->receive([[$this->kibble, 4]], [], [0 => 'skip']))), 'nothing left to add');
        $this->receive([[$this->kibble, 1]], ['received_on' => '2026-09-30'], [0 => 'add']);
        Clock::freeze('2026-10-02 12:00:00');
        $this->receive([[$this->kibble, 1]], ['received_on' => '2026-10-02']); // a later delivery: tomorrow's receipt is after the count, no question
        $this->assertSame('16.00', $this->onHand($this->kibble));
        $this->assertReconciles();
    }

    public function testVoidingReversesEachLineOnce(): void
    {
        $id = $this->receive([[$this->kibble, 5], [$this->tins, 30]]);
        [$first, $second] = $this->lineIds($id);
        $this->assertSame(['reason'], array_keys($this->errorsOf(fn() => ReceiptService::void($id, $this->site, [$first], ' ', $this->admin))));
        ReceiptService::void($id, $this->site, [$first], 'Counted twice', $this->admin);
        $this->assertSame(['0.00', '30.00'], [$this->onHand($this->kibble), $this->onHand($this->tins)]);
        $this->assertSame('Partly voided', ReceiptService::status(ReceiptRepository::list($this->site)[0]));
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => ReceiptService::void($id, $this->site, [$first], 'Again', $this->admin)), 'a line is voided once');
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => ReceiptService::void($id, $this->site, [$first, $second], 'Both', $this->admin)), 'all or nothing');
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => ReceiptService::void($id, $this->makeSite('Southside'), null, 'Wrong site', $this->admin)));
        ReceiptService::void($id, $this->site, null, 'Wrong delivery', $this->admin);
        $this->assertSame('0.00', $this->onHand($this->tins));
        $this->assertSame('Voided', ReceiptService::status(ReceiptRepository::list($this->site)[0]));
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => ReceiptService::void($id, $this->site, null, 'Again', $this->admin)));
        $this->assertSame(2, $this->audits('stock_receipt_void', 'stock_receipt', $id));
        $this->assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM inventory_transaction WHERE txn_type = 'Reversal'"));
        $this->assertReconciles();
    }

    public function testVoidingAfterACountLeavesTheCountedStock(): void
    {
        $id = $this->receive([[$this->kibble, 10]]);
        $this->postCount([$this->kibble => '7']);
        ReceiptService::void($id, $this->site, null, 'Was a duplicate', $this->admin);
        $this->assertSame('7.00', $this->onHand($this->kibble), 'the shelves were counted after the delivery');
        $this->assertSame([['Receipt', '10.00'], ['Count Adjustment', '-3.00'], ['Reversal', '-10.00'], ['Count Adjustment', '10.00']],
            array_map(fn($r) => [$r['txn_type'], $r['qty_change']], Db::pdo()->query('SELECT txn_type, qty_change FROM inventory_transaction ORDER BY txn_id')->fetchAll()));
        $details = json_decode((string) $this->scalar("SELECT details FROM audit_log WHERE action = 'stock_receipt_void'"), true);
        $this->assertSame([$this->kibble], $details['kept_as_counted']);
        $this->assertSame([['Count Adjustment', true, '7.00'], ['Reversal', false, null], ['Count Adjustment', false, '7.00'], ['Receipt', false, '10.00']],
            array_map(fn($r) => [$r['txn_type'], $r['offset'], $r['balance_after']], StockRepository::history($this->site, $this->kibble)['rows']),
            'the history shows the void and its offset as one change');

        $before = $this->receive([[$this->tins, 4]]);
        $this->postCount([$this->kibble => '7']); // a later count of another product does not absorb the tins
        ReceiptService::void($before, $this->site, null, 'Wrong product', $this->admin);
        $this->assertSame('0.00', $this->onHand($this->tins));
        $this->assertReconciles();
    }

    public function testAVoidOfGoodsAlreadyGivenOutIsRefused(): void
    {
        $id = $this->receive([[$this->kibble, 10]]);
        $other = $this->lineIds($this->receive([[$this->kibble, 5]]))[0];
        // Stands in for food given out (P4 distributions): the stock drops to 2 without a count.
        Ledger::post($this->site, 'Reversal', [['product_id' => $this->kibble, 'qty' => -13, 'receipt_line_id' => $other]], $this->admin);
        $errors = $this->errorsOf(fn() => ReceiptService::void($id, $this->site, null, 'Mistake', $this->admin));
        $this->assertStringContainsString('Adult kibble · Acme · Dog dry · 30 lb: only 2 left', $errors['_form']);
        $this->assertSame('2.00', $this->onHand($this->kibble));
        $this->assertSame(0, $this->audits('stock_receipt_void', 'stock_receipt', $id));
    }

    public function testLinesCanBeAddedAndTheDetailsCorrected(): void
    {
        $id = $this->receive([[$this->kibble, 2]], ['name' => 'Delivery']);
        ReceiptService::addLines($id, $this->site, $this->rows([[$this->tins, 12]]), [], $this->admin);
        $this->assertSame('12.00', $this->onHand($this->tins));
        $this->assertSame(1, $this->audits('stock_receipt_add_lines', 'stock_receipt', $id));
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => ReceiptService::addLines($id, $this->makeSite('Southside'), $this->rows([[$this->tins, 1]]), [], $this->admin)));

        $receipt = ReceiptRepository::find($id);
        $revision = ReceiptService::revision($receipt);
        ReceiptService::updateHeader($id, $this->site, ['name' => 'Delivery', 'received_on' => self::TODAY, 'notes' => ''], $revision, $this->admin);
        $this->assertSame(0, $this->audits('stock_receipt_update', 'stock_receipt', $id), 'no change, no audit');
        $this->assertSame(['received_on'], array_keys($this->errorsOf(fn() => ReceiptService::updateHeader($id, $this->site,
            ['name' => 'Delivery', 'received_on' => '2026-10-02'], $revision, $this->admin))), 'not in the future');
        ReceiptService::updateHeader($id, $this->site, ['name' => '', 'received_on' => '2026-09-29', 'notes' => 'Late entry'], $revision, $this->admin);
        $this->assertSame(["RCPT-$id", '2026-09-29', 'Late entry'], array_values(array_intersect_key(ReceiptRepository::find($id), array_flip(['name', 'received_on', 'notes']))),
            'a blank name gives the automatic name back');
        $this->assertSame(['2.00', '12.00'], [$this->onHand($this->kibble), $this->onHand($this->tins)], 'the stock does not change');
        $this->expectException(StaleFormException::class);
        ReceiptService::updateHeader($id, $this->site, ['name' => 'Old form', 'received_on' => self::TODAY], $revision, $this->admin);
    }

    // Counts ----------------------------------------------------------------------------------

    public function testACountSetsWhatWasCountedAndLeavesBlanksAlone(): void
    {
        $this->receive([[$this->kibble, 10], [$this->tins, 0, '2'], [$this->chews, 5]]);
        $cases = [$this->tins => '1'];
        $units = [$this->kibble => '6', $this->tins => '3'];
        $review = CountService::review($this->site, null, $cases, $units);
        $this->assertSame([[$this->kibble, '10.00', 6, '-4.00', false], [$this->tins, '48.00', 27, '-21.00', false]],
            array_map(fn($l) => [$l['product_id'], $l['book'], $l['counted'], $l['change'], $l['large']], $review['lines']), '1 case of 24 + 3');
        $this->assertSame([[$this->chews, '5.00']], array_map(fn($n) => [(int) $n['product']['product_id'], $n['book']], $review['not_counted']));

        $id = $this->postCount($units, cases: $cases);
        $this->assertSame(['6.00', '27.00', '5.00'], [$this->onHand($this->kibble), $this->onHand($this->tins), $this->onHand($this->chews)]);
        $count = CountRepository::find($id);
        $this->assertSame(['All areas', self::TODAY, self::NOW], [$count['location'], $count['count_date'], $count['posted_at']]);
        $this->assertSame([['6', '-4.00'], ['27', '-21.00']], array_map(fn($l) => [(string) $l['counted'], $l['change_units']], CountRepository::lines($id)));
        $this->assertSame(1, $this->audits('inventory_count_post', 'inventory_count', $id));

        $again = $this->postCount([$this->kibble => '6', $this->chews => '0']);
        $this->assertSame(['0.00', '-5.00'], array_column(CountRepository::lines($again), 'change_units'), 'an unchanged product still gets its row');
        $this->assertSame(['6.00', '0.00'], [$this->onHand($this->kibble), $this->onHand($this->chews)]);
        $this->assertSame([true, false], array_map(fn($u) => CountService::review($this->site, null, [], [$this->kibble => $u])['lines'][0]['large'], ['100', '7']),
            'more than half the stock and more than 10 units');

        $wet = $this->postCount([$this->kibble => '1', $this->tins => '5'], $this->cans);
        $this->assertSame(['Wet dog food', '6.00', '5.00'], [CountRepository::find($wet)['location'], $this->onHand($this->kibble), $this->onHand($this->tins)],
            'a category count leaves other products alone');
        $this->assertReconciles();
    }

    public function testEveryProblemWithACountIsReported(): void
    {
        $errors = $this->errorsOf(fn() => CountService::review($this->site, null, [$this->kibble => '1', $this->tins => '4200'],
            [$this->kibble => '2', $this->tins => '', $this->chews => 'abc']));
        $this->assertEqualsCanonicalizing(["cases[$this->kibble]", "units[$this->tins]", "units[$this->chews]"], array_keys($errors));
        foreach (['x', '1.5', '100000', '-1'] as $bad) {
            $this->assertSame(["cases[$this->tins]"], array_keys($this->errorsOf(fn() => CountService::review($this->site, null, [$this->tins => $bad], [$this->tins => '3']))), $bad);
        }
        $this->assertSame(['_form'], array_keys($this->errorsOf(fn() => CountService::review($this->site, null, [], [$this->kibble => ' ']))));
        $this->assertSame(['_form'], array_keys($this->errorsOf(fn() => CountService::review($this->site, $this->cans, [], [$this->kibble => '3']))),
            'figures for products outside the category are ignored');
    }

    public function testACountWaitsWhileAnEventIsOpenAtItsSite(): void
    {
        $south = $this->makeSite('Southside');
        Db::pdo()->prepare("INSERT INTO distribution_event (site_id, event_date, starts_at, ends_at, status) VALUES (?, ?, '09:00:00', '12:00:00', 'Open')")
            ->execute([$this->site, self::TODAY]);
        $event = (int) Db::pdo()->lastInsertId();
        $denied = fn() => (int) Db::durable()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'inventory_count_post' AND outcome = 'Denied'")->fetchColumn();
        $before = $denied();
        $errors = $this->errorsOf(fn() => $this->postCount([$this->kibble => '3']));
        $this->assertStringContainsString('distribution event is open', $errors['_form']);
        $this->assertSame($before + 1, $denied(), 'the refusal is audited');
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM inventory_count'));

        $this->postCount([$this->kibble => '3'], site: $south);
        $this->assertSame('3.00', $this->onHand($this->kibble, $south), 'an event at another site does not matter');
        Db::pdo()->prepare("UPDATE distribution_event SET status = 'Closed' WHERE event_id = ?")->execute([$event]);
        $this->postCount([$this->kibble => '3']);
        $this->assertSame('3.00', $this->onHand($this->kibble));
    }

    public function testAReviewOvertakenByStockChangesIsShownAgain(): void
    {
        $this->receive([[$this->kibble, 10]]);
        $units = [$this->kibble => '8', $this->tins => '0'];
        $book = array_column(CountService::review($this->site, null, [], $units)['lines'], 'book', 'product_id');
        $this->receive([[$this->kibble, 5]]); // goods received while the shelves were being counted
        foreach ([[$book, [$this->kibble]], [[], [$this->kibble, $this->tins]], [[$this->kibble => '15', $this->tins => 'x'], [$this->tins]]] as [$shown, $stale]) {
            try {
                CountService::post($this->site, null, [], $units, $shown, $this->admin);
                $this->fail('expected a stale count');
            } catch (StaleCountException $e) {
                $this->assertSame($stale, $e->productIds);
            }
        }
        $this->assertSame([0, '15.00'], [(int) $this->scalar('SELECT COUNT(*) FROM inventory_count'), $this->onHand($this->kibble)]);
    }

    public function testTheCountDateIsTheSitesOwnDate(): void
    {
        Clock::freeze('2026-10-02 02:00:00'); // 10 pm on 1 October in New York
        $count = CountRepository::find($this->postCount([$this->kibble => '1']));
        $this->assertSame(['2026-10-01', '2026-10-02 02:00:00'], [$count['count_date'], $count['posted_at']]);
        Db::pdo()->prepare('UPDATE site SET time_zone = ? WHERE site_id = ?')->execute(['Pacific/Auckland', $this->site]);
        $this->assertSame('2026-10-02', CountRepository::find($this->postCount([$this->kibble => '2']))['count_date']);
    }

    public function testInactiveProductsAreCountedOnlyWhileTheSiteHoldsThem(): void
    {
        $south = $this->makeSite('Southside');
        $this->receive([[$this->chews, 2]]);
        ProductService::setActive($this->chews, false, true, $this->admin);
        $sheet = fn(int $site) => array_map(fn($p) => (int) $p['product_id'], CountRepository::sheet($site));
        $this->assertContains($this->chews, $sheet($this->site));
        $this->assertNotContains($this->chews, $sheet($south));
        $this->assertArrayHasKey('product[0]', $this->errorsOf(fn() => $this->receive([[$this->chews, 1]])), 'it cannot be received');
        $this->postCount([$this->chews => '0']);
        $this->assertNotContains($this->chews, $sheet($this->site));
    }

    // Stock view ------------------------------------------------------------------------------

    public function testTheStockViewAndHistory(): void
    {
        $this->receive([[$this->kibble, 10, '', '2027-01-31'], [$this->tins, 24]]);
        $this->postCount([$this->kibble => '7']);
        $rows = array_column(StockRepository::onHand($this->site), null, 'product_id');
        $this->assertSame(['7.00', self::TODAY, '2027-01-31'], [$rows[$this->kibble]['quantity_on_hand'], $rows[$this->kibble]['last_counted'], $rows[$this->kibble]['shelf_expiry']]);
        $this->assertSame(['24.00', null, null], [$rows[$this->tins]['quantity_on_hand'], $rows[$this->tins]['last_counted'], $rows[$this->tins]['shelf_expiry']]);
        $this->assertSame('0.00', $rows[$this->chews]['quantity_on_hand']);
        $ids = fn(array $filters) => array_map(fn($r) => (int) $r['product_id'], StockRepository::onHand($this->site, $filters));
        $this->assertSame([$this->kibble, $this->tins], $ids(['in_stock' => true]));
        $this->assertSame([$this->tins], $ids(['category_id' => $this->cans]));
        $this->assertSame([$this->tins], $ids(['q' => 'CHUNKS']));

        $history = StockRepository::history($this->site, $this->kibble);
        $this->assertSame([['Count Adjustment', '-3.00', '7.00', self::TODAY], ['Receipt', '10.00', '10.00', null]],
            array_map(fn($r) => [$r['txn_type'], $r['qty_change'], $r['balance_after'], $r['count_date']], $history['rows']));
        $this->assertFalse($history['more']);
        $this->assertTrue(StockRepository::history($this->site, $this->kibble, 1)['more']);
    }

    // What is posted is what was reviewed ------------------------------------------------------

    public function testAReceiptPostsOnlyWhatWasReviewed(): void
    {
        $header = ['name' => '', 'received_on' => self::TODAY, 'notes' => ''];
        $rows = $this->rows([[$this->kibble, 4]]);
        $this->postCount([$this->kibble => '10']);
        $key = ReceiptService::reviewKey($this->site, ReceiptService::prepare($this->site, $header, $rows));
        $this->postCount([$this->kibble => '14']); // a newer count, which may already have these 4 bags
        $stale = function (callable $post): void {
            try {
                $post();
                $this->fail('expected a stale review');
            } catch (StaleFormException) {
                $this->addToAssertionCount(1);
            }
        };
        $stale(fn() => ReceiptService::create($this->site, $header, $rows, [0 => 'add'], $this->admin, $key));
        $this->assertSame(['14.00', 0], [$this->onHand($this->kibble), (int) $this->scalar('SELECT COUNT(*) FROM stock_receipt')], 'the answer was about the older count');

        $tins = $this->rows([[$this->tins, 0, '3']]);
        $key = ReceiptService::reviewKey($this->site, ReceiptService::prepare($this->site, $header, $tins));
        $stale(fn() => ReceiptService::create($this->makeSite('Southside'), $header, $tins, [], $this->admin, $key)); // the session moved to another site
        ItemCategoryService::update($this->cans, ['name' => 'Wet dog food', 'units_per_case' => '12'], ItemCategoryService::revision(ItemCategoryRepository::find($this->cans)));
        $stale(fn() => ReceiptService::create($this->site, $header, $tins, [], $this->admin, $key)); // 3 cases are now 36, not the 72 reviewed
        $this->assertSame('0.00', $this->onHand($this->tins));

        $id = ReceiptService::create($this->site, $header, $tins, [], $this->admin, ReceiptService::reviewKey($this->site, ReceiptService::prepare($this->site, $header, $tins)));
        $this->assertSame('36.00', $this->onHand($this->tins));
        $receipt = ReceiptRepository::find($id);
        $key = ReceiptService::reviewKey($this->site, ReceiptService::prepare($this->site, [], $rows, $receipt));
        $this->postCount([$this->kibble => '14']);
        $stale(fn() => ReceiptService::addLines($id, $this->site, $rows, [0 => 'add'], $this->admin, $key));
        $this->assertSame('14.00', $this->onHand($this->kibble));
    }

    public function testACountPostsOnlyWhatWasReviewed(): void
    {
        $dog = (string) $this->scalar("SELECT species_id FROM species WHERE name = 'Dog'");
        $pate = ProductService::create(['category_id' => (string) $this->cans, 'name' => 'Pate', 'species_id' => $dog, 'food_form' => 'Wet', 'unit_weight' => '0.3'], $this->admin);
        $this->receive([[$this->tins, 0, '2']]);
        $stale = function (?int $category, array $cases, array $units, callable $between): void {
            $review = CountService::review($this->site, $category, $cases, $units);
            $key = CountService::reviewKey($this->site, $category, $review);
            $between();
            try {
                CountService::post($this->site, $category, $cases, $units, array_column($review['lines'], 'book', 'product_id'), $this->admin, $key);
                $this->fail('expected a stale count');
            } catch (StaleCountException $e) {
                $this->assertSame([], $e->productIds);
                $this->assertStringContainsString('case sizes changed', $e->getMessage());
            }
        };
        $stale(null, [$this->tins => '2'], [], fn() => ItemCategoryService::update($this->cans, ['name' => 'Wet dog food', 'units_per_case' => '12'],
            ItemCategoryService::revision(ItemCategoryRepository::find($this->cans))));
        $this->assertSame('48.00', $this->onHand($this->tins), 'reviewed as 2 cases of 24, not posted as 24');

        $stale($this->cans, [], [$this->tins => '5', $pate => '7'], function (): void {
            $row = ProductRepository::find($this->tins);
            ProductService::update($this->tins, ['category_id' => (string) $this->dry, 'name' => $row['name'], 'brand' => $row['brand'], 'species_id' => (string) $row['species_id'],
                'food_form' => $row['food_form'], 'unit_weight' => $row['unit_weight_lbs']], ProductService::revision($row), $this->admin);
        });
        $this->assertSame([0, '48.00'], [(int) $this->scalar('SELECT COUNT(*) FROM inventory_count'), $this->onHand($this->tins)], 'a product that left the sheet is not dropped silently');
    }

    public function testAReviewShowsStockThatMovedAfterTheSheetWasOpened(): void
    {
        $this->receive([[$this->kibble, 10]]);
        $seen = [$this->kibble => '10.00', $this->tins => '0.00'];
        $this->receive([[$this->kibble, 5]]); // a delivery put away while the shelves were counted
        $lines = array_column(CountService::review($this->site, null, [], [$this->kibble => '10', $this->tins => '0'], $seen + [$this->chews => 'junk'])['lines'], null, 'product_id');
        $this->assertSame([true, '10.00'], [$lines[$this->kibble]['moved'], $lines[$this->kibble]['seen']]);
        $this->assertSame([false, null], [$lines[$this->tins]['moved'], $lines[$this->tins]['seen']]);
    }

    public function testADeliveryCannotBeMovedBackBeforeACountThatMayHaveIt(): void
    {
        $this->postCount([$this->kibble => '10']); // 1 October
        Clock::freeze('2026-10-02 12:00:00');
        $id = $this->receive([[$this->kibble, 5], [$this->tins, 2]], ['received_on' => '2026-10-02']); // entered with the default date
        $revision = ReceiptService::revision(ReceiptRepository::find($id));
        foreach (['2026-09-30', '2026-10-01'] as $date) {
            $errors = $this->errorsOf(fn() => ReceiptService::updateHeader($id, $this->site, ['name' => '', 'received_on' => $date], $revision, $this->admin));
            $this->assertStringContainsString('A stock count on 2026-10-01 may already include these goods', $errors['received_on'], $date);
        }
        $tinsOnly = $this->receive([[$this->tins, 3]], ['received_on' => '2026-10-02']);
        ReceiptService::updateHeader($tinsOnly, $this->site, ['name' => '', 'received_on' => '2026-09-30'], ReceiptService::revision(ReceiptRepository::find($tinsOnly)), $this->admin);
        $this->assertSame('2026-09-30', ReceiptRepository::find($tinsOnly)['received_on'], 'no count has the tins');
        [$kibbleLine] = $this->lineIds($id);
        ReceiptService::void($id, $this->site, [$kibbleLine], 'Wrong date', $this->admin);
        ReceiptService::updateHeader($id, $this->site, ['name' => '', 'received_on' => '2026-09-30'], $revision, $this->admin);
        $this->assertSame('2026-09-30', ReceiptRepository::find($id)['received_on'], 'once the counted line is voided');
    }

    // Receipts list and stock view ---------------------------------------------------------------

    public function testTheReceiptsListCountsOnlyLinesStillInStock(): void
    {
        $id = $this->receive([[$this->kibble, 5, '', '2027-01-01'], [$this->tins, 30, '', '2026-12-01']]);
        ReceiptService::void($id, $this->site, [$this->lineIds($id)[1]], 'Not ours', $this->admin);
        $row = ReceiptRepository::list($this->site)[0];
        $this->assertSame([2, 5, '2027-01-01', 'Partly voided'], [(int) $row['line_count'], (int) $row['units'], $row['earliest_expiry'], ReceiptService::status($row)]);
    }

    public function testTheShelfBestBeforeFollowsTheNewestDeliveries(): void
    {
        $this->receive([[$this->kibble, 10, '', '2025-03-01']], ['received_on' => '2025-01-10']);
        $this->postCount([$this->kibble => '0']); // all given out
        $shelf = fn() => StockRepository::shelfExpiry($this->site)[$this->kibble] ?? null;
        $this->assertNull($shelf(), 'nothing in stock');
        $this->receive([[$this->kibble, 10, '', '2027-06-30']], [], [0 => 'add']);
        $this->assertSame('2027-06-30', $shelf(), 'the old bags have gone');
        $this->receive([[$this->kibble, 5, '', '2027-01-31']], [], [0 => 'add']);
        $this->assertSame('2027-01-31', $shelf(), 'the 15 in stock are the two newest deliveries');
        $this->assertSame('2027-01-31', array_column(StockRepository::onHand($this->site), 'shelf_expiry', 'product_id')[$this->kibble]);
    }

    public function testTheStockListShowsProductsWithoutAStockRowAtZero(): void
    {
        Db::pdo()->prepare('DELETE FROM site_stock WHERE site_id = ?')->execute([$this->site]);
        $rows = StockRepository::onHand($this->site);
        $this->assertSame([$this->kibble => '0.00', $this->chews => '0.00', $this->tins => '0.00'], array_column($rows, 'quantity_on_hand', 'product_id'));
        $this->assertSame([], StockRepository::onHand($this->site, ['in_stock' => true]));
    }

    public function testLinesAddedButAlreadyCountedAreNotedOnTheReceipt(): void
    {
        $id = $this->receive([[$this->chews, 1]], ['notes' => 'Donor: X']);
        $this->postCount([$this->kibble => '10']);
        ReceiptService::addLines($id, $this->site, $this->rows([[$this->kibble, 4], [$this->tins, 6]]), [0 => 'skip'], $this->admin);
        $this->assertSame(['10.00', '6.00'], [$this->onHand($this->kibble), $this->onHand($this->tins)]);
        $notes = (string) ReceiptRepository::find($id)['notes'];
        $this->assertStringStartsWith("Donor: X\nNot added to stock, already in a stock count: Adult kibble", $notes);
        $audit = (int) $this->scalar("SELECT audit_id FROM audit_log WHERE action = 'stock_receipt_add_lines' AND entity_id = ?", [$id]);
        $this->assertSame([['notes', 'Donor: X', $notes]], Db::pdo()->query("SELECT field_name, old_value, new_value FROM audit_field_change WHERE audit_id = $audit")->fetchAll(\PDO::FETCH_NUM));
        $details = json_decode((string) $this->scalar('SELECT details FROM audit_log WHERE audit_id = ?', [$audit]), true);
        $this->assertEquals([['product_id' => $this->kibble, 'quantity' => 4]], $details['left_out_as_counted']);

        $long = $this->receive([[$this->chews, 1]], ['notes' => str_repeat('n', ReceiptService::NOTES_MAX - 10)]);
        ReceiptService::addLines($long, $this->site, $this->rows([[$this->kibble, 1], [$this->chews, 1]]), [0 => 'skip'], $this->admin);
        $this->assertSame(ReceiptService::NOTES_MAX, mb_strlen((string) ReceiptRepository::find($long)['notes']), 'cut to the notes limit');
    }

    // The ledger's P4 options and its guards ------------------------------------------------------

    public function testTheCountOffsetOptionsForOfflineReplay(): void
    {
        $line = $this->lineIds($this->receive([[$this->kibble, 20], [$this->tins, 5]]));
        $countLine = (int) CountRepository::lines($this->postCount([$this->kibble => '12', $this->tins => '5']))[0]['count_line_id']; // posted at 12:00:00
        $post = fn(array $extra, int $qty = -1) => Ledger::post($this->site, 'Reversal', [['product_id' => $this->kibble, 'qty' => $qty, 'receipt_line_id' => $line[0]] + $extra], $this->admin);
        $this->assertSame([$countLine], $post(['offset_after_time' => '2026-10-01 11:00:00'])[$this->kibble]['offsets'], 'given out before the count');
        $this->assertSame([$countLine], $post(['offset_after_time' => '2026-10-01 12:00:00'])[$this->kibble]['offsets'], 'the same second counts as before the count');
        $this->assertSame([], $post(['offset_after_time' => '2026-10-01 12:00:01'])[$this->kibble]['offsets'], 'given out after the count');
        $this->assertSame('11.00', $this->onHand($this->kibble));
        $this->assertSame([$countLine], $post(['offset_count_line_id' => $countLine])[$this->kibble]['offsets']);
        $tinsLine = (int) CountRepository::lines((int) $this->scalar('SELECT MAX(count_id) FROM inventory_count'))[1]['count_line_id'];
        $south = $this->makeSite('Southside');
        $this->receive([[$this->kibble, 3]], [], [], $south);
        $southLine = (int) CountRepository::lines($this->postCount([$this->kibble => '3'], site: $south))[0]['count_line_id'];
        foreach ([['offset_after_time' => '2026-10-01T11:00:00'], ['offset_after_time' => '2026-10-01 11:00'], ['offset_count_line_id' => $tinsLine],
                  ['offset_count_line_id' => $southLine], ['offset_count_line_id' => 999999]] as $bad) {
            try {
                $post($bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('11.00', $this->onHand($this->kibble));
        $this->assertReconciles();
    }

    public function testStockWorkWaitsForTheSiteLock(): void
    {
        $id = $this->receive([[$this->kibble, 5]]);
        $other = Db::durable(); // another request holding the site's stock lock
        $name = Db::lockName(Db::pdo(), "stock:site:{$this->site}");
        $other->prepare('SELECT GET_LOCK(?, 0)')->execute([$name]);
        try {
            foreach ([fn() => $this->receive([[$this->kibble, 1]]), fn() => ReceiptService::addLines($id, $this->site, $this->rows([[$this->tins, 1]]), [], $this->admin),
                      fn() => ReceiptService::void($id, $this->site, null, 'Busy', $this->admin), fn() => $this->postCount([$this->kibble => '2'])] as $i => $call) {
                $this->assertStringContainsString('Stock is being recorded at this site', $this->errorsOf($call)['_form'] ?? '', "call $i");
            }
            $this->assertSame(['5.00', 1, 0], [$this->onHand($this->kibble), (int) $this->scalar('SELECT COUNT(*) FROM stock_receipt'), (int) $this->scalar('SELECT COUNT(*) FROM inventory_count')]);
            $south = $this->makeSite('Southside');
            $this->receive([[$this->kibble, 1]], [], [], $south);
            $this->assertSame('1.00', $this->onHand($this->kibble, $south), 'another site is not held up');
        } finally {
            $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    public function testMismatchesBetweenStockAndLedgerAreReported(): void
    {
        $south = $this->makeSite('Southside');
        $this->receive([[$this->kibble, 5], [$this->tins, 3]]);
        $this->receive([[$this->chews, 2]], [], [], $south);
        Db::pdo()->prepare('UPDATE site_stock SET quantity_on_hand = 7 WHERE site_id = ? AND product_id = ?')->execute([$this->site, $this->kibble]);
        Db::pdo()->prepare('DELETE FROM site_stock WHERE site_id = ? AND product_id = ?')->execute([$this->site, $this->tins]);
        Db::pdo()->prepare('UPDATE site_stock SET quantity_on_hand = 9 WHERE site_id = ? AND product_id = ?')->execute([$south, $this->chews]);
        $d = Ledger::discrepancies($this->site);
        $this->assertSame([[$this->site, $this->kibble, '7.00', '5.00']], array_map(fn($r) => [(int) $r['site_id'], (int) $r['product_id'], $r['quantity_on_hand'], $r['ledger_total']], $d['mismatches']));
        $this->assertSame([[$this->site, $this->tins, '3.00']], array_map(fn($r) => [(int) $r['site_id'], (int) $r['product_id'], $r['ledger_total']], $d['orphans']));
        $this->assertSame(1, $d['missing']);
        $this->assertCount(2, Ledger::discrepancies()['mismatches'], 'every site');
    }

    public function testAQuantityBeyondWhatTheStockColumnHoldsIsRefused(): void
    {
        $rows = array_map(fn($day) => [$this->kibble, 99999, '', sprintf('2027-01-%02d', $day)], range(1, 11)); // 1,099,989 bags
        $this->assertSame(['_form'], array_keys($this->errorsOf(fn() => $this->receive($rows))));
        $this->assertSame([0, 0, '0.00'], [(int) $this->scalar('SELECT COUNT(*) FROM stock_receipt'), (int) $this->scalar('SELECT COUNT(*) FROM inventory_transaction'), $this->onHand($this->kibble)]);
    }

    // The P2A exit check ----------------------------------------------------------------------

    public function testARandomRunOfStockWorkAlwaysReconciles(): void
    {
        mt_srand(20261001);
        $sites = [$this->site, $this->makeSite('Southside')];
        $products = [$this->kibble, $this->tins, $this->chews];
        $expected = [];  // "site|product" => hundredths, from a simple model of the rules
        $lastCount = []; // "site|product" => step of its latest count
        $lines = [];     // open receipt line id => [site, product, units, step]
        $refusals = 0;
        for ($step = 1; $step <= 80; $step++) {
            Clock::advance('+' . mt_rand(1, 240) . ' minutes');
            $site = $sites[mt_rand(0, 1)];
            $op = mt_rand(0, 9);
            if ($op < 5) {
                $receive = array_map(fn($p) => [$p, mt_rand(1, 20)], array_slice($products, 0, mt_rand(1, 3)));
                $id = $this->receive($receive, ['received_on' => Clock::localDate('America/New_York')], [0 => 'add', 1 => 'add', 2 => 'add'], $site);
                foreach (ReceiptRepository::lines($id) as $l) {
                    $key = $site . '|' . $l['product_id'];
                    $lines[(int) $l['receipt_line_id']] = [$site, (int) $l['product_id'], (int) $l['quantity'], $step];
                    $expected[$key] = ($expected[$key] ?? 0) + (int) $l['quantity'] * 100;
                }
            } elseif ($op < 8 && $lines) {
                $lineId = array_rand($lines);
                [$lineSite, $product, $units, $at] = $lines[$lineId];
                $receipt = (int) $this->scalar('SELECT receipt_id FROM stock_receipt_line WHERE receipt_line_id = ?', [$lineId]);
                try {
                    ReceiptService::void($receipt, $lineSite, [$lineId], "Step $step", $this->admin);
                } catch (ValidationException) {
                    $refusals++;
                    continue;
                }
                unset($lines[$lineId]);
                if (($lastCount["$lineSite|$product"] ?? 0) < $at) {
                    $expected["$lineSite|$product"] -= $units * 100; // no count since: the stock goes back down
                }
            } else {
                $units = [];
                foreach ($products as $p) {
                    if (mt_rand(0, 2) > 0) {
                        $units[$p] = (string) mt_rand(0, 30);
                    }
                }
                if (!$units) {
                    continue;
                }
                $this->postCount($units, site: $site);
                foreach ($units as $p => $u) {
                    $expected["$site|$p"] = (int) $u * 100;
                    $lastCount["$site|$p"] = $step;
                }
            }
        }
        foreach ($sites as $site) {
            foreach ($products as $p) {
                $this->assertSame(Validator::fromUnits($expected["$site|$p"] ?? 0, 2), $this->onHand($p, $site), "site $site, product $p");
            }
        }
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM site_stock WHERE quantity_on_hand < 0'));
        $this->assertSame(0, $refusals, 'receipts, voids and counts alone never take stock below zero');
        $this->assertGreaterThan(40, (int) $this->scalar('SELECT COUNT(*) FROM inventory_transaction'));
        $this->assertReconciles();
    }
}

