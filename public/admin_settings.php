<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\SettingsService;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// System settings: one form, grouped, typed and audited (plan P2A).
$ctx = Page::start(['capability' => 'settings.manage']);
$posted = [];
$original = [];
$errors = [];

if (Request::isPost()) {
    Csrf::verify();
    foreach (array_keys(SettingsService::registry()) as $key) {
        $value = Request::string($key);
        if ($value !== null) {
            $posted[$key] = $value;
        }
    }
    $original = Request::array('orig');
    try {
        $changed = SettingsService::update($posted, $ctx->userId(), $original);
        Flash::success(match (count($changed)) {
            0 => 'No settings were changed.',
            1 => '1 setting saved.',
            default => count($changed) . ' settings saved.',
        });
        Response::redirect('admin_settings.php');
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

View::render('pages/admin/settings', ['title' => 'Settings', 'ctx' => $ctx, 'groups' => SettingsService::grouped(),
    'posted' => $posted, 'original' => $original, 'errors' => $errors]);
