<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Account\AccountService;
use Pfpms\Clock;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\SiteRepository;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// UC-11 base flow: invite a person. Nobody types a password; the person chooses it from the emailed link.
$ctx = Page::start(['capability' => 'user.manage']);
$values = ['username' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'role' => 'Volunteer',
    'start_date' => Clock::orgToday(), 'expiry_date' => '', 'onboarding_completed_at' => '', 'can_extract_identifiable' => 0];
$selectedSites = [];
$errors = [];

if (Request::isPost()) {
    Csrf::verify();
    $values = Request::only(['username', ...AccountService::DETAIL_FIELDS]);
    $selectedSites = array_map('intval', Request::array('sites'));
    try {
        $result = AccountService::invite($values, $selectedSites, $ctx->userId());
        if ($result['mailed']) {
            Flash::success('Invitation sent to ' . $values['email'] . '. The link works for ' . AccountService::tokenHours() . ' hours.');
        } else {
            Flash::error('The account was created, but the invitation email could not be sent just now. It will be retried automatically; '
                . 'if the person needs to start sooner, print an activation sheet.');
        }
        Response::redirect('admin_user_edit.php?id=' . $result['user_id']);
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

View::render('pages/admin/user_create', [
    'title' => 'Invite a person', 'ctx' => $ctx, 'values' => $values, 'errors' => $errors, 'selectedSites' => $selectedSites,
    'sites' => array_values(array_filter(SiteRepository::all(), fn($s) => (bool) $s['is_active'])),
]);
