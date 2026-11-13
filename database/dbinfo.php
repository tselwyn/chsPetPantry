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
    }
    foreach (array('host', 'name', 'user', 'pass') as $key) {
        $value = getenv('PFPMS_DB_' . strtoupper($key));
        if ($value !== false) {
            $settings[$key] = $value;
        }
    }
    return $settings;
}

function connect() {
    $db = pfpms_db_settings();
    if ($db['name'] === '' || $db['user'] === '') {
        error_log('PFPMS: database settings missing. Create database/dbconfig.php from database/dbconfig.example.php.');
        http_response_code(500);
        die('The database is not configured. An administrator needs to create database/dbconfig.php.');
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
