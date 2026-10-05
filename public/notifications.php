<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Notify\Notifications;
use Pfpms\Security\Csrf;
use Pfpms\View\View;

// Items needing attention (UC-01 step 11 "outstanding alerts"): lockouts, failed mail, referrals to an Administrator.
$ctx = Page::start(['capability' => 'home.view']);

if (Request::isPost()) {
    Csrf::verify();
    $id = Request::int('notification_id');
    if ($id !== null && Notifications::resolve($ctx, $id)) {
        Audit::record('notification_resolve', 'notification', $id);
        Flash::success('Marked as done.');
    } else {
        Flash::error('That item was already dealt with or is not yours.');
    }
    Response::redirect('notifications.php');
}

$showResolved = Request::query('show') === 'resolved';
View::render('pages/notifications', [
    'title' => 'Needs attention',
    'ctx' => $ctx,
    'items' => Notifications::listFor($ctx, $showResolved),
    'showResolved' => $showResolved,
]);
