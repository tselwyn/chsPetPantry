<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\SizeBandRepository;
use Pfpms\Reference\SizeBandService;
use Pfpms\Reference\SpeciesRepository;
use Pfpms\Security\Csrf;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Add or edit a size band and its picture. ?id= edits; ?species_id= picks the species of a new band.
$ctx = Page::start(['capability' => 'lookup.manage']);
$sizeBandId = Request::int('id', fromQuery: true) ?? Request::int('size_band_id');
$band = $sizeBandId !== null ? (SizeBandRepository::find($sizeBandId) ?? Response::notFound()) : null;
$values = $band
    ? ['species_id' => (string) $band['species_id'], 'name' => $band['name'], 'min_weight_lbs' => SizeBandService::formatWeight($band['min_weight_lbs']),
        'max_weight_lbs' => $band['max_weight_lbs'] === null ? '' : SizeBandService::formatWeight($band['max_weight_lbs'])]
    : ['species_id' => (string) Request::int('species_id', fromQuery: true), 'name' => '', 'min_weight_lbs' => '', 'max_weight_lbs' => ''];
$errors = [];
$pictureChosen = false;
$removePicture = false;

if (Request::isPost()) {
    Csrf::verify();
    $values = Request::only(SizeBandService::FIELDS);
    $removePicture = Request::bool('remove_picture');
    try {
        $picture = SizeBandService::readUpload($_FILES['picture'] ?? null);
        $pictureChosen = $picture !== null;
        if ($band === null) {
            SizeBandService::create($values, $picture);
            Flash::success('Size band added.');
            Flash::info('Pets can be put in this size band once an allotment version that includes it has started. Add it under Setup > Allotment rules.');
        } else {
            $values['species_id'] = (string) $band['species_id'];
            SizeBandService::update((int) $band['size_band_id'], $values, $picture, $removePicture);
            Flash::success('Size band saved.');
        }
        Response::redirect('admin_size_bands.php');
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

View::render('pages/admin/size_band_edit', [
    'title' => $band ? 'Edit size band' : 'Add a size band', 'ctx' => $ctx, 'band' => $band, 'values' => $values, 'errors' => $errors,
    'species' => $band ? SpeciesRepository::find((int) $band['species_id']) : null,
    'speciesChoices' => SpeciesRepository::choices(is_numeric($values['species_id'] ?? null) ? (int) $values['species_id'] : null),
    'pictureChosen' => $pictureChosen, 'removePicture' => $removePicture, 'maxMb' => max(1, Settings::int('photo_max_mb', 5)),
]);
