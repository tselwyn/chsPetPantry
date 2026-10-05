<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Allotment\AllotmentRuleRepository;
use Pfpms\Allotment\AllotmentRuleService;
use Pfpms\Allotment\StaleDraftException;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Change the allotment rules (UC-06 §4.5, plan P2A): start a version, edit the draft grid, review
// and publish it with a start date, discard it, or take a scheduled version back to edit.
$ctx = Page::start(['capability' => 'allotment.manage']);
$version = Request::int('version', fromQuery: true) ?? Request::int('version');
$errors = [];
$review = null;
$input = null;
$reason = '';

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action');
    if ($action !== 'start' && $version === null) {
        Response::badRequest();
    }
    try {
        switch ($action) {
            case 'start':
                $version = AllotmentRuleService::startDraft($ctx->userId());
                Flash::success("Draft version $version started. Fill in the pounds, then review and publish it.");
                Response::redirect('admin_allotment_rule_edit.php?version=' . $version);
            case 'save':
            case 'review':
                $input = ['effective_from' => Request::string('effective_from'), 'mode' => Request::array('mode'), 'lbs' => Request::array('lbs')];
                $changes = AllotmentRuleService::saveDraft($version, $input, $ctx->userId(), Request::string('revision') ?? '');
                if ($action === 'save') {
                    Flash::success($changes ? 'Draft saved.' : 'Nothing was changed.');
                    Response::redirect('admin_allotment_rule_edit.php?version=' . $version);
                }
                $input = null; // saved: from here on show the stored figures
                $review = AllotmentRuleService::review($version, null);
                break;
            case 'publish':
                $reason = Request::string('reason') ?? '';
                $from = AllotmentRuleService::publish($version, Request::string('effective_from'), $reason, $ctx->userId(), Request::string('revision') ?? '');
                Flash::success("Version $version published. It is used from " . AllotmentRuleService::longDate($from) . ', by each site\'s local date.');
                Response::redirect('admin_allotment_rules.php?version=' . $version);
            case 'discard':
                AllotmentRuleService::discardDraft($version, $ctx->userId());
                Flash::success("Draft version $version discarded.");
                Response::redirect('admin_allotment_rules.php');
            case 'withdraw':
                $draft = AllotmentRuleService::withdraw($version, $ctx->userId());
                Flash::success("Version $version will not start. Its figures are now draft version $draft: edit them, then publish again.");
                Response::redirect('admin_allotment_rule_edit.php?version=' . $draft);
            default:
                Response::badRequest();
        }
    } catch (StaleDraftException $e) {
        Flash::error($e->getMessage());
        Response::redirect('admin_allotment_rule_edit.php?version=' . $version);
    } catch (ValidationException $e) {
        $errors = $e->errors;
        if (in_array($action, ['start', 'discard', 'withdraw'], true) || AllotmentRuleRepository::draftVersion() !== $version) {
            Flash::error($e->getMessage());
            Response::redirect('admin_allotment_rules.php');
        }
        if ($action === 'publish' && isset($errors['reason'])) {
            // Only the note was wrong: show the review again with what was typed.
            $review = AllotmentRuleService::review($version, Request::string('effective_from'));
        }
    }
}

// Only the draft is edited here; any other version is shown on the rules page.
if ($version === null || AllotmentRuleRepository::draftVersion() !== $version) {
    Response::redirect('admin_allotment_rules.php' . ($version !== null ? '?version=' . $version : ''));
}
$rows = AllotmentRuleRepository::rules($version);

View::render('pages/admin/allotment_rule_edit', [
    'title' => "Allotment rules: draft version $version", 'ctx' => $ctx, 'version' => $version, 'errors' => $errors,
    'grid' => AllotmentRuleService::grid($version), 'input' => $input, 'review' => $review, 'reason' => $reason,
    'revision' => AllotmentRuleService::revision($version), 'origin' => AllotmentRuleService::draftOrigin($version),
    'effectiveFrom' => $input['effective_from'] ?? ($review['effective_from'] ?? $rows[0]['effective_from']),
    'earliestStart' => AllotmentRuleService::earliestStart(),
]);
