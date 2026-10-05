<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration\Participant;

use Pfpms\Auth\Rbac;
use Pfpms\Db;
use Pfpms\Participant\ParticipantRepository;
use Pfpms\Tests\TestCase;
use Pfpms\Text\Fold;

/** Participant search (UC-02, plan P3): site-scoped, by name, phone or code, ranked, capped, minimal fields. */
final class ParticipantSearchTest extends TestCase
{
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

    /** A participant registered at $siteId (and any $alsoAt), with legal names and code from $values. */
    private function participant(int $siteId, array $values, array $alsoAt = []): int
    {
        static $n = 1000;
        $n++;
        $row = $values + ['participant_code' => "P$n", 'legal_first_name' => 'Test', 'legal_last_name' => "Person$n", 'postal_code' => '22701',
            'household_size' => 1, 'home_site_id' => $siteId, 'registration_site_id' => $siteId, 'registered_by' => $this->staff];
        $row['surname_phonetic'] ??= Fold::phonetic($row['legal_last_name']);
        $columns = array_keys($row);
        Db::pdo()->prepare('INSERT INTO participant (' . implode(', ', $columns) . ') VALUES (' . rtrim(str_repeat('?, ', count($columns)), ', ') . ')')
            ->execute(array_values($row));
        $id = (int) Db::pdo()->lastInsertId();
        foreach (array_merge([$siteId], $alsoAt) as $site) {
            Db::pdo()->prepare('INSERT INTO participant_site (participant_id, site_id, added_by) VALUES (?, ?, ?)')->execute([$id, $site, $this->staff]);
        }
        return $id;
    }

    private function pet(int $participantId, string $status = 'Active'): void
    {
        $dog = (int) $this->scalar("SELECT species_id FROM species WHERE name = 'Dog'");
        $band = $this->scalar('SELECT size_band_id FROM size_band WHERE species_id = ?', [$dog]);
        if ($band === false) {
            Db::pdo()->prepare("INSERT INTO size_band (species_id, name, min_weight_lbs) VALUES (?, 'Any', 0)")->execute([$dog]);
            $band = Db::pdo()->lastInsertId();
        }
        Db::pdo()->prepare('INSERT INTO pet (participant_id, name, species_id, size_band_id, status, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$participantId, 'Rex', $dog, (int) $band, $status, $this->staff]);
    }

    /** @return list<string> the codes found at $siteId for $term, in result order */
    private function codes(string $term, ?int $siteId = null, int $limit = 100, bool $includeDeleted = false): array
    {
        return array_column(ParticipantRepository::search($siteId ?? $this->north, $term, $limit, $includeDeleted)['rows'], 'participant_code');
    }

    public function testOnlyHouseholdsRegisteredAtTheSiteAreFound(): void
    {
        $this->participant($this->north, ['participant_code' => 'P1', 'legal_last_name' => 'Garcia']);
        $this->participant($this->south, ['participant_code' => 'P2', 'legal_last_name' => 'Garcia']);
        $this->participant($this->south, ['participant_code' => 'P3', 'legal_last_name' => 'Garcia'], [$this->north]);
        $this->assertSame(['P1', 'P3'], $this->codes('garcia'), 'volunteers see no other site\'s households');
        $this->assertSame(['P2', 'P3'], $this->codes('garcia', $this->south));
        $this->assertSame([], $this->codes('P2'), 'not even by code');
    }

    public function testCodesAreFoundWithOrWithoutTheP(): void
    {
        $this->participant($this->north, ['participant_code' => 'P12', 'legal_last_name' => 'Brown']);
        $this->participant($this->north, ['participant_code' => 'P120', 'legal_last_name' => 'Black']);
        foreach (['P12', 'p12', '12', ' P-12 ', 'P012'] as $term) {
            $this->assertSame(['P12'], $this->codes($term), $term);
        }
        $this->assertSame('P7', ParticipantRepository::codeOf('7'));
        $this->assertNull(ParticipantRepository::codeOf('Jo 7'));
        $this->assertNull(ParticipantRepository::codeOf('1234567890'), 'ten digits is a phone number, not a code');
    }

    public function testNamesMatchFirstLastFullAndReversed(): void
    {
        $this->participant($this->north, ['participant_code' => 'P1', 'legal_first_name' => 'Robert', 'legal_last_name' => 'Smith']);
        $this->participant($this->north, ['participant_code' => 'P2', 'legal_first_name' => 'Mary', 'legal_last_name' => 'Jones']);
        foreach (['Robert', 'smith', 'Robert Smith', 'Smith Robert', 'Smith, Robert', 'rob', 'rob smi', 'mit', 'ober'] as $term) {
            $this->assertSame(['P1'], $this->codes($term), $term);
        }
        $this->assertSame([], $this->codes('Robert Jones'), 'every word must match the same household');
    }

    public function testTheBestMatchComesFirst(): void
    {
        $this->participant($this->north, ['participant_code' => 'P1', 'legal_first_name' => 'Ann', 'legal_last_name' => 'Hannah']);  // partial
        $this->participant($this->north, ['participant_code' => 'P2', 'legal_first_name' => 'Anna', 'legal_last_name' => 'Ward']);   // prefix
        $this->participant($this->north, ['participant_code' => 'P3', 'legal_first_name' => 'Zed', 'legal_last_name' => 'Ann']);     // exact
        $this->participant($this->north, ['participant_code' => 'P4', 'legal_first_name' => 'Joanne', 'legal_last_name' => 'Bell']); // partial
        $rows = ParticipantRepository::search($this->north, 'ann', 100)['rows'];
        $this->assertSame(['P3', 'P1', 'P2', 'P4'], array_column($rows, 'participant_code'),
            'exact first or last name (P3, P1: Ann is her first name), then prefix (P2), then partial (P4)');
        $this->assertSame([ParticipantRepository::RANK_EXACT, ParticipantRepository::RANK_EXACT, ParticipantRepository::RANK_PREFIX,
            ParticipantRepository::RANK_PARTIAL], array_map('intval', array_column($rows, 'match_rank')));

        $this->participant($this->north, ['participant_code' => 'P5', 'legal_last_name' => 'Annex']);
        $this->participant($this->north, ['participant_code' => 'P6', 'legal_first_name' => 'Ann', 'legal_last_name' => 'Fifth']);
        $this->assertSame('P5', $this->codes('P5')[0], 'a code match outranks everything');
        $this->assertSame(['P3', 'P6', 'P1'], array_slice($this->codes('ann'), 0, 3), 'ties sort by last name: Ann, Fifth, Hannah');
    }

    public function testPreferredNamesAreFoundLikeLegalNames(): void
    {
        $this->participant($this->north, ['participant_code' => 'P1', 'legal_first_name' => 'Elizabeth', 'legal_last_name' => 'Johnson',
            'preferred_name' => 'Liz']);
        $this->participant($this->north, ['participant_code' => 'P2', 'legal_first_name' => 'Lizbeth', 'legal_last_name' => 'Moore']);
        $this->participant($this->north, ['participant_code' => 'P3', 'legal_first_name' => 'Dorothy', 'legal_last_name' => 'Washington',
            'preferred_name' => 'Dot']);
        foreach (['Liz', 'liz johnson', 'Johnson Liz', 'Johnson, Liz', 'Elizabeth Johnson'] as $term) {
            $this->assertSame('P1', $this->codes($term)[0], $term);
        }
        $rows = ParticipantRepository::search($this->north, 'liz', 100)['rows'];
        $this->assertSame(['P1', 'P2'], array_column($rows, 'participant_code'), 'the exact preferred name (P1) before a legal prefix (P2)');
        $this->assertSame([ParticipantRepository::RANK_EXACT, ParticipantRepository::RANK_PREFIX], array_map('intval', array_column($rows, 'match_rank')));
        $this->assertSame(['P3'], $this->codes('dot wash'), 'preferred and legal words mix');
        $this->assertSame(['P3'], $this->codes('Dorothy'), 'the legal name still finds them');
        $this->assertSame('Dot', ParticipantRepository::search($this->north, 'dot', 100)['rows'][0]['preferred_name'], 'returned for display');
    }

    public function testAccentsDoNotMatterInEitherDirection(): void
    {
        $this->participant($this->north, ['participant_code' => 'P1', 'legal_first_name' => 'José', 'legal_last_name' => 'Muñoz']);
        $this->participant($this->north, ['participant_code' => 'P2', 'legal_first_name' => 'Jose', 'legal_last_name' => 'Munoz']);
        $this->participant($this->north, ['participant_code' => 'P3', 'legal_first_name' => 'Thanh', 'legal_last_name' => 'Nguyễn', 'preferred_name' => 'Tôm']);
        $this->participant($this->north, ['participant_code' => 'P4', 'legal_first_name' => 'Ángel', 'legal_last_name' => 'Hernández']);
        foreach (['jose munoz', 'José Muñoz', 'JOSE MUÑOZ', 'muñoz', 'Munoz, José'] as $term) {
            $this->assertSame(['P1', 'P2'], $this->codes($term), "$term finds both spellings (equal names, so by id)");
        }
        $this->assertSame(['P3'], $this->codes('nguyen'));
        $this->assertSame(['P3'], $this->codes('tom'), 'preferred names too');
        $this->assertSame(['P4'], $this->codes('angel hernandez'));
        $this->assertSame(['P4'], $this->codes('ÁNGEL'));
        $this->assertSame(ParticipantRepository::RANK_EXACT, (int) ParticipantRepository::search($this->north, 'Hernandez', 100)['rows'][0]['match_rank'],
            'an unaccented exact name is still exact');
    }

    public function testSoundAlikeSurnamesRankBetweenPrefixAndPartial(): void
    {
        $this->participant($this->north, ['participant_code' => 'P1', 'legal_first_name' => 'Michael', 'legal_last_name' => 'Smyth']);
        $this->participant($this->north, ['participant_code' => 'P2', 'legal_first_name' => 'Robert', 'legal_last_name' => 'Smith']);
        $this->participant($this->north, ['participant_code' => 'P3', 'legal_first_name' => 'Ann', 'legal_last_name' => 'Goldsmith']);
        $this->participant($this->north, ['participant_code' => 'P4', 'legal_first_name' => 'Sarah', 'legal_last_name' => 'Johnson']);
        $rows = ParticipantRepository::search($this->north, 'smith', 100)['rows'];
        $this->assertSame(['P2', 'P1', 'P3'], array_column($rows, 'participant_code'), 'exact, then sounds alike, then partial');
        $this->assertSame([ParticipantRepository::RANK_EXACT, ParticipantRepository::RANK_PHONETIC, ParticipantRepository::RANK_PARTIAL],
            array_map('intval', array_column($rows, 'match_rank')));
        $this->assertSame(['P4'], $this->codes('jonson'), 'a misspelt surname');
        $this->assertSame(['P4'], $this->codes('sarah jonsen'), 'with the first name');
        $this->assertSame([], $this->codes('mary jonson'), 'the other words must still match');
        $this->assertSame(['P2', 'P1', 'P3'], $this->codes('smit'), 'a prefix of Smith, then Smyth (sounds alike, S530), then Goldsmith (partial)');
    }

    public function testSurnamePhoneticIsTheFoldKeyAndOnlyRead(): void
    {
        $id = $this->participant($this->north, ['participant_code' => 'P1', 'legal_last_name' => 'Peña-Ruiz', 'surname_phonetic' => Fold::phonetic('Peña-Ruiz')]);
        $this->assertSame('P500 R200', $this->scalar('SELECT surname_phonetic FROM participant WHERE participant_id = ?', [$id]));
        $this->assertSame(['P500', 'R200'], ParticipantRepository::phoneticKeys('Peña-Ruiz'));
        $this->assertSame(['P1'], $this->codes('rice'), 'either part of a double surname (Ruiz ~ Rice)');
        $this->assertSame(['P1'], $this->codes('Pena'));
        $this->assertSame('P500 R200', $this->scalar('SELECT surname_phonetic FROM participant WHERE participant_id = ?', [$id]), 'search never writes it');

        $this->participant($this->north, ['participant_code' => 'P2', 'legal_last_name' => 'Joy']);
        $this->assertSame([], $this->codes('ja'), 'too short to key: J000 would match Joy');
        $this->participant($this->north, ['participant_code' => 'P3', 'legal_last_name' => 'Legacy', 'surname_phonetic' => null]);
        $this->assertSame(['P3'], $this->codes('legacy'), 'a record without a key is still found by name');
    }

    public function testAccentsDoNotDisturbTheSortOrder(): void
    {
        foreach (['Zúñiga', 'Baker', 'Álvarez', 'Adams', 'Ávila', 'Zimmer'] as $i => $last) {
            $this->participant($this->north, ['participant_code' => 'P' . ($i + 1), 'legal_first_name' => 'Kim', 'legal_last_name' => $last]);
        }
        $rows = ParticipantRepository::search($this->north, 'kim', 100)['rows'];
        $this->assertSame(['Adams', 'Álvarez', 'Ávila', 'Baker', 'Zimmer', 'Zúñiga'], array_column($rows, 'legal_last_name'),
            'Á sorts with A and Ú with U, not after Z');
    }

    public function testPhonesMatchByAnyRunOfDigits(): void
    {
        $this->participant($this->north, ['participant_code' => 'P1', 'phone' => '5405550101']);
        $this->participant($this->north, ['participant_code' => 'P2', 'phone' => '5405550199']);
        foreach (['(540) 555-0101', '540.555.0101', '1 540 555 0101', '+1-540-555-0101', '0101'] as $term) {
            $this->assertSame(['P1'], $this->codes($term), $term);
        }
        $this->assertSame(['P1', 'P2'], $this->codes('555-01'));
        $this->assertNull(ParticipantRepository::phoneDigits('010'), 'three digits is too few');
        $this->assertNull(ParticipantRepository::phoneDigits('Jo 0101'));
    }

    public function testWildcardsInTheSearchAreLiteral(): void
    {
        $this->participant($this->north, ['participant_code' => 'P1', 'legal_last_name' => 'Lee']);
        foreach (['%', '_', 'L_e', '%ee', '\\'] as $term) {
            $this->assertSame([], $this->codes($term), $term);
        }
    }

    public function testResultsAreCappedAndSayWhenThereWereMore(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->participant($this->north, ['legal_last_name' => sprintf('Miller%02d', $i)]);
        }
        $result = ParticipantRepository::search($this->north, 'miller', 10);
        $this->assertCount(10, $result['rows']);
        $this->assertTrue($result['truncated']);
        $this->assertSame('Miller00', $result['rows'][0]['legal_last_name']);
        $this->assertFalse(ParticipantRepository::search($this->north, 'miller', 12)['truncated'], 'exactly the cap is not truncated');
    }

    public function testDeletedRecordsOnlyWhenAskedAndMergedNever(): void
    {
        $this->participant($this->north, ['participant_code' => 'P1', 'legal_last_name' => 'Davis']);
        $this->participant($this->north, ['participant_code' => 'P2', 'legal_last_name' => 'Davis', 'status' => 'Inactive']);
        $this->participant($this->north, ['participant_code' => 'P3', 'legal_last_name' => 'Davis', 'status' => 'Deleted', 'deleted_at' => self::NOW]);
        $this->participant($this->north, ['participant_code' => 'P4', 'legal_last_name' => 'Davis', 'status' => 'Merged']);
        $this->assertSame(['P1', 'P2'], $this->codes('davis'), 'inactive households are still found');
        $this->assertSame(['P1', 'P2', 'P3'], $this->codes('davis', includeDeleted: true));
        $this->assertSame([], $this->codes('P4', includeDeleted: true));
        $this->assertTrue(Rbac::can('Administrator', 'participant.search_include_deleted'));
        $this->assertFalse(Rbac::can('Volunteer', 'participant.search_include_deleted'));
        $this->assertTrue(Rbac::can('Volunteer', 'participant.search'));
    }

    public function testRowsCarryPetCountAndLastDistributionButNoContactDetails(): void
    {
        $id = $this->participant($this->north, ['participant_code' => 'P1', 'legal_last_name' => 'Clark', 'phone' => '5405550123',
            'street_address' => '1 Main St', 'last_distribution_date' => '2026-09-20']);
        $this->pet($id);
        $this->pet($id);
        $this->pet($id, 'Inactive');
        $row = ParticipantRepository::search($this->north, 'clark', 100)['rows'][0];
        $this->assertSame([2, '2026-09-20'], [(int) $row['pet_count'], $row['last_distribution_date']], 'active pets only');
        foreach (['phone', 'street_address', 'date_of_birth', 'email', 'postal_code'] as $column) {
            $this->assertFalse(array_key_exists($column, $row), "no $column in search results");
        }
    }

    public function testATermWithNothingToMatchFindsNothing(): void
    {
        $this->participant($this->north, ['participant_code' => 'P1']);
        foreach (['-', '()', ', ,'] as $term) {
            $this->assertSame(['rows' => [], 'truncated' => false], ParticipantRepository::search($this->north, $term, 100), $term);
        }
    }
}
