<?php
session_start();
require_once(__DIR__ . '/include/sessionTimeout.php'); // 30-minute inactivity sign-out (NFR-SEC-005)

// Toggle lock state
if (!isset($_SESSION['locked'])) {
    $_SESSION['locked'] = false;
}

// Flip the lock state
$_SESSION['locked'] = !$_SESSION['locked'];

// Return the new state as JSON
echo json_encode(['locked' => $_SESSION['locked']]);
?>

