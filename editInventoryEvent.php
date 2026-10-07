<?php
    session_cache_expire(30);
    session_start();

    $loggedIn = false;
    $accessLevel = 0;
    $userID = null;
    if (isset($_SESSION['_id'])) {
        $loggedIn = true;
        $accessLevel = $_SESSION['access_level'];
        $userID = $_SESSION['_id'];
    }

    /* Access control - Inventory Counters and Managers */
    if($accessLevel < 1) {
        header('Location: index.php');
        die();
    }

    require_once('database/dbinfo.php');
    require_once('database/dbInventoryEvent.php');
    require_once('database/dbItemCounts.php');
    require_once('database/dbItemCategory.php');

    /* Get event ID parameter (either warehouseId or pantryId) */
    $warehouseId = $_GET['warehouseId'] ?? null;
    $pantryId = $_GET['pantryId'] ?? null;

    if($warehouseId) {
        /* Warehouse-anchored: get warehouse, find matching pantry and pallet */
        $warehouseEvent = retrieve_inventoryEvent($warehouseId);
        if(!$warehouseEvent || $warehouseEvent->getLocation() != 'Warehouse') {
            echo "Warehouse event not found";
            die();
        }
        $matches = get_matching_inventoryEvent($warehouseEvent);
        $pantryEvent = $matches['Pantry'] ?? null;
        $palletEvent = $matches['Pallet'] ?? null;
        $eventDate = $warehouseEvent->getDate();
    } else if($pantryId) {
        /* Pantry-anchored: get pantry, find matching warehouse and pallet */
        $pantryEvent = retrieve_inventoryEvent($pantryId);
        if(!$pantryEvent || $pantryEvent->getLocation() != 'Pantry') {
            echo "Pantry event not found";
            die();
        }
        $matches = get_matching_inventoryEvent($pantryEvent);
        $warehouseEvent = $matches['Warehouse'] ?? null;
        $palletEvent = $matches['Pallet'] ?? null;
        $eventDate = $pantryEvent->getDate();
    } else {
        echo "Invalid event ID";
        die();
    }

    /* Get item counts for warehouse (if exists) */
    $warehouseCountsMap = array();
    if($warehouseEvent) {
        $warehouseItemCounts = get_itemCounts_by_inventoryEvent($warehouseEvent->getId());
        foreach($warehouseItemCounts as $count) {
            $categoryId = $count->getItemCategory();
            $warehouseCountsMap[$categoryId] = $count;
        }
    }

    /* Get item counts for pantry (if exists) */
    $pantryCountsMap = array();
    if($pantryEvent) {
        $pantryItemCounts = get_itemCounts_by_inventoryEvent($pantryEvent->getId());
        foreach($pantryItemCounts as $count) {
            $categoryId = $count->getItemCategory();
            $pantryCountsMap[$categoryId] = $count;
        }
    }

    /* Get item counts for pallet (if exists) */
    $palletCountsMap = array();
    if($palletEvent) {
        $palletItemCounts = get_itemCounts_by_inventoryEvent($palletEvent->getId());
        foreach($palletItemCounts as $count) {
            $categoryId = $count->getItemCategory();
            $palletCountsMap[$categoryId] = $count;
        }
    }

    /* Get all item categories */
    $allCategories = get_all_ItemCategory();

    /* Build set of categories with data in this inventory (for showing inactive categories with historical data) */
    $categoriesWithData = [];
    foreach ($warehouseCountsMap as $categoryId => $count) {
        $categoriesWithData[$categoryId] = true;
    }
    foreach ($pantryCountsMap as $categoryId => $count) {
        $categoriesWithData[$categoryId] = true;
    }
    foreach ($palletCountsMap as $categoryId => $count) {
        $categoriesWithData[$categoryId] = true;
    }

    /* Handle form submission */
    $errors = [];
    $success = false;
    $deleted = false;
    if (!empty($_POST)) {
        if(isset($_POST['cancel_button'])) {
            header('Location: inventory.php');
            die();
        }
        else if(isset($_POST['delete_button'])) {
            /* Delete warehouse, pantry, and pallet events */
            if($warehouseEvent) {
                remove_inventoryEvent($warehouseEvent->getId());
            }
            if($pantryEvent) {
                remove_inventoryEvent($pantryEvent->getId());
            }
            if($palletEvent) {
                remove_inventoryEvent($palletEvent->getId());
            }
            header('Location: inventory.php');
            die();
        }
        else if(isset($_POST['save_button'])) {
            /* Update quantities for warehouse (if exists) */
            if($warehouseEvent) {
                foreach($allCategories as $category) {
                    if(!isset($categoriesWithData[$category->getId()])) continue;

                    $categoryId = $category->getId();
                    $fieldName = 'warehouse_qty_' . $categoryId;

                    if(isset($_POST[$fieldName]) && isset($warehouseCountsMap[$categoryId])) {
                        try{
                            $newQty = +$_POST[$fieldName];
                        }
                        catch(TypeError $e){
                            $newQty = " ";
                        }

                        if(!is_int($newQty)){
                            $errors[] = 'Warehouse - ' . $category->getName() . ' quantity must be in whole numbers';
                            continue;
                        }
                        else if($newQty < 0){
                            $errors[] = 'Warehouse - ' . $category->getName() . ' quantity cannot be negative';
                            continue;
                        }

                        $itemCountId = $warehouseCountsMap[$categoryId]->getId();
                        $oldQty = $warehouseCountsMap[$categoryId]->getQuantity();

                        if($oldQty != $newQty) {
                            update_quantity($itemCountId, $newQty);
                        }
                    }
                }
            }

            /* Update quantities for pantry (if exists) */
            if($pantryEvent) {
                foreach($allCategories as $category) {
                    if(!isset($categoriesWithData[$category->getId()])) continue;

                    $categoryId = $category->getId();
                    $fieldName = 'pantry_qty_' . $categoryId;

                    if(isset($_POST[$fieldName]) && isset($pantryCountsMap[$categoryId])) {
                        try{
                            $newQty = +$_POST[$fieldName];
                        }
                        catch(TypeError $e){
                            $newQty = " ";
                        }

                        if(!is_int($newQty)){
                            $errors[] = 'Pantry - ' . $category->getName() . ' quantity must be in whole numbers';
                            continue;
                        }
                        else if($newQty < 0){
                            $errors[] = 'Pantry - ' . $category->getName() . ' quantity cannot be negative';
                            continue;
                        }

                        $itemCountId = $pantryCountsMap[$categoryId]->getId();
                        $oldQty = $pantryCountsMap[$categoryId]->getQuantity();

                        if($oldQty != $newQty) {
                            update_quantity($itemCountId, $newQty);
                        }
                    }
                }
            }

            /* Update quantities for pallet (if exists) */
            if($palletEvent) {
                foreach($allCategories as $category) {
                    if(!isset($categoriesWithData[$category->getId()])) continue;

                    $categoryId = $category->getId();
                    $fieldName = 'pallet_qty_' . $categoryId;

                    if(isset($_POST[$fieldName]) && isset($palletCountsMap[$categoryId])) {
                        try{
                            $newQty = +$_POST[$fieldName];
                        }
                        catch(TypeError $e){
                            $newQty = " ";
                        }

                        if(!is_int($newQty)){
                            $errors[] = 'Pallet - ' . $category->getName() . ' quantity must be in whole numbers';
                            continue;
                        }
                        else if($newQty < 0){
                            $errors[] = 'Pallet - ' . $category->getName() . ' quantity cannot be negative';
                            continue;
                        }

                        $itemCountId = $palletCountsMap[$categoryId]->getId();
                        $oldQty = $palletCountsMap[$categoryId]->getQuantity();

                        if($oldQty != $newQty) {
                            update_quantity($itemCountId, $newQty);
                        }
                    }
                }
            }

            if(empty($errors)) {
                /* Redirect to inventory page to view the updated inventory */
                $redirectId = $warehouseEvent ? $warehouseEvent->getId() : ($pantryEvent ? $pantryEvent->getId() : null);
                if ($redirectId) {
                    header('Location: inventory.php?week=' . $redirectId);
                    exit;
                }
            }
        }
    }
?>

<!DOCTYPE html>
<html>
<head>
    <?php require_once('universal.inc') ?>
    <title>Edit Inventory | CCDA</title>
    <style>
        pageheader {
            margin-top: 3rem;
            display: flex; justify-content: center; align-items: center;
            position: sticky;
            top: 1rem;
            z-index: 6;
        }
        .title {
            text-align: center;
            height: 3.5rem;
            width:auto;
            font-size: 2rem;
            font-weight: 600;
            color: var(--secondary-accent-color);
            padding-top: .4rem;
            border-radius: 10px;
            background-color: #ffffffee;
        }
        .edit-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 1.5rem;
            background-color: white;
            border-radius: 15px;
        }
        .event-info {
            background-color: rgba(0,0,0,0.05);
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .event-info p {
            margin: 0.5rem 0;
            color: var(--page-font-color);
            font-weight: 500;
        }
        .modify-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }
        .modify-table th {
            background-color: var(--main-color);
            color: var(--button-font-color);
            padding: 0.75rem;
            text-align: left;
            font-weight: 500;
            position: sticky;
            top: 100px; /* height of page header */
        }
        .modify-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--shadow-and-border-color);
            color: var(--page-font-color);
        }
        .updateInv-qty {
            width: 80px;
            box-sizing: border-box;
            padding: 0.4rem 0.6rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.05);
            color: var(--page-font-color);
        }
        .updateInv-qty:disabled {
            background-color: rgba(0,0,0,0.02);
            color: var(--inactive-font-color);
            cursor: not-allowed;
        }
        .modifyUsers-formBtns {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        .modify-save-btn,
        .modify-cancel-btn,
        .modify-delete-btn {
            padding: 0.5rem 1.5rem;
            background-color: var(--accent-color);
            color: var(--button-font-color);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            max-width: 500px;
        }
        .modify-delete-btn {
            background-color: darkred;
            color: var(--button-font-color);
        }
        .modify-delete-btn:hover {
            opacity: 0.75;
            background-color: darkred;
        }
        .modify-save-btn:hover,
        .modify-cancel-btn:hover {
            opacity: 0.85;
        }
        .back-btn {
            display: inline-block;
            margin-bottom: 1rem;
            padding: 0.5rem 1rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            text-decoration: none;
            border-radius: 0.25rem;
        }
        .back-btn:hover {
            background-color: rgba(0,0,0,0.3);
        }
        @media only screen and (max-width: 768px) {
            pageheader {
                top: 100px;
            }
            .title {
                border-radius: 0;
                background-color: #ffffff;
                width: 100%;
            }
            .modify-table th,
            .modify-table td {
                padding: 0.5rem;
                font-size: 0.8rem;
                position: static;
            }
            div.table-wrapper {
                overflow-x: auto;
            }
            .edit-container {
                padding: 1rem;
            }
            .updateInv-qty {
                width: 80px;
            }
        }
    </style>
</head>
<pageheader>
    <h1 class="title">Edit Inventory</h1>
</pageheader>
<body>
    <?php require_once('header.php') ?>
    <main class="edit-container">
        <a href="inventory.php" class="back-btn">← Back</a>
        

        <!-- Event Info (Read-Only) -->
        <div class="event-info">
            <p><strong>Date:</strong> <?= date('M j, Y', strtotime($eventDate)) ?></p>
        </div>

        <!-- Success Message -->
        <?php if($success): ?>
            <h4 style="color:black;"><i>Inventory Updated: <?= date("F jS, Y", strtotime($eventDate)) ?></i></h4>
        <?php endif; ?>

        <!-- Error Messages -->
        <?php if(!empty($errors)): ?>
            <ul>
                <?php foreach($errors AS $error): ?>
                    <li><?php echo("<h4 style=\"color:red;\"><i>".$error."</i></h4>"); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- Edit Form -->
        <form method="POST">
            <table class="modify-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item Name</th>
                        <th>Items Per Box</th>
                        <th>Warehouse</th>
                        <th>Pantry</th>
                        <th>Pallet</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $rowNum = 0;
                    foreach($allCategories as $category): ?>
                        <?php if(!isset($categoriesWithData[$category->getId()])) continue; ?>
                        <?php $rowNum++; ?>
                        <?php
                        $categoryId = $category->getId();

                        // Warehouse quantity
                        $warehouseQty = isset($warehouseCountsMap[$categoryId])
                            ? $warehouseCountsMap[$categoryId]->getQuantity()
                            : null;

                        // Pantry quantity
                        $pantryQty = isset($pantryCountsMap[$categoryId])
                            ? $pantryCountsMap[$categoryId]->getQuantity()
                            : null;

                        // Pallet quantity
                        $palletQty = isset($palletCountsMap[$categoryId])
                            ? $palletCountsMap[$categoryId]->getQuantity()
                            : null;
                        ?>
                        <tr>
                            <td><?= $rowNum ?></td>
                            <td><?= htmlspecialchars($category->getName()) ?></td>
                            <td><?= htmlspecialchars($category->getItemsPerBox()) ?></td>

                            <!-- Warehouse Input -->
                            <td>
                                <?php if($warehouseEvent && $warehouseQty !== null): ?>
                                    <input type="number"
                                           name="warehouse_qty_<?= $categoryId ?>"
                                           value="<?= $warehouseQty ?>"
                                           min="0"
                                           class="updateInv-qty">
                                <?php else: ?>
                                    <span style="color: var(--inactive-font-color);">-</span>
                                <?php endif; ?>
                            </td>

                            <!-- Pantry Input -->
                            <td>
                                <?php if($pantryEvent && $pantryQty !== null): ?>
                                    <input type="number"
                                           name="pantry_qty_<?= $categoryId ?>"
                                           value="<?= $pantryQty ?>"
                                           min="0"
                                           class="updateInv-qty">
                                <?php else: ?>
                                    <span style="color: var(--inactive-font-color);">-</span>
                                <?php endif; ?>
                            </td>

                            <!-- Pallet Input -->
                            <td>
                                <?php if($palletEvent && $palletQty !== null): ?>
                                    <input type="number"
                                           name="pallet_qty_<?= $categoryId ?>"
                                           value="<?= $palletQty ?>"
                                           min="0"
                                           class="updateInv-qty">
                                <?php else: ?>
                                    <span style="color: var(--inactive-font-color);">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="modifyUsers-formBtns">
                <button type="submit" name="save_button" class="modify-save-btn">Save Changes</button>
                <button type="submit" name="cancel_button" class="modify-cancel-btn" formnovalidate>Cancel</button>
                <hr>
                <button type="submit" name="delete_button" class="modify-delete-btn" onclick="return confirm('<?= date('m/d/Y', strtotime($eventDate)) ?>\nAre you sure you want to delete this inventory?\nThis action cannot be undone.')" formnovalidate>Delete Inventory</button>
            </div>
        </form>
    </main>
</body>
</html>
