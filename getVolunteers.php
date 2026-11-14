<?php
require_once "database/dbPersons.php";
session_start();
require_once(__DIR__ . '/include/sessionTimeout.php'); // 30-minute inactivity sign-out (NFR-SEC-005)

$query = isset($_GET['query']) ? $_GET['query'] : "";

$users = searchUsers($query);

echo json_encode($users);
?>

