<?php

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/Participant.php');

/*
 * Longest search text accepted by find_participants
 */
define('PARTICIPANT_SEARCH_MAX_LENGTH', 100);

/*
 * Participant statuses a user can choose, value => label. Deleted is left
 * out: it is only set by delete_participant.
 */
function participant_statuses() {
    return array(
        'Active' => 'Active',
        'Inactive' => 'Inactive',
        'Expired' => 'Expired',
        'DND' => 'DND (Do Not Distribute)'
    );
}

/*
 * Build a Participant from a dbparticipants row
 */
function make_a_participant($result_row) {
    return new Participant(
        $result_row['id'],
        $result_row['first_name'],
        $result_row['last_name'],
        $result_row['street_address'],
        $result_row['city'],
        $result_row['state'],
        $result_row['zip'],
        $result_row['phone'],
        $result_row['email'],
        $result_row['preferred_language'],
        $result_row['location'],
        $result_row['registration_date'],
        $result_row['status'],
        $result_row['alert'],
        $result_row['notes'],
        $result_row['consent'],
        isset($result_row['pet_count']) ? (int) $result_row['pet_count'] : null
    );
}

/*
 * Add a Participant to dbparticipants. Its id is ignored; the table assigns one.
 * The participant agreement is no longer collected, so consent is left at the
 * column's default. Returns the new participant's id, or false if the insert failed.
 */
function add_participant($participant) {
    $query = 'INSERT INTO dbparticipants (first_name, last_name, street_address, city, state, zip, '
        . 'phone, email, preferred_language, location, registration_date, status, alert, notes) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $args = [
        $participant->get_first_name(),
        $participant->get_last_name(),
        $participant->get_street_address(),
        $participant->get_city(),
        $participant->get_state(),
        $participant->get_zip(),
        $participant->get_phone(),
        $participant->get_email(),
        $participant->get_preferred_language(),
        $participant->get_location(),
        $participant->get_registration_date(),
        $participant->get_status(),
        $participant->get_alert(),
        $participant->get_notes()
    ];

    $con = connect();
    $id = false;
    try {
        $stmt = mysqli_prepare($con, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ssssssssssssis', ...$args);
            if (mysqli_stmt_execute($stmt)) {
                $id = mysqli_insert_id($con);
            }
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        $id = false;
    }
    mysqli_close($con);
    return $id;
}


/*
 * Find potential duplicates before creating a participant.
 * Include all statuses, including Deleted, to avoid recreating old records.
 * Returns matching rows; throws on database errors so registration fails safely.
 */
function find_possible_duplicate_participants($firstName, $lastName, $phone, $email, $streetAddress, $limit = 10) {
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    $phoneDigits = preg_replace('/\D/', '', (string) $phone);
    if (strlen($phoneDigits) === 11 && $phoneDigits[0] === '1') {
        $phoneDigits = substr($phoneDigits, 1);
    }
    $email = trim($email);
    $streetAddress = trim($streetAddress);
    $conditions = [];
    $values = [];

    if (strlen($phoneDigits) === 10) {
        $conditions[] = "REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') = ?";
        $values[] = $phoneDigits;
    }
    if ($email !== '') {
        $conditions[] = "LOWER(TRIM(COALESCE(email, ''))) = LOWER(?)";
        $values[] = $email;
    }
    if ($firstName !== '' && $lastName !== '') {
        $conditions[] = '(LOWER(TRIM(first_name)) = LOWER(?) AND LOWER(TRIM(last_name)) = LOWER(?))';
        $values[] = $firstName;
        $values[] = $lastName;
        if ($streetAddress !== '') {
            $conditions[] = '(LOWER(TRIM(last_name)) = LOWER(?) AND LOWER(TRIM(street_address)) = LOWER(?))';
            $values[] = $lastName;
            $values[] = $streetAddress;
        }
    }
    if (!$conditions) return [];

    $limit = max(1, min(25, (int) $limit));
    $query = 'SELECT id, first_name, last_name, street_address, phone, email, status '
        . 'FROM dbparticipants WHERE ' . implode(' OR ', $conditions)
        . ' ORDER BY last_name, first_name, id LIMIT ' . $limit;
    $con = connect();
    try {
        $stmt = mysqli_prepare($con, $query);
        if (!$stmt) throw new RuntimeException('Could not prepare duplicate check');
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($values)), ...$values);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Could not execute duplicate check');
        $result = mysqli_stmt_get_result($stmt);
        if ($result === false) throw new RuntimeException('Could not read duplicate check');
        $matches = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        return $matches;
    } finally {
        mysqli_close($con);
    }
}

/*
 * The Participant with the given id, or null if there is none
 */
function retrieve_participant($id) {
    $con = connect();
    $participant = null;
    $stmt = mysqli_prepare($con, 'SELECT * FROM dbparticipants WHERE id = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($row) {
            $participant = make_a_participant($row);
        }
        mysqli_stmt_close($stmt);
    }
    mysqli_close($con);
    return $participant;
}

/*
 * Save a Participant's editable fields to its dbparticipants row. The id,
 * registration date and consent are never changed. Returns true if the update ran.
 */
function update_participant($participant) {
    $query = 'UPDATE dbparticipants SET first_name = ?, last_name = ?, street_address = ?, city = ?, '
        . 'state = ?, zip = ?, phone = ?, email = ?, preferred_language = ?, location = ?, status = ?, '
        . 'alert = ?, notes = ? WHERE id = ?';
    $args = [
        $participant->get_first_name(),
        $participant->get_last_name(),
        $participant->get_street_address(),
        $participant->get_city(),
        $participant->get_state(),
        $participant->get_zip(),
        $participant->get_phone(),
        $participant->get_email(),
        $participant->get_preferred_language(),
        $participant->get_location(),
        $participant->get_status(),
        $participant->get_alert(),
        $participant->get_notes(),
        $participant->get_id()
    ];

    $con = connect();
    $updated = false;
    try {
        $stmt = mysqli_prepare($con, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sssssssssssisi', ...$args);
            $updated = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        $updated = false;
    }
    mysqli_close($con);
    return $updated;
}

/*
 * Find participants by ID, name, address, or phone number with one search term.
 *   - ID: a term of only digits (or # and digits) matches that participant ID
 *   - name: every word of the term must appear in the first or last name
 *   - address: every word must appear in the street address, city, state, or zip
 *   - phone: a term of only phone characters with 4+ digits matches the phone digits
 * Best matches come first: ID, then exact name, then name starts with the words, then phone,
 * then partial name, then address. Returns up to $limit + 1 Participants so the
 * caller can tell when more than $limit matched.
 */
function find_participants($term, $limit = 100, $includeDeleted = false) {
    $term = trim($term);
    $words = participant_search_words($term);
    $phone = participant_phone_digits($term);
    $id = parse_participant_id($term); // PP00012, 12 or #12 (FR-008, FR-074)
    if (count($words) == 0 && $phone === null && $id === null) {
        return [];
    }

    $name = implode(' ', $words);
    $exactCondition = '(first_name = ? OR last_name = ? OR CONCAT(first_name, \' \', last_name) = ? OR CONCAT(last_name, \' \', first_name) = ?)';
    $exactArgs = [$name, $name, $name, $name];

    $prefixParts = [];
    $prefixArgs = [];
    $partialParts = [];
    $partialArgs = [];
    $addressParts = [];
    $addressArgs = [];
    foreach ($words as $word) {
        $escaped = participant_like_escape($word);
        $prefixParts[] = '(first_name LIKE ? OR last_name LIKE ?)';
        array_push($prefixArgs, $escaped . '%', $escaped . '%');
        $partialParts[] = '(first_name LIKE ? OR last_name LIKE ?)';
        array_push($partialArgs, '%' . $escaped . '%', '%' . $escaped . '%');
        $addressParts[] = 'CONCAT_WS(\' \', street_address, city, state, zip) LIKE ?';
        $addressArgs[] = '%' . $escaped . '%';
    }

    // Each entry: [rank, SQL condition, arguments]
    $tiers = [];
    if ($id !== null) {
        $tiers[] = [0, 'p.id = ?', [$id]];
    }
    if (count($words) > 0) {
        $tiers[] = [1, $exactCondition, $exactArgs];
        $tiers[] = [2, '(' . implode(' AND ', $prefixParts) . ')', $prefixArgs];
    }
    if ($phone !== null) {
        $tiers[] = [3, 'REGEXP_REPLACE(phone, \'[^0-9]\', \'\') LIKE ?', ['%' . $phone . '%']];
    }
    if (count($words) > 0) {
        $tiers[] = [4, '(' . implode(' AND ', $partialParts) . ')', $partialArgs];
        $tiers[] = [5, '(' . implode(' AND ', $addressParts) . ')', $addressArgs];
    }

    $case = [];
    $where = [];
    $args = [];
    foreach ($tiers as $tier) {
        $case[] = 'WHEN ' . $tier[1] . ' THEN ' . $tier[0];
        $args = array_merge($args, $tier[2]);
    }
    foreach ($tiers as $tier) {
        $where[] = $tier[1];
        $args = array_merge($args, $tier[2]);
    }

    $limit = max(1, min(1000, (int) $limit));

// Normal searches should not show Deleted participants.
// The admin removal page can choose to include them.
$statusCondition = $includeDeleted
    ? ''
    : "p.status != 'Deleted' AND ";

$query = 'SELECT p.*, (SELECT COUNT(*) FROM dbpets pt WHERE pt.participant_id = p.id AND pt.status = \'Active\') AS pet_count, '
    . 'CASE ' . implode(' ', $case) . ' END AS match_rank '
    . 'FROM dbparticipants p '
    . 'WHERE ' . $statusCondition . '(' . implode(' OR ', $where) . ') '
    . 'ORDER BY match_rank, last_name, first_name, id '
    . 'LIMIT ' . ($limit + 1);

    $con = connect();
    $stmt = mysqli_prepare($con, $query);
    if (!$stmt) {
        mysqli_close($con);
        return [];
    }
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($args)), ...$args);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $participants = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $participants[] = make_a_participant($row);
    }
    mysqli_stmt_close($stmt);
    mysqli_close($con);
    return $participants;
}

/*
 * The words of a search term: split on spaces and commas ("Smith, Angela"),
 * keeping words that have a letter or digit in them
 */
function participant_search_words($term) {
    $words = preg_split('/[\s,]+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
    $kept = [];
    foreach ($words as $word) {
        if (preg_match('/[\p{L}\p{N}]/u', $word)) {
            $kept[] = $word;
        }
    }
    return $kept;
}

/*
 * The digits of a phone-like term (only digits, spaces, and ( ) . - +) with at
 * least 4 digits, dropping a leading US "1" from 11 digits; null otherwise
 */
function participant_phone_digits($term) {
    if (!preg_match('/^[\d\s().+-]+$/', $term)) {
        return null;
    }
    $digits = preg_replace('/\D/', '', $term);
    if (strlen($digits) == 11 && $digits[0] == '1') {
        $digits = substr($digits, 1);
    }
    return strlen($digits) >= 4 ? $digits : null;
}

/*
 * Escape LIKE wildcards so a typed % or _ matches itself
 */
function participant_like_escape($value) {
    return addcslashes($value, '%_\\');
}

/*
 * Get all participants for the pet registration dropdown.
 */
function get_all_participants() {
    $con = connect();

    $query = 'SELECT * FROM dbparticipants WHERE status = \'Active\' ORDER BY last_name, first_name';

    $result = mysqli_query($con, $query);

    $participants = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $participants[] = make_a_participant($row);
    }

    mysqli_close($con);

    return $participants;
}

/*
 * Mark a participant as Inactive.
 */
function deactivate_participant($participant_id) {

    $query = "UPDATE dbparticipants
              SET status = 'Inactive'
              WHERE id = ?";

    $con = connect();

    $stmt = mysqli_prepare($con, $query);

    if (!$stmt) {
        mysqli_close($con);
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'i', $participant_id);

    $success = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);
    mysqli_close($con);

    return $success;
}


/*
 * Mark an inactive participant as Active again.
 */
function activate_participant($participant_id) {

    $query = "UPDATE dbparticipants
              SET status = 'Active'
              WHERE id = ?";

    $con = connect();

    $stmt = mysqli_prepare($con, $query);

    if (!$stmt) {
        mysqli_close($con);
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'i', $participant_id);

    $success = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);
    mysqli_close($con);

    return $success;
}


/*
 * Create the deletion audit table if this database doesn't have it yet, so
 * nobody has to run sql/migrations/2026-11-13_participant_deletion_audit.sql
 * by hand. Same definition as that migration. Runs outside any transaction
 * because CREATE TABLE ends one.
 */
function ensure_participant_audit_table($con) {
    return mysqli_query($con, "CREATE TABLE IF NOT EXISTS `dbparticipantaudit` (
          `id` int NOT NULL AUTO_INCREMENT,
          `participant_id` int NOT NULL,
          `action` varchar(30) NOT NULL,
          `previous_status` varchar(20) DEFAULT NULL,
          `account_id` varchar(100) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_participant` (`participant_id`),
          KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci") !== false;
}

/*
 * Mark a participant as Deleted and record who did it (UC-09, NFR-SEC-007).
 * The participant row stays in the database for history. The status change
 * and the audit record are saved together or not at all, so a deletion can
 * never happen without its audit record. $account_id is the account signed in
 * ($_SESSION['_id']); with shared logins that is the shared Administrator.
 * Returns false if the participant doesn't exist, is already Deleted, or the
 * save fails.
 */
function delete_participant($participant_id, $account_id) {
    $participant_id = (int) $participant_id;
    $account_id = trim((string) $account_id);
    if ($participant_id < 1 || $account_id === '') {
        return false;
    }

    $con = connect();
    try {
        if (!ensure_participant_audit_table($con)) throw new RuntimeException('Could not create audit table');
        mysqli_begin_transaction($con);

        // Lock the row so two admins deleting at once log it only once
        $stmt = mysqli_prepare($con, 'SELECT status FROM dbparticipants WHERE id = ? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('Could not prepare participant lookup');
        mysqli_stmt_bind_param($stmt, 'i', $participant_id);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Could not look up participant');
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row || $row['status'] === 'Deleted') {
            mysqli_rollback($con);
            return false;
        }

        $stmt = mysqli_prepare($con, "UPDATE dbparticipants SET status = 'Deleted' WHERE id = ?");
        if (!$stmt) throw new RuntimeException('Could not prepare participant delete');
        mysqli_stmt_bind_param($stmt, 'i', $participant_id);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Could not delete participant');
        mysqli_stmt_close($stmt);

        // Time from PHP (America/New_York) so it reads back the same whatever
        // time zone the MySQL server uses
        $now = date('Y-m-d H:i:s');
        $stmt = mysqli_prepare($con, "INSERT INTO dbparticipantaudit (participant_id, action, previous_status, account_id, created_at) VALUES (?, 'Deleted', ?, ?, ?)");
        if (!$stmt) throw new RuntimeException('Could not prepare audit record');
        mysqli_stmt_bind_param($stmt, 'isss', $participant_id, $row['status'], $account_id, $now);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Could not save audit record');
        mysqli_stmt_close($stmt);

        mysqli_commit($con);
        return true;
    } catch (Throwable $e) {
        mysqli_rollback($con);
        error_log('Participant delete failed: ' . $e->getMessage());
        return false;
    } finally {
        mysqli_close($con);
    }
}

/*
 * Deletion audit records for a participant, newest first (NFR-SEC-007).
 */
function get_participant_deletion_audit($participant_id) {
    $con = connect();
    try {
        ensure_participant_audit_table($con);
        $stmt = mysqli_prepare($con, 'SELECT id, participant_id, action, previous_status, account_id, created_at '
            . 'FROM dbparticipantaudit WHERE participant_id = ? ORDER BY created_at DESC, id DESC');
        if (!$stmt) return array();
        $participant_id = (int) $participant_id;
        mysqli_stmt_bind_param($stmt, 'i', $participant_id);
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        return $rows;
    } finally {
        mysqli_close($con);
    }
}

/*
 * Get participants for the Current Participant List report.
 * Can filter by location and registration date.
 */
function get_participant_report($location = '', $startDate = '', $endDate = '') {

    $con = connect();

    $query = "SELECT *
              FROM dbparticipants
              WHERE status != 'Deleted'";

    // Add location filter if one was selected
    if ($location != '') {
        $safeLocation = mysqli_real_escape_string($con, $location);
        $query .= " AND location = '$safeLocation'";
    }

    // Add start date filter if one was selected
    if ($startDate != '') {
        $safeStartDate = mysqli_real_escape_string($con, $startDate);
        $query .= " AND registration_date >= '$safeStartDate'";
    }

    // Add end date filter if one was selected
    if ($endDate != '') {
        $safeEndDate = mysqli_real_escape_string($con, $endDate);
        $query .= " AND registration_date <= '$safeEndDate'";
    }

    $query .= " ORDER BY last_name, first_name";

    $result = mysqli_query($con, $query);

    $participants = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $participants[] = $row;
    }

    mysqli_close($con);

    return $participants;
}

/*
 * Get the participant locations for the report dropdown.
 */
function get_participant_locations() {

    $con = connect();

    $query = "SELECT DISTINCT location
              FROM dbparticipants
              WHERE location IS NOT NULL
              AND location != ''
              ORDER BY location";

    $result = mysqli_query($con, $query);

    $locations = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $locations[] = $row['location'];
    }

    mysqli_close($con);

    return $locations;
}

/*
 * Get participants for the Participant Status report.
 * Shows Active, Inactive, Expired, and DND participants (SRS FR-049).
 */
function get_participant_status_report($location = '', $startDate = '', $endDate = '') {

    $con = connect();

    $query = "SELECT *
              FROM dbparticipants
              WHERE status IN ('Active', 'Inactive', 'Expired', 'DND')";

    // Filter by location
    if ($location != '') {
        $safeLocation = mysqli_real_escape_string($con, $location);
        $query .= " AND location = '$safeLocation'";
    }

    // Filter by registration start date
    if ($startDate != '') {
        $safeStartDate = mysqli_real_escape_string($con, $startDate);
        $query .= " AND registration_date >= '$safeStartDate'";
    }

    // Filter by registration end date
    if ($endDate != '') {
        $safeEndDate = mysqli_real_escape_string($con, $endDate);
        $query .= " AND registration_date <= '$safeEndDate'";
    }

    $query .= " ORDER BY status, last_name, first_name";

    $result = mysqli_query($con, $query);

    $participants = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $participants[] = $row;
    }

    mysqli_close($con);

    return $participants;
}

/*
 * Get participants who have been flagged.
 */
function get_flagged_participants_report($location = '', $startDate = '', $endDate = '') {

    $con = connect();

    $query = "SELECT *
              FROM dbparticipants
              WHERE alert = 1
              AND status != 'Deleted'";

    // Filter by location
    if ($location != '') {
        $safeLocation = mysqli_real_escape_string($con, $location);
        $query .= " AND location = '$safeLocation'";
    }

    // Filter by registration start date
    if ($startDate != '') {
        $safeStartDate = mysqli_real_escape_string($con, $startDate);
        $query .= " AND registration_date >= '$safeStartDate'";
    }

    // Filter by registration end date
    if ($endDate != '') {
        $safeEndDate = mysqli_real_escape_string($con, $endDate);
        $query .= " AND registration_date <= '$safeEndDate'";
    }

    $query .= " ORDER BY last_name, first_name";

    $result = mysqli_query($con, $query);

    $participants = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $participants[] = $row;
    }

    mysqli_close($con);

    return $participants;
}


?>
