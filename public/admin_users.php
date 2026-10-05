<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Account\AccountRepository;
use Pfpms\Auth\Rbac;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Validation\Validator;
use Pfpms\View\View;

// UC-11 account list: name, role, sites, status and last sign-in, with filters.
$ctx = Page::start(['capability' => 'user.manage']);
$filters = [
    'status' => Validator::oneOf(Request::query('status'), ['Pending', 'Active', 'Inactive', 'Locked']),
    'role' => Validator::oneOf(Request::query('role'), Rbac::ROLES),
    'q' => Validator::text(Request::query('q'), 100),
];

View::render('pages/admin/users', ['title' => 'User accounts', 'ctx' => $ctx, 'filters' => $filters, 'users' => AccountRepository::list($filters)]);
