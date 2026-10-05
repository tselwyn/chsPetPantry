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

// Add or edit a distribution site. ?id= edits; no id adds.
$ctx = Page::start(['capability' => 'site.manage']);
$siteId = Request::int('id', fromQuery: true) ?? Request::int('site_id');
$site = $siteId !== null ? (SiteRepository::find($siteId) ?? Response::notFound()) : null;
$values = $site ?? ['name' => '', 'street_address' => '', 'city' => '', 'state' => '', 'postal_code' => '', 'time_zone' => 'America/New_York'];
$errors = [];

if (Request::isPost()) {
    Csrf::verify();
    $values = Request::only(SiteService::FIELDS);
    try {
        if ($site === null) {
            $siteId = SiteService::create($values);
            Flash::success('Site added.');
        } else {
            SiteService::update((int) $site['site_id'], $values);
            Flash::success('Site saved.');
        }
        Response::redirect('admin_sites.php');
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

View::render('pages/admin/site_edit', [
    'title' => $site ? 'Edit site' : 'Add a site', 'ctx' => $ctx, 'site' => $site, 'values' => $values, 'errors' => $errors,
    'zones' => array_values(array_filter(DateTimeZone::listIdentifiers(), fn($z) => str_starts_with($z, 'America/') || str_starts_with($z, 'Pacific/Honolulu'))),
]);
