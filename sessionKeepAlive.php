<?php
/*
 * "Stay signed in" button on the inactivity warning (NFR-SEC-005).
 * Restarts the 30-minute clock and reports how long is left. Called by
 * header.php with fetch(); returns JSON.
 */
session_cache_expire(30);
session_start();
require_once(__DIR__ . '/include/sessionTimeout.php');

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => 'Use POST'));
    exit;
}
if (!isset($_SESSION['_id'])) {
    http_response_code(401);
    echo json_encode(array('timedOut' => true));
    exit;
}

echo json_encode(array('timedOut' => false, 'secondsLeft' => PFPMS_SESSION_TIMEOUT_SECONDS));
