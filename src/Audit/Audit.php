<?php
declare(strict_types=1);

namespace Pfpms\Audit;

use PDO;
use Pfpms\Clock;
use Pfpms\Db;

/**
 * Writes the immutable audit trail: audit_log plus one audit_field_change row per changed
 * field (UC-04 field-level audit). Rows are only ever inserted.
 *
 * record() writes on the main connection, so an audit entry commits or rolls back with the
 * business change it describes. durable() writes on a separate autocommit connection, for
 * Denied/Failed entries that must survive the rollback of the work that failed.
 */
final class Audit
{
    private const REDACT = '/pass(word)?|token|secret|pin|hash|cipher/i';

    private static ?int $userId = null;
    private static ?string $sessionId = null;
    private static ?int $siteId = null;
    private static ?int $deviceId = null;

    /** Who is acting in this request. Set by Page::start / Api::start, or by CLI scripts. */
    public static function setActor(?int $userId, ?string $sessionId = null, ?int $siteId = null, ?int $deviceId = null): void
    {
        self::$userId = $userId;
        self::$sessionId = $sessionId;
        self::$siteId = $siteId;
        self::$deviceId = $deviceId;
    }

    /**
     * @param array<string, array{0:mixed,1:mixed}> $changes field => [old, new]
     * @param array{user_id?:?int, session_id?:?string, site_id?:?int} $actor overrides for this entry
     */
    public static function record(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        string $outcome = 'Success',
        ?string $reason = null,
        ?array $details = null,
        ?array $snapshot = null,
        array $changes = [],
        array $actor = [],
    ): int {
        return self::write(Db::pdo(), $action, $entityType, $entityId, $outcome, $reason, $details, $snapshot, $changes, $actor);
    }

    /** Same as record(), on the durable connection. Never pass an actor or entity created in the open transaction. */
    public static function durable(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        string $outcome = 'Denied',
        ?string $reason = null,
        ?array $details = null,
        array $actor = [],
    ): int {
        return self::write(Db::durable(), $action, $entityType, $entityId, $outcome, $reason, $details, null, [], $actor);
    }

    /**
     * Compare two versions of a record.
     * @return array<string, array{0:mixed,1:mixed}> only the fields that differ
     */
    public static function diff(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ((string) $old !== (string) $new || ($old === null) !== ($new === null)) {
                $changes[$field] = [$old, $new];
            }
        }
        return $changes;
    }

    private static function write(PDO $pdo, string $action, ?string $entityType, int|string|null $entityId, string $outcome,
        ?string $reason, ?array $details, ?array $snapshot, array $changes, array $actor): int
    {
        $pdo->prepare(
            'INSERT INTO audit_log (occurred_at, user_id, session_id, device_id, site_id, action, entity_type, entity_id, outcome, reason, snapshot, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            Clock::dbMillis(),
            array_key_exists('user_id', $actor) ? $actor['user_id'] : self::$userId,
            array_key_exists('session_id', $actor) ? $actor['session_id'] : self::$sessionId,
            self::$deviceId,
            array_key_exists('site_id', $actor) ? $actor['site_id'] : self::$siteId,
            mb_substr($action, 0, 40),
            $entityType,
            $entityId === null ? null : (int) $entityId,
            $outcome,
            $reason === null ? null : mb_substr($reason, 0, 255),
            $snapshot === null ? null : self::json(self::redact($snapshot)),
            $details === null ? null : self::json(self::redact($details)),
        ]);
        $auditId = (int) $pdo->lastInsertId();
        if ($changes) {
            $insert = $pdo->prepare('INSERT INTO audit_field_change (audit_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?)');
            foreach ($changes as $field => [$old, $new]) {
                $secret = preg_match(self::REDACT, (string) $field) === 1;
                $insert->execute([$auditId, mb_substr((string) $field, 0, 60),
                    $secret ? ($old === null ? null : '[redacted]') : self::scalar($old),
                    $secret ? ($new === null ? null : '[redacted]') : self::scalar($new)]);
            }
        }
        return $auditId;
    }

    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::REDACT, $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }

    private static function scalar(mixed $value): ?string
    {
        return $value === null ? null : (is_scalar($value) ? (string) $value : self::json($value));
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
