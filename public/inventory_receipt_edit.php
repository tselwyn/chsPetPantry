<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Http\Flash;
use Pfpms\Http\FormOnce;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ReceiptRepository;
use Pfpms\Inventory\ReceiptService;
use Pfpms\Inventory\StaleFormException;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Record goods received (enter the lines, review them, post), or with ?id= look at a receipt:
// correct its name, date or notes, void lines, or add lines that were missed (plan P2A; legacy
// viewAddPallet / viewModifyPallet). Stock changes only when a receipt is posted or voided.
$ctx = Page::start(['capability' => 'inventory.receive', 'site' => true]);
$siteId = (int) $ctx->siteId;
$receiptId = Request::int('id', fromQuery: true) ?? Request::int('receipt_id');
$receipt = null;
if ($receiptId !== null) {
    $receipt = ReceiptRepository::find($receiptId) ?? Response::notFound();
    if (!in_array((int) $receipt['site_id'], $ctx->siteIds(), true)) {
        Audit::durable('access_denied', 'stock_receipt', (int) $receipt['receipt_id'], 'Denied', 'Record at a site not available to this user', ['site_id' => (int) $receipt['site_id']]);
        Response::notFound();
    }
    if ((int) $receipt['site_id'] !== $siteId) {
        View::render('pages/inventory/other_site', ['title' => 'Receipt at another site', 'ctx' => $ctx, 'what' => 'Receipt ' . $receipt['name'],
            'siteId' => (int) $receipt['site_id'], 'siteName' => $receipt['site_name'], 'next' => 'inventory_receipt_edit.php?id=' . (int) $receipt['receipt_id']]);
        exit;
    }
}
$here = $receipt !== null ? 'inventory_receipt_edit.php?id=' . (int) $receipt['receipt_id'] : 'inventory_receipt_edit.php';

$maxRows = 100; // rows on one receipt form
$rowCount = min($maxRows, max(1, Request::int('row_count') ?? ($receipt !== null ? 3 : ReceiptService::BLANK_ROWS)));
$rows = [];
foreach (['product' => 'product_id', 'cases' => 'cases', 'units' => 'units', 'expiration' => 'expiration'] as $input => $key) {
    foreach (Request::array($input) as $n => $value) {
        if (ctype_digit((string) $n) && (int) $n < $maxRows) {
            $rows[(int) $n][$key] = $value;
        }
    }
}
ksort($rows);
$header = $receipt === null
    ? ['name' => Request::string('name') ?? '', 'received_on' => Request::string('received_on') ?? Clock::localDate($ctx->site()['time_zone'] ?? 'America/New_York'),
        'notes' => Request::string('notes') ?? '']
    : ['name' => $receipt['name'], 'received_on' => $receipt['received_on'], 'notes' => $receipt['notes'] ?? ''];
$decisions = Request::array('decision');
$stage = 'entry';
$plan = null;
$errors = [];
$headerErrors = [];
$voidErrors = [];
$voidValues = ['reason' => '', 'line_ids' => []];
$postKey = Request::string('post_key');

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action') ?? '';
    if ($receipt === null && in_array($action, ['review', 'post'], true) && Request::int('site_id') !== $siteId) {
        // The session moved to another site (another tab, or a temporary grant ended) after the form was opened.
        $action = 'change';
        $errors['_form'] = 'This receipt was started at another site. You are now working at ' . ($ctx->site()['name'] ?? 'this site')
            . ': nothing was posted. Check it, then review it again to receive the goods here.';
    }
    if ($action === 'post' && ($done = FormOnce::done($postKey)) !== null) {
        Flash::info('These goods were already posted.');
        Response::redirect($done);
    }
    try {
        switch ($action) {
            case 'more':
                $rowCount = min($maxRows, max($rowCount, count($rows) ? max(array_keys($rows)) + 1 : 0) + 10);
                break;
            case 'review':
                $plan = ReceiptService::prepare($siteId, $header, $rows, $receipt);
                $stage = 'review';
                $postKey = FormOnce::issue();
                break;
            case 'change':
                break;
            case 'post':
                $reviewed = Request::string('reviewed') ?? '';
                try {
                    if ($receipt === null) {
                        $id = ReceiptService::create($siteId, $header, $rows, $decisions, $ctx->userId(), $reviewed);
                        FormOnce::remember($postKey, 'inventory_receipt_edit.php?id=' . $id);
                        Flash::success('Receipt posted: the goods are now in stock.');
                        Response::redirect('inventory_receipt_edit.php?id=' . $id);
                    }
                    ReceiptService::addLines((int) $receipt['receipt_id'], $siteId, $rows, $decisions, $ctx->userId(), $reviewed);
                    FormOnce::remember($postKey, $here);
                    Flash::success('Lines added to the receipt and to stock.');
                    Response::redirect($here);
                } catch (StaleFormException $e) {
                    // A count was posted or a case size changed since the review: check again, and answer again.
                    $errors = ['_form' => $e->getMessage()];
                    $decisions = [];
                } catch (ValidationException $e) {
                    $errors = $e->errors; // the site was busy, or a question is unanswered
                }
                // Show the review again while the lines are still valid.
                $plan = ReceiptService::prepare($siteId, $header, $rows, $receipt);
                $stage = 'review';
                break;
            case 'header':
                if ($receipt === null) {
                    Response::badRequest();
                }
                $input = Request::only(ReceiptService::HEADER_FIELDS);
                try {
                    ReceiptService::updateHeader((int) $receipt['receipt_id'], $siteId, $input, Request::string('revision') ?? '', $ctx->userId());
                } catch (ValidationException $e) {
                    $headerErrors = $e->errors;
                    $header = ['name' => $input['name'] ?? '', 'received_on' => $input['received_on'] ?? '', 'notes' => $input['notes'] ?? ''];
                    break;
                }
                Flash::success('Receipt details saved.');
                Response::redirect($here);
            case 'void_lines':
            case 'void_all':
                if ($receipt === null) {
                    Response::badRequest();
                }
                $voidValues = ['reason' => Request::string('reason') ?? '', 'line_ids' => array_values(array_map('intval', Request::array('line_ids')))];
                if ($action === 'void_lines' && !$voidValues['line_ids']) {
                    $voidErrors['_form'] = 'Tick the lines to void.';
                    break;
                }
                try {
                    ReceiptService::void((int) $receipt['receipt_id'], $siteId, $action === 'void_all' ? null : $voidValues['line_ids'], $voidValues['reason'], $ctx->userId());
                } catch (ValidationException $e) {
                    $voidErrors = $e->errors;
                    break;
                }
                Flash::success($action === 'void_all' ? 'Receipt voided.' : 'Lines voided.');
                Response::redirect($here);
            default:
                Response::badRequest();
        }
    } catch (StaleFormException $e) {
        Flash::error($e->getMessage());
        Response::redirect($here);
    } catch (ValidationException $e) {
        $errors = $e->errors + $errors;
        $stage = 'entry';
    }
}

$receiptLines = $receipt !== null ? ReceiptRepository::lines((int) $receipt['receipt_id']) : [];
View::render('pages/inventory/receipt_edit', [
    'title' => $receipt !== null ? 'Receipt ' . $receipt['name'] : 'Record goods received', 'ctx' => $ctx, 'receipt' => $receipt,
    'header' => $header, 'rows' => $rows, 'rowCount' => max($rowCount, count($rows) ? max(array_keys($rows)) + 1 : 0), 'maxRows' => $maxRows,
    'stage' => $stage, 'plan' => $plan, 'decisions' => $decisions, 'errors' => $errors,
    'reviewed' => $plan !== null ? ReceiptService::reviewKey($siteId, $plan) : '', 'postKey' => $postKey ?? '',
    'headerErrors' => $headerErrors, 'voidErrors' => $voidErrors, 'voidValues' => $voidValues,
    'revision' => $receipt !== null ? ReceiptService::revision($receipt) : '',
    'products' => ProductRepository::choices(), 'lines' => $receiptLines,
]);
