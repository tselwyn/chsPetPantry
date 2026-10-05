<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Inventory\Barcode;

/** Barcode normalisation (US-16) against the shared fixtures the Station's JS port must also pass. */
final class BarcodeTest extends TestCase
{
    private static function fixtures(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/barcode.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testCandidatesNormaliseDisplayAndStoreSpecific(): void
    {
        foreach (self::fixtures()['candidates'] as $case) {
            $this->assertSame($case['candidates'], Barcode::candidates($case['raw'], $case['hint']), $case['name']);
            $this->assertSame($case['candidates'][0] ?? null, Barcode::normalise($case['raw'], $case['hint']), $case['name']);
            if (isset($case['display'])) {
                $this->assertSame($case['display'], Barcode::display($case['candidates'][0]), $case['name']);
            }
            if (isset($case['store_specific'])) {
                $this->assertSame($case['store_specific'], Barcode::storeSpecific($case['candidates'][0]), $case['name']);
            }
        }
    }

    public function testCheckDigitAndUpcEExpansion(): void
    {
        foreach (self::fixtures()['check_digit'] as $case) {
            $this->assertSame($case['ok'], Barcode::checkDigitOk($case['digits']), $case['digits']);
        }
        foreach (self::fixtures()['expand_upc_e'] as $case) {
            $this->assertSame($case['upc_a'], Barcode::expandUpcE($case['upc_e']), $case['upc_e']);
        }
    }
}
