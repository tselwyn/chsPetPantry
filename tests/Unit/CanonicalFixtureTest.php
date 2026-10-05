<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Sync\Canonical;

/** Canonical JSON v1 against tests/fixtures/canonical_json.json, the cases the Station's js/canonical.js also passes. */
final class CanonicalFixtureTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/canonical_json.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testValidCasesEncodeToTheirBytes(): void
    {
        $cases = self::fixture()['valid'];
        $this->assertCount(18, $cases);
        foreach ($cases as $case) {
            $value = json_decode($case['json'], false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $this->assertSame($case['bytes'], Canonical::encode($value), $case['name']);
        }
    }

    public function testValidBytesAreCanonical(): void
    {
        foreach (self::fixture()['valid'] as $case) {
            $this->assertTrue(Canonical::isCanonical($case['bytes']), $case['name']);
            $object = Canonical::decodeObject($case['bytes']);
            if ($object !== null) {
                $this->assertSame($case['bytes'], Canonical::encode($object), $case['name'] . ': decodeObject then encode round-trips');
            }
        }
    }

    public function testInvalidCasesAreNotCanonical(): void
    {
        $cases = self::fixture()['invalid'];
        $this->assertCount(26, $cases);
        foreach ($cases as $case) {
            $bytes = isset($case['bytes_hex']) ? (string) hex2bin($case['bytes_hex']) : $case['bytes'];
            if (isset($case['bytes_hex'])) {
                $this->assertFalse(mb_check_encoding($bytes, 'UTF-8'), $case['name'] . ' is not UTF-8');
            }
            $this->assertFalse(Canonical::isCanonical($bytes), $case['name']);
        }
    }

    public function testSizedCases(): void
    {
        $cases = self::fixture()['sized'];
        $this->assertCount(2, $cases);
        foreach ($cases as $case) {
            $bytes = '{"s":"' . str_repeat('a', $case['total_bytes'] - 8) . '"}';
            $this->assertSame($case['total_bytes'], strlen($bytes));
            $this->assertSame($case['canonical'], Canonical::isCanonical($bytes), $case['name']);
        }
    }
}
