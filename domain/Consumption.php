<?php

/*
 * Each Consumption represents the number of items consumed for a category during a shopping event
 */

class Consumption {

    private $id; // Primary key
    private $shoppingEventId; // Foreign Key
    private $itemCategoryId; // Foreign Key
    private $itemsConsumed;
    private $personId; // Foreign Key
    private $date;

    function __construct($id, $shoppingEventId, $itemCategoryId, $itemsConsumed, $personId, $date) {
        $this->id = $id;
        $this->shoppingEventId = $shoppingEventId;
        $this->itemCategoryId = $itemCategoryId;
        $this->itemsConsumed = $itemsConsumed;
        $this->personId = $personId;
        $this->date = $date;
    }

    function getId() {
        return $this->id;
    }

    function getShoppingEventId() {
        return $this->shoppingEventId;
    }

    function getItemCategoryId() {
        return $this->itemCategoryId;
    }

    function getItemsConsumed() {
        return $this->itemsConsumed;
    }

    function getPersonId() {
        return $this->personId;
    }

    function getDate() {
        return $this->date;
    }

    public function __toString() {
        return "ID: {$this->id}, Shopping Event ID: {$this->shoppingEventId}, Item Category ID: {$this->itemCategoryId}, Items Consumed: {$this->itemsConsumed}, Person ID: {$this->personId}, Date: {$this->date}";
    }

}
