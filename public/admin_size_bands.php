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
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Size bands of every species, lightest first, with their pictures; deletes unused bands (plan P2A, US-13).
$ctx = Page::start(['capability' => 'lookup.manage']);

if (Request::isPost()) {
    Csrf::verify();
    $sizeBandId = Request::int('size_band_id');
    try {
        if ($sizeBandId === null || Request::string('action') !== 'delete') {
            Response::badRequest();
        }
        SizeBandService::delete($sizeBandId);
        Flash::success('Size band deleted.');
    } catch (ValidationException $e) {
        Flash::error($e->getMessage());
    }
    Response::redirect('admin_size_bands.php');
}

$bandsBySpecies = [];
foreach (SizeBandRepository::all() as $band) {
    $bandsBySpecies[(int) $band['species_id']][] = $band;
}
View::render('pages/admin/size_bands', [
    'title' => 'Size bands', 'ctx' => $ctx, 'species' => SpeciesRepository::all(), 'bandsBySpecies' => $bandsBySpecies,
]);
