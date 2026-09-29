<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\LookupRepository;
use Pfpms\Reference\LookupService;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Choice lists (lookup_value): add values, change wording, reorder, activate and deactivate (plan P2A). ?list= picks the list; &edit= changes a value's wording.
$ctx = Page::start(['capability' => 'lookup.manage']);
$listKey = (Request::isPost() ? Request::string('list') : Request::query('list')) ?? (string) array_key_first(LookupService::LISTS);
if (!LookupService::isList($listKey)) {
    Response::notFound();
}
$lookupId = Request::isPost() ? Request::int('lookup_id') : Request::int('edit', fromQuery: true);
$target = $lookupId !== null ? LookupRepository::find($lookupId) : null;
if ($lookupId !== null && ($target === null || $target['list_key'] !== $listKey)) {
    Response::notFound();
}
$action = Request::isPost() ? Request::string('action') : ($target ? 'rename' : 'add');
$editing = $action === 'rename' ? $target : null;
$values = ['label' => $editing['label'] ?? '', 'species_id' => ''];
$errors = [];

if (Request::isPost()) {
    Csrf::verify();
    try {
        if ($action === 'add') {
            $values = Request::only(['label', 'species_id']);
            LookupService::create($listKey, $values);
            Flash::success('Value added to the end of the list. Use "Move up" to change its place.');
        } elseif ($target === null) {
            Response::badRequest();
        } elseif ($action === 'rename') {
            $values = Request::only(['label']) + $values;
            LookupService::rename((int) $target['lookup_id'], $values);
            Flash::success('Wording saved. Records that already use this value now show the new wording.');
        } elseif ($action === 'up' || $action === 'down') {
            LookupService::move((int) $target['lookup_id'], $action);
        } elseif ($action === 'activate' || $action === 'deactivate') {
            LookupService::setActive((int) $target['lookup_id'], $action === 'activate');
            Flash::success($action === 'activate' ? 'Value activated. It is offered on forms again.'
                : 'Value deactivated. It is no longer offered on forms; records that already use it keep it.');
        } else {
            Response::badRequest();
        }
        Response::redirect('admin_lookups.php?list=' . $listKey);
    } catch (ValidationException $e) {
        if ($action !== 'add' && $action !== 'rename') {
            Flash::error($e->getMessage());
            Response::redirect('admin_lookups.php?list=' . $listKey);
        }
        $errors = $e->errors;
    }
}

View::render('pages/admin/lookups', [
    'title' => 'Choice lists', 'ctx' => $ctx, 'lists' => LookupService::LISTS, 'listKey' => $listKey,
    'speciesSpecific' => LookupService::isSpeciesSpecific($listKey), 'rows' => LookupRepository::forList($listKey),
    'species' => LookupRepository::species(), 'editing' => $editing, 'values' => $values, 'errors' => $errors,
]);
