<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Pfpms\Clock;
use Pfpms\Device\DeviceStatus;
use Pfpms\Http\Api;
use Pfpms\Http\Request;
use Pfpms\Http\Response;

// P2B: is the server there, which Station build does it serve, and did the tablet's Authorization header arrive (50-design §6.1).
// No session, no database, never extends an idle timer. The header's value is never read. script_path is what a tablet's
// proof would sign here: bin/station-smoke.php checks it is exactly "api/ping.php" (the base path was found).
Api::start(['method' => 'GET', 'public' => true, 'session' => false]);
Response::json(['ok' => true, 'server_time' => Clock::dbMillis(), 'build' => DeviceStatus::currentBuild(),
    'authorization_received' => Request::authorization() !== null, 'script_path' => Request::scriptPath()]);
