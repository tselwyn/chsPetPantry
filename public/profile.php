<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Audit\Audit;
use Pfpms\Db;
use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Security\Csrf;
use Pfpms\Validation\Validator;
use Pfpms\View\View;

// Manage User Profile (included by UC-01): display name, phone, notification preferences.
// Role, sites, status and email are not editable here (UC-11 §4).
$ctx = Page::start(['capability' => 'profile.self']);
$prefs = json_decode((string) ($ctx->user['notification_prefs'] ?? ''), true) ?: [];
$values = [
    'display_name' => $ctx->user['display_name'] ?? '',
    'phone' => $ctx->user['phone'] ?? '',
    'notify_email' => (bool) ($prefs['email'] ?? true),
    'row_version' => (int) $ctx->user['row_version'],
];
$errors = [];
$conflict = false;

if (Request::isPost()) {
    Csrf::verify();
    $displayRaw = Request::string('display_name') ?? '';
    $phoneRaw = Request::string('phone') ?? '';
    $display = $displayRaw === '' ? null : Validator::text($displayRaw, 100);
    $phone = $phoneRaw === '' ? null : Validator::phone($phoneRaw);
    if ($displayRaw !== '' && $display === null) {
        $errors[] = 'The display name can be at most 100 characters.';
    }
    if ($phoneRaw !== '' && $phone === null) {
        $errors[] = 'Enter a 10-digit phone number.';
    }
    $values = ['display_name' => $displayRaw, 'phone' => $phoneRaw, 'notify_email' => Request::bool('notify_email'),
        'row_version' => Request::int('row_version') ?? -1];
    if (!$errors) {
        $newPrefs = json_encode(['email' => $values['notify_email']] + $prefs, JSON_THROW_ON_ERROR);
        $changes = ['display_name' => $display, 'phone' => $phone, 'notification_prefs' => $newPrefs];
        $saved = Db::transaction(function () use ($ctx, $values, $changes): bool {
            if (!Db::updateVersioned('user_account', 'user_id', $ctx->userId(), $values['row_version'], $changes)) {
                return false;
            }
            Audit::record('profile_update', 'user_account', $ctx->userId(), changes: Audit::diff($ctx->user, $changes, array_keys($changes)));
            return true;
        });
        if ($saved) {
            Flash::success('Your profile has been saved.');
            Response::redirect('profile.php');
        }
        $conflict = true;
        $values = ['display_name' => $ctx->user['display_name'] ?? '', 'phone' => $ctx->user['phone'] ?? '',
            'notify_email' => (bool) ($prefs['email'] ?? true), 'row_version' => (int) $ctx->user['row_version']];
    }
}

View::render('pages/profile', ['title' => 'My profile', 'ctx' => $ctx, 'values' => $values, 'errors' => $errors, 'conflict' => $conflict]);
