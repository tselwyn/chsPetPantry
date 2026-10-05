<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Text\Fold;

/** Phonetic key v1 against tests/fixtures/phonetic_key.json, the cases the Station's js/fold.js phoneticKey() also passes. */
final class PhoneticKeyFixtureTest extends TestCase
{
    public function testEveryCase(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/phonetic_key.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
        $this->assertCount(28, $cases);
        foreach ($cases as $case) {
            $this->assertSame($case['out'], Fold::phonetic($case['in']), json_encode($case['in'], JSON_THROW_ON_ERROR));
        }
    }
}
