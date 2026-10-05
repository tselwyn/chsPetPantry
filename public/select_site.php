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

// Choose the site this session works in; only sites the user may use right now are offered.
$ctx = Page::start([]);
$next = Request::safeAppPath(Request::string('next') ?? Request::query('next'));

if (Request::isPost()) {
    Csrf::verify();
    $siteId = Request::int('site_id');
    if ($siteId === null || !in_array($siteId, $ctx->siteIds(), true)) {
        Audit::durable('site_select', 'site', $siteId, 'Denied', 'Site not available to this user');
        Flash::error('You cannot work at that site.');
        Response::redirect('select_site.php');
    }
    $_SESSION['site_id'] = $siteId;
    SessionStore::setSite($ctx->sessionId, $siteId);
    Audit::record('site_select', 'site', $siteId, actor: ['site_id' => $siteId]);
    WebSession::regenerate();
    Response::redirect($next ?? 'index.php');
}

View::render('pages/select_site', ['title' => 'Choose a site', 'ctx' => $ctx, 'next' => $next]);
