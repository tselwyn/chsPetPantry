<?php

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/Distribution.php');

/*
 * add a Distribution to dbdistribution table: return id generated from sql autoincrement
 */

function add_distribution($distributionDays, $personId, $date) {
    $con = connect();
    mysqli_query($con, 'INSERT INTO dbdistribution (distributionDays, personId, date) VALUES("' .
            $distributionDays . '","' .
            $personId . '","' .
            $date . '");');
    $id = mysqli_insert_id($con);
    mysqli_close($con);

    return $id;
}

/*
 * remove a Distribution from dbdistribution table: if it does not exist, return false
 */

function delete_distribution($id) {
    $con = connect();
    $query = 'SELECT * FROM dbdistribution WHERE id = "' . $id . '"';
    $result = mysqli_query($con, $query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'DELETE FROM dbdistribution WHERE id = "' . $id . '"';
    mysqli_query($con, $query);

    mysqli_close($con);
    return true;
}

/*
 * get a Distribution from dbdistribution table by id: return null if not found
 */

function get_distribution_by_id($id) {
    $con = connect();
    $query = 'SELECT * FROM dbdistribution WHERE id = "' . $id . '"';
    $sql_result = mysqli_query($con, $query);
    if ($sql_result == null || mysqli_num_rows($sql_result) == 0) {
        mysqli_close($con);
        return null;
    }
    $row = mysqli_fetch_array($sql_result, MYSQLI_ASSOC);
    $distribution = new Distribution($row['id'], $row['distributionDays'], $row['personId'], $row['date']);
    mysqli_close($con);
    return $distribution;
}

/*
 * get all Distributions from dbdistribution table with a given personId
 */

function get_distributions_by_person($personId) {
    $con = connect();
    $query = 'SELECT * FROM dbdistribution WHERE personId = "' . $personId . '"';
    $sql_result = mysqli_query($con, $query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $distribution_array = array();
    foreach ($array_result as $row) {
        $distribution_array[] = new Distribution($row['id'], $row['distributionDays'], $row['personId'], $row['date']);
    }
    mysqli_close($con);
    return $distribution_array;
}

/*
 * get all Distributions from dbdistribution table with a given date
 */

function get_distributions_by_date($date) {
    $con = connect();
    $query = 'SELECT * FROM dbdistribution WHERE date = "' . $date . '"';
    $sql_result = mysqli_query($con, $query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $distribution_array = array();
    foreach ($array_result as $row) {
        $distribution_array[] = new Distribution($row['id'], $row['distributionDays'], $row['personId'], $row['date']);
    }
    mysqli_close($con);
    return $distribution_array;
}

/*
 * update the distributionDays for a Distribution in dbdistribution table: if it does not exist, return false
 */

function update_distribution_days($id, $distributionDays) {
    $con = connect();
    $query = 'SELECT * FROM dbdistribution WHERE id = "' . $id . '"';
    $result = mysqli_query($con, $query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'UPDATE dbdistribution SET distributionDays = "' . $distributionDays . '" WHERE id = "' . $id . '"';
    mysqli_query($con, $query);

    mysqli_close($con);
    return true;
}
