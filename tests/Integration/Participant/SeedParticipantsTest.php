<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Participant;

use Pfpms\Db;
use Pfpms\Db\SqlSplitter;
use Pfpms\Tests\TestCase;
use Pfpms\Text\Fold;

/**
 * seeds/dev/005_participants.sql: 30 fake households across the two dev sites for participant search (UC-02). Its
 * surname_phonetic values must be what Fold::phonetic() gives, as the Station computes them (50-design §11.3).
 */
final class SeedParticipantsTest extends TestCase
{
    /** What the participant seed needs: the dev sites (001) and the size bands and allotment version 1 (002). */
    private const SEEDS = ['001_sites_policy.sql', '002_size_bands_allotment.sql', '005_participants.sql'];

    public function testEverySurnamePhoneticIsTheFoldPhoneticKey(): void
    {
        $this->runSeeds();
        $rows = Db::pdo()->query('SELECT participant_code, legal_last_name, surname_phonetic FROM participant ORDER BY participant_id')->fetchAll();
        $this->assertCount(30, $rows);
        foreach ($rows as $row) {
            $this->assertSame(Fold::phonetic($row['legal_last_name']), $row['surname_phonetic'], $row['participant_code'] . ' ' . $row['legal_last_name']);
        }
    }

    public function testTheSeedCoversWhatSearchNeeds(): void
    {
        $this->runSeeds();
        $north = (int) $this->scalar("SELECT site_id FROM site WHERE name = 'Dev Site North'");
        $south = (int) $this->scalar("SELECT site_id FROM site WHERE name = 'Dev Site South'");
        $this->assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM participant WHERE legal_last_name = 'Munoz'"), 'Muñoz and Munoz (US-06)');
        $this->assertSame(['Muñoz', 'Munoz'], Db::pdo()->query("SELECT legal_last_name FROM participant WHERE participant_code IN ('P1', 'P4') ORDER BY participant_code")
            ->fetchAll(\PDO::FETCH_COLUMN), 'stored as written');
        $this->assertGreaterThan(9, (int) $this->scalar('SELECT COUNT(*) FROM participant WHERE preferred_name IS NOT NULL'), 'preferred names (US-05)');
        $this->assertSame(['Inactive', 'Deleted'], Db::pdo()->query("SELECT status FROM participant WHERE status <> 'Active' ORDER BY status")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertNotNull($this->scalar("SELECT deleted_at FROM participant WHERE status = 'Deleted'"));
        $this->assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM participant_site ps JOIN participant p USING (participant_id) WHERE p.participant_code = 'P18'"),
            'one household at both sites');
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM participant p WHERE NOT EXISTS
            (SELECT 1 FROM participant_site ps WHERE ps.participant_id = p.participant_id AND ps.site_id = p.home_site_id)'), 'every household at its home site');
        $this->assertGreaterThan(14, (int) $this->scalar('SELECT COUNT(*) FROM participant_site WHERE site_id = ?', [$north]));
        $this->assertGreaterThan(9, (int) $this->scalar('SELECT COUNT(*) FROM participant_site WHERE site_id = ?', [$south]));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM pet pt JOIN participant p USING (participant_id) WHERE p.status = 'Deleted' AND pt.status = 'Active'"),
            'a deleted household\'s pets were inactivated with it');
    }

    public function testLastDistributionDatesMatchTheSeededDistributions(): void
    {
        $this->runSeeds();
        $this->assertGreaterThan(20, (int) $this->scalar('SELECT COUNT(*) FROM distribution'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM participant p WHERE NOT (p.last_distribution_date <=>
            (SELECT MAX(d.local_date) FROM distribution d WHERE d.participant_id = p.participant_id))'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM distribution d JOIN distribution_event e USING (event_id)
            JOIN participant p ON p.participant_id = d.participant_id WHERE e.site_id <> p.home_site_id OR e.event_date <> d.local_date'));
    }

    public function testRegistrationStartsAfterTheSeededCodes(): void
    {
        $this->runSeeds();
        $this->assertSame(31, (int) $this->scalar("SELECT next_value FROM id_sequence WHERE seq_name = 'participant'"));
        Db::pdo()->exec("UPDATE id_sequence SET next_value = 40 WHERE seq_name = 'participant'");
        $this->runSeeds();
        $this->assertSame(40, (int) $this->scalar("SELECT next_value FROM id_sequence WHERE seq_name = 'participant'"), 'never moved back');
    }

    public function testTheSeedIsSafeToRunTwice(): void
    {
        $this->runSeeds();
        $counts = $this->counts();
        Db::pdo()->exec("UPDATE participant SET preferred_name = 'Changed in the app' WHERE participant_code = 'P1'");
        $this->runSeeds();
        $this->assertSame($counts, $this->counts());
        $this->assertSame('Changed in the app', $this->scalar("SELECT preferred_name FROM participant WHERE participant_code = 'P1'"), 'nothing is overwritten');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (['participant', 'participant_site', 'pet', 'distribution_event', 'distribution'] as $table) {
            $counts[$table] = (int) $this->scalar("SELECT COUNT(*) FROM $table");
        }
        return $counts;
    }

    private function runSeeds(): void
    {
        foreach (self::SEEDS as $file) {
            foreach (SqlSplitter::split((string) file_get_contents(APP_ROOT . '/seeds/dev/' . $file)) as $statement) {
                Db::pdo()->exec($statement);
            }
        }
    }
}
