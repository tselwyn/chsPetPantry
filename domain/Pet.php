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
    private $date_of_birth;
    private $dob_is_estimate;
    private $weight_lbs;
    private $is_altered; // 1 = Yes, 0 = No
    private $food_type;
    private $food_restriction;
    private $notes;
    private $status; // Active, Inactive, or Deleted

    function __construct($id, $participant_id, $name, $species, $breed,
            $sex, $date_of_birth, $dob_is_estimate, $weight_lbs,
            $is_altered, $food_type, $food_restriction, $notes, $status) {

        $this->id = $id;
        $this->participant_id = $participant_id;
        $this->name = $name;
        $this->species = $species;
        $this->breed = $breed;
        $this->sex = $sex;
        $this->date_of_birth = $date_of_birth;
        $this->dob_is_estimate = $dob_is_estimate;
        $this->weight_lbs = $weight_lbs;
        $this->food_type = $food_type;
        $this->food_restriction = $food_restriction;
        $this->notes = $notes;
        $this->is_altered = $is_altered;
        $this->status = $status;
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

    function get_date_of_birth() {
        return $this->date_of_birth;
    }

    function get_dob_is_estimate() {
        return $this->dob_is_estimate;
    }

    function get_weight_lbs() {
        return $this->weight_lbs;
    }

    function get_is_altered() {
        return $this->is_altered;
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

}
?>