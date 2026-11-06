<?php
/*
 * Helpers for the pet form (petFormFields.php) on registerPet.php and petEdit.php
 */

require_once(dirname(__FILE__) . '/output.php');

// Youngest and oldest age the pet form accepts, in years
define('PET_MIN_AGE_YEARS', 0.1);
define('PET_MAX_AGE_YEARS', 40);

// Longest special medical needs text the form accepts
define('PET_MAX_MEDICAL_NEEDS', 1000);

/*
 * Statuses a user can give a pet on Edit Pet (FR-030). A pet that has left
 * the home is marked Rehomed or Deceased instead of being deleted, so its
 * history is kept. Deleted is set only by deleting the pet.
 */
function pet_status_choices($current = 'Active') {
    $choices = array('Active' => 'Active', 'Rehomed' => 'Rehomed', 'Deceased' => 'Deceased');
    // Pets marked Inactive before Rehomed and Deceased existed keep that choice
    // until someone picks one of the new statuses
    if ($current === 'Inactive') {
        $choices['Inactive'] = 'Inactive';
    }
    return $choices;
}

/*
 * Fields of the pet form and what they show for a new pet
 */
function pet_form_defaults() {
    return array(
        'name' => '',
        'species' => '',
        'breed' => '',
        'sex' => 'Unknown',
        'age_years' => '',
        'weight_lbs' => '',
        'food_type' => 'dry',
        'food_restriction' => '',
        'special_medical_needs' => '',
        'notes' => '',
        'spay_neuter' => 'Unknown',
        'rabies_status' => 'Unknown'
    );
}

/*
 * What is wrong with a submitted pet form, one message per problem
 */
function pet_form_errors($args) {
    $errors = array();

    if ($args['name'] === '') {
        $errors[] = 'Please enter the pet name.';
    }

    if ($args['species'] === '') {
        $errors[] = 'Please select a species.';
    }

    // Years with at most one decimal place, e.g. 0.5 for a six-month-old
    if ($args['age_years'] === '') {
        $errors[] = 'Please enter the pet\'s age in years.';
    } else if (!preg_match('/^(\d{1,2}(\.\d)?|\.\d)$/', $args['age_years'])
            || (float) $args['age_years'] < PET_MIN_AGE_YEARS || (float) $args['age_years'] > PET_MAX_AGE_YEARS) {
        $errors[] = 'Age must be a number of years from ' . PET_MIN_AGE_YEARS . ' to ' . PET_MAX_AGE_YEARS
            . ', with at most one decimal place (for example 0.5 for six months).';
    }

    // Spay/neuter and rabies are Yes, No, or Unknown (FR-029)
    if (!in_array($args['spay_neuter'], array('Yes', 'No', 'Unknown'), true)) {
        $errors[] = 'Please select whether the pet is spayed/neutered.';
    }

    if (!in_array($args['rabies_status'], array('Yes', 'No', 'Unknown'), true)) {
        $errors[] = 'Please select whether the pet has a rabies vaccination.';
    }

    if (mb_strlen($args['special_medical_needs']) > PET_MAX_MEDICAL_NEEDS) {
        $errors[] = 'Special medical needs must be ' . PET_MAX_MEDICAL_NEEDS . ' characters or fewer.';
    }

    // Only Edit Pet has a status field; $args['pet_status_current'] is the
    // status saved before this edit
    if (array_key_exists('pet_status', $args)) {
        $current = isset($args['pet_status_current']) ? $args['pet_status_current'] : 'Active';
        if (!array_key_exists($args['pet_status'], pet_status_choices($current))) {
            $errors[] = 'Please select a pet status: Active, Rehomed, or Deceased.';
        }
    }

    return $errors;
}

/*
 * A Pet built from a checked pet form. Optional fields that were left blank
 * are stored as NULL.
 */
function pet_from_form($id, $participant_id, $args, $status) {
    return new Pet(
        $id,
        $participant_id,
        $args['name'],
        $args['species'],
        $args['breed'] === '' ? null : $args['breed'],
        $args['sex'],
        (float) $args['age_years'],
        $args['weight_lbs'] === '' ? null : $args['weight_lbs'],
        $args['spay_neuter'],
        $args['rabies_status'],
        $args['food_type'] === '' ? null : $args['food_type'],
        $args['food_restriction'] === '' ? null : $args['food_restriction'],
        $args['notes'] === '' ? null : $args['notes'],
        $status,
        'No',
        null,
        null,
        $args['special_medical_needs'] === '' ? null : $args['special_medical_needs']
    );
}

/*
 * Writes the <option>s of a select, marking the current value
 */
function pet_options($choices, $current) {
    foreach ($choices as $value => $label) {
        $selected = ((string) $value === (string) $current) ? ' selected' : '';
        echo '<option value="' . hsc($value) . '"' . $selected . '>' . hsc($label) . '</option>';
    }
}
