<?php
/*
 * Pet food CHS buys or receives (UC-17), stored in dbfoodreceived, and the
 * cost per pound report built from it (FR-072, FR-073).
 */

include_once('dbinfo.php');

// Food types a food received record can have
define('FOOD_RECEIVED_TYPES', array('Dog', 'Cat'));

/*
 * Add a food received record. $cost is the total paid for the food, 0 for a
 * donation. Returns the new record's id, or false if the insert failed.
 */
function add_food_received($received_date, $food_type, $pounds, $cost, $notes, $recorded_by) {
    $query = 'INSERT INTO dbfoodreceived '
        . '(received_date, food_type, pounds, cost, notes, recorded_by) '
        . 'VALUES (?, ?, ?, ?, ?, ?)';

    $con = connect();
    $id = false;
    try {
        $stmt = mysqli_prepare($con, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ssddss', $received_date, $food_type, $pounds, $cost, $notes, $recorded_by);
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
 * Food received records, newest first. Blank dates are not filtered on.
 * Returns rows of dbfoodreceived columns.
 */
function get_food_received($start_date = '', $end_date = '', $limit = 0) {
    $query = 'SELECT * FROM dbfoodreceived WHERE 1=1';
    $types = '';
    $params = array();

    if ($start_date !== '') {
        $query .= ' AND received_date >= ?';
        $types .= 's';
        $params[] = $start_date;
    }
    if ($end_date !== '') {
        $query .= ' AND received_date <= ?';
        $types .= 's';
        $params[] = $end_date;
    }
    $query .= ' ORDER BY received_date DESC, id DESC';
    if ($limit > 0) {
        $query .= ' LIMIT ' . (int) $limit;
    }

    $con = connect();
    $rows = array();
    try {
        $stmt = mysqli_prepare($con, $query);
        if ($stmt) {
            if ($types !== '') {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $rows[] = $row;
            }
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        $rows = array();
    }
    mysqli_close($con);

    return $rows;
}

/*
 * Remove a food received record that was entered by mistake.
 * Returns true if a record was removed.
 */
function delete_food_received($id) {
    $con = connect();
    $success = false;
    try {
        $stmt = mysqli_prepare($con, 'DELETE FROM dbfoodreceived WHERE id = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            $success = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0;
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        $success = false;
    }
    mysqli_close($con);

    return $success;
}

/*
 * Totals behind the cost per pound report for a date range: pounds and cost
 * of food received, and pounds of food distributed, by food type.
 * Returns array('Dog' => array('received_lbs', 'cost', 'distributed_lbs'),
 * 'Cat' => ...), with every value a float.
 */
function get_food_cost_totals($start_date = '', $end_date = '') {
    $totals = array();
    foreach (FOOD_RECEIVED_TYPES as $type) {
        $totals[$type] = array('received_lbs' => 0.0, 'cost' => 0.0, 'distributed_lbs' => 0.0);
    }

    $receivedQuery = 'SELECT food_type, SUM(pounds) AS lbs, SUM(cost) AS cost FROM dbfoodreceived WHERE 1=1';
    $distributedQuery = 'SELECT SUM(dog_food_lbs) AS dog_lbs, SUM(cat_food_lbs) AS cat_lbs FROM dbparticipantdistributions WHERE 1=1';
    $types = '';
    $params = array();
    if ($start_date !== '') {
        $receivedQuery .= ' AND received_date >= ?';
        $distributedQuery .= ' AND distribution_date >= ?';
        $types .= 's';
        $params[] = $start_date;
    }
    if ($end_date !== '') {
        $receivedQuery .= ' AND received_date <= ?';
        $distributedQuery .= ' AND distribution_date <= ?';
        $types .= 's';
        $params[] = $end_date;
    }
    $receivedQuery .= ' GROUP BY food_type';

    $con = connect();
    try {
        $stmt = mysqli_prepare($con, $receivedQuery);
        if ($stmt) {
            if ($types !== '') {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                if (isset($totals[$row['food_type']])) {
                    $totals[$row['food_type']]['received_lbs'] = (float) $row['lbs'];
                    $totals[$row['food_type']]['cost'] = (float) $row['cost'];
                }
            }
            mysqli_stmt_close($stmt);
        }

        $stmt = mysqli_prepare($con, $distributedQuery);
        if ($stmt) {
            if ($types !== '') {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if ($row) {
                $totals['Dog']['distributed_lbs'] = (float) $row['dog_lbs'];
                $totals['Cat']['distributed_lbs'] = (float) $row['cat_lbs'];
            }
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        // Totals stay at zero; the report then says cost cannot be calculated
    }
    mysqli_close($con);

    return $totals;
}

/*
 * Rows of the cost per pound report (FR-073): one each for dog food, cat
 * food, and all food. Cost per pound is total cost / total pounds received
 * in the range (SRS 7.1). When no food was received in the range, the cost
 * per pound cannot be calculated and the row says so (UC-14 alternate flow).
 * $location is accepted so every report has the same fetch signature; food
 * received has no location.
 */
function get_cost_per_pound_report($location = '', $start_date = '', $end_date = '') {
    $totals = get_food_cost_totals($start_date, $end_date);
    $all = array('received_lbs' => 0.0, 'cost' => 0.0, 'distributed_lbs' => 0.0);
    foreach ($totals as $t) {
        foreach ($all as $key => $value) {
            $all[$key] += $t[$key];
        }
    }

    $rows = array();
    foreach (array('Dog food' => $totals['Dog'], 'Cat food' => $totals['Cat'], 'All food' => $all) as $label => $t) {
        $costPerPound = $t['received_lbs'] > 0 ? $t['cost'] / $t['received_lbs'] : null;
        $rows[] = array(
            'food' => $label,
            'received_lbs' => $t['received_lbs'],
            'cost' => $t['cost'],
            'cost_per_lb' => $costPerPound,
            'distributed_lbs' => $t['distributed_lbs'],
            'distributed_cost' => $costPerPound === null ? null : $costPerPound * $t['distributed_lbs']
        );
    }

    return $rows;
}
?>
