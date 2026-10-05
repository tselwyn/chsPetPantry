<?php
declare(strict_types=1);

/*
 * One-command local setup for development (XAMPP on Windows, or any PHP + MySQL). Safe to run again: every step
 * checks what is already there and skips what is done.
 *
 *   php bin/setup.php [--base-url=URL] [--admin-username=NAME] [--admin-email=ADDRESS]
 *
 * 1. Checks the PHP extensions, and names the php.ini lines to uncomment when one is missing.
 * 2. Installs the Composer dependencies when vendor/ is missing, downloading composer.phar if needed.
 * 3. Creates config/config.php from config.example.php with XAMPP defaults (root, no password) and a new key.
 * 4. Creates the database, runs the migrations and loads the reference and development seeds.
 * 5. Creates the first Administrator and prints the temporary password.
 *
 * --base-url defaults to http://localhost/<folder>/ when the repo sits in htdocs, else http://localhost:8088 (php -S).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/autoload.php';

use Pfpms\Config;
use Pfpms\Db;

const EXTENSIONS = ['pdo_mysql', 'openssl', 'mbstring', 'json', 'gd', 'zip', 'intl'];
const COMPOSER_URL = 'https://getcomposer.org/download/latest-stable/composer.phar';
const KEY_PLACEHOLDER = 'REPLACE_WITH_OUTPUT_OF_bin/generate-key.php';

$known = ['base-url:', 'admin-username:', 'admin-email:', 'help'];
$opts = getopt('', $known);
$allowed = array_map(fn($o) => rtrim($o, ':'), $known);
foreach (array_slice($argv, 1) as $arg) {
    $name = preg_replace('/^--([^=]+).*$/', '$1', $arg);
    if (!str_starts_with($arg, '--') || !in_array($name, $allowed, true)) {
        fwrite(STDERR, "Unknown argument: $arg (see --help)\n");
        exit(2);
    }
}
if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/setup.php [--base-url=URL] [--admin-username=NAME] [--admin-email=ADDRESS]\n");
    exit(0);
}

$configPath = APP_ROOT . '/config/config.php';

step('PHP and extensions');
checkPhp();

step('Composer dependencies');
installDependencies();

step('Configuration');
$baseUrl = option($opts, 'base-url') ?? detectBaseUrl(APP_ROOT);
writeConfig($configPath, $baseUrl);
Config::load($configPath);
if (Config::env() !== 'dev') {
    fail("config/config.php has env '" . Config::env() . "'. This script sets up a development copy only (it loads development seeds).");
}

step('Database');
createDatabase(Config::require('db'));
run(['bin/migrate.php'], 'Migrations failed (see above).');
run(['bin/seed.php', '--dev'], 'Loading the seeds failed (see above).');

step('Administrator');
createAdmin(option($opts, 'admin-username') ?? 'admin', option($opts, 'admin-email') ?? 'admin@example.org');

$url = rtrim((string) Config::get('app.base_url'), '/') . '/';
step('Done');
if (str_contains($url, ':8088')) {
    out("Start the site with: php -S localhost:8088 -t public");
}
out("Open $url and sign in. The first sign-in asks you to replace the temporary password.");
exit(0);

// -------------------------------------------------------------------------------------------------------------------

function step(string $title): void
{
    fwrite(STDOUT, "\n== $title ==\n");
}

/** A --name=value option given once, else null. */
function option(array|false $opts, string $name): ?string
{
    $value = is_array($opts) ? ($opts[$name] ?? null) : null;
    return is_string($value) && $value !== '' ? $value : null;
}

function out(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "\nSetup stopped: $message\n");
    exit(1);
}

/** Every extension the app and Composer need; a missing one is named with its php.ini line. (composer install checks the PHP version.) */
function checkPhp(): void
{
    $missing = array_values(array_filter(EXTENSIONS, fn(string $ext): bool => !extension_loaded($ext)));
    if ($missing === []) {
        out('PHP ' . PHP_VERSION . ' with ' . implode(', ', EXTENSIONS) . ': OK');
        return;
    }
    $ini = php_ini_loaded_file() ?: (PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY) . '\\php.ini' : 'your php.ini');
    out("Missing PHP extensions: " . implode(', ', $missing));
    out("Open $ini and remove the ; at the start of these lines:");
    foreach ($missing as $ext) {
        out("    ;extension=$ext    ->    extension=$ext");
    }
    out('Save the file, restart Apache in the XAMPP Control Panel, and run this script again.');
    exit(1);
}

/** composer install, when vendor/ is not there yet. Uses bin/composer.phar, downloading and verifying it first. */
function installDependencies(): void
{
    if (is_file(APP_ROOT . '/vendor/autoload.php')) {
        out('vendor/ is already installed.');
        return;
    }
    $phar = APP_ROOT . '/bin/composer.phar';
    if (!is_file($phar)) {
        out('Downloading composer.phar from getcomposer.org ...');
        $body = download(COMPOSER_URL);
        $sum = download(COMPOSER_URL . '.sha256sum');
        if ($body === null || $sum === null) {
            fail("composer.phar could not be downloaded. Install Composer from https://getcomposer.org/download/, run\n"
                . "  composer install\nin " . APP_ROOT . ', then run this script again.');
        }
        if (!preg_match('/^([0-9a-f]{64})\b/i', trim($sum), $m) || !hash_equals(strtolower($m[1]), hash('sha256', $body))) {
            fail('the downloaded composer.phar does not match its published checksum, so it was not used. Try again later.');
        }
        file_put_contents($phar, $body);
        out('composer.phar downloaded and verified.');
    }
    run([$phar, 'install', '--no-interaction'], 'composer install failed (see above).');
    if (!is_file(APP_ROOT . '/vendor/autoload.php')) {
        fail('composer install finished but vendor/autoload.php is missing.');
    }
}

/**
 * The body of an HTTPS URL, or null. XAMPP's PHP often has no CA bundle configured, so on a certificate failure
 * the bundle XAMPP ships with Apache is tried.
 */
function download(string $url): ?string
{
    $xampp = dirname(PHP_BINARY, 2);
    $bundles = [null, $xampp . '/apache/bin/curl-ca-bundle.crt', $xampp . '/php/extras/ssl/cacert.pem'];
    foreach ($bundles as $bundle) {
        if ($bundle !== null && !is_file($bundle)) {
            continue;
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true] + ($bundle !== null ? ['cafile' => $bundle] : []);
        $context = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'user_agent' => 'pfpms-setup'], 'ssl' => $ssl]);
        $body = @file_get_contents($url, false, $context);
        if (is_string($body) && $body !== '') {
            return $body;
        }
    }
    return null;
}

/** http://localhost/<path under htdocs>/ when the repo sits in a web root named htdocs, else the php -S address. */
function detectBaseUrl(string $root): string
{
    $parts = [];
    $dir = str_replace('\\', '/', $root);
    while (($parent = dirname($dir)) !== $dir) {
        array_unshift($parts, basename($dir));
        if (strcasecmp(basename($parent), 'htdocs') === 0) {
            return 'http://localhost/' . implode('/', array_map('rawurlencode', $parts)) . '/';
        }
        $dir = $parent;
    }
    return 'http://localhost:8088';
}

/** config/config.php from the example with XAMPP defaults, or the existing file with its key filled in if it lacks one. */
function writeConfig(string $path, string $baseUrl): void
{
    $key = base64_encode(random_bytes(32)); // as bin/generate-key.php
    if (is_file($path)) {
        $text = (string) file_get_contents($path);
        if (!str_contains($text, KEY_PLACEHOLDER)) {
            out('config/config.php already exists; left as it is.');
            return;
        }
        file_put_contents($path, str_replace(KEY_PLACEHOLDER, $key, $text));
        out('config/config.php already exists; added an encryption key.');
        return;
    }
    $text = (string) file_get_contents(APP_ROOT . '/config/config.example.php');
    $replacements = [
        "/'user' => 'pfpms_app',/" => "'user' => 'root',",
        "/'migrate_user' => 'pfpms_owner',/" => "'migrate_user' => '',",
        "/'base_url' => '[^']*',/" => "'base_url' => " . var_export(rtrim($baseUrl, '/'), true) . ',',
        '/' . preg_quote("'k1' => '" . KEY_PLACEHOLDER . "'", '/') . '/' => "'k1' => " . var_export($key, true),
    ];
    foreach ($replacements as $pattern => $replacement) {
        $text = (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement), $text, -1, $count);
        if ($count !== 1) {
            fail("config/config.example.php has changed ($pattern matched $count times); create config/config.php by hand (README, Local setup).");
        }
    }
    file_put_contents($path, $text);
    out("Created config/config.php (database user root with no password, base_url " . rtrim($baseUrl, '/') . ').');
}

/** The database, if it does not exist yet, using the account the migrations use. */
function createDatabase(array $db): void
{
    $name = (string) $db['name'];
    if (!preg_match('/^\w{1,64}$/', $name)) {
        fail("the database name '$name' in config/config.php must be letters, digits and underscores.");
    }
    $user = ($db['migrate_user'] ?? '') !== '' ? $db['migrate_user'] : $db['user'];
    $pass = ($db['migrate_user'] ?? '') !== '' ? ($db['migrate_pass'] ?? '') : ($db['pass'] ?? '');
    $server = $db['host'] . ':' . ($db['port'] ?? 3306);
    try {
        $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . (int) ($db['port'] ?? 3306) . ';charset=utf8mb4', (string) $user, (string) $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        fail("cannot connect to MySQL at $server as '$user': " . $e->getMessage()
            . "\nStart MySQL in the XAMPP Control Panel, or correct 'db' in config/config.php.");
    }
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$name]);
    if ((int) $exists->fetchColumn() > 0) {
        out("Database $name already exists.");
        return;
    }
    $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci");
    out("Created database $name.");
}

/** The first Administrator, unless one exists already (the built-in system account does not count). */
function createAdmin(string $username, string $email): void
{
    $count = (int) Db::pdo()->query("SELECT COUNT(*) FROM user_account WHERE role = 'Administrator' AND username <> 'system'")->fetchColumn();
    if ($count > 0) {
        out('An Administrator already exists; none created. To add another: php bin/create-admin.php --help');
        return;
    }
    run(['bin/create-admin.php', "--username=$username", "--email=$email", '--first=Dev', '--last=Admin'], 'Creating the Administrator failed (see above).');
    out("Username: $username");
}

/**
 * Run a PHP script (path relative to the repo) with this PHP, in the repo, and stop on failure. Its output is relayed
 * line by line (stderr merged), so it stays in order with this script's own when redirected to a file. Neither
 * passing STDERR through (its own file offset) nor stream_select() (unsupported on proc_open pipes on Windows) does.
 */
function run(array $args, string $error): void
{
    $script = str_starts_with($args[0], '/') || preg_match('/^[A-Za-z]:/', $args[0]) ? $args[0] : APP_ROOT . '/' . $args[0];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    // @phpstan-ignore argument.type (PHP 7.4+ accepts ['redirect', <fd>]; the stub only knows 'pipe' and 'file')
    $process = proc_open(array_merge([PHP_BINARY, $script], array_slice($args, 1)), $descriptors, $pipes, APP_ROOT);
    if (!is_resource($process)) {
        fail($error);
    }
    fclose($pipes[0]);
    while (($line = fgets($pipes[1])) !== false) {
        fwrite(STDOUT, $line);
    }
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        fail($error);
    }
}
