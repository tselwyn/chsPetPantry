<?php
declare(strict_types=1);

namespace Pfpms\Tests;

use Pfpms\Audit\Audit;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Clock;
use Pfpms\Db;
use Pfpms\Settings;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Integration test base: every test runs inside one transaction that is rolled back
 * afterwards, with the clock frozen at a known instant.
 */
abstract class TestCase extends BaseTestCase
{
    protected const NOW = '2026-10-01 12:00:00';

    protected function setUp(): void
    {
        Clock::freeze(self::NOW);
        Settings::reload();
        Audit::setActor(null);
        Db::testBegin();
    }

    protected function tearDown(): void
    {
        Db::testRollback();
        Clock::freeze(null);
        Settings::reload();
        $_SESSION = [];
    }

    /** Insert a user and return the row (password is the plain password used). */
    protected function makeUser(array $overrides = [], string $password = 'Correct-Horse-Battery-9'): array
    {
        static $n = 0;
        $n++;
        $row = $overrides + [
            'username' => "user$n" . bin2hex(random_bytes(2)),
            'email' => "user$n." . bin2hex(random_bytes(2)) . '@example.test',
            'first_name' => 'Test',
            'last_name' => "Person$n",
            'role' => 'Volunteer',
            'status' => 'Active',
            'must_change_password' => 0,
            'start_date' => '2026-01-01',
            'password_changed_at' => self::NOW,
        ];
        $row['password_hash'] = $overrides['password_hash'] ?? PasswordPolicy::hash($password);
        $columns = array_keys($row);
        Db::pdo()->prepare('INSERT INTO user_account (' . implode(', ', $columns) . ') VALUES (' . rtrim(str_repeat('?, ', count($columns)), ', ') . ')')
            ->execute(array_values($row));
        $row['user_id'] = (int) Db::pdo()->lastInsertId();
        return $row;
    }

    /** A site with its zero stock rows, as SiteService::create makes them. */
    protected function makeSite(string $name): int
    {
        Db::pdo()->prepare('INSERT INTO site (name) VALUES (?)')->execute([$name]);
        $id = (int) Db::pdo()->lastInsertId();
        \Pfpms\Inventory\StockRepository::precreateForSite($id);
        return $id;
    }

    protected function setSetting(string $key, string $value): void
    {
        Db::pdo()->prepare('INSERT INTO system_setting (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?')
            ->execute([$key, $value, $value]);
        Settings::reload();
    }

    protected function scalar(string $sql, array $args = []): mixed
    {
        $st = Db::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    }
}
