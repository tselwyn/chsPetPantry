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

// Clinic directory: list, suspend and reactivate (plan P2A).
$ctx = Page::start(['capability' => 'clinic.manage']);

if (Request::isPost()) {
    Csrf::verify();
    $clinicId = Request::int('clinic_id');
    $suspend = Request::string('action') === 'suspend';
    try {
        if ($clinicId === null) {
            Response::badRequest();
        }
        ClinicService::setSuspended($clinicId, $suspend);
        Flash::success($suspend ? 'Clinic suspended. It stays on file, but no new referrals can be sent to it until it is reactivated.' : 'Clinic reactivated.');
    } catch (ValidationException $e) {
        Flash::error($e->getMessage());
    }
    Response::redirect('admin_clinics.php');
}

View::render('pages/admin/clinics', ['title' => 'Clinics', 'ctx' => $ctx, 'clinics' => ClinicRepository::all()]);
