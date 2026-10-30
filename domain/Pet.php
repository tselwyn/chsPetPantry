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
    private $status; // Active, Inactive, or Deleted
    private $counseling_status; // Yes once free spay/neuter and vaccination info was given
    private $counseling_date;
    private $counseled_by; // dbpersons id of the user who recorded the counseling

    function __construct($id, $participant_id, $name, $species, $breed,
            $sex, $age_years, $weight_lbs,
            $spay_neuter_status, $rabies_status, $food_type, $food_restriction,
            $notes, $status, $counseling_status = 'No', $counseling_date = null,
            $counseled_by = null) {

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

}
?>
