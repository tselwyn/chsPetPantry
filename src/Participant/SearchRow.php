<?php
declare(strict_types=1);

namespace Pfpms\Participant;

/**
 * One participant row of the search page, ready to show: the page renders it and api/participant/checkins.php sends
 * it to the page's script, so both say the same thing. Minimal fields only (UC-02); values are plain text, escaped by
 * whoever outputs them.
 */
final class SearchRow
{
    /** Check-in outcomes as the queue shows them. */
    private const OUTCOMES = ['Waiting' => 'Waiting', 'Served' => 'Served', 'Left Unserved' => 'Left unserved', 'No Stock' => 'No stock',
        'Reserve List' => 'Reserve list'];

    /**
     * @param array $p a row from ParticipantRepository (search, checkIns or recentlyServed)
     * @param string $timeZone the site's time zone, for the check-in time
     * @return array{id: int, url: string, name: string, legal: ?string, code: string, status: string, pets: int,
     *   last_distribution: ?string, served_here: ?string, checked_in: ?string, outcome: ?string}
     */
    public static function present(array $p, string $timeZone): array
    {
        return [
            'id' => (int) $p['participant_id'],
            'url' => url('participant_view.php', ['id' => (int) $p['participant_id']]),
            'name' => ParticipantName::display($p),
            'legal' => ParticipantName::legalIfDifferent($p),
            'code' => (string) $p['participant_code'],
            'status' => (string) $p['status'],
            'pets' => (int) $p['pet_count'],
            'last_distribution' => self::day($p['last_distribution_date'] ?? null),
            'served_here' => self::day($p['served_here'] ?? null),
            'checked_in' => isset($p['checked_in_at']) ? local_time((string) $p['checked_in_at'], $timeZone, 'g:i A') : null,
            'outcome' => isset($p['outcome']) ? (self::OUTCOMES[$p['outcome']] ?? (string) $p['outcome']) : null,
        ];
    }

    /** A DATE as "Oct 2, 2026", or null. */
    public static function day(?string $date): ?string
    {
        return $date === null || $date === '' ? null : date('M j, Y', (int) strtotime($date . ' 00:00:00 UTC'));
    }
}
