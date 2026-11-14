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
    private $status; // Active, Inactive, Expired, DND (Do Not Distribute), Deleted
    private $alert; // 1 if the participant is Flagged; only an admin can change it
    private $notes;
    private $consent; // no longer collected; kept for rows registered before Oct 2026
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

    // Participant ID as CHS sees it, e.g. PP00012 (SRS FR-074)
    function get_display_id() {
        return format_participant_id($this->id);
    }

}

/*
 * Participant IDs are stored as plain numbers and shown as PP followed by five
 * digits, e.g. 12 is shown as PP00012 (SRS FR-074). IDs past 99999 keep all
 * their digits (PP100000).
 */
function format_participant_id($id) {
    return 'PP' . str_pad((string) (int) $id, 5, '0', STR_PAD_LEFT);
}

/*
 * The numeric ID in a typed Participant ID, or null if it isn't one.
 * Accepts PP00012, pp12, PP-00012, #12 and 12.
 */
function parse_participant_id($text) {
    if (!is_string($text) && !is_int($text)) {
        return null;
    }
    if (!preg_match('/^\s*(?:PP\s*-?\s*|#)?0*(\d{1,9})\s*$/i', (string) $text, $match)) {
        return null;
    }
    $id = (int) $match[1];
    return $id > 0 ? $id : null;
}
