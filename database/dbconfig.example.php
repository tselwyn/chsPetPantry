<?php
/*
 * Template for database/dbconfig.php (SRS NFR-SEC-009).
 *
 * You only need this on a laptop whose local database is NOT the usual
 * foodpantrydb / foodpantrydb / foodpantrydb. Copy it to database/dbconfig.php
 * and put your values in. dbconfig.php is in .gitignore, so it never goes to
 * GitHub.
 *
 * SiteGround doesn't use this: it keeps its own database/dbinfo.php, which the
 * deploy never overwrites.
 */
return array(
    'host' => 'localhost',
    'name' => 'foodpantrydb',
    'user' => 'foodpantrydb',
    'pass' => 'CHANGE_ME',
);
