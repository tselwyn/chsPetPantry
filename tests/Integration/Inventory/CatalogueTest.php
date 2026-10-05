<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Inventory;

use Pfpms\Db;
use Pfpms\Inventory\BarcodeService;
use Pfpms\Inventory\ItemCategoryRepository;
use Pfpms\Inventory\ItemCategoryService;
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;
use Pfpms\Inventory\StaleFormException;
use Pfpms\Reference\SiteService;
use Pfpms\Reference\SpeciesService;
use Pfpms\Tests\TestCase;
use Pfpms\Validation\ValidationException;

/** Product catalogue (plan P2A): categories, products, pre-created stock rows and global barcodes (US-16). */
final class CatalogueTest extends TestCase
{
    private int $admin;
    private int $dog;
    private int $cat;
    private int $dry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser(['role' => 'Administrator'])['user_id'];
        $this->dog = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Dog'");
        $this->cat = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Cat'");
        $this->dry = ItemCategoryService::create(['name' => 'Dry dog food', 'units_per_case' => '1']);
    }

    private function product(array $overrides = []): int
    {
        return ProductService::create($overrides + ['category_id' => (string) $this->dry, 'name' => 'Adult kibble', 'brand' => 'Acme',
            'species_id' => (string) $this->dog, 'food_form' => 'Dry', 'unit_weight' => '30', 'weight_unit' => 'lb'], $this->admin);
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

    // Categories --------------------------------------------------------------------------

    public function testCategoriesAreUniqueValidatedAndAudited(): void
    {
        $this->assertSame(1, $this->audits('item_category_create', 'item_category', $this->dry));
        $this->assertArrayHasKey('name', $this->errorsOf(fn() => ItemCategoryService::create(['name' => 'DRY DÓG FOOD ', 'units_per_case' => '1'])),
            'case, accents and trailing spaces do not make a new name');
        foreach (['0', '1000', '1.5', '', 'x'] as $bad) {
            $this->assertArrayHasKey('units_per_case', $this->errorsOf(fn() => ItemCategoryService::create(['name' => "Cases $bad", 'units_per_case' => $bad])), $bad);
        }
        $wet = ItemCategoryService::create(['name' => '  Wet   cat food ', 'units_per_case' => '24', 'is_banana_box' => '1']);
        $row = ItemCategoryRepository::find($wet);
        $this->assertSame(['Wet cat food', 24, 1], [$row['name'], (int) $row['units_per_case'], (int) $row['is_banana_box']]);

        $revision = ItemCategoryService::revision($row);
        ItemCategoryService::update($wet, ['name' => 'Wet cat food', 'units_per_case' => '24', 'is_banana_box' => '1'], $revision);
        $this->assertSame(0, $this->audits('item_category_update', 'item_category', $wet), 'no change, no audit');
        ItemCategoryService::update($wet, ['name' => 'Wet cat food', 'units_per_case' => '12', 'is_banana_box' => '1'], $revision);
        $this->expectException(StaleFormException::class);
        ItemCategoryService::update($wet, ['name' => 'Tins', 'units_per_case' => '24'], $revision); // the form still shows 24
    }

    public function testACategoryWithActiveProductsStaysActive(): void
    {
        $product = $this->product();
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => ItemCategoryService::setActive($this->dry, false)));
        ProductService::setActive($product, false, true, $this->admin);
        ItemCategoryService::setActive($this->dry, false);
        $this->assertSame('Inactive', ItemCategoryRepository::find($this->dry)['status']);
        $this->assertArrayHasKey('category_id', $this->errorsOf(fn() => $this->product(['name' => 'New'])), 'not for new products');
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => ProductService::setActive($product, true, false, $this->admin)),
            'a product cannot be reactivated into an inactive category');
    }

    // Products ----------------------------------------------------------------------------

    public function testAProductNeedsAWeightForDryAndWetFood(): void
    {
        $this->assertArrayHasKey('unit_weight', $this->errorsOf(fn() => $this->product(['unit_weight' => ''])));
        $treat = $this->product(['name' => 'Chews', 'food_form' => 'Treat', 'unit_weight' => '']);
        $this->assertNull(ProductRepository::find($treat)['unit_weight_lbs'], 'treats may have no weight');
        $can = $this->product(['name' => 'Pate', 'food_form' => 'Wet', 'unit_weight' => '5.5', 'weight_unit' => 'oz']);
        $this->assertSame('0.34', ProductRepository::find($can)['unit_weight_lbs'], '5.5 oz');
        foreach (['0', '200.01', '1.234', '-1', 'heavy'] as $bad) {
            $this->assertArrayHasKey('unit_weight', $this->errorsOf(fn() => $this->product(['name' => "Bad $bad", 'unit_weight' => $bad])), $bad);
        }
        $errors = $this->errorsOf(fn() => ProductService::create(['category_id' => '999999', 'name' => '', 'species_id' => '0', 'food_form' => 'Kibble'], $this->admin));
        $this->assertEqualsCanonicalizing(['category_id', 'name', 'species_id', 'food_form'], array_keys($errors), 'every problem at once');
    }

    public function testDuplicatesAreTheSameFoodInTheSameSize(): void
    {
        $first = $this->product();
        $this->assertSame(1, $this->audits('product_create', 'product', $first));
        $this->assertArrayHasKey('name', $this->errorsOf(fn() => $this->product(['name' => 'ADULT KIBBLE ', 'brand' => 'acme'])));
        $this->product(['unit_weight' => '4']); // the same food in a 4 lb bag is another product
        $this->product(['species_id' => (string) $this->cat]);
        $plain = $this->product(['brand' => '']);
        $this->assertArrayHasKey('name', $this->errorsOf(fn() => $this->product(['brand' => ' '])), 'no brand matches no brand');
        ProductService::setActive($plain, false, false, $this->admin);
        $errors = $this->errorsOf(fn() => $this->product(['brand' => '']));
        $this->assertStringContainsString('Reactivate it', $errors['name']);
    }

    public function testSpeciesAndFormAreLockedOnceUsedButTheRestStaysEditable(): void
    {
        $id = $this->product();
        $site = $this->makeSite('North');
        $row = ProductRepository::find($id);
        ProductService::update($id, ['category_id' => (string) $this->dry, 'name' => 'Adult kibble', 'brand' => 'Acme', 'species_id' => (string) $this->cat,
            'food_form' => 'Dry', 'unit_weight' => '30'], ProductService::revision($row), $this->admin);
        $this->assertSame($this->cat, (int) ProductRepository::find($id)['species_id'], 'unused: may still change');

        Db::pdo()->prepare('INSERT INTO stock_receipt (site_id, name, received_on, received_by) VALUES (?, ?, ?, ?)')->execute([$site, 'R1', '2026-10-01', $this->admin]);
        Db::pdo()->prepare('INSERT INTO stock_receipt_line (receipt_id, product_id, quantity) VALUES (?, ?, 5)')->execute([(int) Db::pdo()->lastInsertId(), $id]);
        $row = ProductRepository::find($id);
        $input = ['category_id' => (string) $this->dry, 'name' => 'Adult kibble', 'brand' => 'Acme', 'species_id' => (string) $this->dog, 'food_form' => 'Dry', 'unit_weight' => '30'];
        $this->assertArrayHasKey('species_id', $this->errorsOf(fn() => ProductService::update($id, $input, ProductService::revision($row), $this->admin)));
        $this->assertArrayHasKey('food_form', $this->errorsOf(fn() => ProductService::update($id, ['species_id' => (string) $this->cat, 'food_form' => 'Wet'] + $input,
            ProductService::revision($row), $this->admin)));
        ProductService::update($id, ['species_id' => (string) $this->cat, 'name' => 'Senior kibble', 'unit_weight' => '15'] + $input, ProductService::revision($row), $this->admin);
        $this->assertSame(['Senior kibble', '15.00'], [ProductRepository::find($id)['name'], ProductRepository::find($id)['unit_weight_lbs']]);
        $this->expectException(StaleFormException::class);
        ProductService::update($id, ['species_id' => (string) $this->cat, 'name' => 'Old form'] + $input, ProductService::revision($row), $this->admin);
    }

    public function testDeactivatingAProductInStockNeedsConfirmation(): void
    {
        $id = $this->product();
        $site = $this->makeSite('North');
        Db::pdo()->prepare('UPDATE site_stock SET quantity_on_hand = 40 WHERE site_id = ? AND product_id = ?')->execute([$site, $id]);
        $errors = $this->errorsOf(fn() => ProductService::setActive($id, false, false, $this->admin));
        $this->assertStringContainsString('North 40', $errors['confirm']);
        ProductService::setActive($id, false, true, $this->admin);
        $this->assertSame(0, (int) ProductRepository::find($id)['is_active']);
        $this->assertStringContainsString('still_in_stock', (string) $this->scalar("SELECT details FROM audit_log WHERE action = 'product_deactivate' AND entity_id = ?", [$id]));
    }

    // Stock rows --------------------------------------------------------------------------

    public function testEverySiteAndProductGetsAZeroStockRow(): void
    {
        $north = $this->makeSite('North');
        $closed = $this->makeSite('Closed');
        Db::pdo()->prepare('UPDATE site SET is_active = 0 WHERE site_id = ?')->execute([$closed]);
        $id = $this->product();
        $this->assertSame((int) $this->scalar('SELECT COUNT(*) FROM site'), (int) $this->scalar('SELECT COUNT(*) FROM site_stock WHERE product_id = ?', [$id]),
            'a new product: a row at every site, inactive ones too');
        $south = SiteService::create(['name' => 'South', 'time_zone' => 'America/New_York']);
        $this->assertSame((int) $this->scalar('SELECT COUNT(*) FROM product'), (int) $this->scalar('SELECT COUNT(*) FROM site_stock WHERE site_id = ?', [$south]),
            'a new site: a row for every product');
        $this->assertSame('0.00', $this->scalar('SELECT quantity_on_hand FROM site_stock WHERE site_id = ? AND product_id = ?', [$north, $id]));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM site s CROSS JOIN product p
            LEFT JOIN site_stock ss ON ss.site_id = s.site_id AND ss.product_id = p.product_id WHERE ss.site_id IS NULL'), 'no pair is missing');
    }

    // Barcodes ----------------------------------------------------------------------------

    public function testABarcodeIsLinkedOnceAndRecognisedWhateverTheScannerSends(): void
    {
        $kibble = $this->product();
        $other = $this->product(['name' => 'Puppy kibble']);
        $result = BarcodeService::link('036000291452', $kibble, $this->admin);
        $this->assertSame(['00036000291452', false], [$result['code'], $result['already']]);
        $this->assertSame(1, $this->audits('barcode_link', 'product', $kibble));
        $this->assertTrue(BarcodeService::link('0036000291452', $kibble, $this->admin)['already'], 'the same bag read as EAN-13');
        $this->assertStringContainsString('Adult kibble', $this->errorsOf(fn() => BarcodeService::link(' 036000291452', $other, $this->admin))['code']);
        $this->assertSame($kibble, (int) BarcodeService::lookup(']E0036000291452')['product_id'], 'the lookup ignores the site');
        $this->assertArrayHasKey('code', $this->errorsOf(fn() => BarcodeService::link('036000291453', $kibble, $this->admin)), 'bad check digit');
        $this->assertArrayHasKey('code', $this->errorsOf(fn() => BarcodeService::link('P123', $kibble, $this->admin)), 'a participant code');
        ProductService::setActive($other, false, true, $this->admin);
        $this->assertArrayHasKey('product_id', $this->errorsOf(fn() => BarcodeService::link('4006381333931', $other, $this->admin)), 'inactive product');
        $this->assertNull(BarcodeService::lookup('4006381333931'));
    }

    public function testAnEightDigitCodeReadTwoWaysCanNeverBelongToTwoProducts(): void
    {
        $kibble = $this->product();
        $other = $this->product(['name' => 'Puppy kibble']);
        BarcodeService::link('01234565', $kibble, $this->admin); // a wedge scanner: no format, stored as the UPC-E reading
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM product_barcode WHERE barcode = '00012345000065'"));
        $this->assertArrayHasKey('code', $this->errorsOf(fn() => BarcodeService::link('01234565', $other, $this->admin, 'ean_8')),
            'a camera reading it as EAN-8 cannot link it to another product');
        $this->assertSame($kibble, (int) BarcodeService::lookup('01234565', 'ean_8')['product_id'], 'and finds the product linked from the wedge scanner');

        Db::pdo()->prepare('INSERT INTO product_barcode (barcode, product_id, linked_by) VALUES (?, ?, ?)')->execute(['00000001234565', $other, $this->admin]);
        $this->assertNull(BarcodeService::lookup('01234565'), 'readings on two products: treated as unknown, picked by hand');
    }

    public function testOnlyWithAReasonCanABarcodeBeRemovedOrMoved(): void
    {
        $kibble = $this->product();
        $other = $this->product(['name' => 'Puppy kibble']);
        BarcodeService::link('036000291452', $kibble, $this->admin);
        $this->assertSame(['product_id'], array_keys($this->errorsOf(fn() => BarcodeService::link('4006381333931', 0, $this->admin))), 'no product chosen');
        $this->assertArrayHasKey('reason', $this->errorsOf(fn() => BarcodeService::move('00036000291452', $kibble, $other, ' ', $this->admin)));
        BarcodeService::move('00036000291452', $kibble, $other, 'Linked in a hurry', $this->admin);
        $this->assertSame($other, (int) BarcodeService::lookup('036000291452')['product_id']);
        $this->assertSame(1, $this->audits('barcode_move', 'product', $kibble));
        $this->assertSame(1, $this->audits('barcode_move', 'product', $other));

        // A page still showing the code on the product it was moved away from changes nothing.
        $this->assertStringContainsString('now belongs to Puppy kibble', $this->errorsOf(fn() => BarcodeService::unlink('00036000291452', $kibble, 'Stale page', $this->admin))['_form']);
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => BarcodeService::move('00036000291452', $kibble, $kibble, 'Stale page', $this->admin)));
        $this->assertSame($other, (int) BarcodeService::lookup('036000291452')['product_id']);

        BarcodeService::unlink('00036000291452', $other, 'Wrong code', $this->admin);
        $this->assertNull(BarcodeService::lookup('036000291452'));
        $this->assertStringContainsString('00036000291452', (string) $this->scalar("SELECT details FROM audit_log WHERE action = 'barcode_unlink'"));
        $this->assertArrayHasKey('_form', $this->errorsOf(fn() => BarcodeService::unlink('00036000291452', $other, 'Again', $this->admin)));
    }

    public function testCatalogueWritesWaitForTheCatalogueLock(): void
    {
        $other = Db::durable();
        $name = Db::lockName(Db::pdo(), 'catalogue');
        $st = $other->prepare('SELECT GET_LOCK(?, 0)');
        $st->execute([$name]);
        try {
            foreach ([fn() => $this->product(['name' => 'Locked']), fn() => ItemCategoryService::create(['name' => 'Locked', 'units_per_case' => '1']),
                      fn() => SiteService::create(['name' => 'Locked', 'time_zone' => 'America/New_York'])] as $call) {
                $this->assertStringContainsString('Someone else', $this->errorsOf($call)['_form'] ?? '');
            }
        } finally {
            $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    public function testAnInactiveSpeciesStaysOnlyOnProductsThatHaveIt(): void
    {
        $rabbit = SpeciesService::create(['name' => 'Rabbit']);
        $hay = $this->product(['name' => 'Hay', 'species_id' => (string) $rabbit, 'food_form' => 'Other', 'unit_weight' => '']);
        SpeciesService::setActive($rabbit, false);
        $this->assertArrayHasKey('species_id', $this->errorsOf(fn() => $this->product(['name' => 'Pellets', 'species_id' => (string) $rabbit])));
        $row = ProductRepository::find($hay);
        ProductService::update($hay, ['category_id' => (string) $this->dry, 'name' => 'Meadow hay', 'species_id' => (string) $rabbit, 'food_form' => 'Other'],
            ProductService::revision($row), $this->admin);
        $this->assertSame('Meadow hay', ProductRepository::find($hay)['name'], 'its own product keeps the species');
    }
}
