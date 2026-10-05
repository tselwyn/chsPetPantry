<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pfpms\Sync\Canonical;
use stdClass;

/** Canonical JSON v1 (50-design §3.6, D-33): the refusals the item HMAC relies on. The shared cases are in CanonicalFixtureTest. */
final class CanonicalTest extends TestCase
{
    /** The reason encode() gives for $value, or null when it encodes. */
    private static function reason(mixed $value): ?string
    {
        try {
            Canonical::encode($value);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return null;
    }

    private static function nest(int $levels): string
    {
        return str_repeat('[', $levels) . '1' . str_repeat(']', $levels);
    }

    public function testDuplicateKeysAreNotCanonical(): void
    {
        $this->assertFalse(Canonical::isCanonical('{"a":1,"a":1}'), 'the same key twice, even with the same value');
        $this->assertFalse(Canonical::isCanonical('{"a":1,"a":2}'));
        $this->assertFalse(Canonical::isCanonical('{"a":1,"b":{"c":1,"c":1}}'), 'inside a nested object too');
        $this->assertTrue(Canonical::isCanonical('{"a":1}'));
    }

    public function testFloatsAndBigIntegersAreRefused(): void
    {
        foreach ([1.5, 1.0, 0.0, -0.5, 1e2, NAN, INF] as $float) {
            $this->assertSame('type', self::reason($float), var_export($float, true));
        }
        foreach ([9007199254740992, -9007199254740992, PHP_INT_MAX, PHP_INT_MIN] as $int) {
            $this->assertSame('number', self::reason($int), (string) $int);
        }
        $this->assertSame('9007199254740991', Canonical::encode(Canonical::MAX_INT));
        $this->assertSame('-9007199254740991', Canonical::encode(-Canonical::MAX_INT));
        foreach (['{"a":1.5}', '{"a":1.0}', '{"a":1e2}', '{"a":1E2}', '{"a":9007199254740992}', '{"a":99999999999999999999}', '{"a":-0}'] as $bytes) {
            $this->assertFalse(Canonical::isCanonical($bytes), $bytes);
        }
        $this->assertTrue(Canonical::isCanonical('{"a":9007199254740991}'));
    }

    public function testEmptyObjectAndEmptyArrayStayApart(): void
    {
        $this->assertSame('{}', Canonical::encode(new stdClass()));
        $this->assertSame('[]', Canonical::encode([]));
        $decoded = Canonical::decodeObject('{"a":{},"b":[]}');
        $this->assertInstanceOf(stdClass::class, $decoded);
        $this->assertInstanceOf(stdClass::class, $decoded->a);
        $this->assertSame([], $decoded->b);
        $this->assertSame('{"a":{},"b":[]}', Canonical::encode($decoded));
        $this->assertTrue(Canonical::isCanonical('{}'));
        $this->assertTrue(Canonical::isCanonical('[]'));
    }

    public function testUnicodeKeysAreRefused(): void
    {
        foreach (["\u{00F1}", 'A', 'aB', '1a', '_a', 'a-b', 'a b', '', "a\n", str_repeat('k', 65)] as $key) {
            $this->assertSame('key', self::reason((object) [$key => 1]), json_encode($key, JSON_THROW_ON_ERROR));
        }
        $this->assertFalse(Canonical::isCanonical("{\"\u{00F1}\":1}"));
        $this->assertFalse(Canonical::isCanonical('{"\u00f1":1}'), 'escaped as well');
        $this->assertSame('{"' . str_repeat('k', 64) . '":1}', Canonical::encode((object) [str_repeat('k', 64) => 1]));
        $this->assertSame('{"a":3,"a0":2,"a_1":1}', Canonical::encode((object) ['a_1' => 1, 'a0' => 2, 'a' => 3]), 'keys sort by byte');
    }

    public function testLineSeparatorsAreNotEscaped(): void
    {
        $this->assertSame("\"a\u{2028}b\u{2029}c\"", Canonical::encode("a\u{2028}b\u{2029}c"));
        $this->assertSame('"a/b"', Canonical::encode('a/b'), 'the slash is literal');
        $this->assertSame("\"\u{007F}\"", Canonical::encode("\u{007F}"), 'U+007F is literal');
        $this->assertSame('"\u0000\u001f\b\t\n\f\r\"\\\\"', Canonical::encode("\0\x1F\x08\t\n\x0C\r\"\\"), 'controls as JSON.stringify writes them');
        $this->assertSame("\"Mu\u{00F1}oz \u{1F600}\"", Canonical::encode("Mu\u{00F1}oz \u{1F600}"), 'letters and emoji are literal');
        $this->assertFalse(Canonical::isCanonical('"\u2028"'));
        $this->assertTrue(Canonical::isCanonical("\"\u{2028}\""));
    }

    public function testDepthSixteenIsAllowedAndSeventeenIsNot(): void
    {
        $this->assertTrue(Canonical::isCanonical(self::nest(16)));
        $this->assertFalse(Canonical::isCanonical(self::nest(17)));
        $this->assertSame(self::nest(16), Canonical::encode(json_decode(self::nest(16), false, 512, JSON_THROW_ON_ERROR)));
        $this->assertSame('depth', self::reason(json_decode(self::nest(17), false, 512, JSON_THROW_ON_ERROR)));

        $object = new stdClass();
        for ($i = 1; $i < Canonical::MAX_DEPTH; $i++) {
            $object = (object) ['a' => $object];
        }
        $this->assertNull(self::reason($object), 'sixteen levels of objects');
        $this->assertSame('depth', self::reason((object) ['a' => $object]), 'seventeen levels of objects');
        $this->assertInstanceOf(stdClass::class, Canonical::decodeObject(Canonical::encode($object)), 'decodeObject takes sixteen levels');
        $this->assertNull(Canonical::decodeObject('{"a":' . Canonical::encode($object) . '}'), 'and refuses seventeen');
    }

    public function testTheSizeLimitIsExact(): void
    {
        $this->assertSame(Canonical::MAX_BYTES, strlen(Canonical::encode((object) ['s' => str_repeat('a', Canonical::MAX_BYTES - 8)])));
        $this->assertSame('too_large', self::reason((object) ['s' => str_repeat('a', Canonical::MAX_BYTES - 7)]));
        $this->assertSame('too_large', self::reason((object) ['s' => str_repeat("\u{00F1}", intdiv(Canonical::MAX_BYTES - 6, 2))]), 'bytes, not characters');
        $this->assertNull(self::reason((object) ['s' => str_repeat("\u{00F1}", intdiv(Canonical::MAX_BYTES - 8, 2))]), 'two-byte letters up to the limit');
        $this->assertTrue(Canonical::isCanonical('{"s":"' . str_repeat('a', Canonical::MAX_BYTES - 8) . '"}'));
        $this->assertFalse(Canonical::isCanonical('{"s":"' . str_repeat('a', Canonical::MAX_BYTES - 7) . '"}'));
        $this->assertNull(Canonical::decodeObject('{"s":"' . str_repeat('a', Canonical::MAX_BYTES - 7) . '"}'), 'decodeObject refuses it too');
    }

    public function testDecodeObjectRefusesAnythingButAnObject(): void
    {
        foreach (['[1]', '[]', '"x"', '1', 'true', 'null', '', 'not json', '{"a":1', "{\"s\":\"\xFF\"}", '{"s":"\ud800"}', '[' . self::nest(16) . ']'] as $bytes) {
            $this->assertNull(Canonical::decodeObject($bytes), $bytes);
        }
        $object = Canonical::decodeObject('{"b":1,"a":{"c":[1,2]}}');
        $this->assertInstanceOf(stdClass::class, $object);
        $this->assertSame('{"a":{"c":[1,2]},"b":1}', Canonical::encode($object));
        $this->assertSame('99999999999999999999', Canonical::decodeObject('{"a":99999999999999999999}')?->a, 'a big integer arrives as a string');
    }

    public function testEncodeRefusesAnAssociativeArray(): void
    {
        $this->assertSame('type', self::reason(['a' => 1]));
        $this->assertSame('type', self::reason([1 => 'a', 0 => 'b']), 'a list must be in order');
        $this->assertSame('type', self::reason([1 => 'a']), 'a list must start at 0');
        $this->assertSame('type', self::reason([[1], ['a' => 1]]), 'nested too');
        $this->assertSame('[1,"a",null]', Canonical::encode([1, 'a', null]));
    }

    public function testEncodeErrorsNameTheirReason(): void
    {
        $this->assertSame('number', self::reason(9007199254740992));
        $this->assertSame('string', self::reason("\xFF"));
        $this->assertSame('string', self::reason(['a', "b\xC3"]), 'a cut UTF-8 sequence');
        $this->assertSame('key', self::reason((object) ['A' => 1]));
        $this->assertSame('depth', self::reason(json_decode(self::nest(17), false, 512, JSON_THROW_ON_ERROR)));
        $this->assertSame('type', self::reason(1.5));
        $this->assertSame('type', self::reason(new \DateTimeImmutable('@0')), 'an object other than stdClass');
        $this->assertSame('too_large', self::reason(str_repeat('a', Canonical::MAX_BYTES - 1)));
        $this->assertNull(self::reason(str_repeat('a', Canonical::MAX_BYTES - 2)), 'the quotes count');
    }
}
