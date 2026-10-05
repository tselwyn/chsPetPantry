<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\SiteRepository;
use Pfpms\Reference\SiteService;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Distribution sites: list, activate and deactivate (plan P2A).
$ctx = Page::start(['capability' => 'site.manage']);

if (Request::isPost()) {
    Csrf::verify();
    $siteId = Request::int('site_id');
    $activate = Request::string('action') === 'activate';
    try {
        if ($siteId === null) {
            Response::badRequest();
        }
        SiteService::setActive($siteId, $activate);
        Flash::success($activate ? 'Site activated.' : 'Site deactivated. Its records are kept; nobody can work in it until it is activated again.');
    } catch (ValidationException $e) {
        Flash::error($e->getMessage());
    }
    Response::redirect('admin_sites.php');
}

View::render('pages/admin/sites', ['title' => 'Sites', 'ctx' => $ctx, 'sites' => SiteRepository::all()]);
