<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Auth;
use Pfpms\Auth\WebSession;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Settings;
use Pfpms\View\View;

// UC-01 §3.2.2 forced change of a temporary or expired password, and voluntary changes.
$ctx = Page::start(['password_change' => true]);
$forced = AccountRules::mustChangePassword($ctx->user);
$errors = [];

if (Request::isPost()) {
    Csrf::verify();
    $errors = Auth::changeOwnPassword($ctx, Request::raw('current_password') ?? '', Request::raw('new_password') ?? '',
        Request::raw('confirm_password') ?? '');
    if ($errors === null) {
        Response::redirect('change_password.php'); // a reset, a deactivation or another change won: Page::start() signs out and says why
    }
    if ($errors === []) {
        WebSession::regenerate();
        Flash::success('Your password has been changed.');
        Response::redirect('index.php');
    }
}

View::render('pages/change_password', [
    'title' => 'Change password', 'ctx' => $ctx, 'forced' => $forced, 'errors' => $errors,
    'minLength' => max(8, Settings::int('password_min_length', 12)),
]);
