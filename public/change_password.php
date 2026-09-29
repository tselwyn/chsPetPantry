<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Auth;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\WebSession;
use Pfpms\Db;
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
    $current = Request::raw('current_password') ?? '';
    $new = Request::raw('new_password') ?? '';
    $confirm = Request::raw('confirm_password') ?? '';
    if (!Auth::verifyPassword($ctx->userId(), $current)) {
        $errors[] = 'The current password is not correct.';
        Audit::record('password_change', 'user_account', $ctx->userId(), 'Failed', 'Wrong current password');
    } elseif ($new !== $confirm) {
        $errors[] = 'The new passwords do not match.';
    } elseif (hash_equals($current, $new)) {
        $errors[] = 'Choose a password different from your current one.';
    } else {
        $errors = PasswordPolicy::check($new, $ctx->user);
    }
    if (!$errors) {
        Db::transaction(function () use ($ctx, $new, $forced): void {
            Auth::setPassword($ctx->userId(), $new, 'Password Reset', $ctx->sessionId);
            Audit::record('password_change', 'user_account', $ctx->userId(), 'Success', $forced ? 'Forced change' : null);
        });
        WebSession::regenerate();
        Flash::success('Your password has been changed.');
        Response::redirect('index.php');
    }
}

View::render('pages/change_password', [
    'title' => 'Change password', 'ctx' => $ctx, 'forced' => $forced, 'errors' => $errors,
    'minLength' => max(8, Settings::int('password_min_length', 12)),
]);
