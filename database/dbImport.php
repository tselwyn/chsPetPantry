<?php
/*
 * Saves households checked by include/import.php (importData.php).
 */

include_once('dbinfo.php');
include_once('dbPets.php');

/*
 * Add each household's participant and pets in one transaction: if any
 * insert fails nothing is saved. Ages are dated today, as when a pet is
 * registered by hand (FR-077).
 * Returns array('participants' => count, 'pets' => count); throws on failure.
 */
function import_households($households) {
    $con = connect();
    $started = false;
    try {
        if (!ensure_pet_age_date_column($con)) {
            throw new RuntimeException('Could not add age_recorded_date');
        }
        mysqli_begin_transaction($con);
        $started = true;

        $participantStmt = mysqli_prepare($con, 'INSERT INTO dbparticipants (first_name, last_name, street_address, '
            . 'city, state, zip, phone, email, preferred_language, location, registration_date, status, alert, notes) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 0, ?)");
        $petStmt = mysqli_prepare($con, 'INSERT INTO dbpets (participant_id, name, species, breed, sex, age_years, '
            . 'age_recorded_date, weight_lbs, spay_neuter_status, rabies_status, food_type, special_medical_needs, notes, status) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
        if (!$participantStmt || !$petStmt) {
            // Usually a local database missing a migration in sql/migrations/
            throw new RuntimeException('Could not prepare import statements: ' . mysqli_error($con));
        }

        $today = date('Y-m-d');
        $participants = 0;
        $pets = 0;
        foreach ($households as $household) {
            $p = $household['participant'];
            $nullable = function ($value) { return $value === '' ? null : $value; };
            $city = $nullable($p['city']);
            $state = $nullable($p['state']);
            $zip = $nullable($p['zip']);
            $notes = $nullable($p['notes']);
            mysqli_stmt_bind_param($participantStmt, 'ssssssssssss',
                $p['first_name'], $p['last_name'], $p['street_address'], $city, $state, $zip,
                $p['phone'], $p['email'], $p['preferred_language'], $p['location'], $p['registration_date'], $notes);
            if (!mysqli_stmt_execute($participantStmt)) {
                throw new RuntimeException('Participant insert failed: ' . mysqli_stmt_error($participantStmt));
            }
            $participantId = mysqli_insert_id($con);
            $participants++;

            foreach ($household['pets'] as $pet) {
                // Older records may have no age or weight: stored as NULL, not 0
                $age = $pet['age_years'] === null || $pet['age_years'] === '' ? null : (float) $pet['age_years'];
                $weight = $pet['weight_lbs'] === null || $pet['weight_lbs'] === '' ? null : (float) $pet['weight_lbs'];
                $ageDate = $age === null ? null : $today;
                mysqli_stmt_bind_param($petStmt, 'issssdsdsssss',
                    $participantId, $pet['name'], $pet['species'], $pet['breed'], $pet['sex'], $age, $ageDate,
                    $weight, $pet['spay_neuter_status'], $pet['rabies_status'], $pet['food_type'],
                    $pet['special_medical_needs'], $pet['notes']);
                if (!mysqli_stmt_execute($petStmt)) {
                    throw new RuntimeException('Pet insert failed: ' . mysqli_stmt_error($petStmt));
                }
                $pets++;
            }
        }

        mysqli_commit($con);
        return array('participants' => $participants, 'pets' => $pets);
    } catch (Throwable $e) {
        if ($started) {
            mysqli_rollback($con);
        }
        throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
    } finally {
        mysqli_close($con);
    }
}
