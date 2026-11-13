<?php
declare(strict_types=1);

namespace Pfpms\Tests\Integration;

/**
 * UC-02 / FR-008 / FR-074: search finds a participant by the PP00012 ID.
 * UC-03 / FR-018 (Sprint 5 decision): a possible duplicate is ANY match on
 * phone, email, full name, or last name + street address.
 */
final class ParticipantSearchAndDuplicatesTest extends DatabaseTestCase
{
    public function testSearchFindsAParticipantByTheirPpId(): void
    {
        $this->participant();
        $id = $this->participant(['first_name' => 'Maria', 'last_name' => 'Gonzalez']);

        foreach ([format_participant_id($id), strtolower(format_participant_id($id)), (string) $id] as $typed) {
            $found = find_participants($typed);
            $this->assertNotEmpty($found, $typed);
            $this->assertSame($id, (int) $found[0]->get_id(), "$typed is the first result");
        }
    }

    private function existing(): int
    {
        return $this->participant([
            'first_name' => 'Maria', 'last_name' => 'Gonzalez', 'street_address' => '412 Princess Anne St',
            'phone' => '5405550101', 'email' => 'maria@example.com',
        ]);
    }

    /** Duplicate matches for a new registration, as participant ids. */
    private function duplicateIds(string $first, string $last, string $phone, string $email, string $street): array
    {
        return array_map('intval', array_column(find_possible_duplicate_participants($first, $last, $phone, $email, $street), 'id'));
    }

    public function testEachOfTheFourRulesFlagsADuplicate(): void
    {
        $id = $this->existing();
        $this->assertSame([$id], $this->duplicateIds('Ann', 'Lee', '540-555-0101', '', '1 Elm St'), 'same phone, any format');
        $this->assertSame([$id], $this->duplicateIds('Ann', 'Lee', '', 'MARIA@example.com', '1 Elm St'), 'same email, any case');
        $this->assertSame([$id], $this->duplicateIds('maria', 'gonzalez', '', '', '1 Elm St'), 'same full name');
        $this->assertSame([$id], $this->duplicateIds('Rosa', 'Gonzalez', '', '', '412 princess anne st'), 'same last name and street');
    }

    public function testSomeoneNewIsNotFlagged(): void
    {
        $this->existing();
        $this->assertSame([], $this->duplicateIds('Ann', 'Lee', '540-555-0199', 'ann@example.com', '1 Elm St'));
        $this->assertSame([], $this->duplicateIds('Rosa', 'Gonzalez', '', '', '9 Oak Ave'), 'same last name alone is not enough');
    }

    public function testABlankEmailDoesNotMatchEveryoneWithoutOne(): void
    {
        // Email is optional at registration, so blank must not count as a match
        $this->participant(['email' => '']);
        $this->assertSame([], $this->duplicateIds('Ann', 'Lee', '540-555-0199', '', '1 Elm St'));
    }
}
