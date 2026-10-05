<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Device\DeviceRepository;
use Pfpms\Device\DeviceScope;
use Pfpms\Device\DeviceService;
use Pfpms\Device\DeviceStatus;
use Pfpms\Device\RegistrationCode;
use Pfpms\Device\RegistrationSheet;
use Pfpms\Device\StaleDeviceException;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// A tablet's registration sheet (plan P2A admin_devices), like the printable activation sheet for
// people: the code is created only on POST, shown once, and never stored in readable form. A
// refresh or Back-and-resubmit is refused (the revision), so a printed sheet is never silently
// replaced.
$ctx = Page::start(['capability' => 'device.register']);
$scope = DeviceScope::fromContext($ctx);
$deviceId = Request::int('id', fromQuery: true) ?? Request::int('device_id') ?? Response::notFound();
$device = DeviceRepository::find($deviceId) ?? Response::notFound();
if (!$scope->covers($device['site_id'] === null ? null : (int) $device['site_id'])) {
    Audit::durable('access_denied', 'device', $deviceId, 'Denied', 'Device at a site not available to this user', ['site_id' => $device['site_id']]);
    Response::notFound();
}
$tz = $device['time_zone'] ?? Settings::string('organisation_time_zone', 'America/New_York');
$reload = fn(): array => DeviceRepository::find($deviceId) ?? Response::notFound(); // its current state after a refusal
$sheet = null;
$error = null;

if (Request::isPost()) {
    Csrf::verify();
    try {
        $issued = DeviceService::issueCode($deviceId, Request::string('revision') ?? '', $scope);
        $sheet = [
            'formatted' => RegistrationCode::format($issued['code']), 'qr' => RegistrationSheet::qr($issued['code']), 'expires_at' => $issued['expires_at'],
            'station_url' => absolute_url('station/'), 'created_by' => $ctx->displayName(),
        ];
    } catch (StaleDeviceException) {
        $device = $reload();
        $live = DeviceRepository::liveCode($deviceId);
        $error = $live !== null
            ? 'A registration code was created for this tablet after you opened this page (it works until ' . DeviceStatus::at($live['expires_at'], $tz)
                . '), so no new code was made. If that sheet is lost, create a new one: the earlier code will stop working.'
            : 'This tablet changed since you opened the page, so no code was made. Its current state is shown: check it before trying again.';
    } catch (ValidationException $e) {
        $device = $reload();
        $error = $e->errors['_form'] ?? $e->getMessage();
    }
}

View::render('pages/admin/device_credential', [
    'title' => 'Tablet registration sheet', 'ctx' => $ctx, 'device' => $device, 'sheet' => $sheet, 'error' => $error, 'tz' => $tz,
    'revision' => DeviceService::revision($device), 'liveCode' => DeviceRepository::liveCode($deviceId), 'minutes' => DeviceService::codeMinutes(),
    'redemptionAvailable' => DeviceService::redemptionAvailable(),
]);
