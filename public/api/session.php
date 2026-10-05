<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Pfpms\Http\Api;
use Pfpms\Http\HttpException;
use Pfpms\Http\Json;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Station\SessionInfo;

// P2B: the Station's CSRF token and who is signed in (50-design §6.2). GET never extends the idle timer;
// POST {"action":"touch"} is the Station's activity keep-alive (D-05).
$ctx = Api::start(['method' => ['GET', 'POST'], 'public' => true, 'touch' => Request::method() === 'POST']);
if (Request::method() === 'POST') {
    Csrf::verify();
    if (Json::string(Request::json(), 'action', 20) !== 'touch') {
        throw new HttpException(400, 'Unknown action.', 'bad_request', ['field' => 'action']);
    }
}
Response::json(SessionInfo::describe($ctx));
