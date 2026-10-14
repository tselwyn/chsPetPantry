<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Participant;

use Pfpms\Db;
use Pfpms\Participant\ParticipantRepository;
use Pfpms\Participant\SearchRow;
use Pfpms\Tests\TestCase;

/**
 * The empty participant search (plan P3, US-04): today's check-ins first while the site has an Open event, then the
 * households served here within recent_participants_days.
 */
final class EmptySearchTest extends TestCase
{
    private const TODAY = '2026-10-01';

    private int $north;
    private int $south;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->north = $this->makeSite('North');
        $this->south = $this->makeSite('South');
        $this->staff = $this->makeUser()['user_id'];
    }

    private function participant(int $siteId, string $code, string $last, array $values = []): int
    {
        $row = $values + ['participant_code' => $code, 'legal_first_name' => 'Pat', 'legal_last_name' => $last, 'postal_code' => '22701',
            'household_size' => 1, 'home_site_id' => $siteId, 'registration_site_id' => $siteId, 'registered_by' => $this->staff];
        $columns = array_keys($row);
        Db::pdo()->prepare('INSERT INTO participant (' . implode(', ', $columns) . ') VALUES (' . rtrim(str_repeat('?, ', count($columns)), ', ') . ')')
            ->execute(array_values($row));
        $id = (int) Db::pdo()->lastInsertId();
        Db::pdo()->prepare('INSERT INTO participant_site (participant_id, site_id, added_by) VALUES (?, ?, ?)')->execute([$id, $siteId, $this->staff]);
        return $id;
    }

    private function event(int $siteId, string $date, string $status): int
    {
        Db::pdo()->prepare("INSERT INTO distribution_event (site_id, event_date, starts_at, ends_at, status) VALUES (?, ?, '09:00', '12:00', ?)")
            ->execute([$siteId, $date, $status]);
        return (int) Db::pdo()->lastInsertId();
    }

    private function checkIn(int $eventId, int $participantId, string $atUtc, string $outcome = 'Waiting'): void
    {
        Db::pdo()->prepare('INSERT INTO event_check_in (event_id, participant_id, checked_in_at, checked_in_by, outcome) VALUES (?, ?, ?, ?, ?)')
            ->execute([$eventId, $participantId, $atUtc, $this->staff, $outcome]);
    }

    private function served(int $eventId, int $participantId, string $date, ?int $reverses = null): int
    {
        static $n = 0;
        $n++;
        Db::pdo()->prepare('INSERT INTO distribution (client_uuid, event_id, participant_id, recorded_by, distributed_at, local_date, pets_served,
                entitled_lbs, allotment_rule_version, frequency_days_applied, reverses_distribution_id, reversal_reason) VALUES (?, ?, ?, ?, ?, ?, 1, 4, 1, 14, ?, ?)')
            ->execute([sprintf('7e570000-0000-4000-8000-%012d', $n), $eventId, $participantId, $this->staff, "$date 15:00:00", $date, $reverses,
                $reverses === null ? null : 'Recorded in error']);
        return (int) Db::pdo()->lastInsertId();
    }

    public function testOnlyTodaysOpenEventAtTheSiteCounts(): void
    {
        $this->event($this->north, self::TODAY, 'Scheduled');
        $this->event($this->north, '2026-09-30', 'Open'); // left open yesterday
        $this->event($this->south, self::TODAY, 'Open');
        $this->assertNull(ParticipantRepository::openEvent($this->north, self::TODAY));
        $open = $this->event($this->north, self::TODAY, 'Open');
        $this->assertSame($open, (int) ParticipantRepository::openEvent($this->north, self::TODAY)['event_id']);
    }

    public function testCheckInsAreListedInArrivalOrderForThisSiteOnly(): void
    {
        $event = $this->event($this->north, self::TODAY, 'Open');
        $late = $this->participant($this->north, 'P1', 'Late');
        $early = $this->participant($this->north, 'P2', 'Early');
        $served = $this->participant($this->north, 'P3', 'Served');
        $deleted = $this->participant($this->north, 'P4', 'Gone', ['status' => 'Deleted']);
        $elsewhere = $this->participant($this->south, 'P5', 'Elsewhere');
        $this->checkIn($event, $late, '2026-10-01 14:30:00');
        $this->checkIn($event, $early, '2026-10-01 13:05:00');
        $this->checkIn($event, $served, '2026-10-01 13:40:00', 'Served');
        $this->checkIn($event, $deleted, '2026-10-01 13:00:00');
        $this->checkIn($event, $elsewhere, '2026-10-01 13:01:00');
        $list = ParticipantRepository::checkIns($this->north, $event, 100);
        $this->assertSame(['P2', 'P3', 'P1'], array_column($list['rows'], 'participant_code'), 'earliest first; no deleted or other-site households');
        $this->assertFalse($list['truncated']);
        $this->assertTrue(ParticipantRepository::checkIns($this->north, $event, 2)['truncated'], 'capped like a search');

        $first = SearchRow::present($list['rows'][0], 'America/New_York');
        $this->assertSame(['9:05 AM', 'Waiting'], [$first['checked_in'], $first['outcome']], 'the check-in time in the site\'s zone');
        $this->assertSame('Served', SearchRow::present($list['rows'][1], 'America/New_York')['outcome']);
    }

    public function testRecentlyServedIsThisSitesWindowNewestFirst(): void
    {
        $sept = $this->event($this->north, '2026-09-24', 'Closed');
        $aug = $this->event($this->north, '2026-08-20', 'Closed');
        $southEvent = $this->event($this->south, '2026-09-24', 'Closed');
        $a = $this->participant($this->north, 'P1', 'Adams');
        $b = $this->participant($this->north, 'P2', 'Baker');
        $old = $this->participant($this->north, 'P3', 'Old');
        $other = $this->participant($this->south, 'P4', 'South');
        $reversed = $this->participant($this->north, 'P5', 'Reversed');
        $this->served($aug, $a, '2026-08-20');
        $this->served($sept, $a, '2026-09-24');
        $this->served($this->event($this->north, '2026-09-28', 'Closed'), $b, '2026-09-28');
        $this->served($aug, $old, '2026-08-20');
        $this->served($southEvent, $other, '2026-09-24');
        $this->served($sept, $reversed, '2026-09-24', $this->served($sept, $reversed, '2026-09-24'));
        $rows = ParticipantRepository::recentlyServed($this->north, '2026-09-01', 100)['rows'];
        $this->assertSame(['P2', 'P1'], array_column($rows, 'participant_code'),
            'newest first; not before the window, not at another site, not when the distribution was reversed');
        $this->assertSame('2026-09-24', $rows[1]['served_here'], 'the latest date served here');
        $this->assertSame('Sep 24, 2026', SearchRow::present($rows[1], 'America/New_York')['served_here']);
    }

    public function testHouseholdsCheckedInTodayAreNotListedTwice(): void
    {
        $sept = $this->event($this->north, '2026-09-24', 'Closed');
        $today = $this->event($this->north, self::TODAY, 'Open');
        $back = $this->participant($this->north, 'P1', 'Back');
        $notYet = $this->participant($this->north, 'P2', 'NotYet');
        $this->served($sept, $back, '2026-09-24');
        $this->served($sept, $notYet, '2026-09-24');
        $this->checkIn($today, $back, '2026-10-01 13:00:00');
        $this->assertSame(['P2'], array_column(ParticipantRepository::recentlyServed($this->north, '2026-09-01', 100, $today)['rows'], 'participant_code'));
        $this->assertSame(['P1', 'P2'], array_column(ParticipantRepository::recentlyServed($this->north, '2026-09-01', 100)['rows'], 'participant_code'));
    }

    public function testThePollingEndpointNeverExtendsTheIdleTimer(): void
    {
        $code = (string) file_get_contents(APP_ROOT . '/public/api/participant/checkins.php');
        $this->assertStringContainsString("Api::start(['method' => 'GET', 'capability' => 'participant.search', 'site' => true, 'touch' => false])", $code,
            'a screen left open on the queue must still time out');
    }

    public function testTheDevSeedOpensTodaysEventOnceAndClosesEarlierOnes(): void
    {
        $load = static function (string $file): void {
            foreach (\Pfpms\Db\SqlSplitter::split((string) file_get_contents(APP_ROOT . '/seeds/dev/' . $file)) as $statement) {
                Db::pdo()->exec($statement);
            }
        };
        foreach (['001_sites_policy.sql', '002_size_bands_allotment.sql', '005_participants.sql'] as $file) {
            $load($file);
        }
        $north = (int) $this->scalar("SELECT site_id FROM site WHERE name = 'Dev Site North'");
        Db::pdo()->prepare("INSERT INTO distribution_event (site_id, event_date, starts_at, ends_at, status, notes) VALUES (?, '2020-01-01', '09:00', '12:00', 'Open', 'Dev seed event, open today')")
            ->execute([$north]);
        $load('006_open_event_today.sql');
        $load('006_open_event_today.sql');
        $this->assertSame('Closed', $this->scalar("SELECT status FROM distribution_event WHERE event_date = '2020-01-01'"), 'an earlier day\'s is closed');
        $open = Db::pdo()->query("SELECT event_id, site_id FROM distribution_event WHERE status = 'Open'")->fetchAll();
        $this->assertCount(1, $open, 'one Open event');
        $this->assertSame($north, (int) $open[0]['site_id']);
        $this->assertSame(5, (int) $this->scalar('SELECT COUNT(*) FROM event_check_in WHERE event_id = ?', [$open[0]['event_id']]), 'loaded twice, checked in once');
    }
}
