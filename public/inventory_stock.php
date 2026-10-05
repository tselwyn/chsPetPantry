<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Inventory\ItemCategoryRepository;
use Pfpms\Inventory\Ledger;
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;
use Pfpms\Inventory\StockRepository;
use Pfpms\Reference\SpeciesRepository;
use Pfpms\Validation\Validator;
use Pfpms\View\View;

// Stock on hand at the current site (plan P2A; legacy inventory.php). ?product_id= shows that
// product's stock history here, newest first, with the stock after each change.
$ctx = Page::start(['capability' => 'inventory.view', 'site' => true]);
$siteId = (int) $ctx->siteId;
$productId = Request::int('product_id', fromQuery: true);
if ($productId !== null) {
    $product = ProductRepository::find($productId) ?? Response::notFound();
    View::render('pages/inventory/stock_history', [
        'title' => 'Stock history', 'ctx' => $ctx, 'product' => $product, 'onHand' => StockRepository::quantity($siteId, $productId),
        'history' => StockRepository::history($siteId, $productId),
    ]);
    exit;
}
$filters = [
    'q' => Validator::text(Request::query('q'), 100),
    'category_id' => Request::int('category_id', fromQuery: true),
    'species_id' => Request::int('species_id', fromQuery: true),
    'food_form' => Validator::oneOf(Request::query('food_form'), ProductService::FORMS),
    'in_stock' => Request::query('in_stock') === '1',
];

View::render('pages/inventory/stock', [
    'title' => 'Stock on hand', 'ctx' => $ctx, 'filters' => $filters, 'stock' => StockRepository::onHand($siteId, $filters),
    'categories' => ItemCategoryRepository::all(), 'species' => SpeciesRepository::all(),
    // The ledger check (plan P2A exit): shown to those who can look into it.
    'discrepancies' => $ctx->can('catalog.manage') ? Ledger::discrepancies($siteId) : null,
]);
