<?php
declare(strict_types=1);

/*
 * Create an Administrator account from the command line (replaces the legacy insertAdmin.php,
 * which anyone could open in a browser). Prints a one-time temporary password; the new
 * Administrator must replace it at first sign-in.
 *
 *   php bin/create-admin.php --username=jdoe --email=jdoe@example.org --first=Jane --last=Doe [--config=path]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

use Pfpms\Audit\Audit;
use Pfpms\Auth\PasswordPolicy;
use Pfpms\Clock;
use Pfpms\Config;
use Pfpms\Db;
use Pfpms\Validation\Validator;

$opts = getopt('', ['username:', 'email:', 'first:', 'last:', 'config:']);
Config::load($opts['config'] ?? null);

$username = Validator::text($opts['username'] ?? null, 50);
$email = Validator::email($opts['email'] ?? null);
$first = Validator::text($opts['first'] ?? null, 50);
$last = Validator::text($opts['last'] ?? null, 50);
if ($username === null || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) || $email === null || $first === null || $last === null) {
    fwrite(STDERR, "Usage: php bin/create-admin.php --username=NAME --email=ADDRESS --first=FIRST --last=LAST\n"
        . "Username: 3-50 letters, digits, dot, dash or underscore.\n");
    exit(2);
}

$pdo = Db::pdo();
$system = (int) $pdo->query("SELECT user_id FROM user_account WHERE username = 'system'")->fetchColumn();
if ($system === 0) {
    fwrite(STDERR, "The system account is missing; run php bin/migrate.php first.\n");
    exit(1);
}
$exists = $pdo->prepare('SELECT COUNT(*) FROM user_account WHERE username = ? OR email = ?');
$exists->execute([$username, $email]);
if ((int) $exists->fetchColumn() > 0) {
    fwrite(STDERR, "An account with that username or email already exists.\n");
    exit(1);
}

$temporary = PasswordPolicy::temporary();
$userId = Db::transaction(function () use ($pdo, $username, $email, $first, $last, $temporary, $system): int {
    $pdo->prepare(
        "INSERT INTO user_account (username, email, first_name, last_name, role, status, password_hash, must_change_password, start_date, created_by, created_at)
         VALUES (?, ?, ?, ?, 'Administrator', 'Pending', ?, 1, ?, ?, ?)"
    )->execute([$username, $email, $first, $last, PasswordPolicy::hash($temporary), Clock::now()->format('Y-m-d'), $system, Clock::db()]);
    $id = (int) $pdo->lastInsertId();
    Audit::setActor($system);
    Audit::record('user_create', 'user_account', $id, 'Success', 'Administrator created from the command line',
        ['role' => 'Administrator', 'username' => $username]);
    return $id;
});

fwrite(STDOUT, "Created Administrator '$username' (user_id $userId).\n"
    . "Temporary password (shown once): $temporary\n"
    . "Sign in with it; you will be asked to choose your own password.\n");
