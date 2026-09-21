<?php
/**
 * Encapsulated version of a dbs inventoryEvent.
 */
class ItemCategory {
    private $id;
    private $name;
    private $bananaBox;
    private $itemsPerBox;
    private $status;
    private $shopOnly;


    function __construct($id, $name, $bananaBox, $itemsPerBox, $status, $shopOnly = 0) {
        $this->id = $id;
        $this->name = $name;
        $this->bananaBox = $bananaBox;
        $this->status = $status;
        $this->itemsPerBox = $itemsPerBox;
        $this->shopOnly = $shopOnly;
    }

    function getId() {
        return $this->id;
    }

    function getName() {
        return $this->name;
    }

    function getBananaBox() {
        return $this->bananaBox;
    }

    function getItemsPerBox() {
        return $this->itemsPerBox;
    }
    function getStatus() {
        return $this->status;
    }

    function getShopOnly() {
        return $this->shopOnly;
    }

}