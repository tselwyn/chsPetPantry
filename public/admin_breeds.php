<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\BreedRepository;
use Pfpms\Reference\BreedService;
use Pfpms\Reference\SpeciesRepository;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Breeds of one species (?species_id=): list, add, rename, activate and deactivate (plan P2A, US-13).
$ctx = Page::start(['capability' => 'lookup.manage']);
$allSpecies = SpeciesRepository::all();
$speciesId = Request::int('species_id', fromQuery: true) ?? Request::int('species_id') ?? ($allSpecies ? (int) $allSpecies[0]['species_id'] : null);
$species = $speciesId !== null ? (SpeciesRepository::find($speciesId) ?? Response::notFound()) : null;
$editId = Request::int('edit', fromQuery: true);
$addValues = ['name' => ''];
$addErrors = [];
$editValue = null;
$editErrors = [];

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action');
    $breedId = Request::int('breed_id');
    $back = 'admin_breeds.php' . ($species ? '?species_id=' . (int) $species['species_id'] : '');
    try {
        if ($action === 'add' && $species !== null) {
            BreedService::create((int) $species['species_id'], ['name' => Request::string('name')]);
            Flash::success('Breed added.');
        } elseif ($action === 'rename' && $breedId !== null) {
            BreedService::rename($breedId, ['name' => Request::string('edit_name')]);
            Flash::success('Breed renamed.');
        } elseif (in_array($action, ['activate', 'deactivate'], true) && $breedId !== null) {
            BreedService::setActive($breedId, $action === 'activate');
            Flash::success($action === 'activate' ? 'Breed activated.'
                : 'Breed deactivated. Pets already recorded keep it, but it is no longer offered for new pets.');
        } else {
            Response::badRequest();
        }
        Response::redirect($back);
    } catch (ValidationException $e) {
        if ($action === 'add' && isset($e->errors['name'])) {
            $addValues = ['name' => Request::string('name')];
            $addErrors = $e->errors;
        } elseif ($action === 'rename' && isset($e->errors['name'])) {
            $editId = $breedId;
            $editValue = Request::string('edit_name');
            $editErrors = ['edit_name' => $e->errors['name']];
        } else {
            Flash::error($e->getMessage());
            Response::redirect($back);
        }
    }
}

View::render('pages/admin/breeds', [
    'title' => $species ? 'Breeds of ' . $species['name'] : 'Breeds', 'ctx' => $ctx, 'allSpecies' => $allSpecies, 'species' => $species,
    'breeds' => $species ? BreedRepository::forSpecies((int) $species['species_id']) : [],
    'addValues' => $addValues, 'addErrors' => $addErrors, 'editId' => $editId, 'editValue' => $editValue, 'editErrors' => $editErrors,
]);
