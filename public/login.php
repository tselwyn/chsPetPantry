<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Auth\Auth;
use Pfpms\Auth\WebSession;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\View\View;

// UC-01 Login.
$ctx = Page::start(['public' => true]);
$next = Request::safeAppPath(Request::string('next') ?? Request::query('next'));
if ($ctx !== null) {
    Response::redirect($next ?? 'index.php');
}

$error = null;
$identifier = '';
if (Request::isPost()) {
    Csrf::verify();
    $identifier = Request::string('identifier') ?? '';
    $password = Request::raw('password') ?? '';
    if ($identifier === '' || $password === '') {
        $error = 'Enter your username (or email) and password.';
    } else {
        $result = Auth::attempt($identifier, $password, Request::ip());
        if ($result['ok']) {
            WebSession::login((int) $result['user']['user_id'], $result['sessionId'], null);
            Response::redirect($next ?? 'index.php');
        }
        $error = match ($result['code']) {
            'locked' => 'This account is locked. Please try again later or contact an Administrator.',
            'inactive', 'expired', 'not_started' => 'This account cannot be used at the moment. Please contact an Administrator.',
            'rate_limited' => 'Too many sign-in attempts. Please wait a few minutes and try again.',
            default => 'That username or password is not correct.',
        };
    }
}

View::render('pages/login', ['title' => 'Sign in', 'error' => $error, 'identifier' => $identifier, 'next' => $next], 'layout/public');
