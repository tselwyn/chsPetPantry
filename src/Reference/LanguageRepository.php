<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Db;

/** SQL for the language table (US-23). Prepared statements only; returns plain arrays. */
final class LanguageRepository
{
    public const COLUMNS = 'language_code, name, is_active';

    /** @return list<array> */
    public static function all(): array
    {
        return Db::pdo()->query('SELECT ' . self::COLUMNS . ' FROM `language` ORDER BY is_active DESC, name')->fetchAll();
    }

    /** @return list<array> the languages that can be chosen for new records */
    public static function active(): array
    {
        return Db::pdo()->query('SELECT ' . self::COLUMNS . ' FROM `language` WHERE is_active = 1 ORDER BY name')->fetchAll();
    }

    public static function find(string $code): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM `language` WHERE language_code = ?');
        $st->execute([$code]);
        return $st->fetch() ?: null;
    }

    public static function nameTaken(string $name, ?string $exceptCode): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM `language` WHERE name = ? AND language_code <> ?');
        $st->execute([$name, $exceptCode ?? '']);
        return (int) $st->fetchColumn() > 0;
    }

    public static function insert(string $code, string $name): void
    {
        Db::pdo()->prepare('INSERT INTO `language` (language_code, name) VALUES (?, ?)')->execute([$code, $name]);
    }

    public static function rename(string $code, string $name): void
    {
        Db::pdo()->prepare('UPDATE `language` SET name = ? WHERE language_code = ?')->execute([$name, $code]);
    }

    public static function setActive(string $code, bool $active): void
    {
        Db::pdo()->prepare('UPDATE `language` SET is_active = ? WHERE language_code = ?')->execute([$active ? 1 : 0, $code]);
    }
}
