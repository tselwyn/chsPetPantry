<?php /* Implemented by Aidan Meyer */

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/ItemCategory.php');

/*
 * Add a item Category to dbitemcategory table: return id
 */
function add_itemCategory($name, $bananaBox, $itemsPerBox, $status, $shopOnly = 0) {
    $con=connect();
    mysqli_query($con,'INSERT INTO dbitemcategory (name, bananaBox, itemsPerBox, status, shopOnly) VALUES("' .
            $name . '","' . 
            $bananaBox . '","' . 
            $itemsPerBox . '","' . 
            $status . '","' .
            $shopOnly . '");');
    
    $id = mysqli_insert_id($con);
    mysqli_close($con);
    
    return $id;
}

/*
 * set status to Active for given Item Category id
 */

function activate_itemCategory($id) {
    $con=connect();
    $query = 'SELECT * FROM dbitemcategory WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = "UPDATE dbitemcategory SET status = 'Active' WHERE id = '$id'";
    $result = mysqli_query($con,$query);
    mysqli_close($con);
    return true;
}

/*
 * set status to Inactive for given Item Category id
 */

function deactivate_itemCategory($id) {
    $con=connect();
    $query = 'SELECT * FROM dbitemcategory WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = "UPDATE dbitemcategory SET status = 'Inactive' WHERE id = '$id'";
    $result = mysqli_query($con,$query);
    mysqli_close($con);
    return true;
}

/*
 * set status to Deleted for given Item Category id
 */

function delete_itemCategory($id) {
    $con=connect();
    $query = 'SELECT * FROM dbitemcategory WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }
    $query = "UPDATE dbitemcategory SET status = 'Deleted' WHERE id = '$id'";
    $result = mysqli_query($con,$query);
    mysqli_close($con);
    return true;
}

/*
 * Retrieve a group from dbGroups table matching a particular group_name.
 * If not in table, return false
 */
function retrieve_ItemCategory($category) {
    $con = connect();
    $query = "SELECT * FROM dbitemcategory WHERE id = '" . $category . "'";
    $result = mysqli_query($con, $query);
    if (mysqli_num_rows($result) !== 1) {
        mysqli_close($con);
        return false;
    }
    $result_row = mysqli_fetch_assoc($result);
    $theCategory = new ItemCategory($result_row['id'], $result_row['name'], $result_row['bananaBox'], $result_row['itemsPerBox'], $result_row['status'], $result_row['shopOnly'] ?? 0);
    mysqli_close($con);
    return $theCategory;
}

/*
 * Retrieve an item category from dbItemCategories table matching a particular category name.
 * If not in table, return null
 */
function retrieve_ItemCategory_by_name($name) {
    $con = connect();
    $query = "SELECT * FROM dbitemcategory WHERE name = '" . $name . "'";
    $result = mysqli_query($con, $query);
    if (mysqli_num_rows($result) !== 1) {
        mysqli_close($con);
        return false;
    }
    $result_row = mysqli_fetch_assoc($result);
    $theCategory = new ItemCategory($result_row['id'], $result_row['name'], $result_row['bananaBox'], $result_row['itemsPerBox'], $result_row['status'], $result_row['shopOnly'] ?? 0);
    mysqli_close($con);
    return $theCategory;
}

function retrieve_ItemCategoryStatus($name) {
    $con = connect();
    $query = "SELECT * FROM dbitemcategory WHERE name = '" . $name . "'";
    $result = mysqli_query($con, $query);
    if (mysqli_num_rows($result) !== 1) {
        mysqli_close($con);
        return false;
    }
    $result_row = mysqli_fetch_assoc($result);
    if($result_row['status'] == 'Deleted') {
        return 'Deleted';
    } 
    if($result_row['status'] == 'Active') {
        return 'Active';
    }
    if($result_row['status'] == 'Inactive') {
        return 'Inactive';
    }
}


function retrieve_ItemID($name) {
    $con = connect();
    $query = "SELECT * FROM dbitemcategory WHERE name = '" . $name . "'";
    $result = mysqli_query($con, $query);
    if (mysqli_num_rows($result) !== 1) {
        mysqli_close($con);
        return false;
    }
    $result_row = mysqli_fetch_assoc($result);
    return $result_row['id'];
}


function get_all_ItemCategory() {
    $con = connect();
    $query = "SELECT * FROM dbitemcategory ORDER BY name";
    $result = mysqli_query($con, $query);

    // If no groups are found, return an empty array
    /*if (mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return [];
    }*/

    // Create an array of Group objects
    $categories = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $categories[] = new ItemCategory($row['id'], $row['name'], $row['bananaBox'], $row['itemsPerBox'], $row['status'], $row['shopOnly'] ?? 0);
        //$category[] = $category;
        }
    }
    /*while ($row = mysqli_fetch_assoc($result)) {
        $category = new ItemCategory($row['id'], $row['name'], $row['status']);
        //$category[] = $category;
    }*/

    mysqli_close($con);
    return $categories;
}

function get_all_active_ItemCategory() {
    $con = connect();
    $query = "SELECT * FROM dbitemcategory WHERE status = 'Active'  ORDER BY name";
    $result = mysqli_query($con, $query);

    // If no groups are found, return an empty array
    /*if (mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return [];
    }*/

    // Create an array of Group objects
    $categories = [];
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $categories[] = new ItemCategory($row['id'], $row['name'], $row['bananaBox'], $row['itemsPerBox'], $row['status'], $row['shopOnly'] ?? 0);
        //$category[] = $category;
        }
    }
    /*while ($row = mysqli_fetch_assoc($result)) {
        $category = new ItemCategory($row['id'], $row['name'], $row['status']);
        //$category[] = $category;
    }*/

    mysqli_close($con);
    return $categories;
}

/*
 * Update an exisint item Category with a new name, bananaBox, itemsPerBox, and shopOnly
 */
function update_itemCategory($id, $name, $bananaBox, $itemsPerBox, $shopOnly = 0) {
    $con=connect();
    $query = 'SELECT * FROM dbitemcategory WHERE id = "' . $id . '"';
    $result = mysqli_query($con,$query);
    if ($result == null || mysqli_num_rows($result) == 0) {
        mysqli_close($con);
        return false;
    }

    $update_query = "UPDATE `dbitemcategory` SET 
       `name` = '$name', 
       `bananaBox` = '$bananaBox', 
       `itemsPerBox` = '$itemsPerBox',
       `shopOnly` = '$shopOnly' 
    where `id` = '$id' ";

    // Perform the insert
    if (mysqli_query($con, $update_query)) {
        mysqli_close($con);
        return true;
    } else {
        mysqli_close($con);
        return false;
    }   
}


/*
add a user to a volunteer group
*/
/*function add_user_to_group($user_id, $group_name) {
    $con = connect();  

    $query = "INSERT INTO user_groups (user_id, group_name) VALUES (?, ?)";
    $stmt = mysqli_prepare($con, $query);

    if ($stmt) {
        // Use prepared statements to prevent SQL injection
        mysqli_stmt_bind_param($stmt, "ss", $user_id, $group_name);  

        // Execute the prepared statement and check for success
        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $success = false;
    }

    mysqli_close($con);  // Close the connection after query execution
    return $success;
}

/*
Remove a user from a volunteer group
*/
/*function remove_user_from_group($user_id, $group_name) {
    $con = connect();  

    // Prepare the query to check if the user exists in the group
    $query = "SELECT * FROM user_groups WHERE user_id = ? AND group_name = ?";
    $stmt = mysqli_prepare($con, $query);

    if ($stmt) {
        // Bind parameters and execute the query
        mysqli_stmt_bind_param($stmt, "ss", $user_id, $group_name); // Bind both as strings
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        // If the user is not in the group, return false
        if (mysqli_num_rows($result) == 0) {
            mysqli_stmt_close($stmt);
            mysqli_close($con);
            return false;  // User is not in the group
        }

        // Prepare and execute the delete query to remove the user
        $delete_query = "DELETE FROM user_groups WHERE user_id = ? AND group_name = ?";
        $delete_stmt = mysqli_prepare($con, $delete_query);

        if ($delete_stmt) {
            // Bind parameters for the delete query and execute
            mysqli_stmt_bind_param($delete_stmt, "ss", $user_id, $group_name); // Bind both as strings
            $delete_result = mysqli_stmt_execute($delete_stmt);
            mysqli_stmt_close($delete_stmt);
        } else {
            $delete_result = false;
        }

        mysqli_stmt_close($stmt);
    } else {
        $delete_result = false;
    }

    mysqli_close($con);  // Close the connection
    return $delete_result ? true : false;
}
function remove_all_users_in_group($group_name){
    $con = connect();

    $query = "DELETE FROM user_groups WHERE group_name = ?";
    $stmt = mysqli_prepare($con, $query);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $group_name);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        error_log("Statement preparation failed: " . mysqli_error($con));
        return false;
    }

    mysqli_close($con);
    return true;

}
/*
    return group name from database
*/
/*function get_group_name($group_name){
    $con = connect();

    // Prepare the SQL query to prevent SQL injection
    $query = "SELECT * FROM dbgroups WHERE group_name = ?";
    $stmt = mysqli_prepare($con, $query);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $group_name);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) === 1) {
            $row = mysqli_fetch_assoc($result);
            mysqli_close($con);
            return new Group($row['group_name'], $row['color_level']); 
        }
    }

    mysqli_close($con);
    return false; // Group not found
}
/*
 * Get all users in a specific group
 */
/*function get_users_in_group($group_name) {
    $con = connect();

    $query = "SELECT dbpersons.id, dbpersons.first_name, dbpersons.last_name, dbpersons.email
              FROM dbpersons 
              INNER JOIN user_groups ON dbpersons.id = user_groups.user_id 
              WHERE user_groups.group_name = ?";  

    $stmt = mysqli_prepare($con, $query);
    $users = [];

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $group_name);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $users[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    mysqli_close($con);
    return $users;
}

/*
 * Get users NOT in a specific group
 */
/*function get_users_not_in_group($group_name) {
    $con = connect();

    $query = "SELECT id, first_name, last_name FROM dbpersons 
              WHERE id NOT IN (SELECT user_id FROM user_groups WHERE group_name = ?)";

    $stmt = mysqli_prepare($con, $query);
    $users = [];

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $group_name);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $users[] = [
                'id' => $row['id'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'] 
            ];
        }

        mysqli_stmt_close($stmt);
    }

    mysqli_close($con);
    return $users;
}

function get_groups_from_user($user_id) {
    $con = connect();
    $query = "SELECT ug.group_name, dg.color_level 
              FROM user_groups ug
              JOIN dbgroups dg ON ug.group_name = dg.group_name
              WHERE ug.user_id = ?";
    $stmt = mysqli_prepare($con, $query);
    $groups = [];

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $groups[] = [
                'group_name' => $row['group_name'],
                'color_level' => $row['color_level']
            ];
        }

        mysqli_stmt_close($stmt);
    }

    mysqli_close($con);
    return $groups;
}*/

