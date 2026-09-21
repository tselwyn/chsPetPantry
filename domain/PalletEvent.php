<?php

/**
 * Encapsulated version of a dbs palletEvent.
 */

class PalletEvent {

    private $id;
    private $name;
    private $personId;
    private $date;
    private $notes;

    function __construct($id, $name, $personId, $date, $notes = null) {
        $this->id = $id;
        $this->name = $name;
        $this->personId = $personId;
        $this->date = $date;
        $this->notes = $notes;
    }

    function getId() {
        return $this->id;
    }

    function getName() {
        return $this->name;
    }

    function getPersonId() {
        return $this->personId;
    }

    function getDate() {
        return $this->date;
    }

    function getNotes() {
        return $this->notes;
    }

}