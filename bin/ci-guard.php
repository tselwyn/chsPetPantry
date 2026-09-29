<?php
declare(strict_types=1);

/*
 * CI guard for the rules in docs/PFPMS_Implementation_Plan.md §8. Command line only.
 *
 *   php bin/ci-guard.php
 *
 * 1. Portability: SQL must run unchanged on MariaDB 10.4 and MySQL 8.4. Checked in
 *    migrations/ and seeds/ (comments stripped) and in SQL string literals in PHP code;
 *    PHP's own -> operator is never checked.
 * 2. Immutability: no UPDATE, DELETE, REPLACE, TRUNCATE or upsert on the append-only tables except in
 *    allowlisted files, and stock quantities (site_stock) are changed only by the inventory ledger;
 *    zero stock rows may also be created by StockRepository and by seed backfills. Checked in SQL
 *    files and PHP strings. Comments are ignored, every INSERT form is recognised (no INTO,
 *    LOW_PRIORITY, a database prefix), and a PHP string that starts with a write keyword is checked
 *    whatever its case. The portability rules only scan PHP strings with an upper-case keyword, so
 *    SQL in PHP must be written in capitals.
 * 3. Hygiene: no logs, dumps, archives or local config files tracked in git.
 * Exits 1 and lists file:line for every violation.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

use Pfpms\Db\SqlSplitter;

const PORTABILITY = [
    '/0900_ai_ci/i' => 'MySQL-only collation (use utf8mb4_unicode_520_ci)',
    '/->>?\s*\'\$/' => 'JSON arrow operator (use JSON_EXTRACT / JSON_UNQUOTE)',
    '/\bJSON_TABLE\s*\(/i' => 'JSON_TABLE (MariaDB 10.6+ only)',
    '/\bJSON_(ARRAYAGG|OBJECTAGG)\s*\(/i' => 'JSON_ARRAYAGG/JSON_OBJECTAGG (MariaDB 10.5+ only)',
    '/\bRENAME\s+COLUMN\b/i' => 'RENAME COLUMN (MariaDB 10.5.2+ only; use CHANGE COLUMN)',
    '/\bANY_VALUE\s*\(/i' => 'ANY_VALUE (MySQL only)',
    '/\bLATERAL\b/i' => 'LATERAL derived tables (MySQL only)',
    '/\bCAST\s*\([^)]*\bAS\s+JSON\b/i' => 'CAST(... AS JSON) (MySQL only)',
    '/\bREGEXP_LIKE\s*\(/i' => 'REGEXP_LIKE (MySQL only; use REGEXP)',
    '/\bSKIP\s+LOCKED\b/i' => 'SKIP LOCKED (MariaDB 10.6+ only)',
    '/ON\s+DUPLICATE\s+KEY\s+UPDATE[^;]*\bVALUES\s*\(/is' => 'VALUES() in ON DUPLICATE KEY UPDATE (deprecated on MySQL 8.4; bind the value twice)',
    '/\)\s+AS\s+`?\w+`?\s+ON\s+DUPLICATE\s+KEY/i' => 'row alias before ON DUPLICATE KEY (MySQL only)',
    '/\bADD\s+(COLUMN|INDEX|KEY|CONSTRAINT)\s+IF\s+NOT\s+EXISTS\b/i' => 'ADD ... IF NOT EXISTS (MariaDB only)',
    '/\bDROP\s+(COLUMN|INDEX|KEY|FOREIGN\s+KEY|CONSTRAINT)\s+IF\s+EXISTS\b/i' => 'DROP ... IF EXISTS on a column or key (MariaDB only)',
    '/\bRETURNING\b/i' => 'RETURNING (MariaDB only)',
    '/\b(NEXTVAL\s*\(|CREATE\s+SEQUENCE\b)/i' => 'sequences (MariaDB only; use id_sequence)',
    '/(?<![\w.])(?:TINYINT\s*\(\s*(?!1\s*\))\d+\s*\)|(?:SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT)\s*\(\s*\d+\s*\))/i' => 'integer display width (deprecated on MySQL; only TINYINT(1) is allowed)',
];

/** Append-only tables: history is corrected by new rows (a reversal, a count adjustment), never by changing old ones. */
const IMMUTABLE_TABLES = 'distribution|distribution_line|distribution_pet|audit_log|audit_field_change|snv_referral_status_log|inventory_transaction|stock_receipt_line|inventory_count|inventory_count_line';
/** The start of a statement (after comments are stripped), optionally after a WITH clause. */
const STATEMENT = '(?:^|;)\s*(?:WITH\b[\s\S]*?\)\s*)?';
/** Every way to write INSERT [INTO] [db.]table. */
const INSERT_INTO = '\bINSERT\s+(?:(?:LOW_PRIORITY|HIGH_PRIORITY|DELAYED|IGNORE)\s+)*(?:INTO\s+)?(?:`?\w+`?\.)?`?';
/** Any UPDATE, DELETE, REPLACE or TRUNCATE naming an append-only table anywhere (also UPDATE IGNORE, joins, DELETE alias FROM). */
const IMMUTABLE = '/' . STATEMENT . '(?:UPDATE|DELETE|REPLACE|TRUNCATE)\b[\s\S]*?\b`?(' . IMMUTABLE_TABLES . ')`?(?![\w])/i';
/** An upsert into an append-only table would overwrite an existing row. */
const IMMUTABLE_UPSERT = '/' . INSERT_INTO . '(' . IMMUTABLE_TABLES . ')`?(?![\w])[\s\S]*\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i';
/** Stock quantities change only through the ledger, so that the ledger always adds up to the stock on hand. */
const STOCK_CHANGE = '/' . STATEMENT . '(?:UPDATE|DELETE|REPLACE|TRUNCATE)\b[\s\S]*?\bsite_stock\b|' . INSERT_INTO . 'site_stock`?(?![\w])[\s\S]*\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i';
/** Creating stock rows: at zero only, by the ledger, by StockRepository, or by a seed backfill (INSERT ... SELECT ..., 0 FROM). */
const STOCK_INSERT = '/' . INSERT_INTO . 'site_stock`?(?![\w])/i';
const ZERO_BACKFILL = '/\bSELECT\b[\s\S]*,\s*0\s+FROM\b/i';
const STOCK_CHANGERS = ['src/Inventory/Ledger.php' => 'the ledger: every change of quantity_on_hand with its inventory_transaction row'];
const STOCK_CREATORS = STOCK_CHANGERS + ['src/Inventory/StockRepository.php' => 'creates the zero rows for a new product or site'];

/** Files allowed to mutate append-only tables (each with the reason). */
const IMMUTABLE_ALLOWLIST = [
    'bin/schema-check.php' => 'smoke test of the optional triggers, inside a rolled-back transaction',
];

$root = APP_ROOT;
$violations = [];

// 1 + 2 in SQL files.
foreach (array_merge(glob("$root/migrations/*.sql") ?: [], glob("$root/migrations/optional/*.sql") ?: [], glob("$root/seeds/*/*.sql") ?: []) as $file) {
    $sql = (string) file_get_contents($file);
    foreach (SqlSplitter::split($sql) as $statement) {
        scan_sql($statement, rel($file), lineOf($sql, $statement), $violations, isMigration: str_contains($file, '/migrations/'));
        scan_stock($statement, rel($file), lineOf($sql, $statement), $violations, sqlFile: true);
    }
}

// 1 + 2 in SQL string literals inside PHP.
foreach (['src', 'public', 'bin', 'templates'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $info) {
        if ($info->getExtension() !== 'php') {
            continue;
        }
        $file = str_replace('\\', '/', $info->getPathname());
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $sql = trim($token[1], "\"'");
            if ($token[1][0] === '"') {
                $sql = strtr($sql, ['\n' => "\n", '\r' => "\r", '\t' => "\t"]); // escapes, so a -- comment ends where the line does
            }
            if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|REPLACE|TRUNCATE)\b/', $sql)) {
                scan_sql($sql, rel($file), $token[2], $violations, isMigration: false);
            } elseif (preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|WITH)\b/i', strip_comments($sql))) {
                scan_writes($sql, rel($file), $token[2], $violations); // a write in lower case
            } else {
                continue;
            }
            scan_stock($sql, rel($file), $token[2], $violations, sqlFile: false);
        }
    }
}

// 3. Tracked files that must never be committed.
exec('git -C ' . escapeshellarg($root) . ' ls-files', $tracked);
foreach ($tracked as $path) {
    if (preg_match('~(^|/)(php_errorlog|\.env(\..*)?|\.DS_Store)$|\.(log|zip|7z|bak|enc|eml)$|^config/config(\.test)?\.php$|^storage/(?!\.gitkeep$)~i', $path)
        || (str_ends_with(strtolower($path), '.sql') && !preg_match('~^(docs|migrations|seeds)/~', $path))) {
        $violations[] = "$path: file type that must not be committed";
    }
}

if ($violations) {
    fwrite(STDERR, implode("\n", $violations) . "\n" . count($violations) . " violation(s)\n");
    exit(1);
}
echo "ci-guard: no violations\n";

function scan_sql(string $sql, string $file, int $line, array &$violations, bool $isMigration): void
{
    foreach (PORTABILITY as $pattern => $why) {
        if (preg_match($pattern, $sql)) {
            $violations[] = "$file:$line: $why";
        }
    }
    if (!$isMigration) {
        scan_writes($sql, $file, $line, $violations);
    }
}

function scan_writes(string $sql, string $file, int $line, array &$violations): void
{
    $sql = strip_comments($sql);
    if ((preg_match(IMMUTABLE, $sql, $m) || preg_match(IMMUTABLE_UPSERT, $sql, $m)) && !isset(IMMUTABLE_ALLOWLIST[$file])) {
        $violations[] = "$file:$line: change to append-only table {$m[1]} (record a reversal or adjustment, or add an allowlisted service)";
    }
}

function scan_stock(string $sql, string $file, int $line, array &$violations, bool $sqlFile): void
{
    $sql = strip_comments($sql);
    if (preg_match(STOCK_CHANGE, $sql) && !isset(STOCK_CHANGERS[$file])) {
        $violations[] = "$file:$line: stock quantities are changed only by src/Inventory/Ledger.php";
    } elseif (preg_match(STOCK_INSERT, $sql) && !isset(STOCK_CREATORS[$file]) && !($sqlFile && preg_match(ZERO_BACKFILL, $sql))) {
        $violations[] = "$file:$line: stock rows are created only by the ledger, StockRepository or a zero backfill (INSERT ... SELECT ..., 0 FROM ...)";
    }
}

/** SQL without its comments, so a leading comment cannot hide a statement's first keyword. */
function strip_comments(string $sql): string
{
    return (string) preg_replace(['~/\*[\s\S]*?\*/~', '~(?:--\s|#)[^\n]*~'], ' ', $sql);
}

function rel(string $file): string
{
    return ltrim(substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', APP_ROOT))), '/');
}

function lineOf(string $haystack, string $statement): int
{
    $first = strtok($statement, "\n");
    $pos = $first === false ? false : strpos($haystack, $first);
    return $pos === false ? 1 : substr_count($haystack, "\n", 0, $pos) + 1;
}
