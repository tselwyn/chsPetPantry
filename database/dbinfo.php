<?php
date_default_timezone_set('America/New_York');
/*
 * Copyright 2015 by Allen Tucker.
 * This program is part of RMHP-Homebase, which is free software.  It comes with
 * absolutely no warranty. You can redistribute and/or modify it under the terms
 * of the GNU General Public License as published by the Free Software Foundation
 * (see <http://www.gnu.org/licenses/ for more information).
 */

/*
 * Database connection (SRS NFR-SEC-009).
 *
 * No password is kept in this file or anywhere else in the repository. The
 * connection settings come from, in order:
 *   1. Environment variables PFPMS_DB_HOST, PFPMS_DB_NAME, PFPMS_DB_USER and
 *      PFPMS_DB_PASS (used by the automated tests).
 *   2. database/dbconfig.php, a file that exists only on each machine and is
 *      never committed (see .gitignore). Copy database/dbconfig.example.php to
 *      database/dbconfig.php and fill in the values for that machine.
 *   3. On a laptop opened as http://localhost with no dbconfig.php, the team's
 *      local XAMPP defaults (database, user and password all "foodpantrydb").
 *      Never used anywhere else.
 *
 * SiteGround keeps its own copy of this file with the live database login.
 * The deploy (.github/workflows/deploy.yml) never overwrites it.
 */

function pfpms_db_settings() {
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = array('host' => 'localhost', 'name' => '', 'user' => '', 'pass' => '');
    $configFile = __DIR__ . '/dbconfig.php';
    if (is_file($configFile)) {
        $fromFile = require $configFile;
        if (is_array($fromFile)) {
            $settings = array_merge($settings, array_intersect_key($fromFile, $settings));
        }
    } else if (pfpms_is_local_machine()) {
        // A teammate's laptop with no dbconfig.php: use the team's local XAMPP
        // defaults so nobody has to set anything up. These only ever work on
        // a local database; a real server must have its own dbconfig.php.
        $settings = array('host' => 'localhost', 'name' => 'foodpantrydb', 'user' => 'foodpantrydb', 'pass' => 'foodpantrydb');
    }
    foreach (array('host', 'name', 'user', 'pass') as $key) {
        $value = getenv('PFPMS_DB_' . strtoupper($key));
        if ($value !== false) {
            $settings[$key] = $value;
        }
    }
    return $settings;
}

/*
 * True when the app is opened as localhost on the machine it runs on (XAMPP
 * on a laptop), never on SiteGround.
 */
function pfpms_is_local_machine() {
    $host = isset($_SERVER['SERVER_NAME']) ? strtolower((string) $_SERVER['SERVER_NAME']) : '';
    $addr = isset($_SERVER['SERVER_ADDR']) ? (string) $_SERVER['SERVER_ADDR'] : '';
    return in_array($host, array('localhost', '127.0.0.1', '::1', '[::1]'), true)
        && in_array($addr, array('', '127.0.0.1', '::1'), true);
}

function connect() {
    $db = pfpms_db_settings();
    if ($db['name'] === '' || $db['user'] === '') {
        error_log('PFPMS: database settings missing. Create database/dbconfig.php from database/dbconfig.example.php.');
        http_response_code(500);
        die('The database is not configured. Create database/dbconfig.php from database/dbconfig.example.php.');
    }

    try {
        $con = mysqli_connect($db['host'], $db['user'], $db['pass'], $db['name']);
    } catch (mysqli_sql_exception $e) {
        $con = false;
    }
    if (!$con) {
        // Details go to the server log only, never to the page (NFR-SEC-008)
        error_log('PFPMS: database connection failed: ' . mysqli_connect_error());
        http_response_code(500);
        die('Unable to connect to the database. Please try again in a few minutes.');
    }
    return $con;
}

?>
