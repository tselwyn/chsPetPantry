<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use Pfpms\Auth\WebSession;
use Pfpms\Http\Api;
use Pfpms\Http\Json;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Station\StationAuth;

// P2B S3: the in-app forced password change (50-design §6.9, D-54). Proof required: the answer can release keys.
$ctx = Api::start(['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'password_change' => true, 'policy_ack' => true]);
$body = Request::json();
$result = StationAuth::changePassword($ctx, Api::device(), Json::string($body, 'current_password', 1024) ?? '',
    Json::string($body, 'new_password', 1024) ?? '', Request::ip());
WebSession::regenerate();
Response::json($result + ['csrf' => Csrf::token()]);
