<?php
declare(strict_types=1);

namespace Pfpms\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfpms\Http\Json;
use Pfpms\Http\Request;

/** Typed access to a decoded JSON body with no silent casts (50-design §5.3): a wrong type is null, like an absent key. */
final class JsonTest extends TestCase
{
    /** As the endpoints see a body: through Request::parseJson(). */
    private static function body(string $json): array
    {
        return Request::parseJson($json, 'application/json', 65536);
    }

    public function testStringNeverTrimsAndRespectsTheLength(): void
    {
        $data = self::body('{"padded":"  Front desk 3 \n","accents":"ééé","long":"abcd","empty":""}');
        $this->assertSame("  Front desk 3 \n", Json::string($data, 'padded'), 'never trimmed');
        $this->assertSame('ééé', Json::string($data, 'accents', 3), 'the length is in characters, not bytes');
        $this->assertSame('abcd', Json::string($data, 'long', 4), 'exactly the maximum');
        $this->assertNull(Json::string($data, 'long', 3), 'one over the maximum');
        $this->assertSame('', Json::string($data, 'empty'), 'an empty string is still a string');
        $this->assertNull(Json::string($data, 'missing'));
    }

    public function testStringRefusesOtherTypes(): void
    {
        $data = self::body('{"int":5,"float":1.5,"bool":true,"null":null,"list":["a"],"object":{"a":"b"}}');
        foreach (['int', 'float', 'bool', 'null', 'list', 'object'] as $key) {
            $this->assertNull(Json::string($data, $key), $key);
        }
    }

    public function testIntRefusesStringsFloatsAndBooleans(): void
    {
        $data = self::body('{"int":5,"string":"5","float":5.0,"exp":1e3,"true":true,"false":false,"null":null,"zero":0,"negative":-3}');
        $this->assertSame(5, Json::int($data, 'int'));
        $this->assertSame(0, Json::int($data, 'zero'), 'zero is an integer, not absent');
        $this->assertSame(-3, Json::int($data, 'negative'));
        foreach (['string', 'float', 'exp', 'true', 'false', 'null', 'missing'] as $key) {
            $this->assertNull(Json::int($data, $key), $key);
        }
    }

    public function testIntRespectsTheRange(): void
    {
        $data = ['low' => 0, 'min' => 1, 'max' => 1000, 'high' => 1001];
        $this->assertNull(Json::int($data, 'low', 1, 1000));
        $this->assertSame(1, Json::int($data, 'min', 1, 1000), 'the minimum is inclusive');
        $this->assertSame(1000, Json::int($data, 'max', 1, 1000), 'the maximum is inclusive');
        $this->assertNull(Json::int($data, 'high', 1, 1000));
        $this->assertSame(PHP_INT_MAX, Json::int(['n' => PHP_INT_MAX], 'n'), 'the default range is every integer');
    }

    public function testBoolOnlyAcceptsTrueAndFalse(): void
    {
        $data = self::body('{"yes":true,"no":false,"one":1,"zero":0,"text":"true","null":null}');
        $this->assertTrue(Json::bool($data, 'yes'));
        $this->assertFalse(Json::bool($data, 'no'), 'false is a value, not absent');
        foreach (['one', 'zero', 'text', 'null', 'missing'] as $key) {
            $this->assertNull(Json::bool($data, $key), $key);
        }
    }

    public function testListAndObjectAreToldApart(): void
    {
        $data = self::body('{"list":[1,{"a":2}],"object":{"a":1,"b":[2]},"empty":[],"emptyObject":{},"text":"[]"}');
        $this->assertSame([1, ['a' => 2]], Json::list($data, 'list'));
        $this->assertNull(Json::object($data, 'list'), 'a list is not an object');
        $this->assertSame(['a' => 1, 'b' => [2]], Json::object($data, 'object'));
        $this->assertNull(Json::list($data, 'object'), 'an object is not a list');
        $this->assertSame([], Json::list($data, 'empty'), 'an empty array is a list');
        $this->assertSame([], Json::object($data, 'emptyObject'), 'an empty object is an object');
        $this->assertNull(Json::list($data, 'text'));
        $this->assertNull(Json::object($data, 'text'));
        $this->assertNull(Json::list($data, 'missing'));
        $this->assertNull(Json::object($data, 'missing'));
    }

    public function testListRespectsItsMaximumItems(): void
    {
        $data = ['groups' => array_fill(0, 20, ['count' => 1])];
        $this->assertCount(20, Json::list($data, 'groups', 20), 'exactly the maximum');
        $this->assertNull(Json::list($data, 'groups', 19), 'one over the maximum');
    }

    public function testObjectRefusesMixedKeys(): void
    {
        $this->assertNull(Json::object(['o' => ['a' => 1, 0 => 2]], 'o'), 'a key that is not a string');
    }
}
