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

?>