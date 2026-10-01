<?php
declare(strict_types=1);

namespace Pfpms\Http;

/**
 * Typed access to a decoded JSON object (Request::json()), with no silent casts: a value of the
 * wrong type, or out of range, is null, like an absent one. Strings are never trimmed.
 */
final class Json
{
    /** Only a JSON string of at most $maxLength characters. */
    public static function string(array $data, string $key, int $maxLength = 1000): ?string
    {
        $value = $data[$key] ?? null;
        return is_string($value) && mb_strlen($value) <= $maxLength ? $value : null;
    }

    /** Only a JSON integer (not "5", 5.0 or true) within the range. */
    public static function int(array $data, string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $value = $data[$key] ?? null;
        return is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }

    /** Only true or false. */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;
        return is_bool($value) ? $value : null;
    }

    /** Only a JSON array (a list) of at most $maxItems items. */
    public static function list(array $data, string $key, int $maxItems = 1000): ?array
    {
        $value = $data[$key] ?? null;
        return is_array($value) && array_is_list($value) && count($value) <= $maxItems ? $value : null;
    }

    /** Only a JSON object (string keys, or empty). */
    public static function object(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return null;
        }
        foreach (array_keys($value) as $k) {
            if (!is_string($k)) {
                return null;
            }
        }
        return $value;
    }
}
