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

// P2B S3: quick switch by PIN on a registered tablet (50-design §6.6, D-24, D-25). Proof required; the answer carries no keys.
$ctx = Api::start(['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'public' => true, 'touch' => false]);
Csrf::verify();
$body = Request::json();
$r = StationAuth::pinSwitch(Json::int($body, 'user_id', 1, 2147483647), Json::string($body, 'pin', 20) ?? '', Api::device(), $ctx, Request::ip());
WebSession::login($r['user_id'], $r['session_id'], $r['site_id']);
Response::json($r['body'] + ['csrf' => Csrf::token()]);
