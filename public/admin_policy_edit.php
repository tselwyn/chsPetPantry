<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Flash;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Http\Response;
use Pfpms\Reference\LanguageRepository;
use Pfpms\Reference\PolicyRepository;
use Pfpms\Reference\PolicyService;
use Pfpms\Security\Csrf;
use Pfpms\Validation\ValidationException;
use Pfpms\View\View;

// Add a policy text, a new version or a translation (?from=&as=), or edit one nobody has used yet (?id=).
$ctx = Page::start(['capability' => 'policy.manage']);
$documentId = Request::int('id', fromQuery: true) ?? Request::int('document_id');
$doc = $documentId !== null ? (PolicyRepository::find($documentId) ?? Response::notFound()) : null;
$as = Request::query('as') === 'translation' ? 'translation' : 'version';
$fromId = $doc === null ? Request::int('from', fromQuery: true) : null;
$source = $fromId !== null ? (PolicyRepository::find($fromId) ?? Response::notFound()) : null;
$values = $doc ?? ($source !== null ? PolicyService::prefill($source, $as) : PolicyService::blank(Request::query('type')));
$errors = [];

if (Request::isPost()) {
    Csrf::verify();
    try {
        if ($doc === null) {
            $values = Request::only(PolicyService::FIELDS);
            PolicyService::create($values);
            Flash::success('Policy text added.');
        } else {
            $values = Request::only(PolicyService::EDITABLE) + $doc;
            PolicyService::update((int) $doc['document_id'], $values);
            Flash::success('Policy text saved.');
        }
        Response::redirect('admin_policies.php');
    } catch (ValidationException $e) {
        $errors = $e->errors;
    }
}

$usage = $doc !== null ? PolicyRepository::usage((int) $doc['document_id']) : null;
$locked = $usage !== null && $usage['acknowledgements'] + $usage['consents'] > 0;
if ($doc !== null) {
    $title = $locked ? 'View policy text' : 'Edit policy text';
} elseif ($source !== null) {
    $title = $as === 'translation' ? 'Add a translation' : 'Create a new version';
} else {
    $title = 'Add a policy text';
}

View::render('pages/admin/policy_edit', [
    'title' => $title, 'ctx' => $ctx, 'doc' => $doc, 'source' => $source, 'as' => $as, 'usage' => $usage, 'locked' => $locked,
    'values' => $values, 'errors' => $errors, 'types' => PolicyService::TYPES, 'languages' => LanguageRepository::active(),
]);
