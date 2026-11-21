<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use Pet;
use PHPUnit\Framework\TestCase;

/** UC-05 / FR-077 / SRS risk 6.2: an entered pet age counts up over time. */
final class PetAgeTest extends TestCase
{
    public function testTheAgeGrowsWithTimeSinceItWasEntered(): void
    {
        $this->assertSame(2.0, pet_current_age('2.0', '2026-11-21', '2026-11-21'), 'same day');
        $this->assertSame(2.5, pet_current_age('2.0', '2026-11-21', '2027-05-22'), 'half a year later');
        $this->assertSame(3.0, pet_current_age('2.0', '2026-11-21', '2027-11-21'), 'a year later');
        $this->assertSame(10.5, pet_current_age(0.5, '2016-11-21', '2026-11-21'), 'a puppy ten years on');
    }

    public function testRecordsWithoutADateShowTheAgeAsEntered(): void
    {
        $this->assertSame(4.0, pet_current_age('4.0', null, '2030-01-01'));
        $this->assertSame(4.0, pet_current_age('4.0', '', '2030-01-01'));
    }

    public function testNoAgeStaysUnknown(): void
    {
        $this->assertNull(pet_current_age(null, '2026-01-01', '2026-11-21'));
        $this->assertSame('Unknown', pet_age_label(null));
    }

    public function testADateInTheFutureNeverMakesAPetYounger(): void
    {
        $this->assertSame(1.0, pet_current_age('1.0', '2027-01-01', '2026-01-01'));
    }

    public function testLabelsDropATrailingZero(): void
    {
        $this->assertSame('3', pet_age_label(3.0));
        $this->assertSame('0.5', pet_age_label(0.5));
        $this->assertSame('12.4', pet_age_label(12.4));
    }

    private function pet(string $age, ?string $date): Pet
    {
        return new Pet(1, 1, 'Biscuit', 'Dog', 'Beagle', 'Male', $age, 25, 'Yes', 'Yes', null, null, null,
            'Active', 'No', null, null, null, $date);
    }

    public function testEditingWithoutChangingTheAgeKeepsTheOriginalEntry(): void
    {
        $pet = $this->pet('2.0', date('Y-m-d', strtotime('-1 year')));
        $shown = pet_age_label($pet->get_current_age_years());
        $this->assertSame('3', $shown, 'the edit form shows the age today');

        $this->assertSame([2.0, $pet->get_age_recorded_date()], pet_age_to_save($pet, $shown),
            'saving it unchanged keeps counting from the first entry, with no rounding drift');
    }

    public function testChangingTheAgeStartsAgainFromToday(): void
    {
        $pet = $this->pet('2.0', date('Y-m-d', strtotime('-1 year')));
        $this->assertSame([5.0, null], pet_age_to_save($pet, '5'), 'null means today');
    }

    public function testAnOldRecordWithoutADateGetsTodayWhenSaved(): void
    {
        $this->assertSame([2.0, null], pet_age_to_save($this->pet('2.0', null), '2'));
    }
}
