<?php

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/PalletCount.php');

/*
 * add an palletCount to dbpalletcounts table: return id generated from sql autoincrement
 */

function add_palletCount($palletEventId, $itemCategoryId, $quantity, $expiration) {
    $con=connect();
    if(!empty($expiration)){
        mysqli_query($con,'INSERT INTO dbpalletcounts (palletEventId, itemCategoryId, quantity, expiration) VALUES("' .
            $palletEventId . '","' . 
            $itemCategoryId . '","' .
            $quantity . '","' . 
            $expiration . '");');
    }
    else{
        mysqli_query($con,'INSERT INTO dbpalletcounts (palletEventId, itemCategoryId, quantity) VALUES("' .
            $palletEventId . '","' . 
            $itemCategoryId . '","' .
            $quantity . '");');	
    }
						
    $id = mysqli_insert_id($con);
    mysqli_close($con);
    
    return $id;
}

/*
 * remove an palletCount from dbpalletcounts table: if it does not exist, return false
 */

function delete_palletCount($id){
    $con=connect();
    $query = 'SELECT * FROM dbpalletcounts WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'DELETE FROM dbpalletcounts WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);

    mysqli_close($con);
    return true;
}

/*
 * remove all palletCounts from dbpalletcounts table with a given palletEventId: 
 * if it does not exist, return false
 */

function delete_palletCount_by_palletEvent($palletEventId){
    $con=connect();
    $query = 'SELECT * FROM dbpalletcounts WHERE palletEventId = "' . $palletEventId . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'DELETE FROM dbpalletcounts WHERE palletEventId = "' . $palletEventId . '"';
    $result = mysqli_query($con,$query);

    mysqli_close($con);
    return true;
}

/*
 * get palletCount from dbpalletcounts table with given palletEventId: return null if not found.
 */

function get_palletCount_by_id($id){
    $con=connect();
    $query = 'SELECT * FROM dbpalletcounts WHERE id = "' . $id . '"';
    $sql_result = mysqli_query($con,$query);
    if ($sql_result == null || mysqli_num_rows($sql_result) == 0) {
        mysqli_close($con);
        return null;
    }
    $array_result = mysqli_fetch_array($sql_result, MYSQLI_ASSOC);
    $itemCount = make_a_palletCount($array_result);
    mysqli_close($con);
    return $itemCount;
}

/*
 * get all palletCounts from dbpalletcounts table that have a given palletEventId
 */

function get_palletCounts_by_palletEvent($palletEventId){
    $con=connect();
    $query = 'SELECT * FROM dbpalletcounts WHERE palletEventId = "' . $palletEventId . '"';
    $sql_result = mysqli_query($con,$query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $itemCount_array = array();
    foreach($array_result as $result){
        $itemCount_array[] = make_a_palletCount($result);
    }
    mysqli_close($con);
    return $itemCount_array;
}

/*
 * get all palletCounts from dbpalletcounts table that have a given itemCategoryId
 */

function get_palletCounts_by_itemCategory($itemCategoryId){
    $con=connect();
    $query = 'SELECT * FROM dbpalletcounts WHERE itemCategoryId = "' . $itemCategoryId . '"';
    $sql_result = mysqli_query($con,$query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $itemCount_array = array();
    foreach($array_result as $result){
        $itemCount_array[] = make_a_palletCount($result);
    }
    mysqli_close($con);
    return $itemCount_array;
}

/*
 * get all palletCounts from dbpalletcounts table that have a given itemCategoryId
 */

function get_palletCount_by_palletEvent_and_itemCategory($palletEventId, $itemCategoryId){
    $con=connect();
    $query = 'SELECT * FROM dbpalletcounts WHERE itemCategoryId = "' . $itemCategoryId . '" AND palletEventId = "' . $palletEventId . '"';
    $sql_result = mysqli_query($con,$query);
    if ($sql_result == null || mysqli_num_rows($sql_result) == 0) {
        mysqli_close($con);
        return null;
    }
    $array_result = mysqli_fetch_array($sql_result, MYSQLI_ASSOC);
    $itemCount = make_a_palletCount($array_result);
    mysqli_close($con);
    return $itemCount;
}

/*
 * change the quantity for an palletCount from dbpalletcounts table: if it does not exist, return false
 */

function update_pallet_quantity($id, $quantity){
    $con=connect();
    $query = 'SELECT * FROM dbpalletcounts WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'UPDATE dbpalletcounts SET quantity = "' . $quantity . '" WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);

    mysqli_close($con);
    return true;
}

/*
 * change the quantity for an palletCount from dbpalletcounts table: if it does not exist, return false
 */

function update_pallet_expiration($id, $expiration){
    $con=connect();
    $query = 'SELECT * FROM dbpalletcounts WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'UPDATE dbpalletcounts SET expiration = "' . $expiration . '" WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);

    mysqli_close($con);
    return true;
}

/*
* builds a palletCount object from a sql query result
*/

function make_a_palletCount($result_row) {
    $itemCount = new PalletCount(
                        $result_row['id'],
                        $result_row['palletEventId'],
                        $result_row['itemCategoryId'],
                        $result_row['quantity'],
                        $result_row['expiration']
                    );
    return $itemCount;
}

/*
 * Get names of pallets that contain a given category (quantity > 0)
 * Returns array of pallet names. Empty array means category is not on any pallet.
 */
function get_pallet_names_with_category($categoryId){
    $con=connect();
    $query = "SELECT DISTINCT pe.name
              FROM dbpalletcounts pc
              INNER JOIN dbpalletevent pe ON pc.palletEventId = pe.id
              WHERE pc.itemCategoryId = ? AND pc.quantity > 0";
    $stmt = $con->prepare($query);
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $result = $stmt->get_result();
    $names = array();
    while ($row = $result->fetch_assoc()) {
        $names[] = $row['name'];
    }
    mysqli_close($con);
    return $names;
}
