<?php
/*
 * Import Participants & Pets (importData.php): reads a spreadsheet, checks
 * every row with the same rules as participantRegister.php and the pet form,
 * and groups the rows into households ready for database/dbImport.php.
 *
 * Sheet layout: one row per pet. The household columns are repeated on each
 * of that household's pet rows; rows with the same first name, last name and
 * phone number become one participant. A household with no pets is one row
 * with the pet columns left blank.
 *
 * Everything here except import_read_spreadsheet() works on plain arrays, so
 * it can be tested without a file or a database.
 */

require_once(dirname(__FILE__) . '/input-validation.php');
require_once(dirname(__FILE__) . '/pet-form.php');

// Largest file and most data rows one import accepts
define('IMPORT_MAX_FILE_BYTES', 5 * 1024 * 1024);
define('IMPORT_MAX_ROWS', 2000);

/*
 * Columns the import understands, field => header shown in the template.
 * Headers in an uploaded sheet are matched ignoring case, spaces and
 * punctuation, and also by the aliases in import_header_aliases().
 */
function import_columns() {
    return array(
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'street_address' => 'Street Address',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'ZIP',
        'phone' => 'Phone',
        'email' => 'Email',
        'preferred_language' => 'Preferred Language',
        'location' => 'Location',
        'registration_date' => 'Registration Date',
        'notes' => 'Participant Notes',
        'pet_name' => 'Pet Name',
        'species' => 'Species',
        'breed' => 'Breed',
        'sex' => 'Sex',
        'age_years' => 'Age (years)',
        'weight_lbs' => 'Weight (lbs)',
        'spay_neuter' => 'Spayed/Neutered',
        'rabies_status' => 'Rabies Vaccinated',
        'food_type' => 'Food Type',
        'special_medical_needs' => 'Special Medical Needs',
        'pet_notes' => 'Pet Notes'
    );
}

// Columns a sheet must have
function import_required_columns() {
    return array('first_name', 'last_name');
}

// Columns that describe a pet; a row with any of them filled in has a pet
function import_pet_columns() {
    return array('pet_name', 'species', 'breed', 'sex', 'age_years', 'weight_lbs',
        'spay_neuter', 'rabies_status', 'food_type', 'special_medical_needs', 'pet_notes');
}

/*
 * Other headers people use for the same columns, already in the form
 * import_header_key() gives
 */
function import_header_aliases() {
    return array(
        'first' => 'first_name', 'firstname' => 'first_name',
        'last' => 'last_name', 'lastname' => 'last_name', 'surname' => 'last_name',
        'address' => 'street_address', 'street' => 'street_address', 'streetaddress' => 'street_address',
        'city' => 'city', 'state' => 'state',
        'zip' => 'zip', 'zipcode' => 'zip', 'postalcode' => 'zip',
        'phone' => 'phone', 'phonenumber' => 'phone', 'phone1' => 'phone', 'telephone' => 'phone',
        'email' => 'email', 'emailaddress' => 'email',
        'language' => 'preferred_language', 'preferredlanguage' => 'preferred_language',
        'location' => 'location', 'site' => 'location',
        'date' => 'registration_date', 'registrationdate' => 'registration_date', 'dateregistered' => 'registration_date',
        'notes' => 'notes', 'participantnotes' => 'notes', 'householdnotes' => 'notes',
        'pet' => 'pet_name', 'petname' => 'pet_name',
        'species' => 'species', 'petspecies' => 'species', 'animal' => 'species',
        'breed' => 'breed',
        'sex' => 'sex', 'gender' => 'sex',
        'age' => 'age_years', 'ageyears' => 'age_years', 'petage' => 'age_years',
        'weight' => 'weight_lbs', 'weightlbs' => 'weight_lbs', 'petweight' => 'weight_lbs',
        'spayedneutered' => 'spay_neuter', 'spayneuter' => 'spay_neuter', 'spayed' => 'spay_neuter',
        'spayednuetered' => 'spay_neuter', 'spaynueter' => 'spay_neuter', 'nuetered' => 'spay_neuter',
        'neutered' => 'spay_neuter', 'fixed' => 'spay_neuter',
        'rabies' => 'rabies_status', 'rabiesshot' => 'rabies_status', 'rabiesvaccinated' => 'rabies_status',
        'rabiesvaccination' => 'rabies_status',
        'foodtype' => 'food_type', 'food' => 'food_type',
        'specialmedicalneeds' => 'special_medical_needs', 'specialneeds' => 'special_medical_needs',
        'medicalneeds' => 'special_medical_needs',
        'petnotes' => 'pet_notes',
        'other' => 'other_pets', 'otherpets' => 'other_pets', 'otheranimals' => 'other_pets'
    );
}

// "Age (years)" => "ageyears"
function import_header_key($header) {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $header)));
}

/*
 * Which field each column of the header row holds.
 * Returns array(column index => field, missing required fields).
 *
 * Besides the template's one-pet-per-row layout, this reads the CHS sheet
 * layout: a "Dogs" column followed by Name, Breed, Sex... for a dog, then a
 * "Cats" column followed by the same for a cat. Pet columns after such a
 * heading are mapped as "Dog:breed", "Cat:breed" and so on, until an
 * "Other" column ends the pet groups. The Dogs/Cats columns themselves hold
 * counts and are not imported.
 */
function import_map_headers($headerRow) {
    $aliases = import_header_aliases();
    foreach (import_columns() as $field => $label) {
        $aliases[import_header_key($label)] = $field;
    }
    $sections = array('dog' => 'Dog', 'dogs' => 'Dog', 'cat' => 'Cat', 'cats' => 'Cat');
    $map = array();
    $section = '';
    foreach ($headerRow as $index => $header) {
        $key = import_header_key($header);
        if ($key === '') continue;
        if (isset($sections[$key])) {
            $section = $sections[$key];
            continue;
        }
        if ($key === 'name' && $section !== '') {
            $field = 'pet_name';
        } else if (isset($aliases[$key])) {
            $field = $aliases[$key];
        } else {
            continue;
        }
        if ($field === 'other_pets') $section = '';
        if ($section !== '' && in_array($field, import_pet_columns(), true)) {
            $field = $section . ':' . $field;
        }
        // The first column with a header wins if two mean the same thing
        if (!in_array($field, $map, true)) {
            $map[$index] = $field;
        }
    }
    $missing = array_values(array_diff(import_required_columns(), $map));
    return array($map, $missing);
}

// The column header a mapped field came from, for the preview
function import_field_label($field) {
    $columns = import_columns();
    if ($field === 'other_pets') return 'Other pets';
    if (strpos($field, ':') !== false) {
        list($species, $name) = explode(':', $field, 2);
        return $species . ' ' . $columns[$name];
    }
    return $columns[$field];
}

/*
 * A spreadsheet cell as trimmed text. Whole numbers lose the ".0" a sheet
 * stores them with, so a phone typed as a number stays 5405550101.
 */
function import_cell_text($value) {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if (is_float($value) && floor($value) == $value && abs($value) < 1e15) {
        return sprintf('%.0f', $value);
    }
    return trim(preg_replace('/\s+/u', ' ', (string) $value));
}

// Yes / No / Unknown from the many ways people write them, or null if unclear
function import_yes_no($value) {
    $v = strtolower(trim($value));
    if ($v === '' || in_array($v, array('unknown', 'unk', '?', 'not sure', 'n/a', 'na'), true)) return 'Unknown';
    if (in_array($v, array('yes', 'y', 'true', '1', 'x', 'done'), true)) return 'Yes';
    // "Needs" (still needs to be spayed / vaccinated) means not done yet
    if (in_array($v, array('no', 'n', 'false', '0', 'none', 'needs', 'need', 'needed', 'needs it'), true)) return 'No';
    return null;
}

// Male / Female / Unknown, or null if unclear
function import_sex($value) {
    $v = strtolower(trim($value));
    if ($v === '' || $v === 'unknown' || $v === 'u' || $v === '?') return 'Unknown';
    if (in_array($v, array('m', 'male', 'boy'), true)) return 'Male';
    if (in_array($v, array('f', 'female', 'girl'), true)) return 'Female';
    return null;
}

/*
 * array(species, original text when it was turned into Other). Anything
 * that isn't a dog or cat is saved as Other, the pet form's third choice.
 */
function import_species($value) {
    $v = strtolower(trim($value));
    if ($v === '') return array('', null);
    if (in_array($v, array('dog', 'dogs', 'puppy', 'canine', 'd'), true)) return array('Dog', null);
    if (in_array($v, array('cat', 'cats', 'kitten', 'feline', 'c'), true)) return array('Cat', null);
    if ($v === 'other') return array('Other', null);
    return array('Other', trim($value));
}

/*
 * Age in years as the pet form expects it ("0.5", "6"), from "6", "6 yrs",
 * "6 mo", "8 weeks", "1 yr 6 mo", "2-3 yrs" (the middle, 2.5) or "< 1 yr"
 * (half, 0.5). Returns the text unchanged when it can't be read.
 */
function import_age_years($value) {
    $v = strtolower(trim($value));
    if ($v === '') return '';
    $under = false;
    if (preg_match('/^(<|under|less than)\s*/', $v, $m)) {
        $under = true;
        $v = substr($v, strlen($m[0]));
    }
    if (preg_match('/^(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)\s*(.*)$/', $v, $m)) {
        $v = ((float) $m[1] + (float) $m[2]) / 2 . ' ' . $m[3];
    }
    $v = trim($v);
    // One or more "number unit" parts, e.g. "1 yr 6 mo"
    if (!preg_match_all('/(\d+(?:\.\d+)?|\.\d+)\s*([a-z]*)\.?,?\s*/', $v, $parts, PREG_SET_ORDER)
            || implode('', array_column($parts, 0)) !== $v) {
        return trim($value);
    }
    $years = 0;
    foreach ($parts as $part) {
        $number = (float) $part[1];
        $unit = $part[2];
        if ($unit === '' || $unit[0] === 'y' || $unit === 'rs' || $unit === 'r') {
            $years += $number;            // yr, yrs, years, and typos like "yrd", "rs"
        } else if ($unit === 'm' || strncmp($unit, 'mo', 2) === 0) {
            $years += $number / 12;
        } else if ($unit[0] === 'w') {
            $years += $number / 52;
        } else {
            return trim($value);          // e.g. "9 lbs" typed in the age column
        }
    }
    if ($under) $years = $years / 2;
    $rounded = round($years, 1);
    // A puppy or kitten under five weeks still counts as the youngest age
    if ($rounded < PET_MIN_AGE_YEARS && $years > 0) $rounded = PET_MIN_AGE_YEARS;
    return rtrim(rtrim(number_format($rounded, 1, '.', ''), '0'), '.');
}

/*
 * Weight in pounds from "45", "45 lbs", "50 lbs +" (50) or "40-50 lbs"
 * (the middle, 45); the text unchanged if it can't be read
 */
function import_weight($value) {
    $v = strtolower(trim($value));
    if (preg_match('/^(?:~|about|approx\.?)?\s*(\d+(?:\.\d+)?|\.\d+)\s*(?:-\s*(\d+(?:\.\d+)?))?\s*(?:lb|lbs|pound|pounds)?\.?\s*\+?$/', $v, $m)) {
        $weight = isset($m[2]) && $m[2] !== '' ? ((float) $m[1] + (float) $m[2]) / 2 : (float) $m[1];
        return rtrim(rtrim(number_format($weight, 1, '.', ''), '0'), '.');
    }
    return trim($value);
}

/*
 * A date as Y-m-d from 2026-08-04, 8/4/2026, 8/4/26 or an Excel date
 * number, or false if it can't be read
 */
function import_date($value) {
    $v = trim($value);
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {
        // Excel counts days from 1899-12-30
        $date = new DateTime('1899-12-30');
        $date->modify('+' . (int) $v . ' days');
        return $date->format('Y-m-d');
    }
    $fourDigitYear = preg_match('/\d{4}/', $v) === 1;
    foreach (array('Y-m-d', 'n/j/Y', 'n/j/y', 'Y/m/d', 'n-j-Y') as $format) {
        // Otherwise "8/4/26" would be read as the year 26
        if ((strpos($format, 'Y') !== false) !== $fourDigitYear) continue;
        $date = DateTime::createFromFormat('!' . $format, $v);
        $errors = DateTime::getLastErrors();
        if ($date && (!$errors || ($errors['warning_count'] == 0 && $errors['error_count'] == 0))) {
            return $date->format('Y-m-d');
        }
    }
    return false;
}

// English / Spanish / Other; blank means English, the column's default
function import_language($value) {
    $v = strtolower(trim($value));
    if ($v === '' || $v === 'english' || $v === 'en') return 'English';
    if (in_array($v, array('spanish', 'español', 'espanol', 'es'), true)) return 'Spanish';
    return 'Other';
}

// The location name as the app spells it, or null if it isn't one
function import_location($value, $locations) {
    foreach ($locations as $location) {
        if (strcasecmp(trim($value), $location) === 0) return $location;
    }
    return null;
}

/*
 * array(street, city, state, zip) from one address cell such as
 * "412 Princess Anne St, Fredericksburg, VA 22401". Returns null when the
 * cell isn't in that form, so it is kept whole as the street address.
 */
function import_split_address($address) {
    if (preg_match('/^(.+?),\s*([^,]+?),\s*([A-Za-z]{2})\.?\s*(\d{5})?(?:-\d{4})?$/', trim($address), $m)) {
        return array(trim($m[1]), trim($m[2]), strtoupper($m[3]), isset($m[4]) ? $m[4] : '');
    }
    return null;
}

/*
 * Check every data row and group them into households.
 *
 * $rows is the sheet without its header row, each row keyed by column index,
 * in sheet order; $firstRowNumber is the sheet row number of $rows[0].
 * $defaultLocation is used when a row's Location is blank ('' = none).
 *
 * Rows with the same first name, last name and phone are one household. The
 * participant's details come from its first row; later rows add pets (and a
 * registration date, if the first row had none).
 *
 * Returns array(
 *   'households' => list of array(
 *       'rows' => sheet row numbers, 'participant' => dbparticipants values,
 *       'pets' => list of dbpets values,
 *       'errors' => list of array(row, message): the household is not imported,
 *       'warnings' => list of array(row, message): imported, but staff should
 *           fill something in later),
 *   'skipped_blank' => number of empty rows)
 */
function import_build_households($map, $rows, $firstRowNumber, $defaultLocation, $locations, $today = null) {
    $today = $today ?: date('Y-m-d');
    $petColumns = import_pet_columns();
    $households = array();
    $byKey = array();
    $blank = 0;

    foreach (array_values($rows) as $i => $cells) {
        $rowNumber = $firstRowNumber + $i;
        $values = array_fill_keys(array_diff(array_keys(import_columns()), $petColumns), '');
        $values['other_pets'] = '';
        $groups = array();
        $anything = false;
        foreach ($map as $index => $field) {
            $text = import_cell_text(isset($cells[$index]) ? $cells[$index] : null);
            if ($text !== '') $anything = true;
            if (strpos($field, ':') !== false) {
                list($species, $name) = explode(':', $field, 2);
                $groups[$species][$name] = $text;
            } else if (in_array($field, $petColumns, true)) {
                $groups[''][$field] = $text;
            } else {
                $values[$field] = $text;
            }
        }
        if (!$anything) {
            $blank++;
            continue;
        }

        $key = strtolower($values['first_name']) . '|' . strtolower($values['last_name']) . '|' . preg_replace('/\D/', '', $values['phone']);
        if (!isset($byKey[$key])) {
            $errors = array();
            $warnings = array();
            $participant = import_check_participant($values, $defaultLocation, $locations, $today, $errors, $warnings);
            $byKey[$key] = count($households);
            $households[] = array(
                'rows' => array(), 'participant' => $participant, 'pets' => array(),
                'errors' => import_with_row($rowNumber, $errors), 'warnings' => import_with_row($rowNumber, $warnings),
                'dated' => $values['registration_date'] !== ''
            );
        }
        $h = &$households[$byKey[$key]];
        $h['rows'][] = $rowNumber;

        // A later row of the household may carry the date the first lacked
        if (!$h['dated'] && $values['registration_date'] !== '') {
            $date = import_date($values['registration_date']);
            if ($date !== false && $date <= $today) {
                $h['participant']['registration_date'] = $date;
                $h['dated'] = true;
            }
        }
        if ($values['other_pets'] !== '') {
            $h['participant']['notes'] = import_add_note($h['participant']['notes'], 'Other pets: ' . $values['other_pets']);
        }

        foreach ($groups as $species => $fields) {
            $fields += array_fill_keys($petColumns, '');
            if (import_is_none($fields['special_medical_needs'])) $fields['special_medical_needs'] = '';
            if (implode('', $fields) === '') continue;
            $errors = array();
            $warnings = array();
            $pet = import_check_pet($fields, $species, $errors, $warnings);
            $pet['row'] = $rowNumber;
            $h['pets'][] = $pet;
            $h['errors'] = array_merge($h['errors'], import_with_row($rowNumber, $errors));
            $h['warnings'] = array_merge($h['warnings'], import_with_row($rowNumber, $warnings));
        }
        unset($h);
    }

    foreach ($households as &$h) {
        unset($h['dated']);
    }
    unset($h);
    return array('households' => $households, 'skipped_blank' => $blank);
}

// Messages paired with the sheet row they came from
function import_with_row($rowNumber, $messages) {
    return array_map(function ($message) use ($rowNumber) { return array($rowNumber, $message); }, $messages);
}

// "No", "none", "n/a": nothing to record
function import_is_none($value) {
    return in_array(strtolower(trim($value)), array('no', 'none', 'n/a', 'na', '-', 'nothing'), true);
}

function import_add_note($notes, $line) {
    return $notes === '' || $notes === null ? $line : $notes . "\n" . $line;
}

/*
 * The dbparticipants values for a household's first row.
 * $errors: problems that stop the household being imported (no name, an
 * unknown location, text too long for the database).
 * $warnings: things saved blank or adjusted, for staff to fill in later.
 * Anything from the sheet that couldn't be saved in its column is kept in
 * the participant's notes.
 */
function import_check_participant($values, $defaultLocation, $locations, $today, &$errors, &$warnings) {
    $p = array(
        'first_name' => $values['first_name'],
        'last_name' => $values['last_name'],
        'street_address' => $values['street_address'],
        'city' => $values['city'],
        'state' => strtoupper(rtrim($values['state'], '.')),
        'zip' => $values['zip'],
        'phone' => '',
        'email' => import_is_none($values['email']) ? '' : $values['email'],
        'preferred_language' => import_language($values['preferred_language']),
        'location' => '',
        'registration_date' => $today,
        'notes' => $values['notes']
    );

    foreach (array('first_name' => 'First name', 'last_name' => 'Last name') as $field => $label) {
        if ($p[$field] === '') $errors[] = $label . ' is missing.';
    }
    if ($p['street_address'] === '') $warnings[] = 'No address on the sheet.';

    // One address cell with no city, state or ZIP: split it if it looks like
    // "street, city, ST 12345"; otherwise it is kept whole
    if ($p['city'] === '' && $p['state'] === '' && $p['zip'] === '' && strpos($p['street_address'], ',') !== false) {
        $parts = import_split_address($p['street_address']);
        if ($parts) {
            list($p['street_address'], $p['city'], $p['state'], $p['zip']) = $parts;
        }
    }

    $limits = array('first_name' => array('First name', 50), 'last_name' => array('Last name', 50),
        'street_address' => array('Street address', 100), 'city' => array('City', 50));
    foreach ($limits as $field => $limit) {
        if (mb_strlen($p[$field]) > $limit[1]) {
            $errors[] = $limit[0] . ' must be ' . $limit[1] . ' characters or fewer.';
        }
    }

    // The first 10-digit number in the cell ("540-555-0101 or 540-555-0102")
    $digits = preg_replace('/\D/', '', $values['phone']);
    if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
    if ($values['phone'] === '') {
        $warnings[] = 'No phone number on the sheet.';
    } else if (strlen($digits) === 10) {
        $p['phone'] = $digits;
    } else if (preg_match('/(?<!\d)(\d{3})\D{0,2}(\d{3})\D{0,2}(\d{4})(?!\d)/', $values['phone'], $m)) {
        $p['phone'] = $m[1] . $m[2] . $m[3];
        $p['notes'] = import_add_note($p['notes'], 'Phone on sheet: ' . $values['phone']);
    } else {
        $warnings[] = 'Phone "' . $values['phone'] . '" is not a 10-digit number, so it was left blank (kept in the notes).';
        $p['notes'] = import_add_note($p['notes'], 'Phone on sheet: ' . $values['phone']);
    }

    if ($p['email'] !== '' && (!validateEmail($p['email']) || mb_strlen($p['email']) > 100)) {
        $warnings[] = 'Email "' . $p['email'] . '" is not a valid e-mail address, so it was left blank (kept in the notes).';
        $p['notes'] = import_add_note($p['notes'], 'Email on sheet: ' . $p['email']);
        $p['email'] = '';
    }

    if ($p['state'] !== '' && !preg_match('/^[A-Z]{2}$/', $p['state'])) {
        $warnings[] = 'State "' . $values['state'] . '" is not a 2-letter code, so it was left blank.';
        $p['state'] = '';
    }

    // A ZIP saved as a number loses its leading zero
    if (preg_match('/^\d{4}$/', $p['zip'])) $p['zip'] = '0' . $p['zip'];
    if (preg_match('/^(\d{5})-?\d{4}$/', $p['zip'], $m)) $p['zip'] = $m[1];
    if ($p['zip'] !== '' && !preg_match('/^\d{5}$/', $p['zip'])) {
        $warnings[] = 'ZIP "' . $values['zip'] . '" is not a 5-digit ZIP code, so it was left blank.';
        $p['zip'] = '';
    }

    if ($values['location'] === '') {
        if ($defaultLocation === '') {
            $errors[] = 'Location is missing. Fill it in or choose a location for blank rows.';
        } else {
            $p['location'] = $defaultLocation;
        }
    } else {
        $location = import_location($values['location'], $locations);
        if ($location === null) {
            $errors[] = 'Location "' . $values['location'] . '" must be one of: ' . implode(', ', $locations) . '.';
        } else {
            $p['location'] = $location;
        }
    }

    if ($values['registration_date'] !== '') {
        $date = import_date($values['registration_date']);
        if ($date === false || $date > $today) {
            $warnings[] = 'Registration date "' . $values['registration_date'] . '" '
                . ($date === false ? 'is not a date' : 'is in the future') . ', so today was used.';
        } else {
            $p['registration_date'] = $date;
        }
    }

    return $p;
}

/*
 * The dbpets values for one pet. $species is the sheet's Dogs/Cats group
 * ('Dog', 'Cat') or '' to use the Species column.
 * Older records often lack a breed, weight or age; those are saved blank
 * with a warning, and Edit Pet asks for them the next time the pet is
 * edited (FR-027). Values that can't be read are kept in the pet's notes.
 */
function import_check_pet($values, $species, &$errors, &$warnings) {
    $notes = $values['pet_notes'];
    $otherSpecies = null;
    if ($species === '') {
        list($species, $otherSpecies) = import_species($values['species']);
    }
    $name = $values['pet_name'];
    $label = ($species !== '' ? $species : 'Pet') . ($name !== '' ? ' "' . $name . '"' : '') . ': ';
    if ($species === '') {
        $species = 'Other';
        $warnings[] = $label . 'no species on the sheet, saved as Other.';
    }
    if ($otherSpecies !== null) {
        $notes = import_add_note($notes, 'Species: ' . $otherSpecies);
    }

    $missing = array();
    if ($name === '') {
        $name = 'Unknown';
        $missing[] = 'name (saved as "Unknown")';
    }
    $breed = $values['breed'];
    if ($breed === '') $missing[] = 'breed';

    $age = import_age_years($values['age_years']);
    if ($age === '') {
        $missing[] = 'age';
    } else if (!is_numeric($age) || (float) $age < PET_MIN_AGE_YEARS || (float) $age > PET_MAX_AGE_YEARS) {
        $warnings[] = $label . 'age "' . $values['age_years'] . '" couldn\'t be read, so it was left blank (kept in the notes).';
        $notes = import_add_note($notes, 'Age on sheet: ' . $values['age_years']);
        $age = '';
    } else if (preg_match('/[<\-]/', $values['age_years'])) {
        $notes = import_add_note($notes, 'Age on sheet: ' . $values['age_years']);
    }

    $weight = import_weight($values['weight_lbs']);
    if ($weight === '') {
        $missing[] = 'weight';
    } else if (!is_numeric($weight) || (float) $weight <= 0 || (float) $weight > 9999) {
        $warnings[] = $label . 'weight "' . $values['weight_lbs'] . '" couldn\'t be read, so it was left blank (kept in the notes).';
        $notes = import_add_note($notes, 'Weight on sheet: ' . $values['weight_lbs']);
        $weight = '';
    } else if (preg_match('/[\-+~]/', $values['weight_lbs'])) {
        $notes = import_add_note($notes, 'Weight on sheet: ' . $values['weight_lbs']);
    }
    if ($missing) {
        $warnings[] = $label . 'no ' . implode(', ', $missing) . ' on the sheet.';
    }

    $sex = import_sex($values['sex']);
    if ($sex === null) {
        $warnings[] = $label . 'sex "' . $values['sex'] . '" saved as Unknown.';
        $sex = 'Unknown';
    }
    $fixed = array();
    foreach (array('spay_neuter' => 'Spayed/neutered', 'rabies_status' => 'Rabies vaccinated') as $field => $title) {
        $fixed[$field] = import_yes_no($values[$field]);
        if ($fixed[$field] === null) {
            $warnings[] = $label . strtolower($title) . ' "' . $values[$field] . '" saved as Unknown (kept in the notes).';
            $notes = import_add_note($notes, $title . ' on sheet: ' . $values[$field]);
            $fixed[$field] = 'Unknown';
        }
    }

    $foodType = strtolower($values['food_type']);
    if ($foodType !== '' && !in_array($foodType, array('dry', 'wet', 'either'), true)) {
        $warnings[] = $label . 'food type "' . $values['food_type'] . '" is not dry, wet or either (kept in the notes).';
        $notes = import_add_note($notes, 'Food type on sheet: ' . $values['food_type']);
        $foodType = '';
    }

    if (mb_strlen($name) > 50) $errors[] = $label . 'pet name must be 50 characters or fewer.';
    if (mb_strlen($breed) > 60) $errors[] = $label . 'breed must be 60 characters or fewer.';
    $special = $values['special_medical_needs'];
    if (mb_strlen($special) > PET_MAX_MEDICAL_NEEDS) {
        $errors[] = $label . 'special medical needs must be ' . PET_MAX_MEDICAL_NEEDS . ' characters or fewer.';
    }

    return array(
        'name' => $name,
        'species' => $species,
        'breed' => $breed === '' ? null : $breed,
        'sex' => $sex,
        'age_years' => $age === '' ? null : $age,
        'weight_lbs' => $weight === '' ? null : $weight,
        'spay_neuter_status' => $fixed['spay_neuter'],
        'rabies_status' => $fixed['rabies_status'],
        'food_type' => $foodType === '' ? null : $foodType,
        'special_medical_needs' => $special === '' ? null : $special,
        'notes' => $notes === '' ? null : $notes
    );
}

/*
 * True when old .xls files (Excel 97-2003) can be read: that needs the
 * PhpSpreadsheet in vendor/, which needs PHP 8.2 or newer. .xlsx and .csv
 * are read with PHP's own zip and XML support, so they work on any server.
 */
function import_xls_supported() {
    return PHP_VERSION_ID >= 80200 && is_file(dirname(__FILE__) . '/../vendor/autoload.php');
}

/*
 * The first sheet of an uploaded .xlsx, .xls or .csv as rows of raw cell
 * values. Throws RuntimeException with a message staff can read.
 */
function import_read_spreadsheet($path, $originalName) {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === 'csv') {
        return import_read_csv($path);
    }
    if ($extension === 'xlsx') {
        return import_read_xlsx($path);
    }
    if ($extension !== 'xls') {
        throw new RuntimeException('Upload an Excel (.xlsx or .xls) or CSV file.');
    }
    if (!import_xls_supported()) {
        throw new RuntimeException('This is an old .xls file. In Excel, choose File > Save As > Excel Workbook (.xlsx) and upload that instead.');
    }
    require_once(dirname(__FILE__) . '/../vendor/autoload.php');
    try {
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xls')->setReadDataOnly(true)->load($path)->getSheet(0);
        if ($sheet->getHighestDataRow() > IMPORT_MAX_ROWS + 1) {
            throw new RuntimeException('The sheet has more than ' . IMPORT_MAX_ROWS . ' rows. Split it into smaller files.');
        }
        // Raw values: dates come back as Excel date numbers, which import_date() reads
        return $sheet->toArray(null, true, false, false);
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
        error_log('Import could not read .xls: ' . $e->getMessage());
        throw new RuntimeException('The file could not be read. Make sure it is a real Excel file and not password-protected.');
    }
}

/*
 * Rows of the first sheet of an .xlsx file, read straight from its XML.
 * Numbers (including dates, which Excel stores as day numbers) come back as
 * floats, text as strings, and missing cells as null.
 */
function import_read_xlsx($path) {
    $unreadable = 'The file could not be read. Make sure it is a real Excel file and not password-protected.';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException($unreadable);
    }
    try {
        $mainNs = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        // The first sheet listed in the workbook, wherever its file is
        $workbook = import_xlsx_xml($zip, 'xl/workbook.xml');
        $rels = import_xlsx_xml($zip, 'xl/_rels/workbook.xml.rels');
        if (!$workbook || !$rels || !isset($workbook->sheets->sheet[0])) {
            throw new RuntimeException($unreadable);
        }
        $sheetRelId = (string) $workbook->sheets->sheet[0]->attributes($relNs)['id'];
        $sheetPath = null;
        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $sheetRelId) {
                $target = (string) $rel['Target'];
                $sheetPath = $target[0] === '/' ? ltrim($target, '/') : 'xl/' . $target;
            }
        }
        $sheet = $sheetPath ? import_xlsx_xml($zip, $sheetPath) : null;
        if (!$sheet) {
            throw new RuntimeException($unreadable);
        }

        // Text cells point into the shared strings list
        $strings = array();
        $shared = import_xlsx_xml($zip, 'xl/sharedStrings.xml');
        if ($shared) {
            foreach ($shared->si as $si) {
                $strings[] = import_xlsx_text($si);
            }
        }

        $rows = array();
        foreach ($sheet->sheetData->row as $row) {
            $rowNumber = isset($row['r']) ? (int) $row['r'] : count($rows) + 1;
            if ($rowNumber > IMPORT_MAX_ROWS + 1) {
                throw new RuntimeException('The sheet has more than ' . IMPORT_MAX_ROWS . ' rows. Split it into smaller files.');
            }
            // Keep empty rows in place so error messages give the right row number
            while (count($rows) < $rowNumber - 1) {
                $rows[] = array();
            }
            $cells = array();
            $next = 0;
            foreach ($row->c as $c) {
                $column = isset($c['r']) ? import_xlsx_column((string) $c['r']) : $next;
                $next = $column + 1;
                $type = (string) $c['t'];
                $v = isset($c->v) ? (string) $c->v : null;
                if ($type === 's') {
                    $value = ($v !== null && isset($strings[(int) $v])) ? $strings[(int) $v] : '';
                } else if ($type === 'inlineStr') {
                    $value = import_xlsx_text($c->is);
                } else if ($type === 'b') {
                    $value = $v === '1';
                } else if ($type === 'str') {
                    $value = (string) $v;
                } else if ($type === 'e' || $v === null) {
                    $value = null;
                } else {
                    $value = is_numeric($v) ? (float) $v : $v;
                }
                $cells[$column] = $value;
            }
            if ($cells) {
                $cells += array_fill(0, max(array_keys($cells)) + 1, null);
                ksort($cells);
            }
            $rows[] = $cells;
        }
        return $rows;
    } finally {
        $zip->close();
    }
}

// One XML part of an .xlsx, or null if it isn't there or isn't valid
function import_xlsx_xml($zip, $name) {
    $stat = $zip->statName($name);
    // A real sheet of 2000 rows is a few MB; anything far bigger is not one
    if ($stat === false || $stat['size'] > 100 * 1024 * 1024) {
        return null;
    }
    $xml = $zip->getFromName($name);
    if ($xml === false) {
        return null;
    }
    $previous = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $doc === false ? null : $doc;
}

// The text of a shared or inline string, joining rich-text runs
function import_xlsx_text($node) {
    if (isset($node->t)) {
        return (string) $node->t;
    }
    $text = '';
    foreach ($node->r as $run) {
        $text .= (string) $run->t;
    }
    return $text;
}

// "B7" => 1 (column B, counting from 0)
function import_xlsx_column($cellRef) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

// The lines on the template's Instructions sheet
function import_template_instructions($locations) {
    return array(
        'How to fill in the Participants & Pets sheet',
        '',
        'One row per pet. Repeat the household columns (First Name to Participant Notes) on each of that household\'s pet rows.',
        'Rows with the same first name, last name and phone become one participant.',
        'A household with no pets: one row with the pet columns left blank.',
        'Columns marked * are required for every row. Phone and address can be blank but should be filled in where known.',
        '',
        'Location: ' . implode(', ', $locations) . '. If blank, the location chosen on the import page is used.',
        'Registration Date: a date such as 8/4/2026. If blank, today is used.',
        'Preferred Language: English, Spanish or Other. If blank, English.',
        'Address: use the separate columns, or put the whole address in Street Address as "412 Princess Anne St, Fredericksburg, VA 22401".',
        '',
        'For each pet fill in Pet Name, Species, Breed, Age and Weight where known. Anything missing is imported blank and listed under "Check later"; Edit Pet asks for it next time.',
        'Species: Dog, Cat or Other. Anything else (for example Rabbit) is saved as Other with the species in the pet notes.',
        'Sex: Male, Female or Unknown.',
        'Age (years): a number such as 6 or 0.5. "6 mo" and "8 weeks" also work.',
        'Weight (lbs): a number such as 45.',
        'Spayed/Neutered and Rabies Vaccinated: Yes, No or Unknown (blank means Unknown).',
        'Food Type: dry, wet or either (optional).',
        '',
        'Only the first sheet of the file is imported.'
    );
}

/*
 * Write the blank import template to $path as an .xlsx: the import sheet
 * (bold, frozen header; Phone and ZIP columns formatted as text so Excel
 * keeps leading zeros) and an Instructions sheet.
 */
function import_write_template($path, $locations) {
    $esc = function ($text) { return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $cell = function ($ref, $text, $style = 0) use ($esc) {
        return '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . $esc($text) . '</t></is></c>';
    };
    $letter = function ($index) {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }
        return $name;
    };

    $cols = '';
    $header = '';
    $i = 0;
    foreach (import_columns() as $field => $label) {
        $label .= in_array($field, import_required_columns(), true) ? ' *' : '';
        $textColumn = in_array($field, array('phone', 'zip'), true);
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(12, strlen($label) + 4) . '" customWidth="1"'
            . ($textColumn ? ' style="2"' : '') . '/>';
        $header .= $cell($letter($i) . '1', $label, 1);
        $i++;
    }
    $dataSheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0" tabSelected="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $cols . '</cols><sheetData><row r="1">' . $header . '</row></sheetData></worksheet>';

    $helpRows = '';
    foreach (import_template_instructions($locations) as $n => $line) {
        $helpRows .= '<row r="' . ($n + 1) . '">' . ($line === '' ? '' : $cell('A' . ($n + 1), $line, $n === 0 ? 1 : 0)) . '</row>';
    }
    $helpSheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols><col min="1" max="1" width="120" customWidth="1"/></cols><sheetData>' . $helpRows . '</sheetData></worksheet>';

    $parts = array(
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Participants &amp; Pets" sheetId="1" r:id="rId1"/><sheet name="Instructions" sheetId="2" r:id="rId2"/></sheets>'
            . '</workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        // Styles: 0 normal, 1 bold, 2 text (number format 49 is "@")
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => $dataSheet,
        'xl/worksheets/sheet2.xml' => $helpSheet,
    );

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the template');
    }
    foreach ($parts as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();
}

/*
 * Rows of a CSV file. Handles Excel's "CSV UTF-8" (with a byte order mark),
 * plain "CSV" saved in Windows-1252, and comma, semicolon or tab separators.
 */
function import_read_csv($path) {
    $text = file_get_contents($path);
    if ($text === false) {
        throw new RuntimeException('The file could not be read.');
    }
    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
        $text = substr($text, 3);
    } else if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $firstLine = strtok($text, "\r\n");
    $delimiter = ',';
    foreach (array(';', "\t") as $other) {
        if (substr_count((string) $firstLine, $other) > substr_count((string) $firstLine, $delimiter)) $delimiter = $other;
    }

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $text);
    rewind($handle);
    $rows = array();
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        if (count($rows) > IMPORT_MAX_ROWS) {
            fclose($handle);
            throw new RuntimeException('The sheet has more than ' . IMPORT_MAX_ROWS . ' rows. Split it into smaller files.');
        }
        $rows[] = $row === array(null) ? array() : $row;
    }
    fclose($handle);
    return $rows;
}
