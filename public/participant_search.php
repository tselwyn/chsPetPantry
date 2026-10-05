<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Participant\ParticipantRepository;
use Pfpms\Settings;
use Pfpms\Validation\Validator;
use Pfpms\View\View;

// Find a participant (UC-02; plan P3, source legacy personSearch.php's GET filters): households registered at the
// current site, by name, phone or participant code, best match first, capped at search_max_results. Administrators
// may include deleted records. Minimal fields only, and no export.
$ctx = Page::start(['capability' => 'participant.search', 'site' => true]);
$canIncludeDeleted = $ctx->can('participant.search_include_deleted');
$filters = [
    'q' => Validator::text(Request::query('q'), ParticipantRepository::MAX_TERM),
    'include_deleted' => $canIncludeDeleted && Request::query('include_deleted') === '1',
];
$limit = Settings::int('search_max_results', 100);

View::render('pages/participant/search', [
    'title' => 'Find a participant', 'ctx' => $ctx, 'filters' => $filters, 'canIncludeDeleted' => $canIncludeDeleted, 'limit' => $limit,
    'tooLong' => Request::query('q') !== null && trim((string) Request::query('q')) !== '' && $filters['q'] === null,
    'result' => $filters['q'] === null ? null : ParticipantRepository::search((int) $ctx->siteId, $filters['q'], $limit, $filters['include_deleted']),
]);
