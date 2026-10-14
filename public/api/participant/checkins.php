<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use Pfpms\Clock;
use Pfpms\Http\Api;
use Pfpms\Http\Response;
use Pfpms\Participant\ParticipantRepository;
use Pfpms\Participant\SearchRow;
use Pfpms\Settings;

// US-04: today's check-ins at the current site, polled by participant_search.php's script so the list stays current
// while a queue forms. Polling never extends the idle timer, so a screen left open still times out. Minimal fields only.
$ctx = Api::start(['method' => 'GET', 'capability' => 'participant.search', 'site' => true, 'touch' => false]);
$siteId = (int) $ctx->siteId;
$timeZone = $ctx->site()['time_zone'] ?? 'America/New_York';
$event = ParticipantRepository::openEvent($siteId, Clock::localDate($timeZone));
if ($event === null) {
    Response::json(['open' => false, 'rows' => [], 'truncated' => false], 200, ['Cache-Control' => 'no-store']);
}
$list = ParticipantRepository::checkIns($siteId, (int) $event['event_id'], Settings::int('search_max_results', 100));
Response::json([
    'open' => true,
    'rows' => array_map(fn(array $p): array => SearchRow::present($p, $timeZone), $list['rows']),
    'truncated' => $list['truncated'],
], 200, ['Cache-Control' => 'no-store']);
