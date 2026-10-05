<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use Pfpms\Auth\Pin;
use Pfpms\Http\Api;
use Pfpms\Http\Json;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;

// P2B S3: set or change the PIN of the person signed in on this tablet (50-design §6.7, D-22). Password re-authentication.
$ctx = Api::start(['method' => 'POST', 'device' => 'in_service', 'capability' => 'auth.pin_switch']);
$body = Request::json();
Response::json(Pin::set($ctx, Api::device(), Json::string($body, 'password', 1024) ?? '', Json::string($body, 'pin', 20) ?? '',
    Json::string($body, 'pin_confirm', 20) ?? '') + ['csrf' => Csrf::token()]);
