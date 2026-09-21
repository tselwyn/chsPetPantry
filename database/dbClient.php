<?php

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/Client.php');

/*
 * add a Client to dbclient table: return id generated from sql autoincrement
 */

function add_client($shoppingEventId, $personId, $numClients, $date) {
    $con = connect();
    mysqli_query($con, 'INSERT INTO dbclient (shoppingEventId, personId, numClients, date) VALUES("' .
            $shoppingEventId . '","' .
            $personId . '","' .
            $numClients . '","' .
            $date . '");');
    $id = mysqli_insert_id($con);
    mysqli_close($con);

    return $id;
}

/*
 * remove a Client from dbclient table: if it does not exist, return false
 */

function delete_client($id) {
    $con = connect();
    $query = 'SELECT * FROM dbclient WHERE id = "' . $id . '"';
    $result = mysqli_query($con, $query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'DELETE FROM dbclient WHERE id = "' . $id . '"';
    mysqli_query($con, $query);

    mysqli_close($con);
    return true;
}

/*
 * get a Client from dbclient table by id: return null if not found
 */

function get_client_by_id($id) {
    $con = connect();
    $query = 'SELECT * FROM dbclient WHERE id = "' . $id . '"';
    $sql_result = mysqli_query($con, $query);
    if ($sql_result == null || mysqli_num_rows($sql_result) == 0) {
        mysqli_close($con);
        return null;
    }
    $row = mysqli_fetch_array($sql_result, MYSQLI_ASSOC);
    $client = new Client($row['id'], $row['shoppingEventId'], $row['personId'], $row['numClients'], $row['date']);
    mysqli_close($con);
    return $client;
}

/*
 * get all Clients from dbclient table with a given shoppingEventId
 */

function get_clients_by_shoppingEvent($shoppingEventId) {
    $con = connect();
    $query = 'SELECT * FROM dbclient WHERE shoppingEventId = "' . $shoppingEventId . '"';
    $sql_result = mysqli_query($con, $query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $client_array = array();
    foreach ($array_result as $row) {
        $client_array[] = new Client($row['id'], $row['shoppingEventId'], $row['personId'], $row['numClients'], $row['date']);
    }
    mysqli_close($con);
    return $client_array;
}

/*
 * get newest Clients from dbclient table with a given shoppingEventId
 */

function get_newest_client_by_shoppingEvent($shoppingEventId) {
    $con = connect();
    $query = 'SELECT * FROM dbclient WHERE shoppingEventId = '.$shoppingEventId.' ORDER BY date DESC, id DESC LIMIT 1';
    $sql_result = mysqli_query($con, $query);
    if ($sql_result == null || mysqli_num_rows($sql_result) == 0) {
        mysqli_close($con);
        return null;
    }
    $row = mysqli_fetch_array($sql_result, MYSQLI_ASSOC);
    $client = new Client($row['id'], $row['shoppingEventId'], $row['personId'], $row['numClients'], $row['date']);
    mysqli_close($con);
    return $client;
}

/*
 * get all Clients from dbclient table with a given personId
 */

function get_clients_by_person($personId) {
    $con = connect();
    $query = 'SELECT * FROM dbclient WHERE personId = "' . $personId . '"';
    $sql_result = mysqli_query($con, $query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $client_array = array();
    foreach ($array_result as $row) {
        $client_array[] = new Client($row['id'], $row['shoppingEventId'], $row['personId'], $row['numClients'], $row['date']);
    }
    mysqli_close($con);
    return $client_array;
}

/*
 * update the numClients for a Client in dbclient table: if it does not exist, return false
 */

function update_client_numClients($id, $numClients) {
    $con = connect();
    $query = 'SELECT * FROM dbclient WHERE id = "' . $id . '"';
    $result = mysqli_query($con, $query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'UPDATE dbclient SET numClients = "' . $numClients . '" WHERE id = "' . $id . '"';
    mysqli_query($con, $query);

    mysqli_close($con);
    return true;
}
