<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** UC-03 / FR-074: Participant IDs are shown as PP followed by five digits. */
final class ParticipantIdTest extends TestCase
{
    public function testIdsAreShownAsPpAndFiveDigits(): void
    {
        $this->assertSame('PP00001', format_participant_id(1));
        $this->assertSame('PP00012', format_participant_id(12));
        $this->assertSame('PP00012', format_participant_id('12'), 'a numeric string from the database');
        $this->assertSame('PP99999', format_participant_id(99999));
        $this->assertSame('PP100000', format_participant_id(100000), 'longer IDs keep every digit');
    }

    public static function typedIds(): array
    {
        return [
            'shown form' => ['PP00012', 12],
            'lower case, no zeros' => ['pp12', 12],
            'with a dash' => ['PP-00012', 12],
            'spaces around' => ['  PP00012 ', 12],
            'plain number' => ['12', 12],
            'hash' => ['#12', 12],
        ];
    }

    #[DataProvider('typedIds')]
    public function testTypedIdsAreRecognised(string $typed, int $id): void
    {
        $this->assertSame($id, parse_participant_id($typed));
    }

    public static function notIds(): array
    {
        return [[''], ['PP'], ['PP00000'], ['0'], ['abc'], ['12a'], ['PP12.5'], ['540-555-0101'], ['PP1234567890']];
    }

    #[DataProvider('notIds')]
    public function testOtherTextIsNotAnId(string $typed): void
    {
        $this->assertNull(parse_participant_id($typed));
    }

    public function testFormatAndParseRoundTrip(): void
    {
        foreach ([1, 7, 450, 12345, 678901] as $id) {
            $this->assertSame($id, parse_participant_id(format_participant_id($id)));
        }
    }
}
