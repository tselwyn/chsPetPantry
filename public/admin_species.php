<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\SpeciesRepository;
use Pfpms\Reference\SpeciesService;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Species: list, add, rename, activate and deactivate (plan P2A, US-13). ?edit= renames one.
$ctx = Page::start(['capability' => 'lookup.manage']);
$editId = Request::int('edit', fromQuery: true);
$addValues = ['name' => ''];
$addErrors = [];
$editValue = null;
$editErrors = [];

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action');
    $speciesId = Request::int('species_id');
    try {
        if ($action === 'add') {
            SpeciesService::create(['name' => Request::string('name')]);
            Flash::success('Species added.');
        } elseif ($action === 'rename' && $speciesId !== null) {
            SpeciesService::rename($speciesId, ['name' => Request::string('edit_name')]);
            Flash::success('Species renamed.');
        } elseif (in_array($action, ['activate', 'deactivate'], true) && $speciesId !== null) {
            SpeciesService::setActive($speciesId, $action === 'activate');
            Flash::success($action === 'activate' ? 'Species activated.'
                : 'Species deactivated. Pets already recorded keep it, but it can no longer be chosen for new records.');
        } else {
            Response::badRequest();
        }
        Response::redirect('admin_species.php');
    } catch (ValidationException $e) {
        if ($action === 'add') {
            $addValues = ['name' => Request::string('name')];
            $addErrors = $e->errors;
        } elseif ($action === 'rename' && isset($e->errors['name'])) {
            $editId = $speciesId;
            $editValue = Request::string('edit_name');
            $editErrors = ['edit_name' => $e->errors['name']];
        } else {
            Flash::error($e->getMessage());
            Response::redirect('admin_species.php');
        }
    }
}

View::render('pages/admin/species', [
    'title' => 'Species', 'ctx' => $ctx, 'species' => SpeciesRepository::all(),
    'addValues' => $addValues, 'addErrors' => $addErrors, 'editId' => $editId, 'editValue' => $editValue, 'editErrors' => $editErrors,
]);
