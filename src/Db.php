<?php
declare(strict_types=1);

namespace Pfpms;

use PDO;
use PDOException;
use Throwable;

/**
 * Database access. One lazily opened PDO connection per request.
 *
 * Portability (docs/PFPMS_Implementation_Plan.md §8): the same SQL must run on
 * MariaDB 10.4 and MySQL 8.4, so every connection pins charset, collation,
 * time zone (UTC) and sql_mode.
 */
final class Db
{
    public const COLLATION = 'utf8mb4_unicode_520_ci';

    public const SQL_MODE = 'STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,'
        . 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /** Error codes that mean "the whole transaction may succeed if re-run". */
    private const RETRYABLE = [1213 /* deadlock */, 1205 /* lock wait timeout */];

    private static ?PDO $pdo = null;
    private static ?PDO $durable = null;
    private static int $depth = 0;
    /** Set when a nested block hit an error that invalidates the outer transaction. */
    private static ?Throwable $rollbackOnly = null;

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect(Config::require('db'));
    }

    /**
     * Open a connection from a config array:
     * [host, port, name, user, pass, (optional) ssl_ca, ssl_verify].
     * $pdoOptions override the defaults (the migration runner needs emulated prepares).
     */
    public static function connect(array $db, array $pdoOptions = []): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'] ?? '127.0.0.1',
            (int) ($db['port'] ?? 3306),
            $db['name'] ?? ''
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            self::mysqlAttr('MULTI_STATEMENTS') => false,
            self::mysqlAttr('INIT_COMMAND') => "SET NAMES utf8mb4 COLLATE " . self::COLLATION
                . ", time_zone = '+00:00', sql_mode = '" . self::SQL_MODE . "'",
        ];
        if (!empty($db['ssl_ca'])) {
            // Needed for caching_sha2_password accounts over TCP on MySQL 8.4 hosts.
            $options[self::mysqlAttr('SSL_CA')] = $db['ssl_ca'];
            $options[self::mysqlAttr('SSL_VERIFY_SERVER_CERT')] = (bool) ($db['ssl_verify'] ?? true);
        }
        return new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), $pdoOptions + $options);
    }

    /**
     * A second, autocommit connection for rows that must survive a rollback of the main
     * transaction (Denied/Failed audit entries). Rows written here must never reference a
     * row created in the open main transaction: the insert would wait on that row's lock.
     */
    public static function durable(): PDO
    {
        return self::$durable ??= self::connect(Config::require('db'));
    }

    /** Test/CLI helper: use a specific connection (or null to reset). */
    public static function use(?PDO $pdo, ?PDO $durable = null): void
    {
        self::$pdo = $pdo;
        self::$durable = $durable;
        self::$depth = 0;
        self::$rollbackOnly = null;
    }

    /**
     * Optimistic locking (UC-04 §4.2): update a row only if it still has the version that
     * was read, and bump the version. Returns false when someone else changed it first.
     * Table and column names come from code, never from input; they are checked anyway.
     */
    public static function updateVersioned(string $table, string $keyColumn, int $id, int $version, array $changes): bool
    {
        foreach (array_merge([$table, $keyColumn], array_keys($changes)) as $identifier) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
                throw new \InvalidArgumentException("Invalid SQL identifier '$identifier'");
            }
        }
        $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($changes)));
        $st = self::pdo()->prepare("UPDATE `$table` SET $sets, `row_version` = `row_version` + 1 WHERE `$keyColumn` = ? AND `row_version` = ?");
        $st->execute([...array_values($changes), $id, $version]);
        return $st->rowCount() === 1;
    }

    /**
     * Run $fn inside a transaction and return its result.
     *
     * Nested calls use savepoints. Deadlocks (1213) and lock-wait timeouts (1205) are
     * retried only at the outermost level, after a full ROLLBACK, by re-running the whole
     * closure. A retryable error inside a nested block marks the whole transaction
     * rollback-only, even if the caller catches it, because a deadlock has already rolled
     * back everything (and destroyed the savepoints) and a lock-wait timeout leaves the
     * outer work half done. The outer level then rolls back and retries or rethrows.
     */
    public static function transaction(callable $fn, int $maxRetries = 2): mixed
    {
        $pdo = self::pdo();
        return self::$depth > 0 ? self::nested($pdo, $fn) : self::outer($pdo, $fn, $maxRetries);
    }

    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Tests only: open a transaction that every Db::transaction() call nests inside (as
     * savepoints), so a test's writes can all be rolled back by testRollback().
     */
    public static function testBegin(): void
    {
        self::pdo()->beginTransaction();
        self::$depth = 1;
        self::$rollbackOnly = null;
    }

    public static function testRollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
        self::$depth = 0;
        self::$rollbackOnly = null;
    }

    public static function errorCode(Throwable $e): ?int
    {
        return $e instanceof PDOException && isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
    }

    public static function isDuplicateKey(Throwable $e): bool
    {
        return self::errorCode($e) === 1062;
    }

    public static function isRetryable(Throwable $e): bool
    {
        return in_array(self::errorCode($e), self::RETRYABLE, true);
    }

    /**
     * Server-wide advisory lock names must be unique per database on shared hosting.
     * Returns a name of at most 64 characters (the MySQL limit).
     */
    public static function lockName(PDO $pdo, string $key): string
    {
        $name = 'pfpms:' . $pdo->query('SELECT DATABASE()')->fetchColumn() . ':' . $key;
        return strlen($name) <= 64 ? $name : 'pfpms:' . sha1($name);
    }

    private static function outer(PDO $pdo, callable $fn, int $maxRetries): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            $pdo->beginTransaction();
            self::$depth = 1;
            self::$rollbackOnly = null;
            try {
                $result = $fn($pdo);
                $nestedFailure = self::pendingRollbackOnly(); // set by nested() while $fn ran
                if ($nestedFailure !== null) {
                    throw $nestedFailure; // a nested failure was caught by the caller
                }
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($attempt < $maxRetries && self::isRetryable($e)) {
                    usleep(random_int(20_000, 80_000) * ($attempt + 1));
                    continue;
                }
                throw $e;
            } finally {
                self::$depth = 0;
                self::$rollbackOnly = null;
            }
        }
    }

    /** The error that made the open transaction rollback-only, if any. */
    private static function pendingRollbackOnly(): ?Throwable
    {
        return self::$rollbackOnly;
    }

    private static function nested(PDO $pdo, callable $fn): mixed
    {
        $savepoint = 'pfpms_sp' . self::$depth;
        $pdo->exec("SAVEPOINT $savepoint");
        self::$depth++;
        try {
            $result = $fn($pdo);
            $pdo->exec("RELEASE SAVEPOINT $savepoint");
            return $result;
        } catch (Throwable $e) {
            $code = self::errorCode($e);
            if ($code === 1213) {
                // The server rolled the whole transaction back. Open a new one so any statements
                // the caller runs after catching this stay uncommitted until the outer rollback.
                self::$rollbackOnly ??= $e;
                $pdo->exec('START TRANSACTION');
            } else {
                try {
                    $pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");
                } catch (Throwable) {
                    self::$rollbackOnly ??= $e; // savepoint gone: the transaction cannot be trusted
                }
                if ($code === 1205) {
                    self::$rollbackOnly ??= $e;
                }
            }
            throw $e;
        } finally {
            self::$depth--;
        }
    }

    /** PDO::MYSQL_ATTR_* are deprecated from PHP 8.5 in favour of Pdo\Mysql::ATTR_* (PHP 8.4+). */
    private static function mysqlAttr(string $name): int
    {
        $modern = 'Pdo\\Mysql::ATTR_' . $name;
        return defined($modern) ? constant($modern) : constant('PDO::MYSQL_ATTR_' . $name);
    }
}
