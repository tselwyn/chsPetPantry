<?php

/*
 * Each ItemCount represents one item counted during an inventory
 */

class ItemCount {

    private $id; // Primary key
    private $inventoryEventId; // Foreign Key
    private $itemCategoryId; // Foreign Key
    private $quantity;

    function __construct($id, $inventoryEventId, $itemCategoryId, $quantity) {
        $this->id = $id;
        $this->inventoryEventId = $inventoryEventId;
        $this->itemCategoryId = $itemCategoryId;
        $this->quantity = $quantity;
    }

    function getId() {
        return $this->id;
    }

    function getInventoryEvent() {
        return $this->inventoryEventId;
    }

    function getItemCategory() {
        return $this->itemCategoryId;
    }

    function getQuantity() {
        return $this->quantity;
    }

    public function __toString(){
        return "ID: {$this->id}, Inventory Event ID: {$this->inventoryEventId}, Item Category ID: {$this->itemCategoryId}, Quantity: {$this->quantity}";
    }

}
