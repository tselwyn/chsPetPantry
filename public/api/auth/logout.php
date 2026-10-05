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

// P2B S3: sign one person out, or End shift / Lock device (50-design §6.8, D-24). 'known': a retiring tablet may end its shift.
$ctx = Api::start(['method' => 'POST', 'device' => 'known', 'public' => true, 'touch' => false]);
Csrf::verify();
$body = Request::json();
$r = StationAuth::logout($ctx, Api::device(), Json::string($body, 'scope', 10), Json::string($body, 'ended_at', 23));
if ($r['ended_current']) {
    WebSession::restart(); // this PHP session's sign-in ended: a fresh anonymous Station session and CSRF token
}                          // else (a replay after someone else signed in, or no session): the PHP session and token stay
Response::json($r['body'] + ['csrf' => Csrf::token()]);
