<?php
/**
 * Encapsulated version of a dbs inventoryEvent.
 */
class InventoryEvent {
    private $id;
    private $personId;
    private $location;
    private $date;


    function __construct($id, $personId, $location, $date) {
        $this->id = $id;
        $this->personId = $personId;
        $this->location = $location;
        $this->date = $date;
    }

    function getId() {
        return $this->id;
    }

    function getPersonId() {
        return $this->personId;
    }

    function getLocation() {
        return $this->location;
    }

    function getDate() {
        return $this->date;
    }

}