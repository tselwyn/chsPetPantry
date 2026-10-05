<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Participant\ParticipantName;

/** US-05: the preferred name is shown first wherever one is recorded, with the legal name available beside it. */
final class ParticipantNameTest extends TestCase
{
    public function testThePreferredNameComesFirstAndTheLegalNameStaysAvailable(): void
    {
        $liz = ['legal_first_name' => 'Elizabeth', 'legal_last_name' => 'Johnson', 'preferred_name' => 'Liz'];
        $this->assertSame('Liz', ParticipantName::display($liz));
        $this->assertSame('Elizabeth Johnson', ParticipantName::legal($liz));
        $this->assertSame('Elizabeth Johnson', ParticipantName::legalIfDifferent($liz));
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
    }
}
