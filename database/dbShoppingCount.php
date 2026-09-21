<?php

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/ShoppingCount.php');

/*
 * add an ShoppingCount to dbshoppingcounts table: return id generated from sql autoincrement
 */

function add_shoppingCount($shoppingEventId, $itemCategoryId, $quantity) {
    $con=connect();
    mysqli_query($con,'INSERT INTO dbshoppingcounts (shoppingEventId, itemCategoryId, quantity) VALUES("' .
            $shoppingEventId . '","' . 
            $itemCategoryId . '","' . 
            $quantity . '");');							
    $id = mysqli_insert_id($con);
    mysqli_close($con);
    
    return $id;
}

/*
 * remove an ShoppingCount from dbshoppingcounts table: if it does not exist, return false
 */

function delete_shoppingCount($id){
    $con=connect();
    $query = 'SELECT * FROM dbshoppingcounts WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'DELETE FROM dbshoppingcounts WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);

    mysqli_close($con);
    return true;
}

/*
 * get ShoppingCount from dbshoppingcounts table with given shoppingEventId: return null if not found.
 */

function get_shoppingCount_by_id($id){
    $con=connect();
    $query = 'SELECT * FROM dbshoppingcounts WHERE id = "' . $id . '"';
    $sql_result = mysqli_query($con,$query);
    if ($sql_result == null || mysqli_num_rows($sql_result) == 0) {
        mysqli_close($con);
        return null;
    }
    $array_result = mysqli_fetch_array($sql_result, MYSQLI_ASSOC);
    $shoppingCount = new ShoppingCount($array_result['id'],$array_result['shoppingEventId'],$array_result['itemCategoryId'],$array_result['quantity'],$array_result['groupId'],$array_result['excludeFromConsumption']);
    mysqli_close($con);
    return $shoppingCount;
}

/*
 * get all ShoppingCounts from dbshoppingcounts table that have a given shoppingEventId
 */

function get_shoppingCounts_by_shoppingEvent($shoppingEventId){
    $con=connect();
    $query = 'SELECT * FROM dbshoppingcounts WHERE shoppingEventId = "' . $shoppingEventId . '"';
    $sql_result = mysqli_query($con,$query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $shoppingCount_array = array();
    foreach($array_result as $result){
        $shoppingCount_array[] = new ShoppingCount($result['id'],$result['shoppingEventId'],$result['itemCategoryId'],$result['quantity'],$result['groupId'],$result['excludeFromConsumption']);
    }
    mysqli_close($con);
    return $shoppingCount_array;
}

/*
 * get all ShoppingCounts from dbshoppingcounts table that have a given itemCategoryId
 */

function get_shoppingCounts_by_itemCategory($itemCategoryId){
    $con=connect();
    $query = 'SELECT * FROM dbshoppingcounts WHERE itemCategoryId = "' . $itemCategoryId . '"';
    $sql_result = mysqli_query($con,$query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $shoppingCount_array = array();
    foreach($array_result as $result){
        $shoppingCount_array[] = new ShoppingCount($result['id'],$result['shoppingEventId'],$result['itemCategoryId'],$result['quantity'],$result['groupId'],$result['excludeFromConsumption']);
    }
    mysqli_close($con);
    return $shoppingCount_array;
}

/*
 * change the quantity for an ShoppingCount from dbshoppingcounts table: if it does not exist, return false
 */

function update_shoppingCount_quantity($id, $quantity){
    if (!is_int($quantity) || $quantity < 0) {
        return false;
    }
    $con=connect();
    $query = 'SELECT * FROM dbshoppingcounts WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'UPDATE dbshoppingcounts SET quantity = ' . (int)$quantity . ' WHERE id = ' . (int)$id;
    $result = mysqli_query($con,$query);

    mysqli_close($con);
    return true;
}

/*
 * get most recent ShoppingCount for each category where shoppingEventId <= given maxEventId
 */

function get_most_recent_shoppingCounts_up_to_event($maxEventId){
    $con=connect();
    $dateQuery = 'SELECT date FROM dbshoppingevent WHERE id = "' . $maxEventId . '"';
    $dateResult = mysqli_query($con, $dateQuery);
    $dateRow = mysqli_fetch_assoc($dateResult);
    $maxDate = $dateRow['date'];

    $query = 'SELECT dic.* FROM dbshoppingcounts dic
              INNER JOIN dbshoppingevent die ON dic.shoppingEventId = die.id
              WHERE die.date <= "' . $maxDate . '"
              ORDER BY dic.itemCategoryId, die.date DESC, dic.shoppingEventId DESC';
    $sql_result = mysqli_query($con,$query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $shoppingCount_array = array();
    $seen_categories = array();

    foreach($array_result as $result){
        $categoryId = $result['itemCategoryId'];
        if(!in_array($categoryId, $seen_categories)){
            $shoppingCount_array[] = new ShoppingCount($result['id'],$result['shoppingEventId'],$result['itemCategoryId'],$result['quantity'],$result['groupId'],$result['excludeFromConsumption']);
            $seen_categories[] = $categoryId;
        }
    }
    mysqli_close($con);
    return $shoppingCount_array;
}

/*
 * Update the notes for a ShoppingCount row; returns true on success.
 */
function update_shoppingCount_notes($id, $notes) {
    $con    = connect();
    $notes  = mysqli_real_escape_string($con, $notes);
    $result = mysqli_query($con, 'UPDATE dbshoppingcounts SET notes = "' . $notes . '" WHERE id = ' . (int)$id);
    mysqli_close($con);
    return (bool)$result;
}

/**
 * Set excludeFromConsumption flag for a shopping count row.
 */
function update_shoppingCount_exclude($id, $exclude) {
    $con = connect();
    $result = mysqli_query($con, 'UPDATE dbshoppingcounts SET excludeFromConsumption = ' . ($exclude ? 1 : 0) . ' WHERE id = ' . (int)$id);
    mysqli_close($con);
    return (bool)$result;
}

// ---- Item-group helpers -------------------------------------------------------

/**
 * Create a new group for a shopping event; returns the new group id.
 */
function create_shoppingCount_group($shoppingEventId, $groupName) {
    $con  = connect();
    $name = mysqli_real_escape_string($con, $groupName);
    mysqli_query($con, 'INSERT INTO dbshoppingcountgroup (shoppingEventId, groupName) VALUES(' .
        (int)$shoppingEventId . ', "' . $name . '")');
    $id = mysqli_insert_id($con);
    mysqli_close($con);
    return $id;
}

/**
 * Assign a shopping-count row to a group.
 */
function assign_to_group($countId, $groupId) {
    $con = connect();
    mysqli_query($con, 'UPDATE dbshoppingcounts SET groupId = ' . (int)$groupId .
        ' WHERE id = ' . (int)$countId);
    mysqli_close($con);
}

/**
 * Remove a shopping-count row from its group (sets groupId to NULL).
 */
function remove_from_group($countId) {
    $con = connect();
    mysqli_query($con, 'UPDATE dbshoppingcounts SET groupId = NULL WHERE id = ' . (int)$countId);
    mysqli_close($con);
}

/*
 * Get family size names of shopping lists that contain a given category.
 * Returns array of family size names. Empty array means category is not in any shopping list.
 */
function get_shoppingList_families_with_category($categoryId){
    $con=connect();
    $query = "SELECT DISTINCT se.familySize
              FROM dbshoppingcounts sc
              INNER JOIN dbshoppingevent se ON sc.shoppingEventId = se.id
              WHERE sc.itemCategoryId = ?";
    $stmt = $con->prepare($query);
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $result = $stmt->get_result();
    $names = array();
    while ($row = $result->fetch_assoc()) {
        $names[] = $row['familySize'];
    }
    mysqli_close($con);
    return $names;
}


