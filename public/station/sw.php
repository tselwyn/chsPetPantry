<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Pfpms\Config;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\Page;
use Pfpms\Station\StationAssets;
use Pfpms\Station\StationConfig;
use Pfpms\Station\StationShell;

// P2B: the Station's service worker (50-design §7.7), or the kill worker while station.sw_kill is on (D-08).
// Public and sessionless, so no session file or cookie is made for the browser's update checks.
Page::start(['public' => true, 'session' => false]);
if (!StationConfig::swKill() && Config::get('app.maintenance') === true) {
    // A deploy is under way (D-58): the files on disk may be half old and half new, and a worker built from them would
    // verify against those same files and install. A 503 fails the browser's update check, so every tablet keeps the
    // worker it has and tries again later. The kill switch still wins: the kill worker reads no Station file.
    http_response_code(503);
    header('Retry-After: 120');
    exit;
}
StationShell::sendHeaders(true);
echo StationConfig::swKill()
    ? StationShell::KILL_WORKER
    : StationShell::worker(DeviceStatus::currentBuild(), StationAssets::precache(), StationAssets::read(StationAssets::WORKER_CORE));
