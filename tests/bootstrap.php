<?php
declare(strict_types=1);

/*
 * Test bootstrap: loads the app's own code (no copies) and, for the
 * Integration suite, rebuilds a throwaway database from sql/foodpantrydb.sql.
 */

date_default_timezone_set('America/New_York');

define('PFPMS_ROOT', dirname(__DIR__));

require_once __DIR__ . '/vendor/autoload.php';
require_once PFPMS_ROOT . '/domain/Participant.php';
require_once PFPMS_ROOT . '/include/sessionTimeout.php';
require_once PFPMS_ROOT . '/database/dbParticipants.php';
require_once __DIR__ . '/Integration/DatabaseTestCase.php';
