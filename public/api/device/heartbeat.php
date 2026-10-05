<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use Pfpms\Device\DeviceHeartbeat;
use Pfpms\Http\Api;
use Pfpms\Http\Request;
use Pfpms\Http\Response;

// P2B: the tablet reports in and learns its directive, before any sign-in (40-design §14.4; 50-design §6.4). Credential (and proof) only.
Api::start(['method' => 'POST', 'device' => 'known', 'session' => false]);
$result = DeviceHeartbeat::receive(Api::device(), Request::json());
Response::json($result['body'], $result['status'], $result['headers']);
