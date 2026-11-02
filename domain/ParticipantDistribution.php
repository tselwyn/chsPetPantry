<?php

/*
 * Each ParticipantDistribution is one visit where a participant was given pet
 * food, recorded through distributionRecord.php. Stored in the
 * dbparticipantdistributions table.
 */

class ParticipantDistribution {

    private $id; // Primary key
    private $participant_id; // Foreign key to dbparticipants
    private $distribution_date;
    private $location; // Empower, Senior Center, Home Delivery
    private $pets_served;
    private $dog_food_lbs;
    private $cat_food_lbs;
    private $processed_by; // dbpersons id of the login that recorded it
    private $notes;
    private $processed_by_name; // name of the volunteer, typed in since logins are shared
    private $created_at;

    function __construct($id, $participant_id, $distribution_date, $location, $pets_served,
            $dog_food_lbs, $cat_food_lbs, $processed_by, $notes, $processed_by_name = '',
            $created_at = null) {
        $this->id = $id;
        $this->participant_id = $participant_id;
        $this->distribution_date = $distribution_date;
        $this->location = $location;
        $this->pets_served = $pets_served;
        $this->dog_food_lbs = $dog_food_lbs;
        $this->cat_food_lbs = $cat_food_lbs;
        $this->processed_by = $processed_by;
        $this->notes = $notes;
        $this->processed_by_name = $processed_by_name;
        $this->created_at = $created_at;
    }

    function get_id() {
        return $this->id;
    }

    function get_participant_id() {
        return $this->participant_id;
    }

    function get_distribution_date() {
        return $this->distribution_date;
    }

    function get_location() {
        return $this->location;
    }

    function get_pets_served() {
        return $this->pets_served;
    }

    function get_dog_food_lbs() {
        return $this->dog_food_lbs;
    }

    function get_cat_food_lbs() {
        return $this->cat_food_lbs;
    }

    function get_processed_by() {
        return $this->processed_by;
    }

    function get_notes() {
        return $this->notes;
    }

    /*
     * Who processed the distribution: the name typed on the form, or for
     * distributions recorded before that field existed, the login's id
     */
    function get_processed_by_name() {
        return (string) $this->processed_by_name !== '' ? $this->processed_by_name : $this->processed_by;
    }

    function get_created_at() {
        return $this->created_at;
    }

}
