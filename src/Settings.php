<?php
declare(strict_types=1);

namespace Pfpms;

/**
 * Typed access to system_setting. All rows are read once per request.
 * Every caller supplies the default used if a row is missing, so a partially
 * seeded database still behaves predictably.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function string(string $key, string $default = ''): string
    {
        return self::raw($key) ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::raw($key);
        return $value !== null && preg_match('/^-?\d+$/', trim($value)) ? (int) $value : $default;
    }

    public static function float(string $key, float $default): float
    {
        $value = self::raw($key);
        return $value !== null && is_numeric(trim($value)) ? (float) $value : $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::raw($key);
        return $value === null || trim($value) === '' ? $default : in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function reload(): void
    {
        self::$cache = null;
    }

    private static function raw(string $key): ?string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::pdo()->query('SELECT setting_key, setting_value FROM system_setting') as $row) {
                self::$cache[$row['setting_key']] = (string) $row['setting_value'];
            }
        }
        return self::$cache[$key] ?? null;
    }
}
