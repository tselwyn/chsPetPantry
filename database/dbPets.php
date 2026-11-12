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
        $result_row['age_years'] ?? null,
        $result_row['weight_lbs'],
        $result_row['spay_neuter_status'],
        $result_row['rabies_status'],
        $result_row['food_type'],
        $result_row['food_restriction'],
        $result_row['notes'],
        $result_row['status'],
        $result_row['counseling_status'],
        $result_row['counseling_date'],
        $result_row['counseled_by'],
        $result_row['special_medical_needs'] ?? null
    );
}

/*
 * Add a Pet to dbpets.
 * The database assigns the pet's id.
 * Returns the new pet's id, or false if the insert failed.
 */
function add_pet($pet) {

    $query = 'INSERT INTO dbpets '
        . '(participant_id, name, species, breed, sex, age_years, '
        . 'weight_lbs, spay_neuter_status, rabies_status, '
        . 'food_type, food_restriction, special_medical_needs, notes, status) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

    $args = [
        $pet->get_participant_id(),
        $pet->get_name(),
        $pet->get_species(),
        $pet->get_breed(),
        $pet->get_sex(),
        $pet->get_age_years(),
        $pet->get_weight_lbs(),
        $pet->get_spay_neuter_status(),
        $pet->get_rabies_status(),
        $pet->get_food_type(),
        $pet->get_food_restriction(),
        $pet->get_special_medical_needs(),
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
                'issssddsssssss',
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
 * Get one pet by its id.
 * Returns a Pet, or null if there is no pet with that id.
 */
function get_pet_by_id($id) {

    $query = 'SELECT * FROM dbpets WHERE id = ?';

    $con = connect();

    $stmt = mysqli_prepare($con, $query);

    if (!$stmt) {
        mysqli_close($con);
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);
    mysqli_close($con);

    return $row ? make_a_pet($row) : null;
}

/*
 * Save the fields of a Pet that petEdit.php changes, including its status
 * (Active, Rehomed or Deceased). The owner and counseling record are left as
 * they were. Returns true if the update ran.
 */
function update_pet($pet) {

    $query = 'UPDATE dbpets SET name = ?, species = ?, breed = ?, sex = ?, '
        . 'age_years = ?, weight_lbs = ?, '
        . 'spay_neuter_status = ?, rabies_status = ?, food_type = ?, '
        . 'food_restriction = ?, special_medical_needs = ?, notes = ?, status = ? '
        . 'WHERE id = ?';

    $args = [
        $pet->get_name(),
        $pet->get_species(),
        $pet->get_breed(),
        $pet->get_sex(),
        $pet->get_age_years(),
        $pet->get_weight_lbs(),
        $pet->get_spay_neuter_status(),
        $pet->get_rabies_status(),
        $pet->get_food_type(),
        $pet->get_food_restriction(),
        $pet->get_special_medical_needs(),
        $pet->get_notes(),
        $pet->get_status(),
        $pet->get_id()
    ];

    $con = connect();
    $success = false;

    try {
        $stmt = mysqli_prepare($con, $query);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ssssddsssssssi', ...$args);
            $success = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

    } catch (mysqli_sql_exception $e) {
        $success = false;
    }

    mysqli_close($con);

    return $success;
}

/*
 * Save a pet's spay/neuter and rabies vaccination status (UC-08).
 * When $counseling_status is 'Yes', counseling is recorded as given today
 * by $counseled_by. Otherwise the counseling fields are left as they were,
 * so a pet that was already counseled stays Yes.
 * Returns true if the update ran.
 */
function update_pet_snv($pet_id, $spay_neuter_status, $rabies_status,
        $counseling_status, $counseled_by) {

    if ($counseling_status === 'Yes') {
        $query = "UPDATE dbpets
                  SET spay_neuter_status = ?, rabies_status = ?,
                      counseling_status = 'Yes', counseling_date = ?,
                      counseled_by = ?
                  WHERE id = ?";
        $types = 'ssssi';
        $args = [$spay_neuter_status, $rabies_status, date('Y-m-d'),
            $counseled_by, $pet_id];
    } else {
        $query = 'UPDATE dbpets
                  SET spay_neuter_status = ?, rabies_status = ?
                  WHERE id = ?';
        $types = 'ssi';
        $args = [$spay_neuter_status, $rabies_status, $pet_id];
    }

    $con = connect();
    $success = false;

    try {
        $stmt = mysqli_prepare($con, $query);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, $types, ...$args);
            $success = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

    } catch (mysqli_sql_exception $e) {
        $success = false;
    }

    mysqli_close($con);

    return $success;
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
 * The pets of several participants at once, for participant search results.
 * Deleted pets are left out. Returns an array of participant id => Pets.
 */
function get_pets_by_participants($participant_ids) {
    $ids = array_values(array_unique(array_map('intval', $participant_ids)));
    $pets = array();
    if (count($ids) == 0) {
        return $pets;
    }

    $query = 'SELECT * FROM dbpets WHERE participant_id IN ('
        . implode(', ', array_fill(0, count($ids), '?')) . ") "
        . "AND status != 'Deleted' ORDER BY participant_id, name";

    $con = connect();
    try {
        $stmt = mysqli_prepare($con, $query);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, str_repeat('i', count($ids)), ...$ids);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $pets[(int) $row['participant_id']][] = make_a_pet($row);
            }
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        $pets = array();
    }
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

/*
 * Pets for the pet reports (UC-16), each with its owner's name, phone and
 * location so CHS can follow up. Returns rows of dbpets columns plus
 * participant_first_name, participant_last_name, participant_phone and
 * participant_location.
 *
 * $location limits the pets to participants at that location (FR-060).
 * $need picks the pets that need a service (FR-056, FR-057):
 *   ''            every pet that is not deleted
 *   'active'      Active pets only (not Rehomed, Deceased or Inactive)
 *   'spay_neuter' Active pets not known to be spayed/neutered (No or Unknown)
 *   'rabies'      Active pets without a known current rabies vaccination
 * Pets of deleted participants are always left out. The date arguments are
 * accepted so every report has the same fetch signature; pet reports have no
 * date filter.
 */
function get_pet_report($location = '', $start_date = '', $end_date = '', $need = '') {

    $query = "SELECT pet.*,
                     p.first_name AS participant_first_name,
                     p.last_name AS participant_last_name,
                     p.phone AS participant_phone,
                     p.location AS participant_location
              FROM dbpets pet
              JOIN dbparticipants p ON p.id = pet.participant_id
              WHERE pet.status != 'Deleted'
              AND p.status != 'Deleted'";
    $types = '';
    $params = array();

    if ($need === 'active') {
        $query .= " AND pet.status = 'Active'";
    } else if ($need === 'spay_neuter') {
        $query .= " AND pet.status = 'Active' AND pet.spay_neuter_status IN ('No', 'Unknown')";
    } else if ($need === 'rabies') {
        $query .= " AND pet.status = 'Active' AND pet.rabies_status IN ('No', 'Unknown')";
    }

    if ($location !== '') {
        $query .= ' AND p.location = ?';
        $types .= 's';
        $params[] = $location;
    }

    $query .= ' ORDER BY p.last_name, p.first_name, p.id, pet.name';

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
 * Every pet that is not deleted, for the Registered Pets report. Reports
 * call their fetch function with a fourth argument (a participant id), so
 * this wrapper keeps it from reaching get_pet_report's $need.
 */
function get_all_pet_report($location = '', $start_date = '', $end_date = '') {
    return get_pet_report($location, $start_date, $end_date, '');
}

/*
 * Current (Active) pets, for the spay/neuter and rabies status report
 */
function get_active_pet_report($location = '', $start_date = '', $end_date = '') {
    return get_pet_report($location, $start_date, $end_date, 'active');
}

/*
 * Pets needing spay/neuter, for the UC-16 report
 */
function get_pets_needing_spay_neuter_report($location = '', $start_date = '', $end_date = '') {
    return get_pet_report($location, $start_date, $end_date, 'spay_neuter');
}

/*
 * Pets needing a rabies vaccination, for the UC-16 report
 */
function get_pets_needing_rabies_report($location = '', $start_date = '', $end_date = '') {
    return get_pet_report($location, $start_date, $end_date, 'rabies');
}


/* Save pet edits and optional counseling together in one transaction.
 * Existing counseling is never cleared by an ordinary edit.
 */
function update_pet_with_counseling($pet, $counseling_provided, $user_id) {
    $con = connect();
    try {
        mysqli_begin_transaction($con);
        $query = 'UPDATE dbpets SET name = ?, species = ?, breed = ?, sex = ?, '
            . 'age_years = ?, weight_lbs = ?, spay_neuter_status = ?, rabies_status = ?, '
            . 'food_type = ?, food_restriction = ?, special_medical_needs = ?, notes = ?, status = ? '
            . 'WHERE id = ?';
        $stmt = mysqli_prepare($con, $query);
        if (!$stmt) throw new RuntimeException('Unable to prepare pet update');
        $args = [
            $pet->get_name(), $pet->get_species(), $pet->get_breed(), $pet->get_sex(),
            $pet->get_age_years(), $pet->get_weight_lbs(), $pet->get_spay_neuter_status(),
            $pet->get_rabies_status(), $pet->get_food_type(), $pet->get_food_restriction(),
            $pet->get_special_medical_needs(), $pet->get_notes(), $pet->get_status(), $pet->get_id()
        ];
        mysqli_stmt_bind_param($stmt, 'ssssddsssssssi', ...$args);
        if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Pet update failed');
        mysqli_stmt_close($stmt);
        if ($counseling_provided) {
            $stmt = mysqli_prepare($con, "UPDATE dbpets SET counseling_status = 'Yes', counseling_date = ?, counseled_by = ? WHERE id = ?");
            if (!$stmt) throw new RuntimeException('Unable to prepare counseling update');
            $date = date('Y-m-d');
            $pet_id = $pet->get_id();
            mysqli_stmt_bind_param($stmt, 'ssi', $date, $user_id, $pet_id);
            if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Counseling update failed');
            mysqli_stmt_close($stmt);
        }
        mysqli_commit($con);
        return true;
    } catch (Throwable $e) {
        mysqli_rollback($con);
        error_log('Pet and counseling update failed: ' . $e->getMessage());
        return false;
    } finally {
        mysqli_close($con);
    }
}

?>