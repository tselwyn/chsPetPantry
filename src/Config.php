<?php
declare(strict_types=1);

namespace Pfpms;

use RuntimeException;

/**
 * Application configuration, loaded from a PHP file that returns an array.
 *
 * Lookup order: explicit path, then the PFPMS_CONFIG environment variable, then
 * config/config.php. There is deliberately no fallback and no branching on
 * $_SERVER['SERVER_NAME']: a missing config is a fatal error, never a guess.
 */
final class Config
{
    private static ?array $data = null;
    private static ?string $path = null;

    public static function load(?string $path = null): void
    {
        $path ??= getenv('PFPMS_CONFIG') ?: APP_ROOT . '/config/config.php';
        if (!is_file($path)) {
            throw new RuntimeException("Config file not found: $path (copy config/config.example.php to config/config.php)");
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new RuntimeException("Config file must return an array: $path");
        }
        $env = $data['env'] ?? null;
        if (!in_array($env, ['dev', 'test', 'staging', 'prod'], true)) {
            throw new RuntimeException("Config 'env' must be one of dev, test, staging, prod");
        }
        self::$data = $data;
        self::$path = $path;
    }

    /** Dot-notation lookup, e.g. Config::get('db.host'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::all();
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function require(string $key): mixed
    {
        $value = self::get($key);
        if ($value === null) {
            throw new RuntimeException("Missing required config key '$key' in " . self::$path);
        }
        return $value;
    }

    public static function env(): string
    {
        return self::all()['env'];
    }

    public static function isProd(): bool
    {
        return self::env() === 'prod';
    }

    public static function path(): ?string
    {
        return self::$path;
    }

    /** Test helper: replace the loaded configuration. */
    public static function override(array $data): void
    {
        self::$data = $data;
    }

    /** Test helper: the whole configuration, to restore after override(). */
    public static function snapshot(): array
    {
        return self::all();
    }

    private static function all(): array
    {
        if (self::$data === null) {
            self::load();
        }
        return self::$data;
    }
}
