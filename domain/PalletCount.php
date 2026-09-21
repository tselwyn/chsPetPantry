<?php

/*
 * Each PalletCount represents one item on a pallet, and the quantity of that item on the pallet. 
 */

class PalletCount {

    private $id; // Primary key
    private $palletEventId; // Foreign Key
    private $itemCategoryId; // Foreign Key
    private $quantity;
    private $expiration;

    function __construct($id, $palletEventId, $itemCategoryId, $quantity, $expiration) {
        $this->id = $id;
        $this->palletEventId = $palletEventId;
        $this->itemCategoryId = $itemCategoryId;
        $this->quantity = $quantity;
        $this->expiration = $expiration;
    }

    function getId() {
        return $this->id;
    }

    function getPalletEvent() {
        return $this->palletEventId;
    }

    function getItemCategory() {
        return $this->itemCategoryId;
    }

    function getQuantity() {
        return $this->quantity;
    }

    function getExpiration() {
        return $this->expiration;
    }

    public function __toString(){
        return "ID: {$this->id}, Pallet Event ID: {$this->palletEventId}, Item Category ID: {$this->itemCategoryId}, Quantity: {$this->quantity}";
    }

}
