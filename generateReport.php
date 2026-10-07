<?php
session_cache_expire(30);
session_start();
ini_set("display_errors", 1);
error_reporting(E_ALL);
date_default_timezone_set("America/New_York");

// Ensure admin authentication
if (!isset($_SESSION['access_level']) || $_SESSION['access_level'] < 1) {
    header('Location: login.php');
    die();
}

// Get current fiscal year
$currentMonth = date("m");
$currentYear = date("Y");
$fiscalYearStart = ($currentMonth >= 10) ? $currentYear : $currentYear -1;
$fiscalYearEnd = $fiscalYearStart + 1;

// Database connection and data fetching
require_once('database/dbinfo.php');
require_once('database/dbInventoryEvent.php');
require_once('database/dbItemCounts.php');
$conn = connect();

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

// Get the unique years from dates
$uniqueYears = array();
foreach($eventPairs as $index => $pair) {
    $year = date('Y', strtotime($pair["date"]));
    if(!in_array($year, $uniqueYears))
        $uniqueYears[] = $year;
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

// Fetch food categories for display (Active OR Inactive with data - Deleted is hidden from UI)
$categoryResult = $conn->query("
    SELECT DISTINCT dic.id, dic.name, dic.status
    FROM dbitemcategory dic
    WHERE dic.shopOnly = 0
      AND (dic.status = 'Active'
           OR (dic.status = 'Inactive' AND dic.id IN (
               SELECT DISTINCT itemCategoryId
               FROM dbitemcounts
           )))
    ORDER BY dic.name ASC
");
$categories = [];
if ($categoryResult) {
    while ($row = $categoryResult->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Fetch ALL category IDs with data (including Deleted) for "All" selections in trends/graphs
// Preserves historical data access while keeping deleted items hidden from UI
$allDataCategoryIds = [];
$allDataResult = $conn->query("
    SELECT DISTINCT dic.id
    FROM dbitemcategory dic
    WHERE dic.shopOnly = 0
      AND (dic.status = 'Active'
           OR dic.id IN (
               SELECT DISTINCT itemCategoryId
               FROM dbitemcounts
           ))
");
if ($allDataResult) {
    while ($row = $allDataResult->fetch_assoc()) {
        $allDataCategoryIds[] = $row['id'];
    }
}

// Get selected category for trend view
$selectedCategory = isset($_GET['category']) ? intval($_GET['category']) : (count($categories) > 0 ? $categories[0]['id'] : null);

// Fetch trend data for selected category (consolidated by date across locations)
$trendData = [];
if ($selectedCategory) {
    $sql = "
        SELECT 
            DATE(ie.date) as eventDate,
            dic.name as itemName,
            dic.itemsPerBox,
            COALESCE(SUM(dbic.quantity), 0) as total_boxes
        FROM dbinventoryevent ie
        LEFT JOIN dbitemcounts dbic ON ie.id = dbic.inventoryEventId
        LEFT JOIN dbitemcategory dic ON dbic.itemCategoryId = dic.id
        WHERE dic.id = ? AND dic.shopOnly = 0
        GROUP BY DATE(ie.date), dic.name, dic.itemsPerBox
        ORDER BY DATE(ie.date) ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $selectedCategory);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $trendData[] = $row;
    }
    $stmt->close();
}

// Fetch ALL items trend data for trends table (combines all events per date)
$allTrendData = [];
$sqlAll = "
    SELECT
        DATE(ie.date) as eventDate,
        dic.id as categoryId,
        dic.name as itemName,
        COALESCE(SUM(dbic.quantity), 0) as total_boxes
    FROM dbinventoryevent ie
    LEFT JOIN dbitemcounts dbic ON ie.id = dbic.inventoryEventId
    LEFT JOIN dbitemcategory dic ON dbic.itemCategoryId = dic.id
    WHERE dic.id IS NOT NULL AND dic.shopOnly = 0
    GROUP BY DATE(ie.date), dic.id, dic.name
    ORDER BY DATE(ie.date) ASC, dic.name ASC
";
$resultAll = $conn->query($sqlAll);
if ($resultAll) {
    while ($row = $resultAll->fetch_assoc()) {
        $allTrendData[] = $row;
    }
}

// Fetch graph data (latest event only per date)
$allGraphData = [];
$sqlGraph = "
    SELECT
        DATE(ie.date) as eventDate,
        dic.id as categoryId,
        dic.name as itemName,
        COALESCE(SUM(dbic.quantity), 0) as total_boxes
    FROM dbinventoryevent ie
    LEFT JOIN dbitemcounts dbic ON ie.id = dbic.inventoryEventId
    LEFT JOIN dbitemcategory dic ON dbic.itemCategoryId = dic.id
    WHERE dic.id IS NOT NULL AND dic.shopOnly = 0
    AND ie.id >= (
        SELECT MAX(ie2.id)
        FROM dbinventoryevent ie2
        WHERE DATE(ie2.date) = DATE(ie.date)
        AND ie2.location = 'Warehouse'
    )
    GROUP BY DATE(ie.date), dic.id, dic.name
    ORDER BY DATE(ie.date) ASC, dic.name ASC
";
$resultGraph = $conn->query($sqlGraph);
if ($resultGraph) {
    while ($row = $resultGraph->fetch_assoc()) {
        $allGraphData[] = $row;
    }
}

// Get monthly inventory totals
$monthlyData = get_monthly_inventory_totals();
$availableMonths = array_keys($monthlyData);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Inventory Analytics | CCDA</title>
    <link rel="icon" type="image/x-icon" href="images/ccda-logo-white.svg">
    <!--<script src="js/data-filters.js" defer></script>-->
    <link href="css/base.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <?php require_once('header.php'); ?>
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
        .low-stock-badge {
            display: inline-block;
            background-color: var(--error-toast-background-color);
            color: var(--error-toast-font-color);
            padding: 0.2rem 0.6rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .expired-text {
            color: var(--error-toast-background-color);
            font-weight: 600;
        }
        .chart-wrapper {
            position: relative;
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }
        .chart-controls {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .chart-controls button {
            padding: 0.4rem 1rem;
            border: 2px solid var(--accent-color);
            border-radius: 0.25rem;
            background-color: transparent;
            color: var(--page-font-color);
            cursor: pointer;
            font-weight: 500;
            width: auto;
            font-size: 0.85rem;
        }
        .chart-controls button.active,
        .chart-controls button:hover {
            background-color: var(--accent-color);
            color: var(--button-font-color);
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--inactive-font-color);
        }
        .week-selector {
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        .week-selector label {
            color: var(--page-font-color);
            font-weight: 500;
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
        .week-and-filter-section,
        .graph-week-and-filter-section{
            display: flex;
            flex-direction: row;
            gap: 2rem;
        }
        .report-type-select {
            padding: 0.6rem 1rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            cursor: pointer;
            min-width: 320px;
            width: auto;
            font-size: 1rem;
        }
        .report-type-select:hover {
            background-color: rgba(0,0,0,0.3);
        }
        .report-type-section {
            padding: 1rem 1.5rem;
            margin-bottom: 1rem;
        }
        .report-type-section .form-section {
            margin-bottom: 0;
        }
        .date-range-selector {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .date-select-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .date-select-group label {
            color: var(--page-font-color);
            font-weight: 500;
        }
        .date-select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            cursor: pointer;
            min-width: 160px;
            width: auto;
        }
        .date-select:hover {
            background-color: rgba(0,0,0,0.3);
        }
        .row-green {
            background-color: rgba(34, 197, 94, 0.15) !important;
        }
        .row-green:hover {
            background-color: rgba(34, 197, 94, 0.25) !important;
        }
        .row-yellow {
            background-color: rgba(234, 179, 8, 0.15) !important;
        }
        .row-yellow:hover {
            background-color: rgba(234, 179, 8, 0.25) !important;
        }
        .row-red {
            background-color: rgba(239, 68, 68, 0.15) !important;
        }
        .row-red:hover {
            background-color: rgba(239, 68, 68, 0.25) !important;
        }
        .basket-options {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .basket-row {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .basket-label {
            color: var(--page-font-color);
            width: 160px;
            flex-shrink: 0;
        }
        .basket-qty {
            width: 100px;
            padding: 0.4rem 0.6rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            font-size: 0.9rem;
        }
        .generate-btn {
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
        .generate-btn:hover {
            opacity: 0.85;
        }
        .form-section,
        .graph-form-section{
            margin-bottom: 1.5rem;
        }
        .form-section label,
        .graph-form-section label {
            display: block;
            color: var(--page-font-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .form-section select,
        .graph-form-section select {
            width: 100%;
            max-width: 300px;
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            cursor: pointer;
        }
        .form-section select:hover,
        .graph-form-section select:hover {
            background-color: rgba(0,0,0,0.3);
        }
        ul.checkbox {
            margin-left: 15px;
        }
        ul.checkbox li input {
            margin-right: .25rem;
        }
        ul.checkbox li {
            border: 1px transparent solid;
            display: inline-block;
            width: 12rem;
        }
        input[type="checkbox"] {
            width: 20px;
            height: 20px;
        }
        .category-trend-row {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        .change-percentage {
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            min-width: 80px;
            text-align: center;
        }
        .change-positive {
            background-color: rgba(34, 197, 94, 0.2);
            color: #22c55e;
        }
        .change-negative {
            background-color: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
        .change-neutral {
            background-color: rgba(156, 163, 175, 0.2);
            color: var(--page-font-color);
        }
        .row-number {
            text-align: center;
            color: var(--inactive-font-color);
            font-weight: 500;
            width: 50px;
        }
        .pagination {
            display: flex;
            gap: 0.5rem;
            margin-top: 1.5rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .pagination button {
            padding: 0.4rem 0.8rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            cursor: pointer;
            font-weight: 500;
        }
        .pagination button:hover {
            background-color: rgba(0,0,0,0.3);
        }
        .pagination button.active {
            background-color: var(--accent-color);
            color: var(--button-font-color);
            border-color: var(--accent-color);
        }
        .pagination-info {
            text-align: center;
            color: var(--page-font-color);
            margin-top: 1rem;
            font-size: 0.9rem;
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
                overflow-x: auto;
            }
            .report-section{
                padding: 0;
            }
        }
    </style>
</head>
<pageheader>
    <h1 class="title">Inventory Analytics</h1>
</pageheader>
<body>
    <main>
        <div class="report-container">

            <!-- Report Type Selector -->
            <div class="report-section report-type-section">
                <div class="form-section">
                    <label for="reportTypeSelect">Select Report Type</label>
                    <select id="reportTypeSelect" class="report-type-select">
                        <option value="export">Export Inventory to Spreadsheet</option>
                        <option value="trends">Trends</option>
                        <option value="graphs">Graphs</option>
                        <option value="monthly">Monthly Summaries</option>
                    </select>
                </div>
            </div>

            <!-- Export Section -->
            <div class="report-section" id="exportSection">
                <h2>Export Inventory to Spreadsheet</h2>
                
                <form method="POST" action="processInventoryReport.php">
                    <div class="week-and-filter-section">
                        <div class="form-section">
                            <label for="weekSelect">Select Date to Export</label>
                            <select name="week" id="weekSelect" required>
                                <?php foreach ($eventPairs as $pair): ?>
                                    <?php $pairId = $pair['warehouseId'] ?? $pair['pantryId']; ?>
                                    <option value="<?= htmlspecialchars($pairId) ?>">
                                        <?= date('m/d/Y', strtotime($pair['date'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-section">
                            <label for="yearSelect" style="font-weight: 500;">Filter Inventories:</label>
                            <select id="yearSelect" name="year" class="toolbar-select" onchange="filterWeekSelect(this, 'weekSelect');">
                                <option value="Most Recent">
                                    Most Recent 
                                </option>
                                <optgroup label="By Year">
                                <?php foreach ($uniqueYears as $year): ?>
                                    <option value="<?= $year ?>">
                                        <?= $year ?>
                                    </option>
                                <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <div class="form-section">
                        <label for="itemSelect">Item Categories</label>
                        <ul class="checkbox">
                                <li> <input name="name[]" class="checkbox" type="checkbox" value="">All</input> </li>
                                <?php foreach ($categories as $category): ?>
                                    <li> <input name="name[]" class="checklist-column" type="checkbox" value="<?= htmlspecialchars($category['id']) ?>" <?= ($category['id'] == $selectedCategory) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($category['name']) ?>
                                        <?php if ($category['status'] != 'Active'): ?>
                                            <span style="color: gray; font-size: 0.85em;">(inactive)</span>
                                        <?php endif; ?>
                                </input> </li>
                                <?php endforeach; ?>
                            </select>
                        </ul>
                    </div>



                    <div class="form-section">
                        <label for="format">File Format</label>
                        <select name="format" id="format">
                            <option value="xlsx">Excel (.xlsx)</option>
                            <!-- <option value="excel">Excel (.xls)</option> -->
                            <option value="csv">CSV (.csv)</option>
                        </select>
                    </div>

                    <div style="margin-top: 2rem;">
                        <input type="hidden" value="<?php echo $_SESSION['_id']; ?>" name="admin" id="admin">
                        <input type="hidden" value="<?php echo date("m/d/Y H:i:s e") ?>" name="time" id="time">
                        <button type="submit" name="generate_button" class="generate-btn">Export to Spreadsheet</button>
                    </div>
                </form>
            </div>

            <!-- Category Trend Section -->
            <div class="report-section" id="trendsSection" style="display: none;">
                <h2>Food Item Trends</h2>
                
                <div class="form-section">
                    <label>Select Food Item Categories</label>
                    <ul class="checkbox">
                        <li><input type="checkbox" id="trendsSelectAll" class="trend-category-checkbox" value="all">All</input></li>
                        <?php foreach ($categories as $category): ?>
                            <li><input type="checkbox" class="trend-category-checkbox trend-category-single" value="<?= htmlspecialchars($category['id']) ?>">
                                <?= htmlspecialchars($category['name']) ?>
                                <?php if ($category['status'] != 'Active'): ?>
                                    <span style="color: gray; font-size: 0.85em;">(inactive)</span>
                                <?php endif; ?>
                            </input></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="form-section">
                    <button type="button" id="showTrendsBtn" class="generate-btn">Show Trends</button>
                </div>

                <div class="form-section" id="trendsExportSection" style="display: none;">
                    <label for="trendsExportFormat">Export Format</label>
                    <select name="trendsExportFormat" id="trendsExportFormat">
                        <option value="xlsx">Excel (.xlsx)</option>
                        <option value="pdf">PDF (.pdf)</option>
                    </select>
                </div>

                <div class="form-section" id="trendsExportButtonSection" style="display: none;">
                    <button type="button" id="trendsExportBtn" class="generate-btn">Export Trends</button>
                </div>

                <div class="table-wrapper">
                    <table class="report-table" id="trendTable">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Total Boxes</th>
                                <th>Change</th>
                                <th>% Change</th>
                            </tr>
                        </thead>
                        <tbody id="trendTableBody">
                            <tr>
                                <td colspan="6" class="empty-state">Select categories and click "Show Trends" to view data.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="pagination" id="trendPagination"></div>
                <div class="pagination-info" id="trendPaginationInfo"></div>
            </div>

            <!-- Graphs Section -->
            <div class="report-section" id="graphsSection" style="display: none;">
                <h2>Graphs</h2>

                <!-- Date Range Selector -->
                <div class="form-section">
                    <label>Select Date Range</label>
                </div>

                <div class="graph-week-and-filter-section">
                    <div class="graph-form-section">
                        <label for="graphFromDate">From:</label>
                        <select name="week" id="graphFromDate" required>
                            <?php 
                                $count = 0;
                                foreach ($eventPairs as $pair): ?>
                                <?php 
                                    $count++;
                                    $pairId = $pair['warehouseId'] ?? $pair['pantryId']; ?>
                                <option 
                                    value="<?= htmlspecialchars($pair['date']) ?>" 
                                    <?php if($count == count($eventPairs)) echo " selected "; ?> 
                                >
                                    <?= date('m/d/Y', strtotime($pair['date'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="graph-form-section">
                        <label for="yearSelect" style="font-weight: 500;">Filter Inventories:</label>
                        <select id="yearSelect" name="year" class="toolbar-select" onchange="filterWeekSelect(this, 'graphFromDate');">
                            <option value="Most Recent">
                                Most Recent 
                            </option>
                            <optgroup label="By Year">
                            <?php foreach ($uniqueYears as $year): ?>
                                <option value="<?= $year ?>">
                                    <?= $year ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                </div>
                <div class="graph-week-and-filter-section">
                    <div class="form-section">
                        <label for="graphToDate">To: </label>
                        <select name="week" id="graphToDate" required>
                            <?php foreach ($eventPairs as $pair): ?>
                                <?php $pairId = $pair['warehouseId'] ?? $pair['pantryId']; ?>
                                <option value="<?= htmlspecialchars($pair['date']) ?>">
                                    <?= date('m/d/Y', strtotime($pair['date'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="graph-form-section">
                        <label for="yearSelect" style="font-weight: 500;">Filter Inventories:</label>
                        <select id="yearSelect" name="year" class="toolbar-select" onchange="filterWeekSelect(this, 'graphToDate');">
                            <option value="Most Recent">
                                Most Recent 
                            </option>
                            <optgroup label="By Year">
                            <?php foreach ($uniqueYears as $year): ?>
                                <option value="<?= $year ?>">
                                    <?= $year ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                </div>

                <!-- Item Selector -->
                <div class="form-section">
                    <label>Select Items</label>
                    <ul class="checkbox">
                        <li><input type="checkbox" id="graphSelectAll" class="graph-item-checkbox" value="all">All</input></li>
                        <?php foreach ($categories as $category): ?>
                            <li><input type="checkbox" class="graph-item-checkbox graph-item-single" value="<?= htmlspecialchars($category['id']) ?>">
                                <?= htmlspecialchars($category['name']) ?>
                                <?php if ($category['status'] != 'Active'): ?>
                                    <span style="color: gray; font-size: 0.85em;">(inactive)</span>
                                <?php endif; ?>
                            </input></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Show Graph Button -->
                <div class="form-section">
                    <button type="button" id="showGraphBtn" class="generate-btn">Show Graph</button>
                </div>

                <!-- Graph Canvas -->
                <div class="form-section" id="graphContainer" style="display: none;">
                    <canvas id="inventoryChart"></canvas>
                    <div style="margin-top: 1rem;">
                        <button type="button" id="downloadPdfBtn" class="generate-btn">Export to PDF</button>
                    </div>
                </div>
            </div>

            <!-- Monthly Summaries Section -->
            <div class="report-section" id="monthlySection" style="display: none;">
                <h2>Monthly Summaries</h2>

                <div class="form-section">
                    <label for="monthSelect">Select Month</label>
                    <select id="monthSelect" style="padding: 0.5rem 0.75rem; border: 1px solid var(--shadow-and-border-color); border-radius: 0.25rem; background-color: rgba(0,0,0,0.2); color: var(--page-font-color); cursor: pointer; width: fit-content;">
                        <?php foreach ($availableMonths as $month): ?>
                            <option value="<?= htmlspecialchars($month) ?>">
                                <?= date('F Y', strtotime($month . '-01')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="table-wrapper">
                    <table class="report-table" id="monthlyTable" style="max-width: 500px;">
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Item Name</th>
                                <th style="width: 100px;">Total Boxes</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <div class="form-section" style="margin-top: 1rem;">
                    <button type="button" id="downloadMonthlyPdfBtn" class="generate-btn">Export to PDF</button>
                </div>
            </div>

        </div>

    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var reportTypeSelect = document.getElementById('reportTypeSelect');
            var exportSection = document.getElementById('exportSection');
            var trendsSection = document.getElementById('trendsSection');
            var graphsSection = document.getElementById('graphsSection');
            var monthlySection = document.getElementById('monthlySection');

            function showSelectedSection() {
                var selected = reportTypeSelect.value;

                // Hide all sections
                exportSection.style.display = 'none';
                trendsSection.style.display = 'none';
                graphsSection.style.display = 'none';
                monthlySection.style.display = 'none';

                // Show selected section
                if (selected === 'export') {
                    exportSection.style.display = 'block';
                } else if (selected === 'trends') {
                    trendsSection.style.display = 'block';
                } else if (selected === 'graphs') {
                    graphsSection.style.display = 'block';
                } else if (selected === 'monthly') {
                    monthlySection.style.display = 'block';
                }
            }

            // Initial display
            showSelectedSection();

            // Listen for changes
            reportTypeSelect.addEventListener('change', showSelectedSection);

            // Graph "All" checkbox handling
            var graphSelectAll = document.getElementById('graphSelectAll');
            var graphItemCheckboxes = document.querySelectorAll('.graph-item-single');

            graphSelectAll.addEventListener('change', function() {
                if (this.checked) {
                    var confirmed = confirm('Are you sure you want to select all items? This may make the graph harder to read.');
                    if (!confirmed) {
                        this.checked = false;
                    } else {
                        // Uncheck individual items when "All" is selected
                        graphItemCheckboxes.forEach(function(cb) {
                            cb.checked = false;
                        });
                    }
                }
            });

            // Uncheck "All" if any individual item is checked
            graphItemCheckboxes.forEach(function(cb) {
                cb.addEventListener('change', function() {
                    if (this.checked) {
                        graphSelectAll.checked = false;
                    }
                });
            });

            // All trend data from PHP
            var allTrendData = <?= json_encode($allTrendData) ?>;
            var allGraphData = <?= json_encode($allGraphData) ?>;
            var categoriesList = <?= json_encode($categories) ?>;
            var allDataCategoryIds = <?= json_encode($allDataCategoryIds) ?>;
            var trendsCurrentPage = 1;
            var trendsPageSize = 50;

            // Chart instance
            var inventoryChart = null;

            // Trends "All" checkbox handling
            var trendsSelectAll = document.getElementById('trendsSelectAll');
            var trendsCategoryCheckboxes = document.querySelectorAll('.trend-category-single');

            if (trendsSelectAll) {
                trendsSelectAll.addEventListener('change', function() {
                    trendsCategoryCheckboxes.forEach(function(cb) {
                        cb.checked = this.checked;
                    }.bind(this));
                });

                trendsCategoryCheckboxes.forEach(function(cb) {
                    cb.addEventListener('change', function() {
                        var allChecked = Array.from(trendsCategoryCheckboxes).every(c => c.checked);
                        trendsSelectAll.checked = allChecked;
                    });
                });

                // Show Trends button handler
                document.getElementById('showTrendsBtn').addEventListener('click', function() {
                    var selectedCategories = [];
                    // If "All" is checked, use all category IDs with data (includes deleted items' historical data)
                    if (trendsSelectAll.checked) {
                        selectedCategories = allDataCategoryIds.map(function(id) { return id.toString(); });
                    } else {
                        trendsCategoryCheckboxes.forEach(function(cb) {
                            if (cb.checked) {
                                selectedCategories.push(cb.value);
                            }
                        });
                    }

                    if (selectedCategories.length === 0) {
                        alert('Please select at least one category.');
                        return;
                    }

                    trendsCurrentPage = 1;
                    displayTrendsPage(selectedCategories);
                    
                    // Show export sections
                    document.getElementById('trendsExportSection').style.display = 'block';
                    document.getElementById('trendsExportButtonSection').style.display = 'block';
                });

                function displayTrendsPage(selectedCategories) {
                    var filteredData = allTrendData.filter(function(d) {
                        return selectedCategories.includes(d.categoryId.toString());
                    });

                    if (filteredData.length === 0) {
                        document.getElementById('trendTableBody').innerHTML = '<tr><td colspan="6" class="empty-state">No data available for selected categories.</td></tr>';
                        document.getElementById('trendPagination').innerHTML = '';
                        document.getElementById('trendPaginationInfo').innerHTML = '';
                        return;
                    }

                    // Sort by categoryId (ascending) then by date (ascending)
                    filteredData.sort(function(a, b) {
                        var categoryCompare = parseInt(a.categoryId) - parseInt(b.categoryId);
                        if (categoryCompare !== 0) {
                            return categoryCompare;
                        }
                        return a.eventDate.localeCompare(b.eventDate);
                    });

                    // Build previous values map for percentage calculation by category
                    var previousValueMap = {};
                    for (var i = 0; i < filteredData.length; i++) {
                        var d = filteredData[i];
                        var key = d.categoryId + '_' + d.eventDate;
                        if (i > 0 && parseInt(filteredData[i - 1].categoryId) === parseInt(d.categoryId)) {
                            previousValueMap[key] = parseInt(filteredData[i - 1].total_boxes);
                        } else {
                            previousValueMap[key] = null;
                        }
                    }

                    // Paginate
                    var totalPages = Math.ceil(filteredData.length / trendsPageSize);
                    var startIndex = (trendsCurrentPage - 1) * trendsPageSize;
                    var endIndex = startIndex + trendsPageSize;
                    var pageData = filteredData.slice(startIndex, endIndex);

                    // Build table rows
                    var tbody = document.getElementById('trendTableBody');
                    tbody.innerHTML = '';
                    pageData.forEach(function(row, index) {
                        var rowNum = startIndex + index + 1;
                        var key = row.categoryId + '_' + row.eventDate;
                        var previousBoxes = previousValueMap[key];
                        var currentBoxes = parseInt(row.total_boxes);
                        var changeBoxes = (previousBoxes !== null) ? (currentBoxes - previousBoxes) : 0;
                        var percentChange = (previousBoxes !== null && previousBoxes > 0) ? ((changeBoxes / previousBoxes) * 100) : 0;

                        var dateObj = new Date(row.eventDate + 'T00:00:00');
                        var dateStr = (dateObj.getMonth() + 1) + '/' + dateObj.getDate() + '/' + dateObj.getFullYear();

                        var changeClass = 'change-neutral';
                        if (changeBoxes > 0) changeClass = 'change-positive';
                        else if (changeBoxes < 0) changeClass = 'change-negative';

                        var tr = document.createElement('tr');
                        tr.innerHTML = '<td class="row-number">' + rowNum + '</td>' +
                                       '<td>' + dateStr + '</td>' +
                                       '<td>' + row.itemName + '</td>' +
                                       '<td>' + currentBoxes + '</td>' +
                                       '<td>' + ((previousBoxes !== null) ? (changeBoxes >= 0 ? '+' : '') + changeBoxes : '—') + '</td>' +
                                       '<td><span class="change-percentage ' + changeClass + '">' +
                                       ((previousBoxes !== null) ? (changeBoxes >= 0 ? '+' : '') + percentChange.toFixed(1) + '%' : '—') +
                                       '</span></td>';
                        tbody.appendChild(tr);
                    });

                    // Build pagination controls
                    var paginationDiv = document.getElementById('trendPagination');
                    paginationDiv.innerHTML = '';

                    if (totalPages > 1) {
                        if (trendsCurrentPage > 1) {
                            var prevBtn = document.createElement('button');
                            prevBtn.textContent = '< Previous';
                            prevBtn.onclick = function() {
                                trendsCurrentPage--;
                                displayTrendsPage(selectedCategories);
                            };
                            paginationDiv.appendChild(prevBtn);
                        }

                        for (var i = 1; i <= totalPages; i++) {
                            var btn = document.createElement('button');
                            btn.textContent = i;
                            if (i === trendsCurrentPage) {
                                btn.classList.add('active');
                            }
                            btn.onclick = function(pageNum) {
                                return function() {
                                    trendsCurrentPage = pageNum;
                                    displayTrendsPage(selectedCategories);
                                };
                            }(i);
                            paginationDiv.appendChild(btn);
                        }

                        if (trendsCurrentPage < totalPages) {
                            var nextBtn = document.createElement('button');
                            nextBtn.textContent = 'Next >';
                            nextBtn.onclick = function() {
                                trendsCurrentPage++;
                                displayTrendsPage(selectedCategories);
                            };
                            paginationDiv.appendChild(nextBtn);
                        }
                    }

                    // Update pagination info
                    var infoDiv = document.getElementById('trendPaginationInfo');
                    if (filteredData.length > 0) {
                        infoDiv.textContent = 'Showing ' + (startIndex + 1) + ' to ' + Math.min(endIndex, filteredData.length) + ' of ' + filteredData.length + ' entries';
                    } else {
                        infoDiv.textContent = '';
                    }
                }

                // Export Trends to XLSX
                function exportTrendsXlsx(selectedCategories) {
                    // Check if XLSX is available
                    if (typeof XLSX === 'undefined') {
                        alert('Excel export library is loading. Please try again in a moment.');
                        console.error('XLSX library not available');
                        return;
                    }

                    var filteredData = allTrendData.filter(function(d) {
                        return selectedCategories.includes(d.categoryId.toString());
                    });

                    if (filteredData.length === 0) {
                        alert('No data to export. Please select categories and display trends first.');
                        return;
                    }

                    try {
                        // Sort by categoryId then by date (same as display)
                        filteredData.sort(function(a, b) {
                            var categoryCompare = parseInt(a.categoryId) - parseInt(b.categoryId);
                            if (categoryCompare !== 0) {
                                return categoryCompare;
                            }
                            return a.eventDate.localeCompare(b.eventDate);
                        });

                        // Build previous values map
                        var previousValueMap = {};
                        for (var i = 0; i < filteredData.length; i++) {
                            var d = filteredData[i];
                            var key = d.categoryId + '_' + d.eventDate;
                            if (i > 0 && parseInt(filteredData[i - 1].categoryId) === parseInt(d.categoryId)) {
                                previousValueMap[key] = parseInt(filteredData[i - 1].total_boxes);
                            } else {
                                previousValueMap[key] = null;
                            }
                        }

                        // Prepare data for export
                        var exportData = [];
                        exportData.push(['#', 'Date', 'Category', 'Total Boxes', 'Change', '% Change']);

                        filteredData.forEach(function(row, index) {
                            var key = row.categoryId + '_' + row.eventDate;
                            var previousBoxes = previousValueMap[key];
                            var currentBoxes = parseInt(row.total_boxes);
                            var changeBoxes = (previousBoxes !== null) ? (currentBoxes - previousBoxes) : 0;
                            var percentChange = (previousBoxes !== null && previousBoxes > 0) ? ((changeBoxes / previousBoxes) * 100) : 0;

                            var dateObj = new Date(row.eventDate + 'T00:00:00');
                            var dateStr = (dateObj.getMonth() + 1) + '/' + dateObj.getDate() + '/' + dateObj.getFullYear();

                            exportData.push([
                                index + 1,
                                dateStr,
                                row.itemName,
                                currentBoxes,
                                (previousBoxes !== null) ? (changeBoxes >= 0 ? '+' : '') + changeBoxes : '—',
                                (previousBoxes !== null) ? (changeBoxes >= 0 ? '+' : '') + percentChange.toFixed(1) + '%' : '—'
                            ]);
                        });

                        // Create workbook
                        var ws = XLSX.utils.aoa_to_sheet(exportData);
                        var wb = XLSX.utils.book_new();
                        XLSX.utils.book_append_sheet(wb, ws, 'Trends');

                        // Set column widths
                        ws['!cols'] = [
                            { wch: 5 },   // #
                            { wch: 12 },  // Date
                            { wch: 20 },  // Category
                            { wch: 13 },  // Total Boxes
                            { wch: 10 },  // Change
                            { wch: 12 }   // % Change
                        ];

                        // Generate filename with current date
                        var now = new Date();
                        var filename = 'Food_Trends_' + (now.getMonth() + 1) + '-' + now.getDate() + '-' + now.getFullYear() + '.xlsx';

                        // Download file
                        XLSX.writeFile(wb, filename);
                    } catch (e) {
                        alert('Error exporting to Excel: ' + e.message);
                        console.error('Excel export error:', e);
                    }
                }

                // Export Trends to PDF
                function exportTrendsPdf(selectedCategories) {
                    var filteredData = allTrendData.filter(function(d) {
                        return selectedCategories.includes(d.categoryId.toString());
                    });

                    if (filteredData.length === 0) {
                        alert('No data to export. Please select categories and display trends first.');
                        return;
                    }

                    // Sort by categoryId then by date (same as display)
                    filteredData.sort(function(a, b) {
                        var categoryCompare = parseInt(a.categoryId) - parseInt(b.categoryId);
                        if (categoryCompare !== 0) {
                            return categoryCompare;
                        }
                        return a.eventDate.localeCompare(b.eventDate);
                    });

                    // Build previous values map
                    var previousValueMap = {};
                    for (var i = 0; i < filteredData.length; i++) {
                        var d = filteredData[i];
                        var key = d.categoryId + '_' + d.eventDate;
                        if (i > 0 && parseInt(filteredData[i - 1].categoryId) === parseInt(d.categoryId)) {
                            previousValueMap[key] = parseInt(filteredData[i - 1].total_boxes);
                        } else {
                            previousValueMap[key] = null;
                        }
                    }

                    // Create PDF
                    var { jsPDF } = window.jspdf;
                    var doc = new jsPDF('portrait', 'mm', 'a4');
                    
                    // Add title
                    doc.setFontSize(16);
                    doc.text('Food Item Trends Report', 14, 15);
                    
                    // Add date and categories info
                    doc.setFontSize(10);
                    var now = new Date();
                    var dateStr = (now.getMonth() + 1) + '/' + now.getDate() + '/' + now.getFullYear();
                    doc.text('Generated: ' + dateStr, 14, 22);
                    
                    // Add table headers
                    var yPos = 35;
                    doc.setFont(undefined, 'bold');
                    doc.text('#', 14, yPos);
                    doc.text('Date', 25, yPos);
                    doc.text('Category', 50, yPos);
                    doc.text('Boxes', 110, yPos);
                    doc.text('Change', 135, yPos);
                    doc.text('% Change', 160, yPos);
                    
                    doc.setFont(undefined, 'normal');
                    yPos += 8;
                    
                    // Add data rows
                    filteredData.forEach(function(row, index) {
                        // Check if we need a new page
                        if (yPos > 270) {
                            doc.addPage();
                            yPos = 20;
                            // Re-add headers on new page
                            doc.setFont(undefined, 'bold');
                            doc.text('#', 14, yPos);
                            doc.text('Date', 25, yPos);
                            doc.text('Category', 50, yPos);
                            doc.text('Boxes', 110, yPos);
                            doc.text('Change', 135, yPos);
                            doc.text('% Change', 160, yPos);
                            doc.setFont(undefined, 'normal');
                            yPos += 8;
                        }
                        
                        var key = row.categoryId + '_' + row.eventDate;
                        var previousBoxes = previousValueMap[key];
                        var currentBoxes = parseInt(row.total_boxes);
                        var changeBoxes = (previousBoxes !== null) ? (currentBoxes - previousBoxes) : 0;
                        var percentChange = (previousBoxes !== null && previousBoxes > 0) ? ((changeBoxes / previousBoxes) * 100) : 0;

                        var dateObj = new Date(row.eventDate + 'T00:00:00');
                        var datePdf = (dateObj.getMonth() + 1) + '/' + dateObj.getDate() + '/' + dateObj.getFullYear();
                        
                        doc.text(String(index + 1), 14, yPos);
                        doc.text(datePdf, 25, yPos);
                        doc.text(row.itemName.substring(0, 20), 50, yPos);
                        doc.text(String(currentBoxes), 110, yPos);
                        doc.text((previousBoxes !== null) ? (changeBoxes >= 0 ? '+' : '') + changeBoxes : '—', 135, yPos);
                        doc.text((previousBoxes !== null) ? (changeBoxes >= 0 ? '+' : '') + percentChange.toFixed(1) + '%' : '—', 160, yPos);
                        
                        yPos += 7;
                    });
                    
                    // Generate filename
                    var filename = 'Food_Trends_' + (now.getMonth() + 1) + '-' + now.getDate() + '-' + now.getFullYear() + '.pdf';
                    doc.save(filename);
                }

                // Add event listeners for export buttons
                var trendsExportBtn = document.getElementById('trendsExportBtn');
                var trendsExportFormat = document.getElementById('trendsExportFormat');

                if (trendsExportBtn) {
                    trendsExportBtn.addEventListener('click', function() {
                        var selectedCategories = [];
                        trendsCategoryCheckboxes.forEach(function(cb) {
                            if (cb.checked && cb.value !== 'all') {
                                selectedCategories.push(cb.value);
                            }
                        });

                        if (selectedCategories.length === 0) {
                            alert('Please select at least one category.');
                            return;
                        }

                        var format = trendsExportFormat.value;
                        if (format === 'xlsx') {
                            exportTrendsXlsx(selectedCategories);
                        } else if (format === 'pdf') {
                            exportTrendsPdf(selectedCategories);
                        }
                    });
                }
            }

            // Show Graph button handler
            document.getElementById('showGraphBtn').addEventListener('click', function() {
                var fromDate = document.getElementById('graphFromDate').value;
                var toDate = document.getElementById('graphToDate').value;
                var selectAll = document.getElementById('graphSelectAll').checked;
                var selectedItems = [];

                if(fromDate > toDate){
                    fromDate = document.getElementById('graphToDate').value;
                    toDate = document.getElementById('graphFromDate').value;
                }

                if (selectAll) {
                    // Use all category IDs with data (includes deleted items' historical data)
                    selectedItems = allDataCategoryIds.map(function(id) { return id.toString(); });
                } else {
                    graphItemCheckboxes.forEach(function(cb) {
                        if (cb.checked) {
                            selectedItems.push(cb.value);
                        }
                    });
                }

                if (selectedItems.length === 0) {
                    alert('Please select at least one item.');
                    return;
                }

                // Filter graph data by date range and selected items
                var filteredData = allGraphData.filter(function(d) {
                    var dateMatch = d.eventDate >= fromDate && d.eventDate <= toDate;
                    var itemMatch = selectedItems.includes(d.categoryId.toString());
                    return dateMatch && itemMatch;
                });

                // Get unique dates for x-axis
                var dates = [...new Set(filteredData.map(function(d) { return d.eventDate; }))].sort();

                // Generate colors dynamically based on number of items
                function generateColors(count) {
                    var colors = [];
                    for (var i = 0; i < count; i++) {
                        var hue = (i * 360 / count) % 360;
                        colors.push('hsl(' + hue + ', 70%, 50%)');
                    }
                    return colors;
                }

                var colors = generateColors(selectedItems.length);
                var datasets = [];
                var colorIndex = 0;

                selectedItems.forEach(function(itemId) {
                    var itemData = filteredData.filter(function(d) { return d.categoryId.toString() === itemId; });
                    if (itemData.length > 0) {
                        var itemName = itemData[0].itemName;
                        var dataPoints = dates.map(function(date) {
                            var found = itemData.find(function(d) { return d.eventDate === date; });
                            return found ? parseInt(found.total_boxes) : 0;
                        });

                        datasets.push({
                            label: itemName,
                            data: dataPoints,
                            borderColor: colors[colorIndex],
                            backgroundColor: colors[colorIndex].replace('50%)', '50%, 0.2)').replace('hsl', 'hsla'),
                            tension: 0.3,
                            fill: false,
                            pointRadius: 0,
                            pointHoverRadius: 0,
                            spanGaps: true
                        });
                        colorIndex++;
                    }
                });

                // Show graph container
                document.getElementById('graphContainer').style.display = 'block';

                // Destroy previous chart if exists
                if (inventoryChart) {
                    inventoryChart.destroy();
                }

                // Create chart
                var ctx = document.getElementById('inventoryChart').getContext('2d');
                inventoryChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: dates.map(function(d) {
                            // Parse date string directly to avoid timezone issues
                            var parts = d.split('-');
                            return parts[1] + '/' + parts[2] + '/' + parts[0];
                        }),
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            title: {
                                display: true,
                                text: 'Graph'
                            },
                            legend: {
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    pointStyle: 'line'
                                }
                            },
                            tooltip: {
                                enabled: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Total Boxes'
                                },
                                ticks: {
                                    stepSize: 10,
                                    callback: function(value) {
                                        return value;
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Date'
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                }
                            }
                        }
                    }
                });
            });

            // PDF Download
            document.getElementById('downloadPdfBtn').addEventListener('click', function() {
                if (!inventoryChart) {
                    alert('Please generate a graph first.');
                    return;
                }

                var { jsPDF } = window.jspdf;
                var pdf = new jsPDF('landscape', 'mm', 'a4');

                // Title
                pdf.setFontSize(18);
                pdf.text('Graph', 14, 15);

                // Date range info
                pdf.setFontSize(11);
                var fromDate = document.getElementById('graphFromDate');
                var toDate = document.getElementById('graphToDate');

                if(fromDate.value > toDate.value){
                    fromDate = document.getElementById('graphToDate');
                    toDate = document.getElementById('graphFromDate');
                }

                var fromText = fromDate.options[fromDate.selectedIndex].text;
                var toText = toDate.options[toDate.selectedIndex].text;
                pdf.text('Date Range: ' + fromText + ' to ' + toText, 14, 25);
                pdf.text('Generated: ' + new Date().toLocaleDateString(), 14, 32);

                // Get chart as image
                var chartImage = inventoryChart.toBase64Image();
                pdf.addImage(chartImage, 'PNG', 14, 40, 270, 140);

                // Save
                pdf.save('graph_' + new Date().toISOString().slice(0,10).replace(/-/g, '_') + '.pdf');
            });

            // Monthly summaries
            var monthlyData = <?= json_encode($monthlyData) ?>;

            function updateMonthlyTable() {
                var selectedMonth = document.getElementById('monthSelect').value;
                var tbody = document.querySelector('#monthlyTable tbody');
                tbody.innerHTML = '';

                if (monthlyData[selectedMonth]) {
                    var items = Object.values(monthlyData[selectedMonth]);
                    items.sort(function(a, b) {
                        return a.itemName.localeCompare(b.itemName);
                    });

                    items.forEach(function(item, index) {
                        var row = '<tr><td class="row-number">' + (index + 1) + '</td>' +
                                  '<td>' + item.itemName + '</td>' +
                                  '<td>' + item.total_boxes + '</td></tr>';
                        tbody.innerHTML += row;
                    });
                }
            }

            var monthSelect = document.getElementById('monthSelect');
            if (monthSelect) {
                monthSelect.addEventListener('change', updateMonthlyTable);
                updateMonthlyTable();
            }

            // Monthly PDF export
            document.getElementById('downloadMonthlyPdfBtn').addEventListener('click', function() {
                var selectedMonth = document.getElementById('monthSelect').value;
                var monthText = document.getElementById('monthSelect').options[document.getElementById('monthSelect').selectedIndex].text;

                if (!monthlyData[selectedMonth]) {
                    alert('No data available for this month.');
                    return;
                }

                var { jsPDF } = window.jspdf;
                var pdf = new jsPDF('portrait', 'mm', 'a4');

                pdf.setFontSize(18);
                pdf.text('Monthly Inventory Summary', 14, 15);

                pdf.setFontSize(11);
                pdf.text('Month: ' + monthText, 14, 25);
                pdf.text('Generated: ' + new Date().toLocaleDateString(), 14, 32);

                var items = Object.values(monthlyData[selectedMonth]);
                items.sort(function(a, b) {
                    return a.itemName.localeCompare(b.itemName);
                });

                var yPos = 45;
                pdf.setFontSize(10);
                pdf.setFont(undefined, 'bold');
                pdf.text('#', 14, yPos);
                pdf.text('Item Name', 25, yPos);
                pdf.text('Total Boxes', 120, yPos);
                pdf.setFont(undefined, 'normal');

                yPos += 8;
                items.forEach(function(item, index) {
                    if (yPos > 270) {
                        pdf.addPage();
                        yPos = 20;
                    }
                    pdf.text(String(index + 1), 14, yPos);
                    pdf.text(item.itemName, 25, yPos);
                    pdf.text(String(item.total_boxes), 120, yPos);
                    yPos += 7;
                });

                var filename = 'monthly_summary_' + selectedMonth + '.pdf';
                pdf.save(filename);
            });
        });
    </script>
    <script>
        //Filter the dropdown list of weeks
        function filterWeekSelect(element, selectId){
            var weekSelect = document.getElementById(selectId);
            const filterSelect = element.value;
            var firstMatch = true;

            for (let i = 0; i < weekSelect.options.length; i++) {
                const option = weekSelect.options[i];

                //unselect all options
                option.selected = false;

                //if filter is Most Recent, only show last 30 Inventories
                if(filterSelect== "Most Recent"){
                    if(i < 30){
                        option.style.display = "block";

                        //select first match
                        if(firstMatch){
                            option.selected = true;
                            firstMatch = false;
                        }
                    }
                    else{
                        option.style.display = "none";
                    }
                }
                // If filter is a year compare to all items and only show the ones that match
                else{
                    const year = new Date(option.text).getFullYear();
                    if(year == filterSelect){
                        option.style.display = "block";

                        //select first match
                        if(firstMatch){
                            option.selected = true;
                            firstMatch = false;
                        }
                    }
                    else{
                        option.style.display = "none";
                    }
                }
            }
        }
    </script>

    <p id="demo"></p>

</body>
</html>

