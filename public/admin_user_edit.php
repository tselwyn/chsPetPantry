<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Account\AccountRepository;
use Pfpms\Account\AccountService;
use Pfpms\Account\StaleAccountException;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\SiteRepository;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// UC-11: edit an account (details, role, sites, dates) and its status actions:
// resend invitation, password reset, unlock, deactivate, reactivate (§3.2.1–3.2.3, §3.3.5).
$ctx = Page::start(['capability' => 'user.manage']);
$userId = Request::int('id', fromQuery: true) ?? Request::int('user_id') ?? Response::notFound();
$account = AccountRepository::find($userId) ?? Response::notFound();
$selfEdit = $userId === $ctx->userId();
$values = $account;
$heldSites = AccountRepository::standingSiteIds($userId);
$selectedSites = $heldSites;
$errors = [];
$deactivateValues = ['deactivate_reason' => '', 'effective_date' => ''];
$reactivateReason = '';
$mailFailed = 'The email could not be sent just now. It will be retried automatically; if it is urgent, print a sheet instead.';

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action');
    try {
        switch ($action) {
            case 'save':
                $values = Request::only([...AccountService::DETAIL_FIELDS, 'reason']) + $account;
                $values['can_extract_identifiable'] = Request::bool('can_extract_identifiable') ? 1 : 0;
                $selectedSites = array_map('intval', Request::array('sites'));
                if ($selfEdit) {
                    // Those fields are disabled on the form (disabled fields are not submitted); keep the stored values.
                    foreach (['email', 'role', 'start_date', 'expiry_date', 'can_extract_identifiable'] as $locked) {
                        $values[$locked] = $account[$locked];
                    }
                    $selectedSites = $heldSites;
                }
                $result = AccountService::update($userId, $values, $selectedSites, Request::int('row_version') ?? -1, $ctx->userId());
                $keys = array_keys($result['changes']);
                Flash::success(!$keys ? 'Nothing was changed.' : 'Account saved.'
                    . (array_intersect($keys, ['role', 'site_ids', 'email', 'start_date', 'expiry_date', 'can_extract_identifiable'])
                        ? ' Their open sessions were signed out so the change takes effect now.' : '')
                    . (in_array('email', $keys, true) ? ' Links sent to the old email address no longer work.' : '')
                    . ($result['mailed'] === true ? ' A new invitation was sent to the new address.' : ''));
                if ($result['mailed'] === false) {
                    Flash::error('The new invitation could not be sent just now. ' . $mailFailed);
                }
                break;
            case 'resend':
                if (AccountService::resendInvitation($userId, $ctx->userId())) {
                    Flash::success('A new invitation was sent to ' . $account['email'] . '. Earlier links no longer work.');
                } else {
                    Flash::error('A new invitation was created, and earlier links no longer work. ' . $mailFailed);
                }
                break;
            case 'reset':
                if (AccountService::sendReset($userId, $ctx->userId())) {
                    Flash::success('Their old password no longer works, and a link to choose a new one was sent to ' . $account['email'] . '.');
                } else {
                    Flash::error('Their old password no longer works. ' . $mailFailed);
                }
                break;
            case 'unlock':
                if (AccountService::unlock($userId, $ctx->userId())) {
                    Flash::success('Account unlocked. The person has been told by email.');
                } else {
                    Flash::success('Account unlocked. The email telling them could not be sent just now; it will be retried automatically.');
                }
                break;
            case 'deactivate':
                $deactivateValues = ['deactivate_reason' => Request::string('deactivate_reason') ?? '', 'effective_date' => Request::string('effective_date') ?? ''];
                $now = AccountService::deactivate($userId, $deactivateValues['deactivate_reason'], $deactivateValues['effective_date'], $ctx->userId());
                Flash::success($now
                    ? 'Account deactivated and signed out everywhere. Everything the person recorded stays in the history.'
                    : 'Deactivation scheduled. The person can sign in until then; from that date they cannot.');
                break;
            case 'reactivate':
                $reactivateReason = Request::string('reactivate_reason') ?? '';
                $mailed = AccountService::reactivate($userId, $reactivateReason, $ctx->userId());
                Flash::success($account['status'] === 'Inactive' ? 'Account reactivated.' : 'The scheduled deactivation was cancelled.');
                if ($mailed === true) {
                    Flash::info('They had not activated yet, so a new invitation was sent.');
                } elseif ($mailed === false) {
                    Flash::error('They had not activated yet, so a new invitation was created. ' . $mailFailed);
                }
                break;
            default:
                Response::badRequest();
        }
        Response::redirect('admin_user_edit.php?id=' . $userId);
    } catch (StaleAccountException $e) {
        Flash::error($e->getMessage() . ' The latest version is shown; please make your change again.');
        Response::redirect('admin_user_edit.php?id=' . $userId);
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

// Active sites, plus any closed site the person still holds (so an unrelated save does not take it away).
$sites = array_values(array_filter(SiteRepository::all(), fn($s) => (bool) $s['is_active'] || in_array((int) $s['site_id'], $heldSites, true)));

View::render('pages/admin/user_edit', [
    'title' => 'Edit account', 'ctx' => $ctx, 'account' => $account, 'values' => $values, 'errors' => $errors,
    'selectedSites' => $selectedSites, 'selfEdit' => $selfEdit, 'deactivateValues' => $deactivateValues, 'reactivateReason' => $reactivateReason,
    'temporaryGrants' => AccountRepository::temporaryGrants($userId), 'sites' => $sites,
]);
