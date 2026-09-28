<?php
declare(strict_types=1);

namespace Pfpms\Db;

use PDO;
use Pfpms\Db;
use RuntimeException;
use Throwable;

/**
 * Applies migrations/NNNN_name.sql in order (and migrations/optional/*.sql on request).
 *
 * DDL commits implicitly, so a migration file cannot be atomic. Instead each file is
 * split into single statements, run one exec() at a time, and progress is recorded
 * per statement in schema_version.
 *
 * - Resume: a Failed or interrupted run resumes at the first unrecorded statement. Session
 *   SET statements before that point are replayed first. If the process died after a
 *   statement committed but before its progress was saved, the resumed statement fails
 *   with an "already exists" error; on that first resumed statement only, such errors
 *   are accepted as "already applied".
 * - Immutability: an Applied or Skipped file may never change. A Failed or interrupted file
 *   may be edited only after its last applied statement (the applied prefix is hashed).
 * - Optional migrations are Skipped only when the host refuses them for lack of privilege
 *   (e.g. triggers without SUPER); any other error fails the run.
 */
final class Migrator
{
    /** Errors meaning "the object this statement creates is already there". */
    private const ALREADY_EXISTS = [1050 /* table */, 1060 /* column */, 1061 /* key name */,
        1826 /* FK name */, 1359 /* trigger */];

    /** Privilege errors that make an optional migration Skipped rather than Failed. */
    private const PRIVILEGE_ERRORS = [1419 /* SUPER + binlog */, 1227 /* access denied, need SUPER */,
        1142 /* command denied */, 1044 /* db access denied */, 1370 /* routine access */];

    /** @var callable(string):void */
    private $out;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $dir,
        callable $out,
    ) {
        $this->out = $out;
    }

    /** @return list<array{version:string,file:string,optional:bool}> */
    public function discover(bool $withOptional): array
    {
        $found = [];
        foreach (['' => false, 'optional/' => true] as $sub => $optional) {
            if ($optional && !$withOptional) {
                continue;
            }
            foreach (glob($this->dir . '/' . $sub . '[0-9][0-9][0-9][0-9]_*.sql') ?: [] as $file) {
                $found[] = ['version' => substr(basename($file), 0, 4), 'file' => $file, 'optional' => $optional];
            }
        }
        usort($found, fn($a, $b) => strcmp($a['version'], $b['version']));
        $versions = array_column($found, 'version');
        if (count($versions) !== count(array_unique($versions))) {
            throw new RuntimeException('Duplicate migration version numbers in ' . $this->dir);
        }
        return $found;
    }

    public function run(bool $withOptional, bool $retrySkipped = false): bool
    {
        $lock = Db::lockName($this->pdo, 'migrate');
        if ((int) $this->pdo->query('SELECT GET_LOCK(' . $this->pdo->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            throw new RuntimeException('Another migration run holds the lock; try again later.');
        }
        try {
            $this->ensureDatabaseCollation();
            $this->ensureVersionTable();
            $applied = $this->recorded();
            $ran = 0;
            foreach ($this->discover($withOptional) as $m) {
                $sql = SqlSplitter::normalise((string) file_get_contents($m['file']));
                $checksum = hash('sha256', $sql);
                $statements = SqlSplitter::split($sql);
                $row = $applied[$m['version']] ?? null;
                if ($row) {
                    $done = in_array($row['status'], ['Applied', 'Skipped'], true);
                    if ($row['checksum'] !== $checksum) {
                        if ($done) {
                            throw new RuntimeException("Migration {$m['version']} changed after it was {$row['status']}; migrations are immutable. Add a new migration instead.");
                        }
                        $this->acceptEditedFile($m, $row, $statements, $checksum);
                    }
                    if ($row['status'] === 'Applied' || ($row['status'] === 'Skipped' && !$retrySkipped)) {
                        continue;
                    }
                }
                $ran++;
                if (!$this->apply($m, $statements, $checksum, $row)) {
                    return false;
                }
            }
            if ($ran === 0) {
                ($this->out)('Nothing to do: database is up to date.');
            }
            return true;
        } finally {
            $this->pdo->query('SELECT RELEASE_LOCK(' . $this->pdo->quote($lock) . ')');
        }
    }

    /** @return array<string, array> */
    public function recorded(): array
    {
        $rows = [];
        foreach ($this->pdo->query('SELECT * FROM schema_version ORDER BY version') as $row) {
            $rows[$row['version']] = $row;
        }
        return $rows;
    }

    private function apply(array $m, array $statements, string $checksum, ?array $row): bool
    {
        $name = basename($m['file']);
        $total = count($statements);
        $start = $row ? (int) $row['statements_applied'] : 0;
        if ($row) {
            $this->pdo->prepare("UPDATE schema_version SET status = 'Applying', note = NULL, statements_total = ? WHERE version = ?")
                ->execute([$total, $m['version']]);
            ($this->out)("Resuming $name at statement " . ($start + 1) . " of $total");
            foreach (array_slice($statements, 0, $start) as $earlier) {
                if (preg_match('/^SET\s/i', $earlier)) {
                    $this->execute($earlier); // session settings do not survive the old connection
                }
            }
        } else {
            $this->pdo->prepare(
                "INSERT INTO schema_version (version, filename, checksum, prefix_checksum, statements_total, statements_applied, status, started_at)
                 VALUES (?, ?, ?, ?, ?, 0, 'Applying', UTC_TIMESTAMP())"
            )->execute([$m['version'], $name, $checksum, self::prefixHash($statements, 0), $total]);
            ($this->out)("Applying $name ($total statements)");
        }

        $progress = $this->pdo->prepare('UPDATE schema_version SET statements_applied = ?, prefix_checksum = ? WHERE version = ?');
        for ($i = $start; $i < $total; $i++) {
            $statement = $statements[$i];
            $warnings = [];
            try {
                $this->execute($statement);
                $warnings = $this->unexpectedWarnings($statement); // read before any other statement resets them
            } catch (Throwable $e) {
                $code = Db::errorCode($e);
                $resumedAlready = $row && $i === $start && (in_array($code, self::ALREADY_EXISTS, true)
                    || ($code === 1062 && preg_match('/^INSERT\s/i', $statement))); // seed rows already committed
                if ($resumedAlready) {
                    ($this->out)("  Statement " . ($i + 1) . " was already applied before the interruption (error $code); continuing");
                } elseif ($m['optional'] && in_array($code, self::PRIVILEGE_ERRORS, true)) {
                    return $this->stop($m, 'Skipped', $i, $statement, $e->getMessage(), true);
                } else {
                    return $this->stop($m, 'Failed', $i, $statement, $e->getMessage(), false);
                }
            }
            // The statement has taken effect: record it before anything else can fail.
            $progress->execute([$i + 1, self::prefixHash($statements, $i + 1), $m['version']]);
            if ($warnings) {
                return $this->stop($m, 'Failed', $i, $statement, 'applied, but with unexpected warnings: ' . implode('; ', $warnings), false);
            }
        }
        $this->pdo->prepare("UPDATE schema_version SET status = 'Applied', finished_at = UTC_TIMESTAMP() WHERE version = ?")
            ->execute([$m['version']]);
        ($this->out)("  Applied $name");
        return true;
    }

    /** Run one statement; any result set (a SELECT in a migration) is discarded so the connection stays usable. */
    private function execute(string $statement): void
    {
        $this->pdo->query($statement)->closeCursor();
    }

    private function stop(array $m, string $status, int $i, string $statement, string $message, bool $continue): bool
    {
        $note = sprintf('statement %d: %s', $i + 1, $message);
        $this->pdo->prepare('UPDATE schema_version SET status = ?, note = ?, finished_at = UTC_TIMESTAMP() WHERE version = ?')
            ->execute([$status, mb_substr($note, 0, 255), $m['version']]);
        ($this->out)("  $status: $note");
        ($this->out)('  Statement: ' . mb_substr((string) preg_replace('/\s+/', ' ', $statement), 0, 200));
        return $continue;
    }

    /**
     * A Failed or interrupted migration may be corrected, but only after the statements that
     * already ran: those are part of the database now.
     */
    private function acceptEditedFile(array $m, array $row, array $statements, string $checksum): void
    {
        $applied = (int) $row['statements_applied'];
        if ($applied > count($statements) || self::prefixHash($statements, $applied) !== $row['prefix_checksum']) {
            throw new RuntimeException("Migration {$m['version']} was edited in its first $applied statement(s), which already ran. Only the unapplied part of a failed migration may change.");
        }
        $this->pdo->prepare('UPDATE schema_version SET checksum = ? WHERE version = ?')->execute([$checksum, $m['version']]);
        ($this->out)("Migration {$m['version']} was edited after statement $applied; accepting the new version of the remaining statements.");
    }

    private static function prefixHash(array $statements, int $count): string
    {
        return hash('sha256', implode("\n;\n", array_slice($statements, 0, $count)));
    }

    /**
     * Notes are ignored. MySQL warning 1681 is tolerated only for TINYINT(1), the schema's
     * boolean type; 1681 is also used for other deprecations that must not slip through.
     */
    private function unexpectedWarnings(string $statement): array
    {
        $bad = [];
        foreach ($this->pdo->query('SHOW WARNINGS')->fetchAll(PDO::FETCH_ASSOC) as $w) {
            if ($w['Level'] === 'Note') {
                continue;
            }
            if ((int) $w['Code'] === 1681 && str_starts_with($w['Message'], 'Integer display width is deprecated')
                && !preg_match('/\b(?:TINYINT\s*\(\s*(?!1\s*\))\d+\s*\)|(?:SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT)\s*\(\s*\d+\s*\))/i', $statement)) {
                continue;
            }
            $bad[] = "{$w['Level']} {$w['Code']}: {$w['Message']}";
        }
        return $bad;
    }

    /**
     * A database created in SiteGround Site Tools on MySQL 8.4 defaults to utf8mb4_0900_ai_ci,
     * and XAMPP MariaDB defaults to latin1. Tables here always name their collation, but
     * anything created without one (temporary tables) inherits the default. The default is
     * changed only on an empty database, so the runner never alters someone else's schema.
     */
    private function ensureDatabaseCollation(): void
    {
        $current = $this->pdo->query(
            'SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
        )->fetchColumn();
        if ($current === Db::COLLATION) {
            return;
        }
        $tables = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME <> 'schema_version'"
        )->fetchColumn();
        if ($tables > 0) {
            throw new RuntimeException("The database default collation is $current, not " . Db::COLLATION
                . ", and the database already has $tables table(s). Refusing to change a non-empty database; check the config points at the PFPMS database.");
        }
        $name = str_replace('`', '``', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        ($this->out)("Setting database default collation ($current -> " . Db::COLLATION . ')');
        $this->pdo->exec("ALTER DATABASE `$name` CHARACTER SET utf8mb4 COLLATE " . Db::COLLATION);
    }

    private function ensureVersionTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `schema_version` (
               `version` CHAR(4) NOT NULL,
               `filename` VARCHAR(255) NOT NULL,
               `checksum` CHAR(64) NOT NULL,
               `prefix_checksum` CHAR(64) NOT NULL,
               `statements_total` INT NOT NULL,
               `statements_applied` INT NOT NULL DEFAULT 0,
               `status` ENUM('Applying','Applied','Skipped','Failed') NOT NULL,
               `started_at` DATETIME NOT NULL,
               `finished_at` DATETIME NULL,
               `note` VARCHAR(255) NULL,
               PRIMARY KEY (`version`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=" . Db::COLLATION
        );
    }
}
