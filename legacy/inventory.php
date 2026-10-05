<?php
session_cache_expire(30);
session_start();
ini_set("display_errors",1);
error_reporting(E_ALL);

// Admin check
if(!isset($_SESSION['_id'])) {
    header('Location: login.php');
    exit;
}

$accessLevel = $_SESSION['access_level'] ?? 0;

require_once(__DIR__ . '/database/dbinfo.php');
require_once(__DIR__ . '/database/dbInventoryEvent.php');
require_once(__DIR__ . '/database/dbItemCategory.php');
require_once(__DIR__ . '/database/dbItemCounts.php');

// Get all inventory events sorted by date (newest first), then by ID (highest first)
$allEventObjects = get_all_inventoryEvents();
usort($allEventObjects, function($a, $b) {
    $dateDiff = strtotime($b->getDate()) - strtotime($a->getDate());
    if ($dateDiff != 0) {
        return $dateDiff;
    }
    return $b->getId() - $a->getId();
});

// Build event triplets (warehouse + pantry + pallet)
$eventPairs = array();
foreach($allEventObjects as $event) {
    if($event->getLocation() == 'Warehouse') {
        $matches = get_matching_inventoryEvent($event);
        $pantryEvent = $matches['Pantry'] ?? null;
        $palletEvent = $matches['Pallet'] ?? null;
        $eventPairs[] = array(
            'warehouse' => $event,
            'pantry' => $pantryEvent,
            'pallet' => $palletEvent,
            'date' => $event->getDate(),
            'warehouseId' => $event->getId(),
            'pantryId' => $pantryEvent ? $pantryEvent->getId() : null,
            'palletId' => $palletEvent ? $palletEvent->getId() : null
        );
    }
}

// OLD CODE - orphan pantry logic (no longer needed with triplets)
// foreach($allEventObjects as $event) {
//     if($event->getLocation() == 'Pantry') {
//         $warehouseEvent = get_matching_inventoryEvent($event);
//         if($warehouseEvent === null) {
//             $eventPairs[] = array(
//                 'warehouse' => null,
//                 'pantry' => $event,
//                 'date' => $event->getDate(),
//                 'warehouseId' => null,
//                 'pantryId' => $event->getId()
//             );
//         }
//     }
// }

// Re-sort pairs by date (newest first)
usort($eventPairs, function($a, $b) {
    $dateDiff = strtotime($b['date']) - strtotime($a['date']);
    if ($dateDiff != 0) {
        return $dateDiff;
    }
    $aId = $a['warehouseId'] ?? $a['pantryId'];
    $bId = $b['warehouseId'] ?? $b['pantryId'];
    return $bId - $aId;
});

// Get the selected week from query params, default to latest
$selectedWeek = $_GET['week'] ?? (count($eventPairs) > 0 ? ($eventPairs[0]['warehouseId'] ?? $eventPairs[0]['pantryId']) : null);

// Get the unique years from dates
$uniqueYears = array();
foreach($eventPairs as $index => $pair) {
    $year = date('Y', strtotime($pair["date"]));
    if(!in_array($year, $uniqueYears))
        $uniqueYears[] = $year;
}

// Default for filterEventList is 30 Most Recent inventories
$filterEventList = 30;

// Get the selected year from query params, if it exists
if(isset($_GET['year'])){
    if(in_array($_GET['year'], $uniqueYears)){
        $filterEventList = $_GET['year'];
        // if year and week are in params, make sure the week is in the selected year.
        // otherwise, change week to be the most recent inventory in the year selected.
        if($selectedWeek){
            $currentEvent = retrieve_inventoryEvent($selectedWeek);
            if($currentEvent){
                $currentEventYear = date('Y', strtotime($currentEvent->getDate()));
                if($currentEventYear != $filterEventList){
                    //set current week to the most recent inventory with the year selected
                    foreach($eventPairs as $index => $pair) {
                        $year = date('Y', strtotime($pair["date"]));
                        if($year == $filterEventList){
                            $selectedWeek = $pair["warehouseId"];
                            break;
                        }
                    }
                }
            }
        }
        
    } 
    else if($_GET['year'] < 100 && $_GET['year'] > 0)
        $filterEventList = $_GET['year'];
} 
// If year is not set but week is set
else if(isset($_GET['week'])){
    $currentEvent = retrieve_inventoryEvent($selectedWeek);
    if($currentEvent){
        $filterEventList = date('Y', strtotime($currentEvent->getDate()));
    }
}

// Find the selected pair index
$selectedPairIndex = null;
foreach($eventPairs as $index => $pair) {
    $pairId = $pair['warehouseId'] ?? $pair['pantryId'];
    if($pairId == $selectedWeek) {
        $selectedPairIndex = $index;
        break;
    }
}

// Fetch inventory items with box information for the selected pair
$items = [];
if ($selectedPairIndex !== null) {
    $selectedPair = $eventPairs[$selectedPairIndex];
    $sameDateEvents = [];
    
    // Collect warehouse, pantry, and pallet events from the triplet
    if ($selectedPair['warehouse']) {
        $sameDateEvents[] = $selectedPair['warehouse'];
    }
    if ($selectedPair['pantry']) {
        $sameDateEvents[] = $selectedPair['pantry'];
    }
    if (isset($selectedPair['pallet']) && $selectedPair['pallet']) {
        $sameDateEvents[] = $selectedPair['pallet'];
    }

    // Get all item categories (including inactive for historical data)
    $allCategories = get_all_ItemCategory();

    // Build set of categories with data in this inventory
    $categoriesWithData = [];
    foreach ($sameDateEvents as $event) {
        $eventCounts = get_itemCounts_by_inventoryEvent($event->getId());
        foreach ($eventCounts as $count) {
            $categoriesWithData[$count->getItemCategory()] = true;
        }
    }

    // Build items array with location-specific counts
    foreach ($allCategories as $category) {
        $categoryId = $category->getId();

        // Skip shopping list only items
        if ($category->getShopOnly() == 1) {
            continue;
        }

        // Only show categories that have data in this inventory
        if (!isset($categoriesWithData[$categoryId])) {
            continue;
        }

        $warehouseBoxes = 0;
        $pantryBoxes = 0;
        $palletBoxes = 0;

        // Check all events on the same date for this category
        foreach ($sameDateEvents as $event) {
            $eventCounts = get_itemCounts_by_inventoryEvent($event->getId());
            foreach ($eventCounts as $count) {
                if ($count->getItemCategory() === $categoryId) {
                    if ($event->getLocation() === 'Warehouse') {
                        $warehouseBoxes += $count->getQuantity();
                    } elseif ($event->getLocation() === 'Pantry') {
                        $pantryBoxes += $count->getQuantity();
                    } elseif ($event->getLocation() === 'Pallet') {
                        $palletBoxes += $count->getQuantity();
                    }
                }
            }
        }

        $totalBoxes = $warehouseBoxes + $pantryBoxes + $palletBoxes;

        $items[] = [
            'id' => $categoryId,
            'item_name' => $category->getName(),
            'itemsPerBox' => $category->getItemsPerBox(),
            'bananaBox' => $category->getBananaBox(),
            'warehouse_boxes' => $warehouseBoxes,
            'pantry_boxes' => $pantryBoxes,
            'pallet_boxes' => $palletBoxes,
            'total_boxes' => $totalBoxes
        ];

    }
    
    // Sort items by name
    usort($items, function($a, $b) {
        return strcmp($a['item_name'], $b['item_name']);
    });
}

?>

<!DOCTYPE html>
<html>
<head>
    <?php require_once('universal.inc'); ?>
    <title>Inventory Log | CCDA</title>
    <link rel="stylesheet" href="css/base.css">
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
        .report-container {
            max-width: 1100px;
            margin: 0 auto 4rem auto;
            padding: 1rem;
        }
        .report-section {
            background-color: white;
            /* border: 1px solid var(--shadow-and-border-color); */
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .report-section h1 {
            font-size: 1.5rem;
            font-weight: 500;
            margin-bottom: 1rem;
            color: var(--secondary-accent-color);
        }
        .report-section h2 {
            font-size: 1.5rem;
            font-weight: 500;
            margin-bottom: 1rem;
            color: var(--secondary-accent-color);
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }
        .report-table th,
        .report-table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--shadow-and-border-color);
            color: var(--page-font-color);
            text-align: left;
            vertical-align: middle;
        }
        .report-table th {
            background-color: var(--main-color);
            color: var(--button-font-color);
            font-weight: 500;
            position: sticky;
            top: 100px; /* height of page header */
        }
        .report-table tr:hover {
            background-color: rgba(255,255,255,0.05);
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--inactive-font-color);
        }
        .mobile-text {
            display: none;
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
            .report-table th,
            .report-table td {
                padding: 0.5rem;
                font-size: 0.8rem;
            }
            .report-table th {
                position: sticky;
                top: 100px;
            }
            .report-container {
                padding: 0.5rem;
            }
            div.table-wrapper {
                overflow-x: visible;
            }
            .updateInv-optionRow {
                display: flex;
                align-items: right;
                flex-direction: column;
                justify-content: left;
                gap: 1rem;
            }
            .updateInv-option {
                display: flex;
                align-items: center;
                flex-direction: row;
                width: auto;
                gap: 1rem;
            }
            .updateInv-qty {
                max-width: 7rem;
                margin-right: 2rem !important;
                
            }
            .desktop-text {
                display: none;
            }
            .mobile-text {
                display: inline;
            }
            .report-table th {
                font-size: 0.85rem;
                padding: 4px;
            }
            .report-table td {
                padding: 4px;
            }
        }
        .week-and-filter-section{
            display: flex;
            flex-direction: row;
            gap: 2rem;
        }
        .form-section {
            margin-bottom: 1.5rem;
        }
        .form-section label {
            display: block;
            color: var(--page-font-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .form-section select {
            width: 100%;
            max-width: 300px;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            cursor: pointer;
        }
        .form-section select:hover {
            background-color: rgba(0,0,0,0.3);
        }
        .inventory-selector-toolbar {
            display: flex;
            align-content: center;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            flex-direction: column;
        }
        .week-selector {
            margin-bottom: .5rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        .week-selector label {
            color: var(--page-font-color);
        }
        .week-selector select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            cursor: pointer;
            min-width: 200px;
        }
        .week-selector select:hover {
            background-color: rgba(0,0,0,0.3);
        }
        .table-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .toolbar-select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            cursor: pointer;
            min-width: 180px;
        }
        .toolbar-select:hover {
            background-color: rgba(0,0,0,0.3);
        }
        .toolbar-search {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            min-width: 200px;
        }
        .toolbar-search::placeholder {
            color: var(--inactive-font-color);
        }
        .toolbar-btn-clear {
            padding: 0.5rem 1rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            cursor: pointer;
            font-weight: 500;
        }
        .toolbar-btn-clear:hover {
            background-color: rgba(0,0,0,0.3);
        }
        .modify-button {
            padding: 0.5rem 1rem;
            background-color: var(--accent-color);
            color: var(--button-font-color);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            max-width: 500px;
        }
        .modify-button:hover {
            opacity: 0.85;
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
            .report-table th,
            .report-table td {
                padding: 0.5rem;
                font-size: 0.8rem;
                position: static;

            }
            .report-container {
                padding: 0.5rem;
            }
            div.table-wrapper {
                overflow-x: visible;
            }
            .updateInv-optionRow {
                display: flex;
                align-items: right;
                flex-direction: column;
                justify-content: left;
                gap: 1rem;
            }
            .updateInv-option {
                display: flex;
                align-items: center;
                flex-direction: row;
                width: auto;
                gap: 1rem;
            }
            .updateInv-qty {
                max-width: 7rem;
                margin-right: 2rem !important;
                
            }
            .desktop-text {
                display: none;
            }
            .mobile-text {
                display: inline;
            }
            .report-table th {
                font-size: 0.85rem;
                padding: 4px;
            }
            .report-table td {
                padding: 4px;
            }
            .report-section{
                padding: 0;
            }
        }
    </style>
</head>
<pageheader>
    <h1 class="title">Inventory Log</h1>
</pageheader>
<body>
    <?php require_once('header.php'); ?>
    <main>
        <div class="report-container">
            <div class="report-section">
                <h2>Food Items</h2>
                <div class="week-and-filter-section">
                    <div class="form-section">
                        <label for="weekSelect">Select Inventory to View:</label>
                        <select id="weekSelect" name="week" class="toolbar-select" style="min-width: 1rem; important!;"  onchange="window.location.href='?year='+<?php echo $filterEventList ?>+'&week=' + this.value">
                            <?php if (count($eventPairs) > 0): ?>
                                <?php foreach ($eventPairs as $index => $pair): ?>
                                    <?php $pairId = $pair['warehouseId'] ?? $pair['pantryId']; ?>
                                    <?php if (date('Y', strtotime($pair['date'])) == $filterEventList || ($filterEventList < 100 && $index < $filterEventList)): ?>
                                        <option value="<?= htmlspecialchars($pairId) ?>" <?= ($pairId == $selectedWeek) ? 'selected' : '' ?>>
                                            <?= date('m/d/Y', strtotime($pair['date'])) ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-section">
                        <label for="yearSelect" style="font-weight: 500;">Filter Inventories:</label>
                        <select id="yearSelect" name="year" class="toolbar-select" style="min-width: 1rem; important!;" onchange="window.location.href='?year=' + this.value +'&week='+<?php echo intval($selectedWeek) ?>">
                            <option value="30" <?= ($filterEventList == "30") ? 'selected' : '' ?>>
                                    Most Recent 
                            </option>
                            <optgroup label="By Year">
                            <?php foreach ($uniqueYears as $year): ?>
                                <option value="<?= $year ?>" <?= ($year == $filterEventList) ? 'selected' : '' ?>>
                                    <?= $year ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                </div>
                <hr>
                <br>
                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <label for="sortSelect" style="color: var(--page-font-color); margin-right: 0.5rem;">Sort by:</label>
                        <select id="sortSelect" class="toolbar-select">
                            <option value="name-asc">Name (A-Z)</option>
                            <option value="name-desc">Name (Z-A)</option>
                        </select>
                    </div>
                    <div class="toolbar-right">
                        <input type="text" id="searchInput" class="toolbar-search" placeholder="Search items...">
                        <button type="button" id="clearSearch" class="toolbar-btn-clear">Clear</button>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="report-table" id="inventoryTable">
                        <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th>
                                        <span class="desktop-text">Warehouse</span>
                                        <span class="mobile-text">WH</span>
                                    </th>
                                    <th>
                                        <span class="desktop-text">Pantry</span>
                                        <span class="mobile-text">PT</span>
                                    </th>
                                    <th>
                                        <div class="pallet-column" style="display: block;">
                                            <span class="desktop-text">Pallet Boxes</span>
                                            <span class="mobile-text">Pallets</span>
                                        </div>
                                    </th>
                                    <th>
                                        <span class="desktop-text">Total Boxes</span>
                                        <span class="mobile-text">Total Boxes</span>
                                    </th>
                                    <th>
                                        <span class="desktop-text">Banana Box</span>
                                        <span class="mobile-text">Ban. Box</span>
                                    </th>
                                    <th>
                                        <span class="desktop-text">Items Per Box</span>
                                        <span class="mobile-text">Items/Box</span>
                                    </th>
                                    <th>
                                        <span class="desktop-text">Total Items</span>
                                        <span class="mobile-text">Total Items</span>
                                    </th>
                                </tr>
                            </thead>
                        <tbody>
                            <?php if (count($items) > 0): ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['item_name']) ?></td>
                                        <td><?= $item['warehouse_boxes'] > 0 ? htmlspecialchars($item['warehouse_boxes']) : '-' ?></td>
                                        <td><?= $item['pantry_boxes'] > 0 ? htmlspecialchars($item['pantry_boxes']) : '-' ?></td>
                                        <td><?= $item['pallet_boxes'] > 0 ? htmlspecialchars($item['pallet_boxes']) : '-' ?></td>
                                        <td><?= htmlspecialchars($item['total_boxes']) ?></td>
                                        <td><?= $item['bananaBox'] == 1 ? '✓' : '' ?></td>
                                        <td><?= htmlspecialchars($item['itemsPerBox']) ?></td>
                                        <td><?= htmlspecialchars($item['total_boxes'] * $item['itemsPerBox']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="empty-state">No items found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if($accessLevel >= 1): ?>
                <div style="margin-bottom: 1.5rem;">
                    <a href="editInventoryEvent.php?warehouseId=<?= htmlspecialchars($selectedWeek) ?>" style="text-decoration: none; display: flex; justify-content: center;">
                        <button class="modify-button">
                            Modify
                        </button>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        $(function() {
            // Sorting functionality
            $('#sortSelect').change(function() {
                var sortValue = $(this).val();
                var $tbody = $('#inventoryTable tbody');
                var $rows = $tbody.find('tr').get();

                if (sortValue === 'name-asc') {
                    // Sort by name A-Z
                    $rows.sort(function(a, b) {
                        var nameA = $(a).find('td').eq(0).text().toLowerCase();
                        var nameB = $(b).find('td').eq(0).text().toLowerCase();
                        return nameA.localeCompare(nameB);
                    });
                } else if (sortValue === 'name-desc') {
                    // Sort by name Z-A
                    $rows.sort(function(a, b) {
                        var nameA = $(a).find('td').eq(0).text().toLowerCase();
                        var nameB = $(b).find('td').eq(0).text().toLowerCase();
                        return nameB.localeCompare(nameA);
                    });
                }

                // Re-append rows in new order
                $.each($rows, function(index, row) {
                    $tbody.append(row);
                });
            });

            // Search functionality
            $('#searchInput').on('input', function() {
                var searchTerm = $(this).val().toLowerCase();

                $('#inventoryTable tbody tr').each(function() {
                    var itemName = $(this).find('td').eq(0).text().toLowerCase();

                    if (itemName.indexOf(searchTerm) > -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // Clear search button
            $('#clearSearch').click(function() {
                $('#searchInput').val('');
                $('#inventoryTable tbody tr').show();
            });

            // Store original order for default sorting
            $('#inventoryTable tbody tr').each(function(index) {
                $(this).data('original-index', index);
            });
        });
    </script>

</body>
</html>




