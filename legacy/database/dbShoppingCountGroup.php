<?php

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/ShoppingCountGroup.php');

/*
 * add an ShoppingCountGroup to dbshoppingcountgroup table: return id generated from sql autoincrement
 */

function add_shoppingCountGroup($shoppingEventId, $groupName) {
    $con=connect();
    mysqli_query($con,'INSERT INTO dbshoppingcountgroup (shoppingEventId, groupName) VALUES("' .
            $shoppingEventId . '","' . 
            $groupName . '");');							
    $id = mysqli_insert_id($con);
    mysqli_close($con);
    
    return $id;
}

/*
 * remove an ShoppingCountGroup from dbshoppingcountgroup table: if it does not exist, return false
 */

function delete_shoppingCountGroup($id){
    $con=connect();
    $query = 'SELECT * FROM dbshoppingcountgroup WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'DELETE FROM dbshoppingcountgroup WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);

    mysqli_close($con);
    return true;
}

/*
 * get ShoppingCount from dbshoppingcounts table with given shoppingEventId: return null if not found.
 */

function get_shoppingCountGroup_by_id($id){
    $con=connect();
    $query = 'SELECT * FROM dbshoppingcountgroup WHERE id = "' . $id . '"';
    $sql_result = mysqli_query($con,$query);
    if ($sql_result == null || mysqli_num_rows($sql_result) == 0) {
        mysqli_close($con);
        return null;
    }
    $array_result = mysqli_fetch_array($sql_result, MYSQLI_ASSOC);
    $shoppingCount = new ShoppingCountGroup($array_result['id'],$array_result['shoppingEventId'],$array_result['groupName']);
    mysqli_close($con);
    return $shoppingCount;
}

/*
 * get all ShoppingCountGroups from dbshoppingcountgroup table that have a given shoppingEventId
 */

function get_shoppingCountGroups_by_shoppingEvent($shoppingEventId){
    $con=connect();
    $query = 'SELECT * FROM dbshoppingcountgroup WHERE shoppingEventId = "' . $shoppingEventId . '"';
    $sql_result = mysqli_query($con,$query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $shoppingCount_array = array();
    foreach($array_result as $result){
        $shoppingCount_array[] = new ShoppingCountGroup($array_result['id'],$array_result['shoppingEventId'],$array_result['groupName']);
    }
    mysqli_close($con);
    return $shoppingCount_array;
}

/**
 * Rename a group; returns true on success.
 */
function rename_shoppingCount_group($groupId, $groupName) {
    $con  = connect();
    $name = mysqli_real_escape_string($con, $groupName);
    $ok   = mysqli_query($con, 'UPDATE dbshoppingcountgroup SET groupName = "' . $name .
        '" WHERE id = ' . (int)$groupId);
    mysqli_close($con);
    return (bool)$ok;
}

/**
 * Delete a group and ungroup all its members.
 */
function delete_shoppingCount_group($groupId) {
    $con = connect();
    mysqli_query($con, 'UPDATE dbshoppingcounts SET groupId = NULL WHERE groupId = ' . (int)$groupId);
    mysqli_query($con, 'DELETE FROM dbshoppingcountgroup WHERE id = ' . (int)$groupId);
    mysqli_close($con);
}