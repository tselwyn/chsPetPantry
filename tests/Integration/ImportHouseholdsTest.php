<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

/**
 * Import Participants & Pets (importData.php): checked households are saved
 * with their pets, all or nothing.
 */
final class ImportHouseholdsTest extends DatabaseTestCase
{
    private function household(string $first, array $pets = []): array
    {
        return [
            'rows' => [2],
            'participant' => [
                'first_name' => $first, 'last_name' => 'Imported', 'street_address' => '1 Elm St',
                'city' => '', 'state' => 'VA', 'zip' => '22401', 'phone' => '5405550199', 'email' => '',
                'preferred_language' => 'English', 'location' => 'Empower', 'registration_date' => '2026-08-04', 'notes' => '',
            ],
            'pets' => $pets,
            'errors' => [],
        ];
    }

    private function petRow(string $name, array $values = []): array
    {
        return $values + [
            'name' => $name, 'species' => 'Dog', 'breed' => 'Lab', 'sex' => 'Male', 'age_years' => '0.5',
            'weight_lbs' => '45', 'spay_neuter_status' => 'Yes', 'rabies_status' => 'Unknown', 'food_type' => null,
            'special_medical_needs' => null, 'notes' => null,
        ];
    }

    private function rowCount(string $table): int
    {
        $con = $this->db();
        $n = (int) $con->query("SELECT COUNT(*) FROM $table")->fetch_row()[0];
        $con->close();
        return $n;
    }

    public function testHouseholdsAndTheirPetsAreSaved(): void
    {
        $saved = import_households([
            $this->household('Ann', [$this->petRow('Max'), $this->petRow('Luna', ['species' => 'Cat', 'sex' => 'Female'])]),
            $this->household('Bob'),
        ]);
        $this->assertSame(['participants' => 2, 'pets' => 2], $saved);

        $con = $this->db();
        $ann = $con->query("SELECT * FROM dbparticipants WHERE first_name = 'Ann' AND last_name = 'Imported'")->fetch_assoc();
        $this->assertSame('Active', $ann['status']);
        $this->assertNull($ann['city'], 'blank optional fields are stored as NULL');
        $this->assertSame('2026-08-04', $ann['registration_date']);
        $pets = $con->query('SELECT name, species, age_years, age_recorded_date, status FROM dbpets WHERE participant_id = '
            . (int) $ann['id'] . ' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $con->close();
        $this->assertSame(['Max', 'Luna'], array_column($pets, 'name'));
        $this->assertSame('0.5', $pets[0]['age_years']);
        $this->assertSame(date('Y-m-d'), $pets[0]['age_recorded_date'], 'ages count up from the import (FR-077)');
        $this->assertSame('Active', $pets[1]['status']);
    }

    public function testAPetWithNoAgeOrWeightIsStoredWithNulls(): void
    {
        import_households([$this->household('Cy', [$this->petRow('Shadow', ['breed' => null, 'age_years' => null, 'weight_lbs' => null])])]);
        $con = $this->db();
        $pet = $con->query("SELECT breed, age_years, age_recorded_date, weight_lbs FROM dbpets WHERE name = 'Shadow'")->fetch_assoc();
        $con->close();
        $this->assertSame(['breed' => null, 'age_years' => null, 'age_recorded_date' => null, 'weight_lbs' => null], $pet);
    }

    public function testNothingIsSavedWhenAnyRowFails(): void
    {
        $participants = $this->rowCount('dbparticipants');
        $pets = $this->rowCount('dbpets');
        try {
            import_households([
                $this->household('Ann', [$this->petRow('Max')]),
                // Longer than dbpets.species holds, so the insert fails part way through
                $this->household('Bob', [$this->petRow('Rex', ['species' => str_repeat('x', 200)])]),
            ]);
            $this->fail('the import should fail');
        } catch (\RuntimeException $e) {
            // expected
        }
        $this->assertSame($participants, $this->rowCount('dbparticipants'));
        $this->assertSame($pets, $this->rowCount('dbpets'));
    }
}
