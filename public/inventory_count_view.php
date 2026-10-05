<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Inventory\CountRepository;
use Pfpms\View\View;

// One posted stock count: what was counted and how much each product's stock changed.
$ctx = Page::start(['capability' => 'inventory.view', 'site' => true]);
$count = CountRepository::find(Request::int('id', fromQuery: true) ?? 0);
if ($count === null) {
    Response::notFound();
}
if (!in_array((int) $count['site_id'], $ctx->siteIds(), true)) {
    Audit::durable('access_denied', 'inventory_count', (int) $count['count_id'], 'Denied', 'Record at a site not available to this user', ['site_id' => (int) $count['site_id']]);
    Response::notFound();
}
if ((int) $count['site_id'] !== $ctx->siteId) {
    View::render('pages/inventory/other_site', ['title' => 'Count at another site', 'ctx' => $ctx, 'what' => 'This stock count',
        'siteId' => (int) $count['site_id'], 'siteName' => $count['site_name'], 'next' => 'inventory_count_view.php?id=' . (int) $count['count_id']]);
    exit;
}

View::render('pages/inventory/count_view', [
    'title' => 'Stock count ' . $count['count_date'], 'ctx' => $ctx, 'count' => $count, 'lines' => CountRepository::lines((int) $count['count_id']),
]);
