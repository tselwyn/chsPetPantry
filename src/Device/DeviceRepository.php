<?php
declare(strict_types=1);

namespace Pfpms\Device;

use Pfpms\Clock;
use Pfpms\Db;

/**
 * SQL for device (tablets that run the Station). Prepared statements only. The credential hash, the
 * vault key and the proof key are never selected into a row: rows carry has_credential (and, for the
 * Station guard, has_vault_key and has_proof_key) instead, so pages, templates and audit snapshots can
 * never show them. The two keys are read only by their own narrow queries. Booleans are bound as 1/0:
 * PHP binds false as '', which a TINYINT refuses under strict mode.
 */
final class DeviceRepository
{
    public const COLUMNS = 'd.device_id, d.site_id, d.label, d.is_site_registered, d.registered_by, d.registered_at,
        (d.token_hash IS NOT NULL) AS has_credential, d.offline_enabled, d.storage_persisted, d.last_seen_at, d.last_sync_at,
        d.pending_count, d.reported_max_seq, d.app_build, d.revoked_at, d.revoked_by, d.revoked_lost, d.revoked_max_seq,
        d.wipe_mode, d.erase_requested_at, d.erase_requested_by, d.wiped_at, d.pbkdf2_iterations, d.display_mode, d.storage_estimate_kb,
        d.clock_skew_seconds, d.oldest_pending_at, d.attention_count, d.locked_out_since, d.shift_ended_at';

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
               (SELECT MAX(t.expires_at) FROM auth_token t WHERE " . self::LIVE_CODE . ") AS code_expires_at,
               (SELECT COUNT(*) FROM sync_item si WHERE si.device_id = d.device_id AND si.recorded_by IS NOT NULL) AS received_count
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

    /**
     * The tablet presenting this credential (its SHA-256, Tokens::hash), for the Station guard: the display-safe
     * columns plus has_vault_key, has_proof_key, in_service (IN_SERVICE_SQL) and its site. Never a secret.
     */
    public static function byCredentialHash(string $hash): ?array
    {
        $st = Db::pdo()->prepare(
            'SELECT ' . self::COLUMNS . ', (d.vault_key_ciphertext IS NOT NULL) AS has_vault_key, (d.proof_key_ciphertext IS NOT NULL) AS has_proof_key,
                    (' . self::IN_SERVICE_SQL . ') AS in_service, s.name AS site_name, s.time_zone, COALESCE(s.is_active, 0) AS site_active
               FROM device d LEFT JOIN site s ON s.site_id = d.site_id
              WHERE d.token_hash = ?'
        );
        $st->execute([$hash]);
        return $st->fetch() ?: null;
    }

    /** The tablet's vault key, encrypted (Crypto, AAD device:<id>:dvk): read only where it is released or opened. */
    public static function vaultKeyCiphertext(int $deviceId): ?string
    {
        $st = Db::pdo()->prepare('SELECT vault_key_ciphertext FROM device WHERE device_id = ?');
        $st->execute([$deviceId]);
        $value = $st->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /** The tablet's proof key, encrypted (Crypto, AAD device:<id>:proof): read only by DeviceProof::check(). */
    public static function proofKeyCiphertext(int $deviceId): ?string
    {
        $st = Db::pdo()->prepare('SELECT proof_key_ciphertext FROM device WHERE device_id = ?');
        $st->execute([$deviceId]);
        $value = $st->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /**
     * A tablet redeemed its registration code (40-design §14.3): its credential hash, vault key, proof key and
     * calibrated rounds. offline_enabled stays 0 until a heartbeat shows the tablet can work offline.
     */
    public static function register(int $deviceId, string $tokenHash, string $vaultKeyCiphertext, string $proofKeyCiphertext, int $registeredBy,
        string $at, bool $persisted, ?string $displayMode, ?string $appBuild, ?int $iterations): bool
    {
        $st = Db::pdo()->prepare(
            'UPDATE device SET token_hash = ?, vault_key_ciphertext = ?, proof_key_ciphertext = ?, pbkdf2_iterations = ?, is_site_registered = 1,
                    registered_by = ?, registered_at = ?, offline_enabled = 0, storage_persisted = ?, display_mode = ?, app_build = ?, last_seen_at = ?
              WHERE device_id = ? AND token_hash IS NULL AND revoked_at IS NULL AND wiped_at IS NULL'
        );
        $st->execute([$tokenHash, $vaultKeyCiphertext, $proofKeyCiphertext, $iterations, $registeredBy, $at, $persisted ? 1 : 0, $displayMode,
            $appBuild, $at, $deviceId]);
        return $st->rowCount() === 1;
    }

    /**
     * What the tablet reported (40-design §14.4). The UPDATE locks the row and evaluates "in service" in it, so a
     * concurrent Retire is never overwritten with offline_enabled = 1; reported_max_seq only rises, and only in service.
     * None of the SET columns appears in IN_SERVICE_SQL, so MySQL's left-to-right SET cannot change the predicate. The same lockout
     * reported again (within 2 seconds, the jitter of the skew correction) keeps its stored time, so it is not a change.
     * @param array{app_build: ?string, storage_persisted: bool, display_mode: ?string, pending_count: ?int, attention_count: ?int,
     *   oldest_pending_at: ?string, storage_estimate_kb: ?int, locked_out_since: ?string, max_seq: ?int} $r
     */
    public static function heartbeat(int $deviceId, array $r, bool $offlineEligible, ?int $clockSkewSeconds, string $at): void
    {
        Db::pdo()->prepare(
            'UPDATE device d
                SET d.last_seen_at = ?, d.app_build = COALESCE(?, d.app_build), d.storage_persisted = ?, d.display_mode = COALESCE(?, d.display_mode),
                    d.pending_count = COALESCE(?, d.pending_count), d.attention_count = ?, d.oldest_pending_at = ?, d.storage_estimate_kb = ?,
                    d.clock_skew_seconds = ?,
                    d.locked_out_since = CASE WHEN ? IS NULL THEN NULL
                        WHEN d.locked_out_since IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, d.locked_out_since, ?)) <= 2 THEN d.locked_out_since ELSE ? END,
                    d.reported_max_seq = IF((' . self::IN_SERVICE_SQL . ') AND ? IS NOT NULL, GREATEST(COALESCE(d.reported_max_seq, 0), CAST(? AS SIGNED)), d.reported_max_seq),
                    d.offline_enabled = IF((' . self::IN_SERVICE_SQL . ') AND CAST(? AS SIGNED) = 1, 1, 0)
              WHERE d.device_id = ? AND d.wiped_at IS NULL'
        )->execute([$at, $r['app_build'], $r['storage_persisted'] ? 1 : 0, $r['display_mode'], $r['pending_count'], $r['attention_count'],
            $r['oldest_pending_at'], $r['storage_estimate_kb'], $clockSkewSeconds, $r['locked_out_since'], $r['locked_out_since'], $r['locked_out_since'],
            $r['max_seq'], $r['max_seq'],
            $offlineEligible ? 1 : 0, $deviceId]);
    }

    /** Whether any tablet in service authenticated (heartbeat or registration) since $since. */
    public static function anySeenSince(string $since): bool
    {
        $st = Db::pdo()->prepare('SELECT 1 FROM device d WHERE ' . self::IN_SERVICE_SQL . ' AND d.last_seen_at >= ? LIMIT 1');
        $st->execute([$since]);
        return $st->fetchColumn() !== false;
    }

    /** A revoked tablet confirmed it erased itself: nothing on the server can open a copy of its old storage any more. */
    public static function confirmWipe(int $deviceId, string $at): bool
    {
        $st = Db::pdo()->prepare(
            'UPDATE device SET wiped_at = ?, pending_count = 0, attention_count = NULL, vault_key_ciphertext = NULL, proof_key_ciphertext = NULL, offline_enabled = 0
              WHERE device_id = ? AND revoked_at IS NOT NULL AND wiped_at IS NULL'
        );
        $st->execute([$at, $deviceId]);
        return $st->rowCount() === 1;
    }

    /** Forget the secrets of the tablet's offline grants (its erase was confirmed). Returns how many. */
    public static function shredGrantSecrets(int $deviceId): int
    {
        $st = Db::pdo()->prepare("UPDATE auth_token SET secret_ciphertext = NULL WHERE device_id = ? AND purpose = 'Offline Grant' AND secret_ciphertext IS NOT NULL");
        $st->execute([$deviceId]);
        return $st->rowCount();
    }

    /**
     * Tablets revoked before $before that never confirmed their erase and still hold their vault key on the server
     * (devices:clear-unconfirmed-wipes).
     * @return list<int>
     */
    public static function unconfirmedWipes(string $before): array
    {
        $st = Db::pdo()->prepare(
            'SELECT device_id FROM device
              WHERE revoked_at IS NOT NULL AND wiped_at IS NULL AND vault_key_ciphertext IS NOT NULL AND revoked_at <= ?
              ORDER BY device_id'
        );
        $st->execute([$before]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Clear the vault key of a revoked tablet whose erase was never confirmed (its proof key stays, for a late confirmation). */
    public static function clearKeys(int $deviceId): bool
    {
        $st = Db::pdo()->prepare(
            'UPDATE device SET vault_key_ciphertext = NULL
              WHERE device_id = ? AND revoked_at IS NOT NULL AND wiped_at IS NULL AND vault_key_ciphertext IS NOT NULL'
        );
        $st->execute([$deviceId]);
        return $st->rowCount() === 1;
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

    /** The tablet's row under a shared lock (the sign-in, PIN, acknowledgement and password transactions start here, D-16): a
     *  Retire either committed first (in_service 0 here) or waits for this transaction. No join: no other row is locked. */
    public static function lockShared(int $deviceId): ?array
    {
        $st = Db::pdo()->prepare('SELECT ' . self::COLUMNS . ', (' . self::IN_SERVICE_SQL . ') AS in_service FROM device d WHERE d.device_id = ? LOCK IN SHARE MODE');
        $st->execute([$deviceId]);
        return $st->fetch() ?: null;
    }

    /** End shift (D-24): the later of the stored time and $at (Clock::db text); it never moves backwards. */
    public static function setShiftEnded(int $deviceId, string $at): void
    {
        Db::pdo()->prepare('UPDATE device SET shift_ended_at = GREATEST(COALESCE(shift_ended_at, CAST(? AS DATETIME)), CAST(? AS DATETIME)) WHERE device_id = ?')
            ->execute([$at, $at, $deviceId]);
    }
}
