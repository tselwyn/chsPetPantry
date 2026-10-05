<?php
declare(strict_types=1);

/*
 * Schema contract check and smoke tests (docs/PFPMS_Implementation_Plan.md §9).
 * Run after bin/migrate.php on every engine (MariaDB 10.4, MySQL 8.x):
 *
 *   php bin/schema-check.php [--config=path]
 *
 * Compares normalised information_schema data, not dump text (the engines print types and
 * defaults differently). Smoke tests run in one transaction that is always rolled back.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Db\Migrator;

const EXPECTED_TABLES = 66;       // 59 (v2.0.1) + 7 (v2.1), excluding schema_version; 0013 adds none
const EXPECTED_FOREIGN_KEYS = 167; // 143 (v2.0.1) + 24 (v2.1); 0013 adds none
// JSON columns are LONGTEXT COLLATE utf8mb4_bin on MariaDB; that is the only allowed exception.
const JSON_COLUMNS = [
    'user_account.notification_prefs', 'registration_draft.form_data', 'intake_question.options',
    'audit_log.snapshot', 'audit_log.details', 'import_mapping.column_map',
    'saved_report.parameters', 'report_run.parameters',
];

$opts = getopt('', ['config:']);
Config::load($opts['config'] ?? null);
$db = Config::require('db');
if (!empty($db['migrate_user'])) {
    $db['user'] = $db['migrate_user'];
    $db['pass'] = $db['migrate_pass'] ?? '';
}
$pdo = Db::connect($db);

final class Checks
{
    public static int $failures = 0;
}

function check(string $label, bool $ok, string $detail = ''): void
{
    if (!$ok) {
        Checks::$failures++;
    }
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

function col(PDO $pdo, string $sql, array $args = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

/** Run a statement that must fail with MySQL error $code. */
function expectError(PDO $pdo, string $sql, array $args, int $code): bool
{
    try {
        $pdo->prepare($sql)->execute($args);
        return false;
    } catch (PDOException $e) {
        return (int) ($e->errorInfo[1] ?? 0) === $code;
    }
}

$engine = col($pdo, 'SELECT VERSION()');
echo "Engine: $engine, database: " . col($pdo, 'SELECT DATABASE()') . "\n";

// --- Migrations recorded -------------------------------------------------------------------
$recorded = (new Migrator($pdo, APP_ROOT . '/migrations', static fn() => null))->recorded();
$missing = [];
foreach ((new Migrator($pdo, APP_ROOT . '/migrations', static fn() => null))->discover(true) as $m) {
    $status = $recorded[$m['version']]['status'] ?? 'Pending';
    if (!($status === 'Applied' || ($m['optional'] && in_array($status, ['Skipped', 'Pending'], true)))) {
        $missing[] = "{$m['version']}=$status";
    }
}
check('every required migration is applied (optional ones applied or skipped)', !$missing, implode(', ', $missing));

// --- 0001 matches the documented schema -------------------------------------------------------
$docs = preg_replace('/^DROP TABLE IF EXISTS `[a-z_]+`;\n/m', '', str_replace("\r\n", "\n", file_get_contents(APP_ROOT . '/docs/PFPMS_schema_v2.sql')));
$m0001 = str_replace("\r\n", "\n", file_get_contents(APP_ROOT . '/migrations/0001_schema_v2_0_1.sql'));
$m0001 = preg_replace('/\A(?:--[^\n]*\n)+\n/', '', $m0001);
check('migrations/0001 matches docs/PFPMS_schema_v2.sql minus DROP lines', $docs === $m0001);

// --- Counts ----------------------------------------------------------------------------------
$tables = (int) col($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME <> 'schema_version'");
check('table count', $tables === EXPECTED_TABLES, "found $tables, expected " . EXPECTED_TABLES);
$fks = (int) col($pdo, 'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()');
check('foreign key count', $fks === EXPECTED_FOREIGN_KEYS, "found $fks, expected " . EXPECTED_FOREIGN_KEYS);
$noPk = $pdo->query("SELECT t.TABLE_NAME FROM information_schema.TABLES t
    LEFT JOIN information_schema.TABLE_CONSTRAINTS c ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.CONSTRAINT_TYPE = 'PRIMARY KEY'
    WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' AND c.CONSTRAINT_NAME IS NULL")->fetchAll(PDO::FETCH_COLUMN);
check('every table has a primary key', !$noPk, implode(', ', $noPk));
$notInno = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'")->fetchAll(PDO::FETCH_COLUMN);
check('every table is InnoDB', !$notInno, implode(', ', $notInno));

// --- Collations --------------------------------------------------------------------------------
check('database default collation', col($pdo, 'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()') === Db::COLLATION);
$badTables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_COLLATION <> '" . Db::COLLATION . "'")->fetchAll(PDO::FETCH_COLUMN);
check('every table uses ' . Db::COLLATION, !$badTables, implode(', ', $badTables));
$badCols = [];
foreach ($pdo->query("SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> '" . Db::COLLATION . "'") as $c) {
    $name = "{$c['TABLE_NAME']}.{$c['COLUMN_NAME']}";
    if (!($c['COLLATION_NAME'] === 'utf8mb4_bin' && in_array($name, JSON_COLUMNS, true))) {
        $badCols[] = "$name={$c['COLLATION_NAME']}";
    }
}
check('every text column uses ' . Db::COLLATION . ' (JSON columns excepted)', !$badCols, implode(', ', $badCols));

// --- Seeds -------------------------------------------------------------------------------------
$settings = (int) col($pdo, 'SELECT COUNT(*) FROM system_setting');
check('system settings seeded (12 in v2 + 54 in 0002 + 1 in 0010 + 1 in 0012)', $settings === 68, "found $settings");
$columnType = static fn(string $table, string $column): string => (string) col($pdo,
    'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
check("auth_token.purpose includes 'Device Registration' (0012)", str_contains($columnType('auth_token', 'purpose'), "'Device Registration'"));
check("user_session.end_reason includes 'Device Revoked' (0012)", str_contains($columnType('user_session', 'end_reason'), "'Device Revoked'"));
check('device has the 0012 columns', (int) col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device'
    AND COLUMN_NAME IN ('reported_max_seq', 'revoked_lost', 'revoked_max_seq', 'wiped_at', 'erase_requested_at', 'erase_requested_by')") === 6);
check('device has the 0013 columns', (int) col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device'
    AND COLUMN_NAME IN ('pbkdf2_iterations', 'proof_key_ciphertext', 'display_mode', 'storage_estimate_kb', 'clock_skew_seconds',
                        'oldest_pending_at', 'attention_count', 'locked_out_since', 'shift_ended_at')") === 9);
check('auth_token has created_at (0013)', (int) col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'auth_token' AND COLUMN_NAME = 'created_at'") === 1);
check('sync_item has recorded_at_raw (0013)', (int) col($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sync_item' AND COLUMN_NAME = 'recorded_at_raw'") === 1);
check("user_session.auth_method includes 'Offline PIN' (0013)", str_contains($columnType('user_session', 'auth_method'), "'Offline PIN'"));
check("user_session.end_reason includes 'User Switch' (0013)", str_contains($columnType('user_session', 'end_reason'), "'User Switch'"));
check('system account exists and is inactive', col($pdo, "SELECT status FROM user_account WHERE username = 'system'") === 'Inactive');

// --- Smoke tests (rolled back) -----------------------------------------------------------------
$pdo->beginTransaction();
try {
    $system = (int) col($pdo, "SELECT user_id FROM user_account WHERE username = 'system'");
    $dog = (int) col($pdo, "SELECT species_id FROM species WHERE name = 'Dog'");
    $pdo->exec("INSERT INTO site (name) VALUES ('Smoke Test Site')");
    $site = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO size_band (species_id, name, min_weight_lbs, max_weight_lbs) VALUES (?, ?, 0, 25)')->execute([$dog, 'Small']);
    $band = (int) $pdo->lastInsertId();
    $insP = $pdo->prepare('INSERT INTO participant (participant_code, legal_first_name, legal_last_name, postal_code, household_size, home_site_id, registration_site_id, registered_by)
                           VALUES (?, ?, ?, ?, 2, ?, ?, ?)');
    $insP->execute(['SMOKE-1', 'José', 'Muñoz', '29401', $site, $site, $system]);
    $p1 = (int) $pdo->lastInsertId();

    check('accent- and case-insensitive match (munoz finds Muñoz)', (int) col($pdo, "SELECT COUNT(*) FROM participant WHERE legal_last_name = 'munoz' AND legal_first_name = 'JOSE'") === 1);
    check('accent-only difference detectable with utf8mb4_bin', (int) col($pdo, "SELECT COUNT(*) FROM participant WHERE legal_last_name COLLATE utf8mb4_bin = 'Munoz'") === 0);
    check('trailing spaces compare equal (PAD SPACE; validators must trim)', (int) col($pdo, "SELECT 'abc' = 'abc '") === 1);

    $insPet = $pdo->prepare('INSERT INTO pet (participant_id, name, species_id, size_band_id, microchip_number, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    $insPet->execute([$p1, 'Rex', $dog, $band, '985112345678901', $system]);
    $petA = (int) $pdo->lastInsertId();
    check('second active pet with the same microchip is refused', expectError($pdo, 'INSERT INTO pet (participant_id, name, species_id, size_band_id, microchip_number, created_by) VALUES (?, ?, ?, ?, ?, ?)', [$p1, 'Rex II', $dog, $band, '985112345678901', $system], 1062));
    $pdo->prepare("UPDATE pet SET status = 'Inactive' WHERE pet_id = ?")->execute([$petA]);
    $insPet->execute([$p1, 'Rex II', $dog, $band, '985112345678901', $system]);
    check('chip is free once the first pet is inactive', (int) $pdo->lastInsertId() > $petA);
    check('reactivating the first pet is refused', expectError($pdo, "UPDATE pet SET status = 'Active' WHERE pet_id = ?", [$petA], 1062));

    $pdo->prepare("UPDATE user_account SET notification_prefs = ? WHERE user_id = ?")->execute(['{"email": true, "digest": "weekly"}', $system]);
    check('JSON_EXTRACT / JSON_UNQUOTE round trip', col($pdo, "SELECT JSON_UNQUOTE(JSON_EXTRACT(notification_prefs, '$.digest')) FROM user_account WHERE user_id = ?", [$system]) === 'weekly');

    $before = (int) col($pdo, "SELECT next_value FROM id_sequence WHERE seq_name = 'participant'");
    $pdo->exec('SAVEPOINT seq_test');
    $pdo->exec("UPDATE id_sequence SET next_value = LAST_INSERT_ID(next_value) + 1 WHERE seq_name = 'participant'");
    $taken = (int) col($pdo, 'SELECT LAST_INSERT_ID()');
    $advanced = (int) col($pdo, "SELECT next_value FROM id_sequence WHERE seq_name = 'participant'");
    $pdo->exec('ROLLBACK TO SAVEPOINT seq_test');
    $after = (int) col($pdo, "SELECT next_value FROM id_sequence WHERE seq_name = 'participant'");
    check('id_sequence hands out the stored value (P1 first) and gives it back on rollback', $taken === $before && $advanced === $before + 1 && $after === $before, "taken $taken, before $before, after rollback $after");

    $insR = $pdo->prepare("INSERT INTO snv_referral (pet_id, participant_id, site_id, issued_by, status) VALUES (?, ?, ?, ?, 'Declined')");
    $insR->execute([$petA, $p1, $site, $system]);
    $insR->execute([$petA, $p1, $site, $system]);
    check('declined referrals need no voucher number or expiry (several NULLs allowed)', (int) col($pdo, 'SELECT COUNT(*) FROM snv_referral WHERE voucher_number IS NULL AND participant_id = ?', [$p1]) === 2);

    $pdo->prepare("INSERT INTO distribution_event (site_id, event_date, starts_at, ends_at) VALUES (?, '2026-10-03', '09:00', '12:00')")->execute([$site]);
    $event = (int) $pdo->lastInsertId();
    $insD = $pdo->prepare("INSERT INTO distribution (client_uuid, event_id, participant_id, recorded_by, distributed_at, local_date, pets_served, entitled_lbs, allotment_rule_version, frequency_days_applied, reverses_distribution_id, reversal_reason)
                           VALUES (?, ?, ?, ?, '2026-10-03 14:00:00', '2026-10-03', 1, 10, 1, 30, ?, ?)");
    $insD->execute(['00000000-0000-4000-8000-000000000001', $event, $p1, $system, null, null]);
    $original = (int) $pdo->lastInsertId();
    $insD->execute(['00000000-0000-4000-8000-000000000002', $event, $p1, $system, $original, 'Smoke test']);
    check('a second reversal of the same distribution is refused', expectError($pdo, $insD->queryString, ['00000000-0000-4000-8000-000000000003', $event, $p1, $system, $original, 'Smoke test'], 1062));
    check('client_uuid makes a replayed distribution a duplicate', expectError($pdo, $insD->queryString, ['00000000-0000-4000-8000-000000000001', $event, $p1, $system, null, null], 1062));

    $triggers = col($pdo, "SELECT status FROM schema_version WHERE version = '9001'");
    if ($triggers === 'Applied') {
        check('immutability triggers refuse UPDATE on distribution (9001 applied)', expectError($pdo, 'UPDATE distribution SET pets_served = 2 WHERE distribution_id = ?', [$original], 1644));
        check('immutability triggers refuse DELETE on distribution (9001 applied)', expectError($pdo, 'DELETE FROM distribution WHERE distribution_id = ?', [$original], 1644));
        $pdo->exec('SET @pfpms_allow_mutation = 1');
        try {
            $pdo->prepare('UPDATE distribution SET pets_served = 2 WHERE distribution_id = ?')->execute([$original]);
            check('allowlisted services can mutate with @pfpms_allow_mutation = 1', true);
        } finally {
            $pdo->exec('SET @pfpms_allow_mutation = NULL');
        }
    } else {
        echo "INFO  immutability triggers not installed (9001: " . ($triggers ?: 'not run') . "); the app-layer guard is the control\n";
    }
} finally {
    $pdo->rollBack();
}

echo Checks::$failures === 0 ? "\nAll checks passed.\n" : "\n" . Checks::$failures . " check(s) FAILED.\n";
exit(Checks::$failures === 0 ? 0 : 1);
