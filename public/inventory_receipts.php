<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Inventory\ReceiptRepository;
use Pfpms\Validation\Validator;
use Pfpms\View\View;

// Goods received at the current site (plan P2A; legacy viewManagePallets): newest first, with the
// units and earliest best-before date of the lines that were not voided.
$ctx = Page::start(['capability' => 'inventory.receive', 'site' => true]);
$filters = [
    'from' => Validator::date(Request::query('from')),
    'to' => Validator::date(Request::query('to')),
    'q' => Validator::text(Request::query('q'), 50),
];

View::render('pages/inventory/receipts', [
    'title' => 'Goods received', 'ctx' => $ctx, 'filters' => $filters, 'receipts' => ReceiptRepository::list((int) $ctx->siteId, $filters),
]);
