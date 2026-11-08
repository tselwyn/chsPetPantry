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
        $result_row['processed_by_name'],
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
        . 'pets_served, dog_food_lbs, cat_food_lbs, processed_by, processed_by_name, notes) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $args = [
        $distribution->get_participant_id(),
        $distribution->get_distribution_date(),
        $distribution->get_location(),
        $distribution->get_pets_served(),
        $distribution->get_dog_food_lbs(),
        $distribution->get_cat_food_lbs(),
        $distribution->get_processed_by(),
        $distribution->get_processed_by_name(),
        $distribution->get_notes()
    ];

    $con = connect();
    $id = false;
    try {
        $stmt = mysqli_prepare($con, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'issiddsss', ...$args);
            if (mysqli_stmt_execute($stmt)) {
                $id = mysqli_insert_id($con);
            } else {
                error_log('add_participant_distribution failed: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log('add_participant_distribution failed: ' . mysqli_error($con));
        }
    } catch (mysqli_sql_exception $e) {
        // Usually a schema that is behind the code: run the files in sql/migrations
        error_log('add_participant_distribution failed: ' . $e->getMessage());
        $id = false;
    }
    mysqli_close($con);
    return $id;
}

/*
 * One distribution by its id, or null if there is no such distribution
 */
function get_participant_distribution($id) {
    $con = connect();
    $distribution = null;
    try {
        $stmt = mysqli_prepare($con, 'SELECT * FROM dbparticipantdistributions WHERE id = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if ($row) {
                $distribution = make_a_participant_distribution($row);
            }
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        $distribution = null;
    }
    mysqli_close($con);
    return $distribution;
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

/*
 * Distributions for the UC-14 reports, oldest first, each with the number of
 * dogs and cats in the household when the food was given out.
 *
 * Distributions record only a total pets_served, so dogs and cats come from
 * the participant's Active pet records that were registered on or before the
 * distribution date.
 *
 * Optional filters: location, start date, end date, and participant id.
 * Returns an array of rows with id, participant_id, first_name, last_name,
 * distribution_date, location, pets_served, dog_food_lbs, cat_food_lbs,
 * processed_by_name, notes, dogs and cats.
 */
function get_distribution_report_rows($location = '', $start_date = '', $end_date = '', $participant_id = 0) {
    $query = "SELECT d.id, d.participant_id, p.first_name, p.last_name, d.distribution_date, d.location, "
        . "d.pets_served, d.dog_food_lbs, d.cat_food_lbs, d.processed_by_name, d.notes, "
        . "(SELECT COUNT(*) FROM dbpets pet WHERE pet.participant_id = d.participant_id "
        . "AND pet.species = 'Dog' AND pet.status = 'Active' AND DATE(pet.created_at) <= d.distribution_date) AS dogs, "
        . "(SELECT COUNT(*) FROM dbpets pet WHERE pet.participant_id = d.participant_id "
        . "AND pet.species = 'Cat' AND pet.status = 'Active' AND DATE(pet.created_at) <= d.distribution_date) AS cats "
        . "FROM dbparticipantdistributions d "
        . "LEFT JOIN dbparticipants p ON p.id = d.participant_id "
        . "WHERE 1=1";
    $types = '';
    $params = array();

    if ($location !== '') {
        $query .= ' AND d.location = ?';
        $types .= 's';
        $params[] = $location;
    }
    if ($start_date !== '') {
        $query .= ' AND d.distribution_date >= ?';
        $types .= 's';
        $params[] = $start_date;
    }
    if ($end_date !== '') {
        $query .= ' AND d.distribution_date <= ?';
        $types .= 's';
        $params[] = $end_date;
    }
    if ((int) $participant_id > 0) {
        $query .= ' AND d.participant_id = ?';
        $types .= 'i';
        $params[] = (int) $participant_id;
    }
    $query .= ' ORDER BY d.distribution_date, d.id';

    $con = connect();
    $rows = array();
    try {
        $stmt = mysqli_prepare($con, $query);
        if ($stmt) {
            if (count($params) > 0) {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }
            mysqli_stmt_execute($stmt);
            $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('get_distribution_report_rows failed: ' . $e->getMessage());
        $rows = array();
    }
    mysqli_close($con);
    return $rows;
}

/*
 * UC-14 totals by month: participants served (each participant counted once
 * per month), distributions, dogs and cats served, and pounds of dog and cat
 * food. Returns ['months' => [...], 'total' => [...]], months oldest first.
 * The total counts each participant once across the whole period, so it can
 * be less than the sum of the months.
 */
function get_distribution_monthly_totals($location = '', $start_date = '', $end_date = '') {
    $blank = function ($label) {
        return array('month' => $label, 'distributions' => 0, 'participants' => array(),
            'dogs' => 0, 'cats' => 0, 'dog_food_lbs' => 0.0, 'cat_food_lbs' => 0.0);
    };

    $add = function (&$bucket, $row) {
        $bucket['distributions']++;
        $bucket['participants'][(int) $row['participant_id']] = true;
        $bucket['dogs'] += (int) $row['dogs'];
        $bucket['cats'] += (int) $row['cats'];
        $bucket['dog_food_lbs'] += (float) $row['dog_food_lbs'];
        $bucket['cat_food_lbs'] += (float) $row['cat_food_lbs'];
    };

    $months = array();
    $total = $blank('Total');
    foreach (get_distribution_report_rows($location, $start_date, $end_date) as $row) {
        $key = substr($row['distribution_date'], 0, 7);
        if (!isset($months[$key])) {
            $months[$key] = $blank($key);
        }
        $add($months[$key], $row);
        $add($total, $row);
    }

    $finish = function ($bucket) {
        $bucket['participants'] = count($bucket['participants']);
        return $bucket;
    };
    return array('months' => array_values(array_map($finish, $months)), 'total' => $finish($total));
}

/*
 * Locations that distributions have been recorded at, for the report filter
 */
function get_distribution_locations() {
    $con = connect();
    $locations = array();
    try {
        $result = mysqli_query($con, "SELECT DISTINCT location FROM dbparticipantdistributions "
            . "WHERE location IS NOT NULL AND location != '' ORDER BY location");
        while ($row = mysqli_fetch_assoc($result)) {
            $locations[] = $row['location'];
        }
    } catch (mysqli_sql_exception $e) {
        $locations = array();
    }
    mysqli_close($con);
    return $locations;
}
