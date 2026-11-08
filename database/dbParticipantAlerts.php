<?php
// Participant alert data access. Requires database/dbinfo.php.
require_once __DIR__ . '/dbinfo.php';

function participant_alert_types() {
    return ['Duplicate Participant', 'Distribution Restriction', 'Staff Review Required'];
}

function get_participant_alerts($participantId, $includeResolved = false) {
    $con = connect();
    $sql = 'SELECT id, participant_id, alert_type, reason, created_by, created_at, resolved_by, resolved_at FROM dbparticipantalerts WHERE participant_id = ?';
    if (!$includeResolved) $sql .= ' AND resolved_at IS NULL';
    $sql .= ' ORDER BY created_at DESC, id DESC';
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) { mysqli_close($con); return []; }
    mysqli_stmt_bind_param($stmt, 'i', $participantId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    mysqli_close($con);
    return $rows;
}

function add_participant_alert($participantId, $alertType, $reason, $userId) {
    $reason = trim($reason);
    //if ($participantId < 1 || $userId < 1 || !in_array($alertType, participant_alert_types(), true) || $reason === '' || mb_strlen($reason) > 2000) return false;
    if ($participantId < 1) {
    error_log("Alert Error: Invalid participant ID");
    return false;
}

if (!is_string($userId) || trim($userId) === '' || strlen($userId) > 100) {
    error_log("Alert Error: Invalid username");
    return false;
}

if (!in_array($alertType, participant_alert_types(), true)) {
    error_log("Alert Error: Invalid alert type");
    return false;
}

if ($reason === '' || mb_strlen($reason) > 2000) {
    error_log("Alert Error: Invalid reason");
    return false;
}
    $con = connect();
    mysqli_begin_transaction($con);
    try {
        $stmt = mysqli_prepare($con, 'INSERT INTO dbparticipantalerts (participant_id, alert_type, reason, created_by) VALUES (?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException('Cannot prepare alert insert');
        mysqli_stmt_bind_param($stmt, 'isss', $participantId, $alertType, $reason, $userId);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Cannot insert alert');
        mysqli_stmt_close($stmt);
        $stmt = mysqli_prepare($con, 'UPDATE dbparticipants SET alert = 1 WHERE id = ?');
        if (!$stmt) throw new RuntimeException('Cannot prepare participant update');
        mysqli_stmt_bind_param($stmt, 'i', $participantId);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Cannot update participant flag');
        mysqli_stmt_close($stmt);
        mysqli_commit($con);
        $success = true;
    } catch (Throwable $e) {
        mysqli_rollback($con);
        error_log('Could not add participant alert: ' . $e->getMessage());
        $success = false;
    }
    mysqli_close($con);
    return $success;
}

// Caller must verify that the logged-in user is an admin.
function resolve_participant_alert($alertId, $participantId, $adminUserId) {
    $success = false;
    if (
        $alertId < 1 ||
        $participantId < 1 ||
        !is_string($adminUserId) ||
        trim($adminUserId) === '' ||
        strlen($adminUserId) > 100
    ) {
        return false;
    }

    $con = connect();
    mysqli_begin_transaction($con);

    try {
        $stmt = mysqli_prepare(
            $con,
            'UPDATE dbparticipantalerts
             SET resolved_by = ?, resolved_at = CURRENT_TIMESTAMP
             WHERE id = ? AND participant_id = ?
             AND resolved_at IS NULL'
        );

        if (!$stmt) {
            throw new RuntimeException('Cannot prepare alert resolution');
        }

        mysqli_stmt_bind_param(
            $stmt,
            'sii',
            $adminUserId,
            $alertId,
            $participantId
        );

        if (
            !mysqli_stmt_execute($stmt) ||
            mysqli_stmt_affected_rows($stmt) !== 1
        ) {
            throw new RuntimeException('Alert not found or already resolved');
        }

        mysqli_stmt_close($stmt);

        // Clear the participant flag if no active alerts remain.
        $stmt = mysqli_prepare(
        $con,
        'UPDATE dbparticipants
        SET alert = 0
        WHERE id = ?
        AND NOT EXISTS (
            SELECT 1
            FROM dbparticipantalerts
            WHERE participant_id = ?
            AND resolved_at IS NULL
        )'
        );

if (!$stmt) {
    throw new RuntimeException('Cannot prepare participant flag update');
}

mysqli_stmt_bind_param($stmt, 'ii', $participantId, $participantId);

if (!mysqli_stmt_execute($stmt)) {
    throw new RuntimeException('Cannot update participant flag');
}

mysqli_stmt_close($stmt);

mysqli_commit($con);
$success = true;


    } catch (Throwable $e) {
        mysqli_rollback($con);
        error_log('Could not resolve participant alert: ' . $e->getMessage());
        $success = false;
    }

    mysqli_close($con);
    return $success;
}
