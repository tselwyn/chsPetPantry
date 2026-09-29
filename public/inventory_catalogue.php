<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Inventory\ItemCategoryRepository;
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;
use Pfpms\Reference\SpeciesRepository;
use Pfpms\Validation\Validator;
use Pfpms\View\View;

// Product catalogue (plan P2A): categories and products, read-only for Volunteers. Changes are made
// on inventory_category_edit.php and inventory_product_edit.php (catalog.manage); barcodes are
// linked on inventory_barcode_link.php (catalog.barcode_link).
$ctx = Page::start(['capability' => 'inventory.view']);
$filters = [
    'q' => Validator::text(Request::query('q'), 100),
    'species_id' => Request::int('species_id', fromQuery: true),
    'food_form' => Validator::oneOf(Request::query('food_form'), ProductService::FORMS),
    'status' => Validator::oneOf(Request::query('status'), ['active', 'inactive', 'all']) ?? 'active',
];

View::render('pages/inventory/catalogue', [
    'title' => 'Product catalogue', 'ctx' => $ctx, 'filters' => $filters,
    'categories' => ItemCategoryRepository::all(), 'products' => ProductRepository::all($filters), 'species' => SpeciesRepository::all(),
]);
