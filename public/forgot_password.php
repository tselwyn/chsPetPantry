<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Auth\AccountRules;
use Pfpms\Auth\Tokens;
use Pfpms\Db;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Mail\Mailer;
use Pfpms\Security\Csrf;
use Pfpms\Security\RateLimit;
use Pfpms\Settings;
use Pfpms\Validation\Validator;
use Pfpms\View\View;

// UC-01 §3.2.1: a single-use, time-limited reset link, without revealing whether the address is registered.
Page::start(['public' => true]);
$sent = false;
$error = null;

if (Request::isPost()) {
    Csrf::verify();
    $email = Validator::email(Request::string('email'));
    $ip = Request::ip();
    if ($email === null) {
        $error = 'Enter a valid email address.';
    } elseif (!RateLimit::hit("reset:ip:$ip", 10, 3600) || !RateLimit::hit('reset:email:' . mb_strtolower($email), 3, 3600)) {
        $sent = true; // same answer as success: do not reveal anything
        Audit::record('password_reset_request', 'user_account', null, 'Denied', 'Rate limited', ['ip' => $ip]);
    } else {
        $sent = true;
        $st = Db::pdo()->prepare("SELECT user_id, username, email, first_name, last_name, status, locked_until, start_date, expiry_date,
                                          must_change_password, password_changed_at, deactivation_effective_date
                                     FROM user_account WHERE email = ? AND username <> 'system'");
        $st->execute([$email]);
        $user = $st->fetch();
        if ($user && in_array(AccountRules::blockReason($user), [null, 'locked'], true)) {
            $minutes = max(5, Settings::int('reset_link_minutes', 60));
            $token = Tokens::issue((int) $user['user_id'], Tokens::PASSWORD_RESET, $minutes);
            $link = absolute_url('reset_password.php', ['token' => $token]);
            $org = Settings::string('organisation_name', 'CHS Pet Pantry');
            $body = "Hello {$user['first_name']},\n\nSomeone asked to reset the password for your $org account.\n"
                . "To choose a new password, open this link within $minutes minutes. It works once:\n\n$link\n\n"
                . "If you did not ask for this, you can ignore this email; your password has not changed.\n";
            Mailer::sendAfterResponse($user['email'], "$org: reset your password", $body, 'password_reset', ['user_id' => (int) $user['user_id']]);
            Audit::record('password_reset_request', 'user_account', (int) $user['user_id'], 'Success', null, ['ip' => $ip], actor: ['user_id' => (int) $user['user_id']]);
        } else {
            Audit::record('password_reset_request', 'user_account', $user ? (int) $user['user_id'] : null, 'Denied',
                $user ? 'Account not usable' : 'Unknown email', ['ip' => $ip]);
        }
    }
}

View::render('pages/forgot_password', ['title' => 'Reset password', 'sent' => $sent, 'error' => $error], 'layout/public');
