<?php
declare(strict_types=1);

namespace Pfpms\Reference;

use Pfpms\Db;

/** SQL for the clinic directory. Prepared statements only; returns plain arrays. The portal code hash is never read here. */
final class ClinicRepository
{
    public const COLUMNS = 'clinic_id, name, is_partner, street_address, city, state, postal_code, phone, hours_text, directions_url,
                            voucher_rate, period_capacity, current_wait_days, offers_low_cost_vaccination, status';

    /** Columns written by the edit form, in INSERT/UPDATE order. */
    private const WRITABLE = ['name', 'is_partner', 'street_address', 'city', 'state', 'postal_code', 'phone', 'hours_text', 'directions_url',
        'voucher_rate', 'period_capacity', 'current_wait_days', 'offers_low_cost_vaccination'];

    /** @return list<array> active clinics first, then by name */
    public static function all(): array
    {
        return Db::pdo()->query('SELECT ' . self::COLUMNS . " FROM clinic ORDER BY status = 'Suspended', name")->fetchAll();
    }

    public static function find(int $clinicId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM clinic WHERE clinic_id = ?');
        $st->execute([$clinicId]);
        return $st->fetch() ?: null;
    }

    /** @param array<string, mixed> $values */
    public static function insert(array $values): int
    {
        Db::pdo()->prepare('INSERT INTO clinic (' . implode(', ', self::WRITABLE) . ') VALUES (' . rtrim(str_repeat('?, ', count(self::WRITABLE)), ', ') . ')')
            ->execute(self::args($values));
        return (int) Db::pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $values */
    public static function update(int $clinicId, array $values): void
    {
        Db::pdo()->prepare('UPDATE clinic SET ' . implode(' = ?, ', self::WRITABLE) . ' = ? WHERE clinic_id = ?')
            ->execute([...self::args($values), $clinicId]);
    }

    public static function setStatus(int $clinicId, string $status): void
    {
        Db::pdo()->prepare('UPDATE clinic SET status = ? WHERE clinic_id = ?')->execute([$status, $clinicId]);
    }

    /** @return list<mixed> */
    private static function args(array $values): array
    {
        return array_map(static fn(string $column): mixed => $values[$column], self::WRITABLE);
    }
}
