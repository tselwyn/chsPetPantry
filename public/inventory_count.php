<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\FormOnce;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Inventory\CountRepository;
use Pfpms\Inventory\CountService;
use Pfpms\Inventory\ItemCategoryRepository;
use Pfpms\Inventory\StaleCountException;
use Pfpms\Security\Csrf;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Stock count at the current site (plan P2A; legacy viewUpdateInventory / editInventoryEvent):
// enter what is on the shelves, review the changes, post. Optionally one category at a time.
$ctx = Page::start(['capability' => 'inventory.count', 'site' => true]);
$siteId = (int) $ctx->siteId;
$categoryId = Request::int('category_id', fromQuery: true) ?? Request::int('category_id');
$category = $categoryId !== null ? (ItemCategoryRepository::find($categoryId) ?? Response::notFound()) : null;
$cases = Request::array('cases');
$units = Request::array('units');
$book = Request::array('book');
$seen = Request::array('seen'); // the stock when the sheet was opened
$stage = 'entry';
$review = null;
$errors = [];
$stale = [];
$postKey = Request::string('post_key');

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action') ?? '';
    if (in_array($action, ['review', 'post'], true) && Request::int('site_id') !== $siteId) {
        // The session moved to another site (another tab, or a temporary grant ended) after the sheet was opened.
        $action = 'change';
        $seen = [];
        $errors['_form'] = 'This count was started at another site. You are now working at ' . ($ctx->site()['name'] ?? 'this site')
            . ': nothing was posted. Check the figures before reviewing again.';
    }
    if ($action === 'post' && ($done = FormOnce::done($postKey)) !== null) {
        Flash::info('This count was already posted.');
        Response::redirect($done);
    }
    try {
        switch ($action) {
            case 'review':
                $review = CountService::review($siteId, $categoryId, $cases, $units, $seen);
                $stage = 'review';
                $postKey = FormOnce::issue();
                break;
            case 'change':
                break;
            case 'post':
                try {
                    $countId = CountService::post($siteId, $categoryId, $cases, $units, $book, $ctx->userId(), Request::string('reviewed') ?? '');
                    FormOnce::remember($postKey, 'inventory_count_view.php?id=' . $countId);
                    Flash::success('Count posted: the stock now matches what was counted.');
                    Response::redirect('inventory_count_view.php?id=' . $countId);
                } catch (StaleCountException $e) {
                    $errors['_form'] = $e->getMessage();
                } catch (ValidationException $e) {
                    $errors = $e->errors;
                }
                $review = CountService::review($siteId, $categoryId, $cases, $units, $seen);
                $stage = 'review';
                // Mark every product whose stock is not what the person saw, whatever stopped the post (a busy site
                // included), so the new figures are never posted unnoticed.
                foreach ($review['lines'] as $line) {
                    if (($book[$line['product_id']] ?? null) !== $line['book']) {
                        $stale[] = $line['product_id'];
                    }
                }
                if ($stale && !str_starts_with($errors['_form'] ?? '', 'Stock changed')) {
                    $errors['_form'] = trim(($errors['_form'] ?? '') . ' Stock changed while you were counting: the new figures are marked.');
                }
                break;
            default:
                Response::badRequest();
        }
    } catch (ValidationException $e) {
        $errors = $e->errors + $errors;
        $stage = 'entry';
    }
}

View::render('pages/inventory/count', [
    'title' => 'Stock count', 'ctx' => $ctx, 'category' => $category, 'categories' => ItemCategoryRepository::all(),
    'sheet' => CountRepository::sheet($siteId, $categoryId), 'cases' => $cases, 'units' => $units, 'seen' => $seen,
    'stage' => $stage, 'review' => $review, 'errors' => $errors, 'stale' => $stale,
    'reviewed' => $review !== null ? CountService::reviewKey($siteId, $categoryId, $review) : '', 'postKey' => $postKey ?? '',
    'openEvent' => CountRepository::openEvent($siteId), 'recent' => CountRepository::recent($siteId, 10),
    'idleMinutes' => max(1, Settings::int('session_idle_minutes', 30)),
]);
