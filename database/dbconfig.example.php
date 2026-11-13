<?php
/*
 * Template for database/dbconfig.php (SRS NFR-SEC-009).
 *
 * Copy this file to database/dbconfig.php on each machine (your laptop, the
 * SiteGround server) and fill in that machine's values. dbconfig.php is in
 * .gitignore and is skipped by the SiteGround deploy, so the real password
 * never goes into GitHub.
 *
 * Local XAMPP: you only need this file if your local database is NOT the
 * usual foodpantrydb / foodpantrydb / foodpantrydb. Without it, localhost
 * uses those defaults.
 * SiteGround: Site Tools > Site > MySQL shows the database name and user.
 * Set the password there too.
 */
return array(
    'host' => 'localhost',
    'name' => 'foodpantrydb',
    'user' => 'foodpantrydb',
    'pass' => 'CHANGE_ME',
);
