<?php

/*
 * Each Client represents a client count record tied to a shopping event
 */

class Client {

    private $id; // Primary key
    private $shoppingEventId; // Foreign Key
    private $personId; // Foreign Key
    private $numClients;
    private $date;

    function __construct($id, $shoppingEventId, $personId, $numClients, $date) {
        $this->id = $id;
        $this->shoppingEventId = $shoppingEventId;
        $this->personId = $personId;
        $this->numClients = $numClients;
        $this->date = $date;
    }

    function getId() {
        return $this->id;
    }

    function getShoppingEventId() {
        return $this->shoppingEventId;
    }

    function getPersonId() {
        return $this->personId;
    }

    function getNumClients() {
        return $this->numClients;
    }

    function getDate() {
        return $this->date;
    }

    public function __toString() {
        return "ID: {$this->id}, Shopping Event ID: {$this->shoppingEventId}, Person ID: {$this->personId}, Num Clients: {$this->numClients}, Date: {$this->date}";
    }

}
