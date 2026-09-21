<?php

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/ItemCount.php');

/*
 * add an itemCount to dbitemcounts table: return id generated from sql autoincrement
 */

function add_itemCount($inventoryEventId, $itemCategoryId, $quantity) {
    $con=connect();
    mysqli_query($con,'INSERT INTO dbitemcounts (inventoryEventID, itemCategoryId, quantity) VALUES("' .
            $inventoryEventId . '","' . 
            $itemCategoryId . '","' . 
            $quantity . '");');							
    $id = mysqli_insert_id($con);
    mysqli_close($con);
    
    return $id;
}

/*
 * remove an itemCount from dbitemcounts table: if it does not exist, return false
 */

function delete_itemCount($id){
    $con=connect();
    $query = 'SELECT * FROM dbitemcounts WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'DELETE FROM dbitemcounts WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);

    mysqli_close($con);
    return true;
}

/*
 * get itemCount from dbitemcounts table with given inventoryEventId: return null if not found.
 */

function get_itemCount_by_id($id){
    $con=connect();
    $query = 'SELECT * FROM dbitemcounts WHERE id = "' . $id . '"';
    $sql_result = mysqli_query($con,$query);
    if ($sql_result == null || mysqli_num_rows($sql_result) == 0) {
        mysqli_close($con);
        return null;
    }
    $array_result = mysqli_fetch_array($sql_result, MYSQLI_ASSOC);
    $itemCount = new ItemCount($array_result['id'],$array_result['inventoryEventId'],$array_result['itemCategoryId'],$array_result['quantity']);
    mysqli_close($con);
    return $itemCount;
}

/*
 * get all itemCounts from dbitemcounts table that have a given inventoryEventId
 */

function get_itemCounts_by_inventoryEvent($inventoryEventId){
    $con=connect();
    $query = 'SELECT * FROM dbitemcounts WHERE inventoryEventId = "' . $inventoryEventId . '"';
    $sql_result = mysqli_query($con,$query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $itemCount_array = array();
    foreach($array_result as $result){
        $itemCount_array[] = new ItemCount($result['id'],$result['inventoryEventId'],$result['itemCategoryId'],$result['quantity']);
    }
    mysqli_close($con);
    return $itemCount_array;
}

/*
 * get all itemCounts from dbitemcounts table that have a given itemCategoryId
 */

function get_itemCounts_by_itemCategory($itemCategoryId){
    $con=connect();
    $query = 'SELECT * FROM dbitemcounts WHERE itemCategoryId = "' . $itemCategoryId . '"';
    $sql_result = mysqli_query($con,$query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $itemCount_array = array();
    foreach($array_result as $result){
        $itemCount_array[] = new ItemCount($result['id'],$result['inventoryEventId'],$result['itemCategoryId'],$result['quantity']);
    }
    mysqli_close($con);
    return $itemCount_array;
}

/*
 * change the quantity for an itemCount from dbitemcounts table: if it does not exist, return false
 */

function update_quantity($id, $quantity){
    $con=connect();
    $query = 'SELECT * FROM dbitemcounts WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = 'UPDATE dbitemcounts SET quantity = "' . $quantity . '" WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);

    mysqli_close($con);
    return true;
}

/*
 * get most recent itemCount for each category where inventoryEventId <= given maxEventId
 */

function get_most_recent_counts_up_to_event($maxEventId){
    $con=connect();
    $dateQuery = 'SELECT date FROM dbinventoryevent WHERE id = "' . $maxEventId . '"';
    $dateResult = mysqli_query($con, $dateQuery);
    $dateRow = mysqli_fetch_assoc($dateResult);
    $maxDate = $dateRow['date'];

    $query = 'SELECT dic.* FROM dbitemcounts dic
              INNER JOIN dbinventoryevent die ON dic.inventoryEventId = die.id
              WHERE die.date <= "' . $maxDate . '"
              ORDER BY dic.itemCategoryId, die.date DESC, dic.inventoryEventId DESC';
    $sql_result = mysqli_query($con,$query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);
    $itemCount_array = array();
    $seen_categories = array();

    foreach($array_result as $result){
        $categoryId = $result['itemCategoryId'];
        if(!in_array($categoryId, $seen_categories)){
            $itemCount_array[] = new ItemCount($result['id'],$result['inventoryEventId'],$result['itemCategoryId'],$result['quantity']);
            $seen_categories[] = $categoryId;
        }
    }
    mysqli_close($con);
    return $itemCount_array;
}

/*
 * get most recent itemCount per location for each category up to given event
 */

function get_current_counts_by_event($eventId){
    $con=connect();

    // Get date for the selected event
    $dateQuery = 'SELECT date FROM dbinventoryevent WHERE id = "' . $eventId . '"';
    $dateResult = mysqli_query($con, $dateQuery);
    $dateRow = mysqli_fetch_assoc($dateResult);
    $selectedDate = $dateRow['date'];

    // Get all categories
    $categories = get_all_ItemCategory();
    $itemCount_array = array();

    foreach($categories as $category){
        $categoryId = $category->getId();
        $totalQuantity = 0;

        // Get latest Warehouse count on selected date
        $whQuery = 'SELECT dic.quantity FROM dbitemcounts dic
                    INNER JOIN dbinventoryevent die ON dic.inventoryEventId = die.id
                    WHERE dic.itemCategoryId = "' . $categoryId . '"
                      AND die.location = "Warehouse"
                      AND die.date = "' . $selectedDate . '"
                    ORDER BY die.id DESC
                    LIMIT 1';
        $whResult = mysqli_query($con, $whQuery);
        if($whRow = mysqli_fetch_assoc($whResult)){
            $totalQuantity += $whRow['quantity'];
        }
        // If not found, add 0 (implicit)

        // Get latest Pantry count on selected date
        $pantryQuery = 'SELECT dic.quantity FROM dbitemcounts dic
                        INNER JOIN dbinventoryevent die ON dic.inventoryEventId = die.id
                        WHERE dic.itemCategoryId = "' . $categoryId . '"
                          AND die.location = "Pantry"
                          AND die.date = "' . $selectedDate . '"
                        ORDER BY die.id DESC
                        LIMIT 1';
        $pantryResult = mysqli_query($con, $pantryQuery);
        if($pantryRow = mysqli_fetch_assoc($pantryResult)){
            $totalQuantity += $pantryRow['quantity'];
        }
        // If not found, add 0 (implicit)

        // Create ItemCount with combined quantity
        $itemCount_array[] = new ItemCount(0, $eventId, $categoryId, $totalQuantity);
    }

    mysqli_close($con);
    return $itemCount_array;
}

/*
 * get previous itemCount per location for each category (hybrid: same-day progression or previous event)
 */

function get_previous_counts_by_event($eventId){
    $con=connect();

    // Get date for the selected event
    $dateQuery = 'SELECT date FROM dbinventoryevent WHERE id = "' . $eventId . '"';
    $dateResult = mysqli_query($con, $dateQuery);
    $dateRow = mysqli_fetch_assoc($dateResult);
    $selectedDate = $dateRow['date'];

    // Find the previous date that has inventory events
    $prevDateQuery = 'SELECT DISTINCT date FROM dbinventoryevent
                      WHERE date < "' . $selectedDate . '"
                      ORDER BY date DESC
                      LIMIT 1';
    $prevDateResult = mysqli_query($con, $prevDateQuery);
    $prevDateRow = mysqli_fetch_assoc($prevDateResult);
    $previousDate = $prevDateRow ? $prevDateRow['date'] : null;

    // If no previous date exists, return empty array (show N/A on report)
    if(!$previousDate){
        mysqli_close($con);
        return array();
    }

    // Get all categories
    $categories = get_all_ItemCategory();
    $itemCount_array = array();

    foreach($categories as $category){
        $categoryId = $category->getId();
        $totalQuantity = 0;

        // Get latest Warehouse count on previous date
        $whQuery = 'SELECT dic.quantity FROM dbitemcounts dic
                    INNER JOIN dbinventoryevent die ON dic.inventoryEventId = die.id
                    WHERE dic.itemCategoryId = "' . $categoryId . '"
                      AND die.location = "Warehouse"
                      AND die.date = "' . $previousDate . '"
                    ORDER BY die.id DESC
                    LIMIT 1';
        $whResult = mysqli_query($con, $whQuery);
        if($whRow = mysqli_fetch_assoc($whResult)){
            $totalQuantity += $whRow['quantity'];
        }
        // If not found, add 0 (implicit)

        // Get latest Pantry count on previous date
        $pantryQuery = 'SELECT dic.quantity FROM dbitemcounts dic
                        INNER JOIN dbinventoryevent die ON dic.inventoryEventId = die.id
                        WHERE dic.itemCategoryId = "' . $categoryId . '"
                          AND die.location = "Pantry"
                          AND die.date = "' . $previousDate . '"
                        ORDER BY die.id DESC
                        LIMIT 1';
        $pantryResult = mysqli_query($con, $pantryQuery);
        if($pantryRow = mysqli_fetch_assoc($pantryResult)){
            $totalQuantity += $pantryRow['quantity'];
        }
        // If not found, add 0 (implicit)

        // Create ItemCount with combined quantity
        $itemCount_array[] = new ItemCount(0, $eventId, $categoryId, $totalQuantity);
    }

    mysqli_close($con);
    return $itemCount_array;
}

/*
 * get inventory totals grouped by month and category
 */

function get_monthly_inventory_totals(){
    $con = connect();
    $query = "SELECT
                DATE_FORMAT(ie.date, '%Y-%m') as month,
                dic.id as categoryId,
                dic.name as itemName,
                SUM(dbic.quantity) as total_boxes
              FROM dbinventoryevent ie
              INNER JOIN dbitemcounts dbic ON ie.id = dbic.inventoryEventId
              INNER JOIN dbitemcategory dic ON dbic.itemCategoryId = dic.id
              WHERE dic.shopOnly = 0
              GROUP BY DATE_FORMAT(ie.date, '%Y-%m'), dic.id, dic.name
              ORDER BY month DESC, dic.name ASC";
    $sql_result = mysqli_query($con, $query);
    $array_result = mysqli_fetch_all($sql_result, MYSQLI_ASSOC);

    $monthly_data = array();
    foreach($array_result as $row){
        $month = $row['month'];
        $categoryId = $row['categoryId'];

        if(!isset($monthly_data[$month])){
            $monthly_data[$month] = array();
        }
        $monthly_data[$month][$categoryId] = array(
            'itemName' => $row['itemName'],
            'total_boxes' => $row['total_boxes']
        );
    }

    mysqli_close($con);
    return $monthly_data;
}
