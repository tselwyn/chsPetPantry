<?php

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/Participant.php');

/*
 * Longest search text accepted by find_participants
 */
define('PARTICIPANT_SEARCH_MAX_LENGTH', 100);

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
 * Find participants by name, address, or phone number with one search term.
 *   - name: every word of the term must appear in the first or last name
 *   - address: every word must appear in the street address, city, state, or zip
 *   - phone: a term of only phone characters with 4+ digits matches the phone digits
 * Best matches come first: exact name, then name starts with the words, then phone,
 * then partial name, then address. Returns up to $limit + 1 Participants so the
 * caller can tell when more than $limit matched.
 */
function find_participants($term, $limit = 100) {
    $term = trim($term);
    $words = participant_search_words($term);
    $phone = participant_phone_digits($term);
    if (count($words) == 0 && $phone === null) {
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
    $query = 'SELECT p.*, (SELECT COUNT(*) FROM dbpets pt WHERE pt.participant_id = p.id AND pt.status = \'Active\') AS pet_count, '
        . 'CASE ' . implode(' ', $case) . ' END AS match_rank '
        . 'FROM dbparticipants p '
        . 'WHERE ' . implode(' OR ', $where) . ' '
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

?>
