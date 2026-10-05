<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\ClinicRepository;
use Pfpms\Reference\ClinicService;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Add or edit a clinic in the directory. ?id= edits; no id adds.
$ctx = Page::start(['capability' => 'clinic.manage']);
$clinicId = Request::int('id', fromQuery: true) ?? Request::int('clinic_id');
$clinic = $clinicId !== null ? (ClinicRepository::find($clinicId) ?? Response::notFound()) : null;
$values = $clinic ?? ['is_partner' => 1, 'offers_low_cost_vaccination' => 0] + array_fill_keys(ClinicService::FIELDS, '');
if ($clinic !== null) {
    $values['phone'] = ClinicService::formatPhone($clinic['phone']);
}
$errors = [];

if (Request::isPost()) {
    Csrf::verify();
    $values = Request::only(ClinicService::FIELDS);
    try {
        if ($clinic === null) {
            ClinicService::create($values);
            Flash::success('Clinic added.');
        } else {
            ClinicService::update((int) $clinic['clinic_id'], $values);
            Flash::success('Clinic saved.');
        }
        Response::redirect('admin_clinics.php');
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

View::render('pages/admin/clinic_edit', [
    'title' => $clinic ? 'Edit clinic' : 'Add a clinic', 'ctx' => $ctx, 'clinic' => $clinic, 'values' => $values, 'errors' => $errors,
]);
