<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use Pfpms\Device\DeviceRegistration;
use Pfpms\Http\Api;
use Pfpms\Http\Json;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;

// P2B: the installed Station redeems a registration code (40-design §14.3; 50-design §6.3). Anonymous.
Api::start(['method' => 'POST', 'public' => true]);
Csrf::verify(); // Api::start verified it already; kept explicit (40 §14.3 step 1; contract rule)
$body = Request::json();
Response::json(DeviceRegistration::redeem(Json::string($body, 'code', 100), Json::string($body, 'registration_nonce', 40),
    Json::string($body, 'proof_key', 60), [
        'display_mode' => Json::string($body, 'display_mode', 20),
        'storage_persisted' => Json::bool($body, 'storage_persisted') ?? false,
        'app_build' => Json::string($body, 'app_build', 40),
        'pbkdf2_iterations' => Json::int($body, 'pbkdf2_iterations', 100000, 2000000),
    ], Request::ip()));
