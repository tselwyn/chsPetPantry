<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Clock;
use Pfpms\Http\Page;
use Pfpms\Http\Request;
use Pfpms\Participant\ParticipantRepository;
use Pfpms\Settings;
use Pfpms\Validation\Validator;
use Pfpms\View\View;

// Find a participant (UC-02; plan P3, source legacy personSearch.php's GET filters): households registered at the
// current site, by name, phone or participant code, best match first, capped at search_max_results. Administrators
// may include deleted records. Minimal fields only, and no export. With nothing typed, the page lists today's
// check-ins first while the site has an Open event (US-04; kept fresh by assets/js/participant_search.js through
// api/participant/checkins.php), then the households served here within recent_participants_days.
$ctx = Page::start(['capability' => 'participant.search', 'site' => true]);
$siteId = (int) $ctx->siteId;
$canIncludeDeleted = $ctx->can('participant.search_include_deleted');
$filters = [
    'q' => Validator::text(Request::query('q'), ParticipantRepository::MAX_TERM),
    'include_deleted' => $canIncludeDeleted && Request::query('include_deleted') === '1',
];
$limit = Settings::int('search_max_results', 100);
$timeZone = $ctx->site()['time_zone'] ?? 'America/New_York';
$vars = [
    'title' => 'Find a participant', 'ctx' => $ctx, 'filters' => $filters, 'canIncludeDeleted' => $canIncludeDeleted, 'limit' => $limit,
    'timeZone' => $timeZone, 'tooLong' => trim((string) Request::query('q')) !== '' && $filters['q'] === null,
    'result' => null, 'event' => null, 'checkIns' => null, 'recent' => null, 'recentDays' => 0, 'pollSeconds' => 0,
];
if ($filters['q'] !== null) {
    $vars['result'] = ParticipantRepository::search($siteId, $filters['q'], $limit, $filters['include_deleted']);
} elseif (!$vars['tooLong']) {
    $today = Clock::localDate($timeZone);
    $vars['event'] = ParticipantRepository::openEvent($siteId, $today);
    if ($vars['event'] !== null) {
        $vars['checkIns'] = ParticipantRepository::checkIns($siteId, (int) $vars['event']['event_id'], $limit);
        $vars['pollSeconds'] = max(5, min(120, Settings::int('station_poll_seconds', 15)));
    }
    $vars['recentDays'] = max(1, min(365, Settings::int('recent_participants_days', 30)));
    $since = (new DateTimeImmutable($today))->modify('-' . $vars['recentDays'] . ' days')->format('Y-m-d');
    $vars['recent'] = ParticipantRepository::recentlyServed($siteId, $since, $limit, $vars['event'] === null ? null : (int) $vars['event']['event_id']);
}

View::render('pages/participant/search', $vars);
