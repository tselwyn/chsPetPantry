<?php

/*
 * Each ShoppingCountGroup represents a collection of item on a shopping list
 */

class ShoppingCountGroup {

    private $id; // Primary key
    private $shoppingEventId; // Foreign Key
    private $groupName;

    function __construct($id, $shoppingEventId,  $groupName) {
        $this->id = $id;
        $this->shoppingEventId = $shoppingEventId;
        $this->groupName = $groupName;
    }

    function getId() {
        return $this->id;
    }

    function getShoppingEvent() {
        return $this->shoppingEventId;
    }

    function getGroupName() {
        return $this->groupName;
    }

}
