<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Auth\Policy;
use Pfpms\Http\Page;
use Pfpms\Notify\Notifications;
use Pfpms\View\Menu;
use Pfpms\View\View;

// UC-17 View Dashboard: the role's home screen with the current site and outstanding items.
$ctx = Page::start(['capability' => 'home.view']);

View::render('pages/home', [
    'title' => 'Home',
    'ctx' => $ctx,
    'menu' => Menu::for($ctx),
    'openNotifications' => Notifications::openCountFor($ctx),
    'policyMissing' => Policy::current(Policy::CONFIDENTIALITY) === null,
]);
