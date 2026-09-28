<?php
declare(strict_types=1);

namespace Pfpms\Db;

use RuntimeException;

/**
 * Splits a SQL script into single statements so each can be run with its own
 * PDO::exec() call. That way a failure names the exact statement, and progress
 * can be recorded statement by statement.
 *
 * Understands:
 * - quoted strings: '...', "..." and `...`, including backslash escapes and doubled quotes;
 * - comments: "-- " to end of line, "#" to end of line, and block comments. Comments are
 *   replaced by a single space so the tokens on either side stay separate;
 * - DELIMITER directives at the start of a statement (used for triggers).
 *
 * Rejects with an exception: an unterminated quote or block comment, and MySQL executable
 * comments or optimizer hints ("/*!" and "/*+"), which would otherwise be silently dropped.
 */
final class SqlSplitter
{
    private const DELIMITER_DIRECTIVE = '/\G[ \t]*DELIMITER[ \t]+(\S+)[ \t]*(?:(?:--[ \t]|#)[^\n]*)?(?:\n|$)/i';

    /** @return list<string> */
    public static function split(string $sql): array
    {
        $sql = self::normalise($sql);
        $statements = [];
        $delimiter = ';';
        $buffer = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            if (trim($buffer) === '' && preg_match(self::DELIMITER_DIRECTIVE, $sql, $m, 0, $i)) {
                $buffer = '';
                $delimiter = $m[1];
                $i += strlen($m[0]);
                continue;
            }

            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $buffer .= ' ';
                continue;
            }
            if ($char === '/' && $next === '*') {
                $kind = $sql[$i + 2] ?? '';
                if ($kind === '!' || $kind === '+') {
                    throw new RuntimeException("Executable comment or optimizer hint (/*$kind) at offset $i is not supported");
                }
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new RuntimeException("Unterminated /* comment starting at offset $i");
                }
                $i = $end + 2;
                $buffer .= ' ';
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $j = $i + 1;
                $closed = false;
                while ($j < $length) {
                    if ($sql[$j] === '\\' && $char !== '`') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $char) {
                        if (($sql[$j + 1] ?? '') === $char) {
                            $j += 2;
                            continue;
                        }
                        $closed = true;
                        break;
                    }
                    $j++;
                }
                if (!$closed) {
                    throw new RuntimeException("Unterminated $char quote starting at offset $i");
                }
                $buffer .= substr($sql, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            if (substr_compare($sql, $delimiter, $i, strlen($delimiter)) === 0) {
                self::push($buffer, $statements);
                $i += strlen($delimiter);
                continue;
            }

            $buffer .= $char;
            $i++;
        }
        self::push($buffer, $statements);
        return $statements;
    }

    /** Normalise line endings and drop a UTF-8 byte-order mark. */
    public static function normalise(string $sql): string
    {
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }
        return str_replace("\r\n", "\n", $sql);
    }

    private static function push(string &$buffer, array &$statements): void
    {
        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }
        $buffer = '';
    }
}
