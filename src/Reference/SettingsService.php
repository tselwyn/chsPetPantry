<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Audit\Audit;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Settings;
use Pfpms\Validation\ValidationException;
use Pfpms\Validation\Validator;

/**
 * The system settings screen (plan P2A, admin_settings: typed, audited). Every setting is
 * described in settings_registry.php; this class checks new values against it, writes only
 * the settings that changed and records them as one audit entry.
 */
final class SettingsService
{
    /** Headings on the settings screen, in order. */
    public const GROUPS = ['Sign-in and accounts', 'Offline station', 'Participants and distribution', 'Spay/neuter', 'Import and reports', 'Retention'];

    /** [smaller, larger]: the first setting may not be more than the second. */
    private const ORDERED = [
        ['pin_min_digits', 'pin_max_digits'],
        ['voucher_reminder_days', 'voucher_expiry_days'],
        ['import_max_rows_sync', 'import_max_rows'],
    ];

    private static ?array $registry = null;

    /** @return array<string, array<string, mixed>> setting key => definition */
    public static function registry(): array
    {
        return self::$registry ??= require __DIR__ . '/settings_registry.php';
    }

    /**
     * Every setting with its current value, grouped for the settings screen.
     * @return list<array{name: string, settings: array<string, array<string, mixed>>}>
     */
    public static function grouped(): array
    {
        $rows = self::rows();
        $groups = array_fill_keys(self::GROUPS, []);
        foreach (self::registry() as $key => $def) {
            $value = isset($rows[$key]) ? (string) $rows[$key]['setting_value'] : '';
            $choices = $def['type'] === 'enum' ? self::choices($def) : [];
            if ($choices && $value !== '' && !isset($choices[$value])) {
                $choices[$value] = "$value (no longer available)";
            }
            $hint = self::hint($rows[$key]['description'] ?? null);
            $groups[$def['group']][$key] = $def + ['value' => $value, 'hint' => strcasecmp($hint, $def['label']) === 0 ? '' : $hint, 'choices' => $choices];
        }
        $out = [];
        foreach ($groups as $name => $settings) {
            if ($settings) {
                $out[] = ['name' => $name, 'settings' => $settings];
            }
        }
        return $out;
    }

    /**
     * Save the settings whose values changed. Keys that are not settings are ignored, and a
     * setting left out of $input keeps its value.
     *
     * @param array<string, mixed> $input setting key => new value, as typed
     * @param array<string, string> $original setting key => value when the form was loaded. A setting
     *        whose submitted value equals its original was not touched by this person and is skipped,
     *        so saving the form never puts back a value someone else changed in the meantime.
     * @return list<string> the keys that changed
     * @throws ValidationException one message per setting key; nothing is saved
     */
    public static function update(array $input, int $userId, array $original = []): array
    {
        $registry = self::registry();
        $rows = self::rows();
        $errors = [];
        $changes = [];
        foreach ($registry as $key => $def) {
            if (!isset($input[$key]) || !is_scalar($input[$key])) {
                continue;
            }
            $current = isset($rows[$key]) ? (string) $rows[$key]['setting_value'] : null;
            $raw = trim((string) $input[$key]);
            if ($raw === $current || (isset($original[$key]) && trim($original[$key]) === $raw)) {
                continue; // unchanged, or not touched by this person
            }
            $value = self::normalise($def, $raw);
            if ($value === null) {
                $errors[$key] = self::message($def, $raw);
            } elseif ($current === null || $value !== self::normalise($def, $current)) {
                $changes[$key] = [$current, $value];
            }
        }

        foreach (self::ORDERED as [$small, $large]) {
            if ((!isset($changes[$small]) && !isset($changes[$large])) || isset($errors[$small]) || isset($errors[$large])) {
                continue;
            }
            $smallValue = $changes[$small][1] ?? $rows[$small]['setting_value'] ?? null;
            $largeValue = $changes[$large][1] ?? $rows[$large]['setting_value'] ?? null;
            if (is_numeric($smallValue) && is_numeric($largeValue) && (float) $smallValue > (float) $largeValue) {
                if (isset($changes[$small])) {
                    $errors[$small] = sprintf('This cannot be more than “%s” (%s).', $registry[$large]['label'], $largeValue);
                } else {
                    $errors[$large] = sprintf('This cannot be less than “%s” (%s).', $registry[$small]['label'], $smallValue);
                }
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }
        if (!$changes) {
            return [];
        }
        Db::transaction(function () use ($changes, $userId): void {
            $now = Clock::db();
            $upsert = Db::pdo()->prepare(
                'INSERT INTO system_setting (setting_key, setting_value, updated_by, updated_at) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?, updated_by = ?, updated_at = ?'
            );
            foreach ($changes as $key => [, $value]) {
                $upsert->execute([$key, $value, $userId, $now, $value, $userId, $now]);
            }
            // Settings hold no secrets; keys like password_min_length must stay visible in the audit log.
            Audit::record('settings_update', 'system_setting', changes: $changes, redactChanges: false);
        });
        Settings::reload();
        return array_keys($changes);
    }

    /**
     * The stored form of a value, or null when it breaks the setting's rules.
     * @param array<string, mixed> $def
     */
    public static function normalise(array $def, string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' && !empty($def['optional'])) {
            return '';
        }
        switch ($def['type']) {
            case 'int':
                if (!preg_match('/^-?\d{1,10}$/', $raw)) {
                    return null;
                }
                $n = (int) $raw;
                return $n >= $def['min'] && $n <= $def['max'] ? (string) $n : null;
            case 'float':
                if (!preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $raw)) {
                    return null;
                }
                $n = (float) $raw + 0.0; // adding 0.0 turns "-0" into 0, so it is stored as "0"
                return $n >= $def['min'] && $n <= $def['max'] ? (string) $n : null;
            case 'bool':
                $word = strtolower($raw);
                return in_array($word, ['1', 'true', 'yes', 'on'], true) ? '1' : (in_array($word, ['0', 'false', 'no', 'off', ''], true) ? '0' : null);
            case 'enum':
                return isset(self::choices($def)[$raw]) ? $raw : null;
            case 'mmdd':
                if (!preg_match('~^(\d{1,2})[-/](\d{1,2})$~', $raw, $m) || !checkdate((int) $m[1], (int) $m[2], 2025)) {
                    return null; // 2025 is not a leap year, so 02-29 is refused: it would skip most years
                }
                return sprintf('%02d-%02d', $m[1], $m[2]);
            case 'string':
                if ($raw === '') {
                    return ($def['min'] ?? 0) > 0 ? null : '';
                }
                return Validator::text($raw, (int) $def['max']);
        }
        return null;
    }

    /**
     * Allowed values of an enum setting, value => label.
     * @param array<string, mixed> $def
     * @return array<string, string>
     */
    private static function choices(array $def): array
    {
        if (($def['source'] ?? null) === 'language') {
            $choices = [];
            foreach (Db::pdo()->query('SELECT language_code, name FROM language WHERE is_active = 1 ORDER BY name') as $row) {
                $choices[(string) $row['language_code']] = (string) $row['name'];
            }
            return $choices;
        }
        $options = $def['options'] ?? [];
        return array_combine($options, $options);
    }

    /** @param array<string, mixed> $def */
    private static function message(array $def, string $raw): string
    {
        $unit = isset($def['unit']) ? " ({$def['unit']})" : '';
        $range = static fn(int|float $n): string => is_int($n) ? number_format($n) : (string) $n;
        return match ($def['type']) {
            'int' => sprintf('Enter a whole number from %s to %s%s.', $range($def['min']), $range($def['max']), $unit),
            'float' => sprintf('Enter a number from %s to %s%s, for example 2.5%s.', $range($def['min']), $range($def['max']), $unit,
                empty($def['optional']) ? '' : ', or leave it empty'),
            'bool' => 'Choose yes or no.',
            'enum' => 'Choose one of the options in the list.',
            'mmdd' => 'Enter a month and day as MM-DD, for example 07-01 for July 1.',
            default => $raw === '' ? 'This cannot be left empty.' : sprintf('Use no more than %d characters.', $def['max']),
        };
    }

    /** The stored description as help text: no specification references, other settings named by their labels. */
    private static function hint(?string $description): string
    {
        $hint = trim((string) preg_replace('/\s*\((?:UC|US)-[^)]*\)\s*$/', '', (string) $description));
        if (str_starts_with($hint, '1 = ')) {
            $hint = ucfirst(substr($hint, 4));
        }
        $labels = [];
        foreach (self::registry() as $key => $def) {
            $labels[$key] = '“' . $def['label'] . '”';
        }
        return (string) preg_replace_callback('/\b[a-z]+(?:_[a-z0-9]+)+\b/', fn(array $m): string => $labels[$m[0]] ?? $m[0], $hint);
    }

    /** @return array<string, array{setting_key: string, setting_value: string, description: ?string}> */
    private static function rows(): array
    {
        $rows = [];
        foreach (Db::pdo()->query('SELECT setting_key, setting_value, description FROM system_setting') as $row) {
            $rows[(string) $row['setting_key']] = $row;
        }
        return $rows;
    }
}
