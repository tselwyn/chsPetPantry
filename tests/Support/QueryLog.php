<?php
declare(strict_types=1);

namespace Pfpms\Tests\Support;

/**
 * The statements executed through prepare()->execute() while a callable runs, in order (S3 spec §0.2). The main connection's
 * PDO::ATTR_STATEMENT_CLASS is switched to this class for the call and back afterwards; the open transaction is untouched, so
 * statement-order tests need no hook in production code.
 */
final class QueryLog extends \PDOStatement
{
    /** @var list<string> */
    private static array $log = [];
    /** @var ?array{0: string, 1: callable} after(): the statement to wait for and what to run once it has executed */
    private static ?array $then = null;
    /** True while after()'s callable runs: its own statements are neither logged nor matched. */
    private static bool $inThen = false;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        if (self::$inThen) {
            return parent::execute($params);
        }
        $sql = (string) preg_replace('/\s+/', ' ', trim($this->queryString));
        self::$log[] = $sql;
        $ok = parent::execute($params);
        if (self::$then !== null && preg_match(self::$then[0], $sql) === 1) {
            $then = self::$then[1];
            self::$then = null;
            self::$inThen = true;
            try {
                $then(); // the statement's result is already buffered: the caller still reads what it read before this
            } finally {
                self::$inThen = false;
            }
        }
        return $ok;
    }

    /**
     * during($fn), and right after the first statement matching $regex has executed, $then() once: a change that another
     * request commits at that exact point (a reset after the password was read, an access change after the access check),
     * with no hook in production code. @return list<string> $fn's statements, as during()
     * @throws \LogicException when no statement matched (the test would prove nothing)
     */
    public static function after(string $regex, callable $then, callable $fn): array
    {
        self::$then = [$regex, $then];
        try {
            return self::during($fn);
        } finally {
            $missed = self::$then !== null;
            self::$then = null;
            if ($missed) {
                throw new \LogicException("QueryLog::after(): no statement matched $regex");
            }
        }
    }

    /** @return list<string> (statements of $fn only; restored even when $fn throws, which is rethrown) */
    public static function during(callable $fn): array
    {
        self::$log = [];
        $pdo = \Pfpms\Db::pdo();
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [self::class, []]);
        try {
            $fn();
        } finally {
            $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        $log = self::$log;
        self::$log = [];
        return $log;
    }

    /**
     * Place a step that runs no SQL among the statements being logged, as "-- $label" (from a test-only hook such as
     * PasswordPolicy::$afterHash): first() then finds it like a statement. Not logged inside after()'s callable.
     */
    public static function mark(string $label): void
    {
        if (!self::$inThen) {
            self::$log[] = "-- $label";
        }
    }

    /**
     * Index of the first statement matching $regex, or null.
     * @param list<string> $log
     */
    public static function first(array $log, string $regex): ?int
    {
        foreach ($log as $i => $sql) {
            if (preg_match($regex, $sql) === 1) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Index of the last statement matching $regex, or null.
     * @param list<string> $log
     */
    public static function last(array $log, string $regex): ?int
    {
        $found = null;
        foreach ($log as $i => $sql) {
            if (preg_match($regex, $sql) === 1) {
                $found = $i;
            }
        }
        return $found;
    }
}
