<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Participant\ParticipantName;

/** US-05: the preferred name is shown first wherever one is recorded, with the legal name available beside it. */
final class ParticipantNameTest extends TestCase
{
    public function testAOneWordPreferredNameTakesTheLegalSurname(): void
    {
        $liz = ['legal_first_name' => 'Elizabeth', 'legal_last_name' => 'Johnson', 'preferred_name' => 'Liz'];
        $this->assertSame('Liz Johnson', ParticipantName::display($liz));
        $this->assertSame('Elizabeth Johnson', ParticipantName::legal($liz));
        $this->assertSame('Elizabeth Johnson', ParticipantName::legalIfDifferent($liz), 'the full legal name goes underneath');
        $this->assertSame('Mary-Jo Peña', ParticipantName::display(['legal_first_name' => 'Maria', 'legal_last_name' => 'Peña', 'preferred_name' => ' Mary-Jo ']),
            'a hyphen does not make it a full name');
    }

    public function testAPreferredNameWithASurnameIsShownAsItIs(): void
    {
        $alex = ['legal_first_name' => 'Alexandra', 'legal_last_name' => 'Smith', 'preferred_name' => 'Alex  Rivera'];
        $this->assertSame('Alex Rivera', ParticipantName::display($alex), 'spaces tidied, no surname added');
        $this->assertSame('Alexandra Smith', ParticipantName::legalIfDifferent($alex));
    }

    public function testWithoutAPreferredNameTheLegalNameIsShownOnce(): void
    {
        foreach ([null, '', '  '] as $preferred) {
            $p = ['legal_first_name' => 'José', 'legal_last_name' => 'Muñoz', 'preferred_name' => $preferred];
            $this->assertSame('José Muñoz', ParticipantName::display($p), var_export($preferred, true));
            $this->assertNull(ParticipantName::legalIfDifferent($p), 'not repeated');
        }
        $this->assertNull(ParticipantName::legalIfDifferent(['legal_first_name' => 'Ann', 'legal_last_name' => 'Lee', 'preferred_name' => 'Ann Lee']),
            'a preferred name equal to the legal name is not shown twice');
        $this->assertNull(ParticipantName::legalIfDifferent(['legal_first_name' => 'Ann', 'legal_last_name' => 'Lee', 'preferred_name' => 'Ann']),
            'nor is a preferred first name equal to the legal one');
    }
}
