<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Auth\SessionStore;
use Pfpms\Auth\WebSession;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\View\View;

// Sign out. Only a POST with a CSRF token ends the session, so a link cannot sign anyone out.
$ctx = Page::start(['public' => true]);
if ($ctx === null) {
    Response::redirect('login.php');
}
if (!Request::isPost()) {
    View::render('pages/logout', ['title' => 'Sign out', 'ctx' => $ctx]);
    exit;
}
Csrf::verify();
SessionStore::end($ctx->sessionId, 'Logout', $ctx->userId());
Audit::record('logout', 'user_account', $ctx->userId());
WebSession::restart();
Flash::info('You have signed out.');
Response::redirect('login.php');
