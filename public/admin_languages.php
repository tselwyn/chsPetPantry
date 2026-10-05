<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\LanguageRepository;
use Pfpms\Reference\LanguageService;
use Pfpms\Security\Csrf;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Languages (US-23): add, rename, activate and deactivate.
$ctx = Page::start(['capability' => 'language.manage']);
$values = ['language_code' => '', 'name' => ''];
$errors = [];
$rename = ['code' => null, 'name' => '', 'error' => null];

if (Request::isPost()) {
    Csrf::verify();
    $action = Request::string('action');
    if ($action === 'add') {
        $values = Request::only(['language_code', 'name']);
        try {
            $code = LanguageService::create($values);
            Flash::success("Language added ($code).");
            Response::redirect('admin_languages.php');
        } catch (ValidationException $e) {
            $errors = $e->errors;
        }
    } elseif ($action === 'rename') {
        $code = Request::string('language_code') ?? Response::badRequest();
        $name = Request::string('name') ?? '';
        try {
            LanguageService::rename($code, $name);
            Flash::success('Language name saved.');
            Response::redirect('admin_languages.php');
        } catch (ValidationException $e) {
            if (!isset($e->errors['name'])) {
                Flash::error($e->getMessage());
                Response::redirect('admin_languages.php');
            }
            $rename = ['code' => $code, 'name' => $name, 'error' => $e->errors['name']];
        }
    } elseif ($action === 'activate' || $action === 'deactivate') {
        $code = Request::string('language_code') ?? Response::badRequest();
        try {
            LanguageService::setActive($code, $action === 'activate');
            Flash::success($action === 'activate' ? 'Language activated.'
                : 'Language deactivated. Records already in this language keep it; it is no longer offered for new ones.');
        } catch (ValidationException $e) {
            Flash::error($e->getMessage());
        }
        Response::redirect('admin_languages.php');
    } else {
        Response::badRequest();
    }
}

View::render('pages/admin/languages', [
    'title' => 'Languages', 'ctx' => $ctx, 'languages' => LanguageRepository::all(),
    'defaultCode' => Settings::string('default_language', 'en'), 'values' => $values, 'errors' => $errors, 'rename' => $rename,
]);
