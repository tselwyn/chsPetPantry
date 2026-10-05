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

// P2B S3: a person signs in with their password on a registered tablet (50-design §6.5, D-16). The tablet's proof is
// required: a successful sign-in can release the tablet's vault key and an offline grant. Never touches the session the
// cookie may still carry (someone else's, about to be ended).
Api::start(['method' => 'POST', 'device' => 'in_service', 'proof' => true, 'public' => true, 'touch' => false]);
Csrf::verify(); // Api::start verified it already; kept explicit (contract rule)
$body = Request::json();
$r = StationAuth::login(Json::string($body, 'identifier', 254), Json::string($body, 'password', 1024), Request::ip(), Api::device());
WebSession::login($r['user_id'], $r['session_id'], $r['site_id']); // new PHP session id, CSRF rotated
Response::json($r['body'] + ['csrf' => Csrf::token()]);
