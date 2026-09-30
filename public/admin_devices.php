<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Device\DeviceRepository;
use Pfpms\Device\DeviceScope;
use Pfpms\Device\DeviceService;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Reference\SiteRepository;
use Pfpms\Settings;
use Pfpms\View\View;

// Tablets that run the Station at the person's sites (plan P2A admin_devices): register, retire,
// erase. Coordinators see their own sites' tablets; Administrators see every tablet.
$ctx = Page::start(['capability' => 'device.register']);
$scope = DeviceScope::fromContext($ctx);
$siteFilter = Request::int('site', fromQuery: true);
if ($siteFilter !== null && !$scope->covers($siteFilter)) {
    $siteFilter = null;
}
$showAll = Request::query('show') === 'all';
$sites = $scope->allSites
    ? array_map(fn($s) => ['site_id' => (int) $s['site_id'], 'name' => $s['name'] . ((int) $s['is_active'] ? '' : ' (inactive)')], SiteRepository::all())
    : array_map(fn($s) => ['site_id' => $s['site_id'], 'name' => $s['name']], $ctx->sites);

$devices = DeviceRepository::list($scope, $siteFilter, $showAll);
View::render('pages/admin/devices', [
    'title' => 'Devices', 'ctx' => $ctx, 'devices' => $devices, 'sites' => $sites,
    // An empty list says "no tablets yet" only when there are none at all, not when the choices hide some.
    'hasHidden' => !$devices && ($siteFilter !== null || !$showAll) && DeviceRepository::list($scope, null, true) !== [],
    'siteFilter' => $siteFilter, 'showAll' => $showAll, 'redemptionAvailable' => DeviceService::redemptionAvailable(),
    'offlineAllowed' => Settings::bool('offline_mode_enabled', true), 'orgZone' => Settings::string('organisation_time_zone', 'America/New_York'),
]);
