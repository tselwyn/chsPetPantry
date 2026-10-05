<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Auth\Auth;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Auth\Tokens;
use Pfpms\Db;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Settings;
use Pfpms\View\View;

// UC-01 §3.2.1: set a new password from a reset link; the link is used up and every session ends.
Page::start(['public' => true]);
$token = Request::isPost() ? (Request::string('token') ?? '') : (Request::query('token') ?? '');
$row = Tokens::find($token, Tokens::PASSWORD_RESET);
$errors = [];

if ($row !== null && Request::isPost()) {
    Csrf::verify();
    $new = Request::raw('new_password') ?? '';
    $confirm = Request::raw('confirm_password') ?? '';
    $st = Db::pdo()->prepare('SELECT user_id, username, email, first_name, last_name FROM user_account WHERE user_id = ?');
    $st->execute([$row['user_id']]);
    $user = $st->fetch();
    $errors = $new !== $confirm ? ['The passwords do not match.'] : PasswordPolicy::check($new, $user ?: []);
    if (!$errors) {
        $done = Db::transaction(function () use ($row, $new): bool {
            if (!Tokens::consume((int) $row['token_id'])) {
                return false; // used by a concurrent request
            }
            Auth::setPassword((int) $row['user_id'], $new, 'Password Reset');
            Audit::record('password_reset', 'user_account', (int) $row['user_id'], 'Success', null, ['ip' => Request::ip()],
                actor: ['user_id' => (int) $row['user_id']]);
            return true;
        });
        if ($done) {
            Flash::success('Your password has been changed. Please sign in with your new password.');
            Response::redirect('login.php');
        }
        $row = null;
    }
}

View::render('pages/reset_password', [
    'title' => 'Choose a new password', 'valid' => $row !== null, 'token' => $token, 'errors' => $errors,
    'minLength' => max(8, Settings::int('password_min_length', 12)),
], 'layout/public');
