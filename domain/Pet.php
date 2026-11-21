<?php

/*
 * Each Pet belongs to a Pet Pantry participant.
 * Stored in the dbpets table.
 */

class Pet {

    private $id; // Primary key
    private $participant_id; // Participant who owns the pet
    private $name;
    private $species;
    private $breed;
    private $sex;
    private $age_years; // age in years to one decimal place, e.g. 0.5 for six months
    private $weight_lbs;
    private $spay_neuter_status; // Yes, No, or Unknown
    private $rabies_status; // Yes, No, or Unknown
    private $food_type;
    private $food_restriction;
    private $notes;
    private $status; // Active, Rehomed, Deceased, Inactive (older records), or Deleted
    private $counseling_status; // Yes once free spay/neuter and vaccination info was given
    private $counseling_date;
    private $counseled_by; // dbpersons id of the user who recorded the counseling
    private $special_medical_needs;
    private $age_recorded_date; // date age_years was entered; the age counts up from it (FR-077)

    function __construct($id, $participant_id, $name, $species, $breed,
            $sex, $age_years, $weight_lbs,
            $spay_neuter_status, $rabies_status, $food_type, $food_restriction,
            $notes, $status, $counseling_status = 'No', $counseling_date = null,
            $counseled_by = null, $special_medical_needs = null, $age_recorded_date = null) {

        $this->id = $id;
        $this->participant_id = $participant_id;
        $this->name = $name;
        $this->species = $species;
        $this->breed = $breed;
        $this->sex = $sex;
        $this->age_years = $age_years;
        $this->weight_lbs = $weight_lbs;
        $this->spay_neuter_status = $spay_neuter_status;
        $this->rabies_status = $rabies_status;
        $this->food_type = $food_type;
        $this->food_restriction = $food_restriction;
        $this->notes = $notes;
        $this->status = $status;
        $this->counseling_status = $counseling_status;
        $this->counseling_date = $counseling_date;
        $this->counseled_by = $counseled_by;
        $this->special_medical_needs = $special_medical_needs;
        $this->age_recorded_date = $age_recorded_date;
    }

    function get_id() {
        return $this->id;
    }

    function get_participant_id() {
        return $this->participant_id;
    }

    function get_name() {
        return $this->name;
    }

    function get_species() {
        return $this->species;
    }

    function get_breed() {
        return $this->breed;
    }

    function get_sex() {
        return $this->sex;
    }

    /*
     * Age in years as entered, or null for a pet saved before ages were recorded
     */
    function get_age_years() {
        return $this->age_years;
    }

    function get_weight_lbs() {
        return $this->weight_lbs;
    }

    function get_spay_neuter_status() {
        return $this->spay_neuter_status;
    }

    function get_rabies_status() {
        return $this->rabies_status;
    }

    function get_food_type() {
        return $this->food_type;
    }

    function get_food_restriction() {
        return $this->food_restriction;
    }

    function get_notes() {
        return $this->notes;
    }

    function get_status() {
        return $this->status;
    }

    function get_counseling_status() {
        return $this->counseling_status;
    }

    function get_counseling_date() {
        return $this->counseling_date;
    }

    function get_counseled_by() {
        return $this->counseled_by;
    }

    function get_special_medical_needs() {
        return $this->special_medical_needs;
    }

    // The date the stored age was entered (Y-m-d), or null for older records
    function get_age_recorded_date() {
        return $this->age_recorded_date;
    }

    // The pet's age today: the age entered plus the time since (FR-077)
    function get_current_age_years($today = null) {
        return pet_current_age($this->age_years, $this->age_recorded_date, $today);
    }

}

/*
 * A pet's age today, in years to one decimal place (FR-077, SRS risk 6.2).
 * CHS enters an age, not a date of birth, so the age is stored with the date
 * it was entered and counts up from there: a pet entered as 2.0 on
 * 2026-11-21 is 3.0 on 2027-11-21. Returns null when no age is recorded, and
 * the age as entered when the date is unknown (records from before this was
 * added). $today defaults to the current date.
 */
function pet_current_age($age_years, $recorded_date, $today = null) {
    if ($age_years === null || $age_years === '') {
        return null;
    }
    $age = (float) $age_years;
    if ($recorded_date === null || $recorded_date === '') {
        return round($age, 1);
    }
    try {
        $from = new DateTime(substr((string) $recorded_date, 0, 10));
        $to = new DateTime($today === null ? 'today' : (string) $today);
    } catch (Exception $e) {
        return round($age, 1);
    }
    $days = (int) $from->diff($to)->format('%r%a');
    return round($age + max(0, $days) / 365.25, 1);
}

/*
 * A pet age for display: "3", "0.5", "12.4", or "Unknown".
 */
function pet_age_label($age) {
    if ($age === null) {
        return 'Unknown';
    }
    $text = number_format((float) $age, 1, '.', '');
    return substr($text, -2) === '.0' ? substr($text, 0, -2) : $text;
}
?>
