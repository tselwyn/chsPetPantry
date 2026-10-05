<?php
declare(strict_types=1);

namespace Pfpms\Sync;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Canonical JSON v1 (docs/design/50-design-station.md §3.6, D-33), byte for byte the same as the Station's
 * js/canonical.js. The item HMAC covers these exact bytes, so the server never re-serialises a tablet's item:
 * it checks that the bytes it received ARE the canonical form (isCanonical) and refuses anything else.
 *
 * - Values: objects (keys ^[a-z][a-z0-9_]{0,63}$, unique, sorted by byte), arrays, strings (valid UTF-8),
 *   integers with |n| ≤ 2^53−1, true, false and null. No floats, no whitespace, at most 16 levels of
 *   containers and 65 536 bytes.
 * - Strings are escaped exactly as JSON.stringify does: \" \\ \b \f \n \r \t, other controls as lower-case
 *   \u00xx; the slash, U+007F, U+2028 and U+2029 are literal. tests/fixtures/canonical_json.json pins both sides.
 */
final class Canonical
{
    public const MAX_BYTES = 65536;
    /** Container nesting (the top-level object or array is 1). json_decode's depth counts one more. */
    public const MAX_DEPTH = 16;
    public const MAX_INT = 9007199254740991;
    private const KEY = '/^[a-z][a-z0-9_]{0,63}$/D';
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    /**
     * The canonical text of a decoded value: null, bool, int (|n| ≤ 2^53−1), string (valid UTF-8), a list array,
     * or stdClass (an object; an empty PHP array is the empty JSON array).
     * @throws InvalidArgumentException with the reason as its message: number, string, key, depth, type, too_large
     */
    public static function encode(mixed $value): string
    {
        $text = self::emit($value, 0);
        if (strlen($text) > self::MAX_BYTES) {
            throw new InvalidArgumentException('too_large');
        }
        return $text;
    }

    /** The object in $bytes (objects as stdClass, so {} and [] stay apart), or null when it is not one JSON object. */
    public static function decodeObject(string $bytes): ?stdClass
    {
        $value = self::decode($bytes);
        return $value instanceof stdClass ? $value : null;
    }

    /** True only when $bytes is exactly the canonical text of the value it holds (any JSON value). Never throws. */
    public static function isCanonical(string $bytes): bool
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            return false;
        }
        try {
            $value = json_decode($bytes, false, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            return self::encode($value) === $bytes;
        } catch (JsonException | InvalidArgumentException) {
            return false;
        }
    }

    private static function decode(string $bytes): mixed
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        try {
            return json_decode($bytes, false, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return null;
        }
    }

    private static function emit(mixed $v, int $depth): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            if ($v > self::MAX_INT || $v < -self::MAX_INT) {
                throw new InvalidArgumentException('number');
            }
            return (string) $v;
        }
        if (is_string($v)) {
            if (!mb_check_encoding($v, 'UTF-8')) {
                throw new InvalidArgumentException('string');
            }
            return json_encode($v, self::FLAGS);
        }
        if (is_array($v) || $v instanceof stdClass) {
            if ($depth + 1 > self::MAX_DEPTH) {
                throw new InvalidArgumentException('depth');
            }
            if (is_array($v)) {
                if (!array_is_list($v)) {
                    throw new InvalidArgumentException('type');
                }
                return '[' . implode(',', array_map(static fn(mixed $x): string => self::emit($x, $depth + 1), $v)) . ']';
            }
            $fields = get_object_vars($v);
            $keys = array_map('strval', array_keys($fields));
            foreach ($keys as $k) {
                if (preg_match(self::KEY, $k) !== 1) {
                    throw new InvalidArgumentException('key');
                }
            }
            sort($keys, SORT_STRING);
            $parts = [];
            foreach ($keys as $k) {
                $parts[] = '"' . $k . '":' . self::emit($fields[$k], $depth + 1);
            }
            return '{' . implode(',', $parts) . '}';
        }
        throw new InvalidArgumentException('type'); // floats; integers beyond 64 bits arrive as strings and so re-encode differently
    }
}
