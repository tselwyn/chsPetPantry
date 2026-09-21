<?php

/*
 * Each Distribution represents the number of distribution days recorded by a person
 */

class Distribution {

    private $id; // Primary key
    private $distributionDays;
    private $personId; // Foreign Key
    private $date;

    function __construct($id, $distributionDays, $personId, $date) {
        $this->id = $id;
        $this->distributionDays = $distributionDays;
        $this->personId = $personId;
        $this->date = $date;
    }

    function getId() {
        return $this->id;
    }

    function getDistributionDays() {
        return $this->distributionDays;
    }

    function getPersonId() {
        return $this->personId;
    }

    function getDate() {
        return $this->date;
    }

    public function __toString() {
        return "ID: {$this->id}, Distribution Days: {$this->distributionDays}, Person ID: {$this->personId}, Date: {$this->date}";
    }

}
