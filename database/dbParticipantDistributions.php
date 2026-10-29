<?php

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/ParticipantDistribution.php');

/*
 * Build a ParticipantDistribution from a dbparticipantdistributions row
 */
function make_a_participant_distribution($result_row) {
    return new ParticipantDistribution(
        $result_row['id'],
        $result_row['participant_id'],
        $result_row['distribution_date'],
        $result_row['location'],
        $result_row['pets_served'],
        $result_row['dog_food_lbs'],
        $result_row['cat_food_lbs'],
        $result_row['processed_by'],
        $result_row['notes'],
        $result_row['created_at']
    );
}

/*
 * Add a ParticipantDistribution to dbparticipantdistributions. Its id and
 * created_at are ignored; the table assigns them.
 * Returns the new distribution's id, or false if the insert failed.
 */
function add_participant_distribution($distribution) {
    $query = 'INSERT INTO dbparticipantdistributions (participant_id, distribution_date, location, '
        . 'pets_served, dog_food_lbs, cat_food_lbs, processed_by, notes) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    $args = [
        $distribution->get_participant_id(),
        $distribution->get_distribution_date(),
        $distribution->get_location(),
        $distribution->get_pets_served(),
        $distribution->get_dog_food_lbs(),
        $distribution->get_cat_food_lbs(),
        $distribution->get_processed_by(),
        $distribution->get_notes()
    ];

    $con = connect();
    $id = false;
    try {
        $stmt = mysqli_prepare($con, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'issiddss', ...$args);
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
 * A participant's distribution history, newest first
 */
function get_participant_distributions($participant_id) {
    $con = connect();
    $distributions = array();
    try {
        $stmt = mysqli_prepare($con, 'SELECT * FROM dbparticipantdistributions WHERE participant_id = ? '
            . 'ORDER BY distribution_date DESC, id DESC');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $participant_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $distributions[] = make_a_participant_distribution($row);
            }
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        $distributions = array();
    }
    mysqli_close($con);
    return $distributions;
}

/*
 * Each participant's most recent distribution date, for the Remove Participant
 * list. Returns an array of participant id => 'Y-m-d'; participants with no
 * distributions are left out.
 */
function get_last_distribution_dates($participant_ids) {
    $ids = array_values(array_unique(array_map('intval', $participant_ids)));
    $dates = array();
    if (count($ids) == 0) {
        return $dates;
    }

    $query = 'SELECT participant_id, MAX(distribution_date) AS last_date FROM dbparticipantdistributions '
        . 'WHERE participant_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') '
        . 'GROUP BY participant_id';

    $con = connect();
    try {
        $stmt = mysqli_prepare($con, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, str_repeat('i', count($ids)), ...$ids);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $dates[(int) $row['participant_id']] = $row['last_date'];
            }
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        $dates = array();
    }
    mysqli_close($con);
    return $dates;
}

/*
 * How many active pets a participant has, used to pre-fill the pets served field
 */
function count_active_pets($participant_id) {
    $con = connect();
    $count = 0;
    try {
        $stmt = mysqli_prepare($con, "SELECT COUNT(*) AS pet_count FROM dbpets WHERE participant_id = ? AND status = 'Active'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $participant_id);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if ($row) {
                $count = (int) $row['pet_count'];
            }
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        $count = 0;
    }
    mysqli_close($con);
    return $count;
}

/*
 * Get distributions for reports.
 * Optional filters: location, start date, and end date.
 * Results are newest first.
 */
function get_distribution_report($location = '', $start_date = '', $end_date = '') {
    $con = connect();
    $distributions = array();

    $query = 'SELECT * FROM dbparticipantdistributions WHERE 1=1';
    $types = '';
    $params = array();

    if ($location !== '') {
        $query .= ' AND location = ?';
        $types .= 's';
        $params[] = $location;
    }

    if ($start_date !== '') {
        $query .= ' AND distribution_date >= ?';
        $types .= 's';
        $params[] = $start_date;
    }

    if ($end_date !== '') {
        $query .= ' AND distribution_date <= ?';
        $types .= 's';
        $params[] = $end_date;
    }

    $query .= ' ORDER BY distribution_date DESC, id DESC';

    try {
        $stmt = mysqli_prepare($con, $query);

        if ($stmt) {
            if (!empty($params)) {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }

            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_assoc($result)) {
                $distributions[] = make_a_participant_distribution($row);
            }

            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        $distributions = array();
    }

    mysqli_close($con);
    return $distributions;
}
