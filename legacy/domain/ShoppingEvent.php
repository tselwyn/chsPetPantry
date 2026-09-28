<?php
/**
 * Encapsulated version of a dbs shoppingevent.
 */
class ShoppingEvent {
    private $id;
    private $personId;
    private $familySize;
    private $date;


    function __construct($id, $personId, $familySize, $date) {
        $this->id = $id;
        $this->personId = $personId;
        $this->familySize = $familySize;
        $this->date = $date;
    }

    function getId() {
        return $this->id;
    }

    function getPersonId() {
        return $this->personId;
    }

    function getFamilySize() {
        return $this->familySize;
    }

    function getDate() {
        return $this->date;
    }

}