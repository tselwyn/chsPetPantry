<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Inventory\Barcode;
use Pfpms\Inventory\BarcodeService;
use Pfpms\Inventory\ProductRepository;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Link a barcode to a product (US-16 AC2): scan or type a code; if nobody has linked it, choose the
// product it belongs to. Coordinators and Administrators (catalog.barcode_link). Only an
// Administrator can remove or move a code already linked (on the product's page).
$ctx = Page::start(['capability' => 'catalog.barcode_link']);
$raw = Request::isPost() ? (Request::string('code') ?? '') : (Request::query('code') ?? '');
$errors = [];
$productId = null;

if (Request::isPost()) {
    Csrf::verify();
    $productId = Request::int('product_id');
    try {
        $result = BarcodeService::link($raw, $productId ?? 0, $ctx->userId());
        $product = ProductRepository::find((int) $productId);
        $label = $product !== null ? ProductRepository::label($product) : 'the product';
        Flash::success($result['already'] ? 'Barcode ' . Barcode::display($result['code']) . ' was already linked to ' . $label . '.'
            : 'Barcode ' . Barcode::display($result['code']) . ' linked to ' . $label . '. It is now recognised at every site. Scan the next one.');
        if ($result['store_specific']) {
            Flash::info('This is an in-store code: another shop may use it for a different product.');
        }
        Response::redirect('inventory_barcode_link.php'); // ready for the next scan (and no code in the URL)
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

$candidates = $raw !== '' ? Barcode::candidates($raw) : [];
if ($raw !== '' && !$candidates && !isset($errors['code'])) {
    $errors['code'] = BarcodeService::BAD_CODE;
}
View::render('pages/inventory/barcode_link', [
    'title' => 'Link a barcode', 'ctx' => $ctx, 'raw' => $raw, 'errors' => $errors, 'productId' => $productId,
    'code' => $candidates[0] ?? null, 'linked' => $candidates ? BarcodeService::lookup($raw) : null,
    'products' => $candidates ? ProductRepository::choices() : [],
]);
