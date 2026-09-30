<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Device\DeviceRepository;
use Pfpms\Device\DeviceScope;
use Pfpms\Device\DeviceService;
use Pfpms\Device\StaleDeviceException;
use Pfpms\Http\Flash;
use Pfpms\Http\FormOnce;
use Pfpms\Http\HttpException;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Add a tablet (no id), or with ?id= see a tablet and act on it: rename, cancel its registration,
// retire it, or (Administrators) have it erase itself (plan P2A admin_devices).
$ctx = Page::start(['capability' => 'device.register']);
$scope = DeviceScope::fromContext($ctx);
$deviceId = Request::int('id', fromQuery: true) ?? Request::int('device_id');
$errors = [];

if ($deviceId === null) {
    $values = ['site_id' => '', 'label' => ''];
    $preselect = Request::int('site', fromQuery: true);
    if ($preselect === null || !in_array($preselect, $ctx->siteIds(), true)) {
        $preselect = $ctx->siteId ?? (count($ctx->sites) === 1 ? $ctx->sites[0]['site_id'] : null);
    }
    $values['site_id'] = $preselect !== null ? (string) $preselect : '';
    $formKey = Request::string('form_key');
    if (Request::isPost()) {
        Csrf::verify();
        if (($done = FormOnce::done($formKey)) !== null) {
            Response::redirect($done);
        }
        $values = ['site_id' => Request::string('site_id') ?? '', 'label' => Request::string('label') ?? ''];
        try {
            $id = DeviceService::add($values, $scope);
            FormOnce::remember($formKey, "admin_device_credential.php?id=$id");
            Flash::success('Tablet added. Now create its registration sheet.');
            Response::redirect("admin_device_credential.php?id=$id");
        } catch (ValidationException $e) {
            $errors = $e->errors;
        }
    }
    View::render('pages/admin/device_add', ['title' => 'Register a tablet', 'ctx' => $ctx, 'values' => $values, 'errors' => $errors,
        'formKey' => $formKey ?? FormOnce::issue()]);
    exit;
}

$device = DeviceRepository::find($deviceId) ?? Response::notFound();
if (!$scope->covers($device['site_id'] === null ? null : (int) $device['site_id'])) {
    Audit::durable('access_denied', 'device', $deviceId, 'Denied', 'Device at a site not available to this user', ['site_id' => $device['site_id']]);
    Response::notFound();
}
$here = 'admin_device_edit.php?id=' . $deviceId;
$reload = fn(): array => DeviceRepository::find($deviceId) ?? Response::notFound(); // its current state after a refusal
$open = null; // the form to keep open with the typed values after an error
$typed = ['label' => $device['label'], 'reason' => '', 'lost' => false, 'confirm_label' => '', 'lost_reason' => ''];

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action') ?? '';
    $revision = Request::string('revision') ?? '';
    $typed = ['label' => Request::string('label') ?? $device['label'], 'reason' => Request::string('reason') ?? '', 'lost' => Request::bool('lost'),
        'confirm_label' => Request::string('confirm_label') ?? '', 'lost_reason' => Request::string('lost_reason') ?? ''];
    $open = $action;
    $sessionsText = fn(int $n) => $n === 0 ? '' : ($n === 1 ? ' 1 Station session on it was signed out.' : " $n Station sessions on it were signed out.");
    try {
        switch ($action) {
            case 'rename':
                $changed = DeviceService::rename($deviceId, Request::string('label'), $revision, $scope);
                $changed ? Flash::success('Name saved.') : Flash::info('Nothing changed.');
                break;
            case 'cancel':
                DeviceService::cancel($deviceId, $revision, $scope)
                    ? Flash::success('Registration cancelled. Its code no longer works.') : Flash::info('This registration was already cancelled.');
                break;
            case 'retire':
                $result = DeviceService::retire($deviceId, Request::string('reason'), Request::bool('lost'), $revision, $scope);
                if ($result === null) {
                    Flash::info('This tablet had already been taken out of service. Nothing was changed.');
                } elseif ($result['already_retired']) {
                    Flash::success('Someone else had just retired this tablet. It is now also reported lost or stolen: Administrators have been told, and records it still uploads will be held for review.');
                } else {
                    Flash::success('Tablet retired: its registration, PIN switching and offline permissions no longer work.' . $sessionsText($result['sessions_ended'])
                        . ' The next time it connects it will upload any records it still holds, then erase itself.'
                        . ($result['administrators_told'] ? ' Administrators have been told it may be lost or stolen.' : ''));
                }
                break;
            case 'report_lost':
                DeviceService::reportLost($deviceId, Request::string('lost_reason'), $revision, $scope)
                    ? Flash::success('Reported lost or stolen. Administrators have been told, and records it still uploads will be held for review.')
                    : Flash::info('This tablet had already been reported lost or stolen. Nothing was changed.');
                break;
            case 'erase':
                if (!$ctx->can('device.erase')) {
                    Audit::durable('access_denied', 'device', $deviceId, 'Denied', 'Missing capability device.erase');
                    throw new HttpException(403);
                }
                $result = DeviceService::erase($deviceId, Request::string('reason'), Request::string('confirm_label'), Request::int('seen_pending'),
                    Request::bool('pending_ack'), $revision, $scope);
                if ($result === null) {
                    Flash::info('An erase had already been requested for this tablet. Nothing was changed.');
                } else {
                    Flash::success('Erase requested. The next time the tablet connects it will erase itself, including records it has not uploaded.'
                        . $sessionsText($result['sessions_ended'])
                        . ($result['coordinators_told'] ? " The site's Coordinators have been asked to check stock." : ''));
                }
                break;
            default:
                Response::badRequest();
        }
        Response::redirect($here);
    } catch (StaleDeviceException $e) {
        Flash::error($e->getMessage());
        Response::redirect($here);
    } catch (ValidationException $e) {
        $errors = $e->errors;
        $device = $reload();
    }
}

View::render('pages/admin/device_edit', [
    'title' => $device['label'], 'ctx' => $ctx, 'device' => $device, 'errors' => $errors, 'open' => $open, 'typed' => $typed,
    'revision' => DeviceService::revision($device), 'liveCode' => DeviceRepository::liveCode($deviceId),
    'recentUsers' => DeviceRepository::recentUsers($deviceId), 'redemptionAvailable' => DeviceService::redemptionAvailable(),
    'offlineAllowed' => Settings::bool('offline_mode_enabled', true), 'graceHours' => max(1, Settings::int('offline_grant_hours', 72)),
    'orgZone' => Settings::string('organisation_time_zone', 'America/New_York'),
]);
