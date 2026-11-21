<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

/** UC-05 / FR-077: the date an age was entered is saved, so the age counts up. */
final class PetAgeStorageTest extends DatabaseTestCase
{
    public function testANewPetIsSavedWithTodaysDate(): void
    {
        $owner = $this->participant();
        $args = array_merge(pet_form_defaults(), ['name' => 'Rex', 'species' => 'Dog', 'breed' => 'Lab', 'sex' => 'Male',
            'age_years' => '3', 'weight_lbs' => '60', 'spay_neuter' => 'Yes', 'rabies_status' => 'Yes']);

        $id = add_pet(pet_from_form(null, $owner, $args, 'Active'));

        $this->assertNotFalse($id);
        $pet = get_pet_by_id($id);
        $this->assertSame(date('Y-m-d'), $pet->get_age_recorded_date());
        $this->assertSame(3.0, $pet->get_current_age_years());
    }

    public function testAStoredAgeIsShownCountedUp(): void
    {
        $id = $this->pet($this->participant(), ['age_years' => '2.0', 'age_recorded_date' => date('Y-m-d', strtotime('-6 months'))]);
        $this->assertSame(2.5, get_pet_by_id($id)->get_current_age_years());
    }

    public function testEditingKeepsOrResetsTheDate(): void
    {
        $owner = $this->participant();
        $yearAgo = date('Y-m-d', strtotime('-1 year'));
        $id = $this->pet($owner, ['age_years' => '2.0', 'age_recorded_date' => $yearAgo]);
        $pet = get_pet_by_id($id);
        $args = array_merge(pet_form_defaults(), ['name' => 'Biscuit', 'species' => 'Dog', 'breed' => 'Beagle', 'sex' => 'Male',
            'weight_lbs' => '25', 'spay_neuter' => 'Yes', 'rabies_status' => 'Yes']);

        // Saved with the age left as shown (3): nothing about the age changes
        [$age, $date] = pet_age_to_save($pet, '3');
        $this->assertTrue(update_pet(pet_from_form($id, $owner, array_merge($args, ['age_years' => (string) $age]), 'Active', $date)));
        $saved = get_pet_by_id($id);
        $this->assertSame('2.0', (string) $saved->get_age_years());
        $this->assertSame($yearAgo, $saved->get_age_recorded_date());

        // Saved with a corrected age: stored as entered, counting from today
        [$age, $date] = pet_age_to_save($saved, '7');
        $this->assertTrue(update_pet_with_counseling(pet_from_form($id, $owner, array_merge($args, ['age_years' => (string) $age]), 'Active', $date), false, 'chsadmin'));
        $saved = get_pet_by_id($id);
        $this->assertSame('7.0', (string) $saved->get_age_years());
        $this->assertSame(date('Y-m-d'), $saved->get_age_recorded_date());
    }

    public function testTheColumnIsAddedIfTheMigrationWasNeverRun(): void
    {
        $con = $this->db();
        $con->query('ALTER TABLE dbpets DROP COLUMN age_recorded_date');
        $con->close();
        $owner = $this->participant();
        $oldPet = $this->pet($owner, ['age_years' => '4.0']);

        $args = array_merge(pet_form_defaults(), ['name' => 'Rex', 'species' => 'Dog', 'breed' => 'Lab', 'sex' => 'Male',
            'age_years' => '3', 'weight_lbs' => '60', 'spay_neuter' => 'Yes', 'rabies_status' => 'Yes']);
        $id = add_pet(pet_from_form(null, $owner, $args, 'Active'));

        $this->assertNotFalse($id, 'the first save adds the column');
        $this->assertSame(date('Y-m-d'), get_pet_by_id($id)->get_age_recorded_date());
        $this->assertSame(date('Y-m-d'), get_pet_by_id($oldPet)->get_age_recorded_date(), 'existing ages are dated from when the pet was added');
    }
}
