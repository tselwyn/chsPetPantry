<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Page;
use Pfpms\Reference\PolicyService;
use Pfpms\View\View;

// Policy texts: every version and translation of each type, and which one is in force today (plan P2A).
$ctx = Page::start(['capability' => 'policy.manage']);

View::render('pages/admin/policies', ['title' => 'Policy texts', 'ctx' => $ctx, 'types' => PolicyService::overview()]);
