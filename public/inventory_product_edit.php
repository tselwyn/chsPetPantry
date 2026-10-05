<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Inventory\Barcode;
use Pfpms\Inventory\BarcodeService;
use Pfpms\Inventory\ItemCategoryRepository;
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;
use Pfpms\Inventory\StaleFormException;
use Pfpms\Inventory\StockRepository;
use Pfpms\Reference\SpeciesRepository;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Add or edit a product, activate or deactivate it, and manage its barcodes (US-16). ?id= edits.
$ctx = Page::start(['capability' => 'catalog.manage']);
$productId = Request::int('id', fromQuery: true) ?? Request::int('product_id');
$product = $productId !== null ? (ProductRepository::find($productId) ?? Response::notFound()) : null;
$values = $product
    ? ['category_id' => (string) $product['category_id'], 'name' => $product['name'], 'brand' => $product['brand'] ?? '', 'species_id' => (string) $product['species_id'],
        'food_form' => $product['food_form'], 'unit_weight' => $product['unit_weight_lbs'] ?? '', 'weight_unit' => 'lb']
    : ['category_id' => (string) Request::int('category_id', fromQuery: true), 'name' => '', 'brand' => '', 'species_id' => '', 'food_form' => '',
        'unit_weight' => '', 'weight_unit' => 'lb'];
$errors = [];
$barcodeValues = ['code' => '', 'barcode' => null, 'reason' => '', 'to_product_id' => null]; // the barcode row last submitted
$confirmDeactivate = null;

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action') ?? 'save';
    $here = $product !== null ? 'inventory_product_edit.php?id=' . (int) $product['product_id'] : null;
    try {
        if ($action === 'save') {
            $values = Request::only(ProductService::FIELDS);
            if ($product === null) {
                $id = ProductService::create($values, $ctx->userId());
                Flash::success('Product added. It can now be received and counted at every site; link its barcodes below.');
                Response::redirect('inventory_product_edit.php?id=' . $id);
            }
            ProductService::update((int) $product['product_id'], $values, Request::string('revision') ?? '', $ctx->userId());
            Flash::success('Product saved.');
            Response::redirect($here ?? 'inventory_catalogue.php');
        }
        if ($product === null) {
            Response::badRequest();
        }
        switch ($action) {
            case 'activate':
            case 'deactivate':
                ProductService::setActive((int) $product['product_id'], $action === 'activate', Request::bool('confirm'), $ctx->userId());
                Flash::success($action === 'activate' ? 'Product reactivated.' : 'Product deactivated. It no longer appears on new receipts or barcode links.');
                break;
            case 'link':
                $barcodeValues['code'] = Request::string('code') ?? '';
                $result = BarcodeService::link($barcodeValues['code'], (int) $product['product_id'], $ctx->userId());
                Flash::success($result['already'] ? 'That barcode was already linked to this product.' : 'Barcode ' . Barcode::display($result['code']) . ' linked.');
                if ($result['store_specific']) {
                    Flash::info('This is an in-store code: another shop may use it for a different product.');
                }
                break;
            case 'unlink':
            case 'move':
                $barcodeValues = ['barcode' => Request::string('barcode') ?? '', 'reason' => Request::string('reason') ?? '',
                    'to_product_id' => Request::int('to_product_id')] + $barcodeValues;
                if ($action === 'unlink') {
                    BarcodeService::unlink($barcodeValues['barcode'], (int) $product['product_id'], $barcodeValues['reason'], $ctx->userId());
                    Flash::success('Barcode removed.');
                } else {
                    BarcodeService::move($barcodeValues['barcode'], (int) $product['product_id'], $barcodeValues['to_product_id'] ?? 0, $barcodeValues['reason'], $ctx->userId());
                    Flash::success('Barcode moved.');
                }
                break;
            default:
                Response::badRequest();
        }
        Response::redirect($here ?? 'inventory_catalogue.php');
    } catch (StaleFormException $e) {
        Flash::error($e->getMessage());
        Response::redirect($here ?? 'inventory_catalogue.php');
    } catch (ValidationException $e) {
        $errors = $e->errors;
        if (isset($errors['confirm'])) {
            $confirmDeactivate = $errors['confirm'];
            unset($errors['confirm']);
        }
    }
}

$currentCategory = $product !== null ? (int) $product['category_id'] : null;
View::render('pages/inventory/product_edit', [
    'title' => $product ? 'Edit product' : 'Add a product', 'ctx' => $ctx, 'product' => $product, 'values' => $values, 'errors' => $errors,
    'barcodeValues' => $barcodeValues, 'confirmDeactivate' => $confirmDeactivate,
    'revision' => $product ? ProductService::revision($product) : '',
    'categories' => ItemCategoryRepository::choices($currentCategory),
    'species' => SpeciesRepository::choices($product !== null ? (int) $product['species_id'] : null),
    'used' => $product !== null && ProductRepository::isUsed((int) $product['product_id']),
    'barcodes' => $product !== null ? ProductRepository::barcodes((int) $product['product_id']) : [],
    'holdings' => $product !== null ? StockRepository::holdings((int) $product['product_id']) : [],
    'moveTargets' => $product !== null ? array_values(array_filter(ProductRepository::choices(), fn($p) => (int) $p['product_id'] !== (int) $product['product_id'])) : [],
]);
