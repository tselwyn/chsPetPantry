<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Inventory\ItemCategoryRepository;
use Pfpms\Inventory\ItemCategoryService;
use Pfpms\Inventory\StaleFormException;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Add or edit a product category, or change whether it is in use. ?id= edits; no id adds.
$ctx = Page::start(['capability' => 'catalog.manage']);
$categoryId = Request::int('id', fromQuery: true) ?? Request::int('category_id');
$category = $categoryId !== null ? (ItemCategoryRepository::find($categoryId) ?? Response::notFound()) : null;
$values = $category ?? ['name' => '', 'is_banana_box' => 0, 'units_per_case' => '1'];
$errors = [];

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action') ?? 'save';
    try {
        if ($action === 'save') {
            $values = Request::only(ItemCategoryService::FIELDS);
            $values['is_banana_box'] = Request::bool('is_banana_box') ? 1 : 0;
            if ($category === null) {
                ItemCategoryService::create($values);
                Flash::success('Category added.');
            } else {
                ItemCategoryService::update((int) $category['category_id'], $values, Request::string('revision') ?? '');
                Flash::success('Category saved.');
            }
            Response::redirect('inventory_catalogue.php');
        } elseif ($category !== null && in_array($action, ['activate', 'deactivate'], true)) {
            ItemCategoryService::setActive((int) $category['category_id'], $action === 'activate');
            Flash::success($action === 'activate' ? 'Category reactivated.' : 'Category deactivated.');
            Response::redirect('inventory_category_edit.php?id=' . (int) $category['category_id']);
        }
        Response::badRequest();
    } catch (StaleFormException $e) {
        Flash::error($e->getMessage());
        Response::redirect('inventory_category_edit.php?id=' . (int) $category['category_id']);
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

View::render('pages/inventory/category_edit', [
    'title' => $category ? 'Edit category' : 'Add a category', 'ctx' => $ctx, 'category' => $category, 'values' => $values, 'errors' => $errors,
    'revision' => $category ? ItemCategoryService::revision($category) : '',
    'maxUnits' => ItemCategoryService::MAX_UNITS_PER_CASE,
]);
