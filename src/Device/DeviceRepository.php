<?php
declare(strict_types=1);

namespace Pfpms\Device;

use Pfpms\Clock;
use Pfpms\Db;

/**
 * SQL for device (tablets that run the Station). Prepared statements only. The credential hash
 * and the vault key are never selected: rows carry has_credential instead, so pages, templates
 * and audit snapshots can never show them.
 */
final class DeviceRepository
{
    public const COLUMNS = 'd.device_id, d.site_id, d.label, d.is_site_registered, d.registered_by, d.registered_at,
        (d.token_hash IS NOT NULL) AS has_credential, d.offline_enabled, d.storage_persisted, d.last_seen_at, d.last_sync_at,
        d.pending_count, d.reported_max_seq, d.app_build, d.revoked_at, d.revoked_by, d.revoked_lost, d.revoked_max_seq,
        d.wipe_mode, d.erase_requested_at, d.erase_requested_by, d.wiped_at';

    /** The one test of a tablet P2B may trust (with its site active): registered, not taken out of service, not erased. */
    public const IN_SERVICE_SQL = 'd.token_hash IS NOT NULL AND d.is_site_registered = 1 AND d.revoked_at IS NULL AND d.wiped_at IS NULL';

    /** The tablet's registration codes of any state: the newest one is part of a form's revision (a new sheet changes it; an expiry or a cancellation by an account change does not). */
    private const ANY_CODE = "t.device_id = d.device_id AND t.purpose = 'Device Registration'";
    private const LIVE_CODE = self::ANY_CODE . ' AND t.used_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > ?';

    private const DETAIL = "SELECT " . self::COLUMNS . ", s.name AS site_name, s.time_zone, s.is_active AS site_active,
               COALESCE(NULLIF(rb.display_name, ''), CONCAT(rb.first_name, ' ', rb.last_name)) AS registered_by_name,
               COALESCE(NULLIF(vb.display_name, ''), CONCAT(vb.first_name, ' ', vb.last_name)) AS revoked_by_name,
               COALESCE(NULLIF(eb.display_name, ''), CONCAT(eb.first_name, ' ', eb.last_name)) AS erase_requested_by_name,
               (SELECT MAX(t.token_id) FROM auth_token t WHERE " . self::ANY_CODE . ") AS code_id,
               (SELECT MAX(t.token_id) FROM auth_token t WHERE " . self::LIVE_CODE . ") AS live_code_id,
               (SELECT MAX(t.expires_at) FROM auth_token t WHERE " . self::LIVE_CODE . ") AS code_expires_at
          FROM device d
          LEFT JOIN site s ON s.site_id = d.site_id
          LEFT JOIN user_account rb ON rb.user_id = d.registered_by
          LEFT JOIN user_account vb ON vb.user_id = d.revoked_by
          LEFT JOIN user_account eb ON eb.user_id = d.erase_requested_by";

    public static function find(int $deviceId): ?array
    {
        $now = Clock::db();
        $st = Db::pdo()->prepare(self::DETAIL . ' WHERE d.device_id = ?');
        $st->execute([$now, $now, $deviceId]);
        return $st->fetch() ?: null;
    }

    /**
     * Tablets in the person's scope, by site and name; optionally one site; cancelled and erased
     * ones only when asked for.
     * @return list<array>
     */
    public static function list(DeviceScope $scope, ?int $siteId, bool $includeClosed): array
    {
        if (!$scope->allSites && !$scope->siteIds) {
            return [];
        }
        $now = Clock::db();
        $where = [];
        $args = [$now, $now];
        if (!$scope->allSites) {
            $where[] = 'd.site_id IN (' . implode(',', array_fill(0, count($scope->siteIds), '?')) . ')';
            array_push($args, ...$scope->siteIds);
        }
        if ($siteId !== null) {
            $where[] = 'd.site_id = ?';
            $args[] = $siteId;
        }
        if (!$includeClosed) {
            $where[] = 'd.wiped_at IS NULL AND NOT (d.revoked_at IS NOT NULL AND d.token_hash IS NULL)';
        }
        $st = Db::pdo()->prepare(self::DETAIL . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY s.name, d.label, d.device_id');
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Lock the tablet's row for the rest of the transaction (no joins: no other row is locked) and return it. */
    public static function lock(int $deviceId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM device d WHERE d.device_id = ? FOR UPDATE');
        $st->execute([$deviceId]);
        return $st->fetch() ?: null;
    }

    /** @return ?array{token_id: int, expires_at: string, issued_by_name: string} the tablet's working registration code */
    public static function liveCode(int $deviceId): ?array
    {
        $st = Db::pdo()->prepare(
            "SELECT t.token_id, t.expires_at, COALESCE(NULLIF(u.display_name, ''), CONCAT(u.first_name, ' ', u.last_name)) AS issued_by_name
               FROM auth_token t JOIN user_account u ON u.user_id = t.user_id
              WHERE t.device_id = ? AND t.purpose = 'Device Registration' AND t.used_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > ?
              ORDER BY t.token_id DESC LIMIT 1"
        );
        $st->execute([$deviceId, Clock::db()]);
        $row = $st->fetch();
        return $row ? ['token_id' => (int) $row['token_id'], 'expires_at' => (string) $row['expires_at'], 'issued_by_name' => (string) $row['issued_by_name']] : null;
    }

    /** The tablet's newest registration code of any state: part of a form's revision. */
    public static function currentCodeId(int $deviceId): ?int
    {
        $st = Db::pdo()->prepare("SELECT MAX(t.token_id) FROM auth_token t WHERE t.device_id = ? AND t.purpose = 'Device Registration'");
        $st->execute([$deviceId]);
        $id = $st->fetchColumn();
        return $id === null || $id === false ? null : (int) $id;
    }

    /** Another current tablet at the site with this name (the column collation ignores case and accents). */
    public static function labelTaken(int $siteId, string $label, ?int $exceptDeviceId): bool
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM device WHERE site_id = ? AND label = ? AND revoked_at IS NULL AND wiped_at IS NULL AND device_id <> ?');
        $st->execute([$siteId, $label, $exceptDeviceId ?? 0]);
        return (int) $st->fetchColumn() > 0;
    }

    /** Tablets at the site waiting to be registered. */
    public static function pendingCount(int $siteId): int
    {
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM device WHERE site_id = ? AND token_hash IS NULL AND revoked_at IS NULL');
        $st->execute([$siteId]);
        return (int) $st->fetchColumn();
    }

    /** A tablet waiting for registration; registered_by/at are who added it and when (P2B overwrites them). */
    public static function insertPending(int $siteId, string $label, int $addedBy, string $at): int
    {
        Db::pdo()->prepare('INSERT INTO device (site_id, label, is_site_registered, registered_by, registered_at) VALUES (?, ?, 0, ?, ?)')
            ->execute([$siteId, $label, $addedBy, $at]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function rename(int $deviceId, string $label): void
    {
        Db::pdo()->prepare('UPDATE device SET label = ? WHERE device_id = ?')->execute([$label, $deviceId]);
    }

    /** Cancel a registration no tablet has used. */
    public static function cancel(int $deviceId, int $actorId, string $at): bool
    {
        $st = Db::pdo()->prepare('UPDATE device SET revoked_at = ?, revoked_by = ? WHERE device_id = ? AND token_hash IS NULL AND revoked_at IS NULL');
        $st->execute([$at, $actorId, $deviceId]);
        return $st->rowCount() === 1;
    }

    /**
     * Take a registered tablet out of service. The credential hash and vault key are kept: P2B must
     * still recognise the tablet to tell it to erase itself, and a rescue upload needs the key.
     */
    public static function revoke(int $deviceId, int $actorId, string $at, string $wipeMode, bool $lost, int $maxSeq): bool
    {
        $erase = $wipeMode === 'Wipe Now';
        $st = Db::pdo()->prepare(
            'UPDATE device SET revoked_at = ?, revoked_by = ?, revoked_lost = ?, revoked_max_seq = ?, wipe_mode = ?, erase_requested_at = ?, erase_requested_by = ?,
                    is_site_registered = 0, offline_enabled = 0
              WHERE device_id = ? AND token_hash IS NOT NULL AND revoked_at IS NULL'
        );
        $st->execute([$at, $actorId, $lost ? 1 : 0, $maxSeq, $wipeMode, $erase ? $at : null, $erase ? $actorId : null, $deviceId]);
        return $st->rowCount() === 1;
    }

    /**
     * A retiring tablet is to erase without uploading. Who retired it and when stay; the upload
     * cut-off can only come down, to what the server has received.
     */
    public static function escalateToWipeNow(int $deviceId, int $actorId, string $at, int $receivedMaxSeq): bool
    {
        $st = Db::pdo()->prepare(
            "UPDATE device SET wipe_mode = 'Wipe Now', erase_requested_at = ?, erase_requested_by = ?, revoked_max_seq = LEAST(COALESCE(revoked_max_seq, CAST(? AS SIGNED)), CAST(? AS SIGNED))
              WHERE device_id = ? AND revoked_at IS NOT NULL AND wipe_mode = 'Push Then Wipe' AND wiped_at IS NULL"
        );
        $st->execute([$at, $actorId, $receivedMaxSeq, $receivedMaxSeq, $deviceId]);
        return $st->rowCount() === 1;
    }

    /** A retiring tablet turns out to be lost or stolen: its upload cut-off comes down to what the server has received. */
    public static function markLost(int $deviceId, int $receivedMaxSeq): bool
    {
        $st = Db::pdo()->prepare(
            "UPDATE device SET revoked_lost = 1, revoked_max_seq = LEAST(COALESCE(revoked_max_seq, CAST(? AS SIGNED)), CAST(? AS SIGNED))
              WHERE device_id = ? AND revoked_at IS NOT NULL AND revoked_lost = 0 AND wipe_mode = 'Push Then Wipe' AND wiped_at IS NULL"
        );
        $st->execute([$receivedMaxSeq, $receivedMaxSeq, $deviceId]);
        return $st->rowCount() === 1;
    }

    /** The highest sequence number the server has received from the tablet. */
    public static function receivedMaxSeq(int $deviceId): int
    {
        $st = Db::pdo()->prepare('SELECT COALESCE(MAX(client_seq), 0) FROM sync_item WHERE device_id = ?');
        $st->execute([$deviceId]);
        return (int) $st->fetchColumn();
    }

    /** @return list<array{user_id: int, name: string, last_signed_in: string}> people who signed in on the tablet, latest first */
    public static function recentUsers(int $deviceId): array
    {
        $st = Db::pdo()->prepare(
            "SELECT u.user_id, COALESCE(NULLIF(u.display_name, ''), CONCAT(u.first_name, ' ', u.last_name)) AS name, MAX(s.started_at) AS last_signed_in
               FROM user_session s JOIN user_account u ON u.user_id = s.user_id
              WHERE s.device_id = ?
              GROUP BY u.user_id, u.display_name, u.first_name, u.last_name
              ORDER BY last_signed_in DESC, name, u.user_id
              LIMIT 50"
        );
        $st->execute([$deviceId]);
        return array_map(fn($r) => ['user_id' => (int) $r['user_id'], 'name' => (string) $r['name'], 'last_signed_in' => (string) $r['last_signed_in']], $st->fetchAll());
    }
}
