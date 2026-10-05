<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Text\Fold;

/** Accent fold v1 against tests/fixtures/accent_fold.json, the cases the Station's js/fold.js accentFold() also passes. */
final class AccentFoldFixtureTest extends TestCase
{
    public function testEveryCase(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/accent_fold.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
        $this->assertCount(42, $cases);
        foreach ($cases as $case) {
            $this->assertSame($case['out'], Fold::fold($case['in']), json_encode($case['in'], JSON_THROW_ON_ERROR));
        }
    }
}
