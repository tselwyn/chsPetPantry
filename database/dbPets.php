<?php

include_once('dbinfo.php');
include_once(dirname(__FILE__).'/../domain/Pet.php');

/*
 * Build a Pet object from a dbpets row.
 */
function make_a_pet($result_row) {
    return new Pet(
        $result_row['id'],
        $result_row['participant_id'],
        $result_row['name'],
        $result_row['species'],
        $result_row['breed'],
        $result_row['sex'],
        $result_row['date_of_birth'],
        $result_row['dob_is_estimate'],
        $result_row['weight_lbs'],
        $result_row['is_altered'],
        $result_row['food_type'],
        $result_row['food_restriction'],
        $result_row['notes'],
        $result_row['status']
    );
}

/*
 * Add a Pet to dbpets.
 * The database assigns the pet's id.
 * Returns the new pet's id, or false if the insert failed.
 */
function add_pet($pet) {

    $query = 'INSERT INTO dbpets '
        . '(participant_id, name, species, breed, sex, date_of_birth, '
        . 'dob_is_estimate, weight_lbs, is_altered, food_type, food_restriction, notes, status) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

    $args = [
        $pet->get_participant_id(),
        $pet->get_name(),
        $pet->get_species(),
        $pet->get_breed(),
        $pet->get_sex(),
        $pet->get_date_of_birth(),
        $pet->get_dob_is_estimate(),
        $pet->get_weight_lbs(),
        $pet->get_is_altered(),
        $pet->get_food_type(),
        $pet->get_food_restriction(),
        $pet->get_notes(),
        $pet->get_status()
    ];

    $con = connect();
    $id = false;

    try {
        $stmt = mysqli_prepare($con, $query);

        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                'isssssidissss',
                ...$args
            );

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
 * Get all pets belonging to one participant.
 */
function get_pets_by_participant($participant_id, $includeDeleted = false) {


if ($includeDeleted) {
    $query = 'SELECT * FROM dbpets
              WHERE participant_id = ?
              ORDER BY name';

} 
else {

    $query = "SELECT * FROM dbpets
              WHERE participant_id = ?
              AND status != 'Deleted'
              ORDER BY name";
}

    $con = connect();

    $stmt = mysqli_prepare($con, $query);

    if (!$stmt) {
        mysqli_close($con);
        return [];
    }

    mysqli_stmt_bind_param($stmt, 'i', $participant_id);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $pets = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $pets[] = make_a_pet($row);
    }

    mysqli_stmt_close($stmt);
    mysqli_close($con);

    return $pets;
}

/*
 * Set a pet's status to Inactive.
 * This keeps the pet in the database instead of permanently deleting it.
 */
function deactivate_pet($pet_id) {

    $query = "UPDATE dbpets
              SET status = 'Inactive'
              WHERE id = ?";

    $con = connect();

    $stmt = mysqli_prepare($con, $query);

    if (!$stmt) {
        mysqli_close($con);
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'i', $pet_id);

    $success = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);
    mysqli_close($con);

    return $success;
}

/*
 * Set an inactive pet back to Active.
 */
function activate_pet($pet_id) {

    $query = "UPDATE dbpets
              SET status = 'Active'
              WHERE id = ?";

    $con = connect();

    $stmt = mysqli_prepare($con, $query);

    if (!$stmt) {
        mysqli_close($con);
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'i', $pet_id);

    $success = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);
    mysqli_close($con);

    return $success;
}

/*
 * Mark a pet as Deleted.
 * The record stays in the database for historical purposes.
 */
function delete_pet($pet_id) {

    $query = "UPDATE dbpets
              SET status = 'Deleted'
              WHERE id = ?";

    $con = connect();

    $stmt = mysqli_prepare($con, $query);

    if (!$stmt) {
        mysqli_close($con);
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'i', $pet_id);

    $success = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);
    mysqli_close($con);

    return $success;
}

?>