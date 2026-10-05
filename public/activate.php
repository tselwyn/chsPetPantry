<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Account\AccountRepository;
use Pfpms\Account\AccountService;
use Pfpms\Auth\Tokens;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Security\RateLimit;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// UC-11 / UC-01 §3.2.2: the person opens their invitation or reset link and chooses their own password.
$ctx = Page::start(['public' => true]);
$raw = Request::isPost() ? (Request::string('token') ?? '') : (Request::query('token') ?? '');
$token = $raw !== '' ? Tokens::find($raw, Tokens::TEMPORARY_CREDENTIAL) : null;
$account = $token ? AccountRepository::find((int) $token['user_id']) : null;
$errors = [];

if ($account !== null && Request::isPost()) {
    Csrf::verify();
    if (!RateLimit::hit('activate:ip:' . Request::ip(), 20, 3600)) {
        $errors['_form'] = 'Too many attempts. Please wait a while and try again.';
    } else {
        try {
            AccountService::activate($raw, Request::raw('new_password') ?? '', Request::raw('confirm_password') ?? '');
            // Someone else signed in on this browser (e.g. an Administrator trying the link) stays signed in.
            $other = $ctx !== null && $ctx->userId() !== (int) $account['user_id'];
            Flash::success($other ? "The password for {$account['username']} is set. You are still signed in as {$ctx->displayName()}." : 'Your password is set. Please sign in.');
            Response::redirect($other ? 'index.php' : 'login.php');
        } catch (ValidationException $e) {
            $errors = $e->errors;
            if (isset($errors['_form'])) {
                $account = null; // the link stopped working (used or expired meanwhile)
            }
        }
    }
}

View::render('pages/activate', [
    'title' => 'Choose your password', 'account' => $account && $account['status'] !== 'Inactive' ? $account : null,
    'token' => $raw, 'errors' => $errors, 'minLength' => max(8, Settings::int('password_min_length', 12)),
], 'layout/public');
