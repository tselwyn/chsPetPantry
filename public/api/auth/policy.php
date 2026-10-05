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

// P2B S3: the in-app confidentiality agreement (50-design §6.10, D-54). Proof required: an acceptance can release keys.
$ctx = Api::start(['method' => ['GET', 'POST'], 'device' => 'in_service', 'proof' => true, 'policy_ack' => true]);
if (Request::method() === 'GET') {
    Response::json(StationAuth::policyDocument($ctx));
}
$body = Request::json();
$r = StationAuth::decidePolicy($ctx, Api::device(), Json::int($body, 'document_id', 1, 2147483647), Json::string($body, 'fingerprint', 64) ?? '',
    Json::string($body, 'decision', 10) ?? '');
if ($r['signed_out']) {
    WebSession::restart();
} else {
    WebSession::regenerate();
}
Response::json($r['body'] + ['csrf' => Csrf::token()]);
