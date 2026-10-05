<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\ServiceAreaRepository;
use Pfpms\Reference\ServiceAreaService;
use Pfpms\Reference\SiteRepository;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Service area ZIP codes (UC-03 §4.3): bulk add, nearest site, activate and deactivate (plan P2A).
$ctx = Page::start(['capability' => 'service_area.manage']);
$values = ['postal_codes' => '', 'site_id' => ''];
$errors = [];

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action');
    $zips = static fn(int $n): string => $n === 1 ? '1 ZIP code' : "$n ZIP codes";
    try {
        if ($action === 'add') {
            $values = ['postal_codes' => (string) Request::string('postal_codes'), 'site_id' => (string) Request::string('site_id')];
            $result = ServiceAreaService::bulkAdd($values['postal_codes'], Request::int('site_id'));
            $done = array_filter([
                $result['added'] ? 'Added ' . $zips(count($result['added'])) . '.' : '',
                $result['reactivated'] ? 'Reactivated ' . $zips(count($result['reactivated'])) . ' that had been deactivated.' : '',
                $result['existing'] ? $zips(count($result['existing'])) . (count($result['existing']) === 1 ? ' was' : ' were') . ' already in the service area.' : '',
            ]);
            if ($done) {
                Flash::success(implode(' ', $done));
            }
            if (!$result['invalid']) {
                Response::redirect('admin_service_area.php');
            }
            // Keep only the entries that need correcting in the box; the valid ones are saved.
            $errors['postal_codes'] = 'These are not 5-digit ZIP codes, so they were not added: ' . implode(', ', $result['invalid']) . '. Correct them and add them again.';
            $values['postal_codes'] = implode("\n", $result['invalid']);
        } else {
            $postalCode = Request::string('postal_code');
            if ($postalCode === null || !in_array($action, ['set_site', 'activate', 'deactivate'], true)) {
                Response::badRequest();
            }
            if ($action === 'set_site') {
                ServiceAreaService::setSite($postalCode, Request::int('site_id'));
                Flash::success("Nearest site saved for $postalCode.");
            } else {
                ServiceAreaService::setActive($postalCode, $action === 'activate');
                Flash::success($action === 'activate'
                    ? "$postalCode is in the service area again."
                    : "$postalCode is no longer in the service area. New registrations from it will be treated as outside the area.");
            }
            Response::redirect('admin_service_area.php');
        }
    } catch (ValidationException $e) {
        if ($action === 'add') {
            $errors = $e->errors;
        } else {
            Flash::error($e->getMessage());
            Response::redirect('admin_service_area.php');
        }
    }
}

View::render('pages/admin/service_area', [
    'title' => 'Service area ZIP codes', 'ctx' => $ctx, 'values' => $values, 'errors' => $errors,
    'postalCodes' => ServiceAreaRepository::all(),
    'sites' => array_values(array_filter(SiteRepository::all(), fn(array $s): bool => (bool) $s['is_active'])),
]);
