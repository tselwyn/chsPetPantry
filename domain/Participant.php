<?php

/*
 * Each Participant is a Pet Pantry household registered through
 * registrationForm.php. Stored in the dbparticipants table.
 */

class Participant {

    private $id; // Primary key
    private $first_name;
    private $last_name;
    private $street_address;
    private $city;
    private $state;
    private $zip;
    private $phone; // digits only
    private $email;
    private $preferred_language;
    private $location; // Empower, Senior Center, Home Delivery
    private $registration_date;
    private $status; // Active, Inactive, Expired, No Service
    private $alert; // 1 if the participant is flagged
    private $notes;
    private $consent; // 1 if the participant agreed to the program rules
    private $pet_count; // active pets, filled in by searches; null if not loaded

    function __construct($id, $first_name, $last_name, $street_address, $city, $state, $zip,
            $phone, $email, $preferred_language, $location, $registration_date, $status,
            $alert, $notes, $consent, $pet_count = null) {
        $this->id = $id;
        $this->first_name = $first_name;
        $this->last_name = $last_name;
        $this->street_address = $street_address;
        $this->city = $city;
        $this->state = $state;
        $this->zip = $zip;
        $this->phone = $phone;
        $this->email = $email;
        $this->preferred_language = $preferred_language;
        $this->location = $location;
        $this->registration_date = $registration_date;
        $this->status = $status;
        $this->alert = $alert;
        $this->notes = $notes;
        $this->consent = $consent;
        $this->pet_count = $pet_count;
    }

    function get_id() {
        return $this->id;
    }

    function get_first_name() {
        return $this->first_name;
    }

    function get_last_name() {
        return $this->last_name;
    }

    function get_street_address() {
        return $this->street_address;
    }

    function get_city() {
        return $this->city;
    }

    function get_state() {
        return $this->state;
    }

    function get_zip() {
        return $this->zip;
    }

    function get_phone() {
        return $this->phone;
    }

    function get_email() {
        return $this->email;
    }

    function get_preferred_language() {
        return $this->preferred_language;
    }

    function get_location() {
        return $this->location;
    }

    function get_registration_date() {
        return $this->registration_date;
    }

    function get_status() {
        return $this->status;
    }

    function get_alert() {
        return $this->alert;
    }

    function get_notes() {
        return $this->notes;
    }

    function get_consent() {
        return $this->consent;
    }

    function get_pet_count() {
        return $this->pet_count;
    }

    // Street address, city, state and zip on one line, skipping blank parts
    function get_full_address() {
        $cityLine = trim(implode(', ', array_filter([$this->city, trim($this->state . ' ' . $this->zip)])));
        return implode(', ', array_filter([$this->street_address, $cityLine]));
    }

}
