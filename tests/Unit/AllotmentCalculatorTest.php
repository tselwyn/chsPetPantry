<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use OverflowException;
use PHPUnit\Framework\TestCase;
use Pfpms\Allotment\AllotmentCalculator;
use Pfpms\Allotment\MissingAllotmentRule;

/** The allotment arithmetic against the shared fixtures the Station's JS port must also pass. */
final class AllotmentCalculatorTest extends TestCase
{
    private static function fixtures(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/allotment_calculator.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testVersionInForceOnADate(): void
    {
        foreach (self::fixtures()['version_on'] as $case) {
            $this->assertSame($case['expected'], AllotmentCalculator::versionOn($case['published'], $case['date']), $case['name']);
        }
    }

    public function testEntitlement(): void
    {
        foreach (self::fixtures()['entitlement'] as $case) {
            try {
                $result = AllotmentCalculator::entitlement($case['rules'], $case['pets']);
                $this->assertArrayNotHasKey('error', $case, $case['name'] . ': expected an error');
                $this->assertSame($case['expected'], $result, $case['name']);
            } catch (MissingAllotmentRule $e) {
                $this->assertSame('missing_rule', $case['error'] ?? null, $case['name']);
                $this->assertSame([$case['species_id'], $case['size_band_id']], [$e->speciesId, $e->sizeBandId], $case['name']);
            } catch (OverflowException $e) {
                $this->assertSame('overflow', $case['error'] ?? null, $case['name']);
            }
        }
    }
}
