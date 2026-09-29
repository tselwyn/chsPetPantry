<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Auth\Policy;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\WebSession;
use Pfpms\Db;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\View\View;

// US-03: accept the confidentiality agreement before seeing any participant data; declining signs out.
$ctx = Page::start(['policy_ack' => true]);
$next = Request::safeAppPath(Request::string('next') ?? Request::query('next'));
$doc = Policy::current(Policy::CONFIDENTIALITY);
if ($doc === null || !Policy::acknowledgementRequired($ctx->user)) {
    Response::redirect($next ?? 'index.php');
}

if (Request::isPost()) {
    Csrf::verify();
    // Accept only the exact wording that was on screen: a new version or an edit to this one
    // while the page was open means the person must read it again.
    if (Request::int('document_id') !== (int) $doc['document_id'] || !hash_equals(Policy::fingerprint($doc), Request::string('fingerprint') ?? '')) {
        Flash::info('The agreement was updated while you were reading it. Please read the current version.');
        Response::redirect('policy_ack.php');
    }
    if (Request::string('decision') === 'accept') {
        Db::transaction(function () use ($ctx, $doc): void {
            Policy::acknowledge($ctx->userId(), (int) $doc['document_id']);
            Audit::record('policy_acknowledge', 'policy_document', (int) $doc['document_id'], 'Success', null,
                ['doc_type' => $doc['doc_type'], 'version' => $doc['version'], 'language' => $doc['language_code']]);
        });
        WebSession::regenerate();
        Response::redirect($next ?? 'index.php');
    }
    Audit::record('policy_decline', 'policy_document', (int) $doc['document_id'], 'Denied', 'Declined the confidentiality agreement');
    SessionStore::end($ctx->sessionId, 'Logout', $ctx->userId());
    WebSession::restart();
    Flash::info('You need to accept the confidentiality agreement to use the system. You have been signed out.');
    Response::redirect('login.php');
}

View::render('pages/policy_ack', ['title' => 'Confidentiality agreement', 'ctx' => $ctx, 'doc' => $doc, 'next' => $next]);
