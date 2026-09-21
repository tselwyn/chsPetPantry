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

    /* Access control - Managers only */
    if($accessLevel < 2) {
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
    $confirm = $_GET['confirm'] ?? 0;

    if(!$warehouseId && !$pantryId) {
        header('Location: viewEditDeleteInventory.php');
        die();
    }

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

    $errors = array();

    /* If confirmed, delete the triplet (warehouse, pantry, pallet events) */
    if($confirm == 1) {
        $deleteSuccess = true;
        $locations = array();

        /* Delete warehouse event */
        if($warehouseEvent) {
            $result = remove_inventoryEvent($warehouseEvent->getId());
            if(!$result) {
                $deleteSuccess = false;
            } else {
                $locations[] = 'Warehouse';
            }
        }

        /* Delete paired pantry event */
        if($pantryEvent) {
            $result = remove_inventoryEvent($pantryEvent->getId());
            if(!$result) {
                $deleteSuccess = false;
            } else {
                $locations[] = 'Pantry';
            }
        }

        /* Delete paired pallet event */
        if($palletEvent) {
            $result = remove_inventoryEvent($palletEvent->getId());
            if(!$result) {
                $deleteSuccess = false;
            } else {
                $locations[] = 'Pallet';
            }
        }

        if($deleteSuccess) {
            $locationStr = implode(' & ', $locations);
            header('Location: viewEditDeleteInventory.php?deleted=success&date=' . urlencode($eventDate) . '&location=' . urlencode($locationStr));
            die();
        } else {
            $errors[] = "Failed to delete inventory events";
        }
    }
?>

<!DOCTYPE html>
<html>
<head>
    <?php require_once('universal.inc') ?>
    <title>Delete Inventory | Whiskey Valor Foundation</title>
    <style>
        .edit-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 1.5rem;
            background-color: white;
            border-radius: 15px;
        }
        .title {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--secondary-accent-color);
            margin-bottom: 1rem;
            text-align: center;
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
        }
        .modify-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--shadow-and-border-color);
            color: var(--page-font-color);
        }
        .modifyUsers-formBtns {
            display: flex;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }
        .modify-delete-btn {
            padding: 0.6rem 1.5rem;
            background-color: #dc2626;
            color: white;
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-weight: 500;
            font-size: 1rem;
        }
        .modify-delete-btn:hover {
            background-color: #b91c1c;
        }
        .modify-cancel-btn {
            padding: 0.6rem 1.5rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-weight: 500;
            font-size: 1rem;
        }
        .modify-cancel-btn:hover {
            background-color: rgba(0,0,0,0.3);
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
            .modify-table th,
            .modify-table td {
                padding: 0.5rem;
                font-size: 0.8rem;
            }
            .edit-container {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php require_once('header.php') ?>
    <main class="edit-container">
        <a href="viewEditDeleteInventory.php" class="back-btn">← Back</a>

        <h1 class="title">Delete Inventory</h1>

        <!-- Warning Message -->
        <h2 class="title" style="color:red;">Warning: This action will permanently delete this inventory.</h2>

        <?php if(!empty($errors)): ?>
            <ul>
                <?php foreach($errors as $error): ?>
                    <li><?php echo("<h4 style=\"color:red;\"><i>".$error."</i></h4>"); ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="viewEditDeleteInventory.php" class="modify-cancel-btn">Back to List</a>
        <?php else: ?>
            <!-- Event Info (Read-Only) -->
            <div class="event-info">
                <p><strong>Date:</strong> <?= date('M j, Y', strtotime($eventDate)) ?></p>
            </div>

            <!-- Items List (Read-Only) -->
            <h3 style="margin-bottom: 1rem;">Items to be deleted:</h3>
            <table class="modify-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item Name</th>
                        <th>Items Per Box</th>
                        <th>Warehouse</th>
                        <th>Pantry</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $hasItems = false;
                    $rowNum = 0;
                    foreach($allCategories as $category): ?>
                        <?php if(!isset($categoriesWithData[$category->getId()])) continue; ?>
                        <?php
                        $categoryId = $category->getId();
                        $hasWarehouse = isset($warehouseCountsMap[$categoryId]);
                        $hasPantry = isset($pantryCountsMap[$categoryId]);

                        if(!$hasWarehouse && !$hasPantry) continue;

                        $hasItems = true;
                        $rowNum++;

                        $warehouseQty = $hasWarehouse ? $warehouseCountsMap[$categoryId]->getQuantity() : '-';
                        $pantryQty = $hasPantry ? $pantryCountsMap[$categoryId]->getQuantity() : '-';
                        ?>
                        <tr>
                            <td><?= $rowNum ?></td>
                            <td><?= htmlspecialchars($category->getName()) ?></td>
                            <td><?= htmlspecialchars($category->getItemsPerBox()) ?></td>
                            <td><?= $warehouseQty ?></td>
                            <td><?= $pantryQty ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if(!$hasItems): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--inactive-font-color);">
                                No items in inventory events for this date
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Confirmation Form -->
            <form method="GET" onsubmit="return confirmDelete()">
                <?php if($warehouseId): ?>
                    <input type="hidden" name="warehouseId" value="<?= htmlspecialchars($warehouseId) ?>">
                <?php else: ?>
                    <input type="hidden" name="pantryId" value="<?= htmlspecialchars($pantryId) ?>">
                <?php endif; ?>
                <input type="hidden" name="confirm" value="1">
                <div class="modifyUsers-formBtns">
                    <button type="submit" class="modify-delete-btn">
                        Delete
                    </button>
                    <button type="button" class="modify-cancel-btn" onclick="window.location.href='viewEditDeleteInventory.php'">
                        Cancel
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </main>
    <script>
        /* Final confirmation before deleting inventory events */
        function confirmDelete() {
            var locations = [];
            <?php if($warehouseEvent): ?>
            locations.push('Warehouse');
            <?php endif; ?>
            <?php if($pantryEvent): ?>
            locations.push('Pantry');
            <?php endif; ?>

            return confirm("Date: <?= date('m/d/Y', strtotime($eventDate)) ?>\n" +
                          "Locations: " + locations.join(' & ') + "\n\n" +
                          "Are you sure you want to delete this inventory pair?");
        }
    </script>
</body>
</html>
