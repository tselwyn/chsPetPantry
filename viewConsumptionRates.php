<?php
    session_cache_expire(30);
    session_start();

    
    // Handle AJAX client count save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'saveClient') {
        require_once('database/dbinfo.php');
        require_once('database/dbClient.php');
        $personId   = isset($_SESSION['_id']) ? $_SESSION['_id'] : 0;
        $familySize = isset($_POST['familySize']) ? trim($_POST['familySize']) : '';
        $numClients = isset($_POST['numClients']) ? (float)$_POST['numClients'] : -1;
        $date       = date('Y-m-d');
        header('Content-Type: application/json');
        if (!empty($familySize) && $numClients >= 0) {
            $con    = connect();
            $query  = 'SELECT id FROM dbshoppingevent WHERE familySize = "' . mysqli_real_escape_string($con, $familySize) . '" ORDER BY date DESC, id DESC LIMIT 1';
            $result = mysqli_query($con, $query);
            if ($result && mysqli_num_rows($result) > 0) {
                $row             = mysqli_fetch_assoc($result);
                $shoppingEventId = $row['id'];
                mysqli_close($con);
                $id = add_client($shoppingEventId, $personId, $numClients, $date);
                echo json_encode(['success' => $id > 0, 'id' => $id]);
            } else {
                mysqli_close($con);
                echo json_encode(['success' => false, 'error' => 'No shopping event found for family size: ' . htmlspecialchars($familySize)]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
        }
        exit;
    }

    // Handle AJAX consumption rate save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'saveConsumption') {
        require_once('database/dbinfo.php');
        require_once('database/dbConsumption.php');
        $personId = isset($_SESSION['_id']) ? $_SESSION['_id'] : 0;
        $records  = isset($_POST['records']) ? json_decode($_POST['records'], true) : [];
        header('Content-Type: application/json');
        if (!empty($records) && is_array($records)) {
            $saved = 0;
            foreach ($records as $rec) {
                $shoppingEventId = isset($rec['shoppingEventId']) ? (int)$rec['shoppingEventId'] : 0;
                $itemCategoryId  = isset($rec['itemCategoryId'])  ? (int)$rec['itemCategoryId']  : 0;
                $itemsConsumed   = isset($rec['consumptionRate']) ? (float)$rec['consumptionRate'] : 0;
                $date            = isset($rec['date'])            ? trim($rec['date'])            : '';
                if ($shoppingEventId > 0 && $itemCategoryId > 0 && !empty($date)) {
                    add_consumption($shoppingEventId, $itemCategoryId, $itemsConsumed, $personId, $date);
                    $saved++;
                }
            }
            echo json_encode(['success' => true, 'saved' => $saved]);
        } else {
            echo json_encode(['success' => false, 'error' => 'No records provided']);
        }
        exit;
    }

    // Handle AJAX distribution days save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'saveDistribution') {
        require_once('database/dbinfo.php');
        require_once('database/dbDistribution.php');
        $personId         = isset($_SESSION['_id']) ? $_SESSION['_id'] : 0;
        $distributionDays = isset($_POST['distributionDays']) ? (int)$_POST['distributionDays'] : 0;
        $date             = date('Y-m-d');
        header('Content-Type: application/json');
        if ($distributionDays > 0) {
            $id = add_distribution($distributionDays, $personId, $date);
            echo json_encode(['success' => $id > 0, 'id' => $id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
        }
        exit;
    }

    $loggedIn = false;
    $accessLevel = 0;
    $userID = null;
    if (isset($_SESSION['_id'])) {
        $loggedIn = true;
        $accessLevel = $_SESSION['access_level'];
        $userID = $_SESSION['_id'];
    }

    if ($accessLevel < 2) {
        header('Location: index.php');
        die();
    }

    require_once('database/dbinfo.php');
    require_once('database/dbItemCategory.php');
    require_once('database/dbShoppingEvent.php');
    require_once('database/dbShoppingCount.php');
    require_once('database/dbClient.php');
    require_once('database/dbDistribution.php');
    require_once('database/dbConsumption.php');

    // Get all item categories and build category ID → name map
    $allCategories = get_all_ItemCategory();
    $categoryMap   = array();
    foreach ($allCategories as $cat) {
        $categoryMap[$cat->getId()] = $cat->getName();
    }

    // Get all shopping events
    $allShoppingEvents = get_all_shoppingEvents();
    $familySizes = array();
    foreach ($allShoppingEvents as $event) {
        $fs = $event->getFamilySize();
        if (!in_array($fs, $familySizes)) {
            $familySizes[] = $fs;
        }
    }
    sort($familySizes);

    // Build shopping event ID → family size map
    $shoppingEventFamilyMap = array();
    foreach ($allShoppingEvents as $se) {
        $shoppingEventFamilyMap[$se->getId()] = $se->getFamilySize();
    }

    // ---- Consumption Rate Calculation ----
    $con = connect();

    // Latest distribution days record
    $distRow = mysqli_fetch_assoc(mysqli_query($con,
        'SELECT * FROM dbdistribution ORDER BY date DESC, id DESC LIMIT 1'));
    $latestDistribution = $distRow
        ? new Distribution($distRow['id'], $distRow['distributionDays'], $distRow['personId'], $distRow['date'])
        : null;

    // Most recent client record per shopping event group (shoppingEventId)
    // Using MAX(id) per group ensures only the latest entry for each group is counted,
    // so updating a group on a new day replaces the old value rather than adding to it.
    $latestClients    = array();
    $latestClientDate = null;
    $clientResult = mysqli_query($con,
        'SELECT c.* FROM dbclient c
         WHERE c.id IN (SELECT MAX(id) FROM dbclient GROUP BY shoppingEventId)
         ORDER BY c.date DESC, c.id DESC');
    if ($clientResult) {
        while ($row = mysqli_fetch_assoc($clientResult)) {
            $latestClients[] = new Client($row['id'], $row['shoppingEventId'], $row['personId'], $row['numClients'], $row['date']);
        }
        if (!empty($latestClients)) {
            $latestClientDate = substr($latestClients[0]->getDate(), 0, 7);
        }
    }
    mysqli_close($con);

    // Compute consumption rates
    $consumptionRateRows = array();
    $clientsPerDay       = null;

    if (!empty($latestClients) && $latestDistribution !== null && $latestDistribution->getDistributionDays() > 0) {
        $totalClients = 0;
        foreach ($latestClients as $c) { $totalClients += $c->getNumClients(); }
        $distDays      = $latestDistribution->getDistributionDays();
        // Matches spreadsheet: ROUND(total_clients / dist_days, 0)
        $clientsPerDay = round($totalClients / $distDays);


        /*if ($totalClients > 0 && $clientsPerDay > 0) {
            foreach ($latestClients as $c) {
                $seId       = $c->getShoppingEventId();
                $familySize = isset($shoppingEventFamilyMap[$seId]) ? $shoppingEventFamilyMap[$seId] : 'Unknown';

                // Fetch counts with group info via direct SQL
                $con4      = connect();
                $cntRes4   = mysqli_query($con4,
                    'SELECT sc.*, scg.groupName FROM dbshoppingcounts sc
                     LEFT JOIN dbshoppingcountgroup scg ON sc.groupId = scg.id
                     WHERE sc.shoppingEventId = ' . (int)$seId . '
                     ORDER BY sc.id ASC');
                $countsForEvent = [];
                $groupSizeMap   = []; // groupId => count of non-excluded members
                if ($cntRes4) {
                    while ($cRow = mysqli_fetch_assoc($cntRes4)) {
                        $countsForEvent[] = $cRow;
                        // Only non-excluded items count toward the divisor; an excluded item
                        // in a group of 4 should not shrink the remaining 3 items' rates.
                        if ($cRow['groupId'] !== null && empty($cRow['excludeFromConsumption'])) {
                            $gId = (int)$cRow['groupId'];
                            $groupSizeMap[$gId] = ($groupSizeMap[$gId] ?? 0) + 1;
                        }
                    }
                }
                mysqli_close($con4);

                // group_clients / clients_per_day matches the spreadsheet formula:
                // clients_per_day per group = group_clients / ROUND(total_clients / dist_days)
                $groupClientsPerDay = $c->getNumClients() / $clientsPerDay;

                foreach ($countsForEvent as $sc) {
                    // Skip items marked as excluded from consumption rate calculation
                    if (!empty($sc['excludeFromConsumption'])) continue;

                    $catId     = (int)$sc['itemCategoryId'];
                    $qty       = (int)$sc['quantity'];
                    $gId       = $sc['groupId'] !== null ? (int)$sc['groupId'] : null;
                    $gName     = $sc['groupName'] ?? null;
                    $gSize     = ($gId !== null && isset($groupSizeMap[$gId])) ? $groupSizeMap[$gId] : 1;

                    $baseRate  = $groupClientsPerDay * $qty;
                    $rate      = round($gSize > 1 ? $baseRate / $gSize : $baseRate, 2);

                    $consumptionRateRows[] = array(
                        'shoppingEventId'   => $seId,
                        'itemCategoryId'    => $catId,
                        'itemName'          => isset($categoryMap[$catId]) ? $categoryMap[$catId] : 'Unknown',
                        'familySize'        => $familySize,
                        'clientsInGroup'    => $c->getNumClients(),
                        'groupClientsPerDay'=> round($groupClientsPerDay, 2),
                        'itemsPerCart'      => $qty,
                        'consumptionRate'   => $rate,
                        'date'              => $c->getDate(),
                        'groupName'         => $gName,
                        'groupSize'         => $gSize,
                    );
                }
            }
        }*/
        
         foreach ($allShoppingEvents as $event) {
            $rates = compute_current_consumption_rates_by_shoppingEvent($event->getId());
            foreach ($rates as $rec){
                $consumptionRateRows[] = array(
                    'shoppingEventId'   => $rec['shoppingEventId'],
                    'itemCategoryId'    => $rec['itemCategoryId'],
                    'itemName'          => $rec['itemName'],
                    'familySize'        => $rec['familySize'],
                    'clientsInGroup'    => $rec['clientsInGroup'],
                    'groupClientsPerDay'=> $rec['groupClientsPerDay'],
                    'itemsPerCart'      => $rec['itemsPerCart'],
                    'consumptionRate'   => $rec['consumptionRate'],
                    'date'              => $rec['date'],
                    'groupName'         => $rec['groupName'],
                    'groupSize'         => $rec['groupSize'],
                );
            }
        }
    }

// Auto-save consumption rates to database (skip duplicates by shoppingEventId + itemCategoryId + date)
// Use a single normalized date (latest client date in the batch) so all records from the same
// calculation share one date — prevents the weekly report's MAX(date) query from missing items
// that came from shopping events entered on different days within the same month.
$consumptionAutoSave = ['saved' => 0, 'updated' => 0];
if (!empty($consumptionRateRows)) {
    require_once('database/dbConsumption.php');
    $batchDate = max(array_map(function($c) { return $c->getDate(); }, $latestClients));
    $saveCon = connect();
    foreach ($consumptionRateRows as $rec) {
        $seId     = (int)$rec['shoppingEventId'];
        $catId    = (int)$rec['itemCategoryId'];
        $rate     = (float)$rec['consumptionRate'];
        $dateEsc  = mysqli_real_escape_string($saveCon, $batchDate);
        $check = mysqli_query($saveCon,
            'SELECT id FROM dbcomsumption WHERE shoppingEventId = ' . $seId .
            ' AND itemCategoryId = ' . $catId .
            ' AND date = "' . $dateEsc . '" LIMIT 1');
        if ($check && mysqli_num_rows($check) > 0) {
            $existing = mysqli_fetch_assoc($check);
            update_consumption_itemsConsumed((int)$existing['id'], $rate);
            $consumptionAutoSave['updated']++;
        } else {
            add_consumption($seId, $catId, $rate, $userID ?? 0, $batchDate);
            $consumptionAutoSave['saved']++;
        }
    }
    mysqli_close($saveCon);
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php require_once('universal.inc') ?>
    <title>Consumption Rates | CCDA</title>
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
            border-collapse: separate;
            border-spacing: 0;
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
            border-right: 1px solid var(--main-color);
        }
        .report-table tr:hover {
            background-color: rgba(255,255,255,0.05);
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
        .week-selector select:hover { background-color: rgba(0,0,0,0.3); }
        .select { background-color: white !important; }
        .generate-btn {
            padding: 0.5rem 1.5rem;
            background-color: var(--accent-color);
            color: var(--button-font-color);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            width: auto;
        }
        .generate-btn:hover { opacity: 0.85; }
        .data-entry-grid {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            max-width: 500px;
        }
        .data-entry-row  { display: flex; align-items: center; gap: 1rem; }
        .data-entry-label {
            color: var(--page-font-color);
            width: 160px;
            flex-shrink: 0;
            font-weight: 500;
        }
        .data-entry-input {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            flex: 1;
        }
        .feedback-msg    { display: inline-block; margin-left: 1rem; font-size: 0.9rem; font-weight: 500; }
        .feedback-success { color: rgb(34, 197, 94); }
        .feedback-error   { color: rgb(239, 68, 68); }
        .row-number { text-align: center; color: var(--inactive-font-color); font-weight: 500; }
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
            .report-container { padding: 0.5rem; }
            div.table-wrapper { overflow-x: auto; }
            .data-entry-row  { flex-direction: column; align-items: stretch; }
            .data-entry-label { width: auto; }
            .report-section{
                padding: 0;
            }
        }
        .list-tip {
            position: relative;
            display: inline-block;
            margin-left: 0.35rem;
            cursor: default;
            font-size: 0.8rem;
            opacity: 0.6;
        }
        .list-tip::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: #fff;
            padding: 0.3rem 0.6rem;
            border-radius: 5px;
            font-size: 0.78rem;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.15s;
            z-index: 99;
        }
        .list-tip:hover::after {
            opacity: 1;
        }
    </style>
</head>
<pageheader>
    <h1 class="title">Consumption Rates</h1>
</pageheader>
<body>
    <?php require_once('header.php') ?>
    <main>
        <div class="report-container">

            <!-- Monthly Client Count -->
            <div class="report-section">
                <h2>Monthly Client Count</h2>
                <p style="color: var(--page-font-color); margin-bottom: 1rem;">Record the monthly number of clients for a family size.</p>

                <div class="data-entry-grid">
                    <div class="data-entry-row">
                        <label class="data-entry-label" for="clientFamilySize">Family Size:</label>
                        <select id="clientFamilySize" class="data-entry-input select" onchange="displayClientCount(this)">
                            <option value="">-- Select Family Size --</option>
                            <?php foreach ($familySizes as $fs): ?>
                                <option value="<?= htmlspecialchars($fs) ?>"><?= htmlspecialchars($fs) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="all-client-counts">
                        <?php foreach ($latestClients as $client):
                            $se = $client->getShoppingEventId(); 
                            if(!isset($shoppingEventFamilyMap[$se])) continue;
                            $fs = $shoppingEventFamilyMap[$se]; ?>
                            <div id="clientCount_<?= htmlspecialchars($fs) ?>" style="display: none;">
                                <strong>Current Client Count:</strong> <?= htmlspecialchars($client->getNumClients()) ?>
                                    &nbsp;(<?= htmlspecialchars(date('m/d/Y', strtotime($client->getDate()))) ?>) 
                                <br>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="data-entry-row">
                        <label class="data-entry-label" for="numClients">Number of Clients:</label>
                        <input type="number" id="numClients" class="data-entry-input" min="0" step="0.01" placeholder="e.g. 50">
                    </div>
                </div>
                <button class="generate-btn" id="saveClientBtn">Save Client Count</button>
                <span id="clientFeedback" class="feedback-msg"></span>
            </div>

            <!-- Distribution Days -->
            <div class="report-section">
                <h2>Distribution Days</h2>
                <p style="color: var(--page-font-color); margin-bottom: 1rem;">Record the number of distribution days for a specific date.</p>
                <strong>Current Distribution Days:</strong> <?= htmlspecialchars($latestDistribution->getDistributionDays()) ?>
                            &nbsp;(<?= htmlspecialchars(date('m/d/Y', strtotime($latestDistribution->getDate()))) ?>) 
                <br><br>

                <div class="data-entry-grid">
                    <div class="data-entry-row">
                        <label class="data-entry-label" for="distributionDays">Distribution Days:</label>
                        <input type="number" id="distributionDays" class="data-entry-input" min="1" step="1" placeholder="e.g. 5">
                    </div>
                </div>
                <button class="generate-btn" id="saveDistributionBtn">Save Distribution Days</button>
                <span id="distributionFeedback" class="feedback-msg"></span>
            </div>

            <!-- Consumption Rate -->
            <div class="report-section">
                <h2>Consumption Rate</h2>
                <p style="color: var(--page-font-color); margin-bottom: 0.5rem;">
                    Calculated from the most recent client counts and distribution days on record.<br>
                    <strong>Group Clients/Day</strong> = Group Clients &divide; Clients/Day &nbsp;&nbsp;
                    <strong>Items/Day</strong> = Group Clients/Day &times; Items per Cart &divide; Group Size
                </p>

                <?php if ($latestDistribution === null || empty($latestClients)): ?>
                    <p class="empty-state" style="text-align:left; padding: 1.5rem 0;">
                        No data yet. Enter client counts and distribution days above, then return here to view rates.
                    </p>
                <?php else: ?>
                    <div style="display:flex; gap:2rem; margin-bottom:1.25rem; flex-wrap:wrap;">
                        <span style="color:var(--page-font-color);">
                            <strong>Distribution Days:</strong> <?= htmlspecialchars($latestDistribution->getDistributionDays()) ?>
                            &nbsp;(<?= htmlspecialchars(date('m/d/Y', strtotime($latestDistribution->getDate()))) ?>) 
                        </span>
                        <span style="color:var(--page-font-color);">
                            <strong>Total Clients:</strong>
                            <?= htmlspecialchars(array_sum(array_map(function($c){ return $c->getNumClients(); }, $latestClients))) ?>

                        </span>
                        <span style="color:var(--page-font-color);">
                            <strong>Clients/Day:</strong> <?= htmlspecialchars($clientsPerDay) ?>
                        </span>
                    </div>

                    <?php if (empty($consumptionRateRows)): ?>
                        <p class="empty-state" style="text-align:left; padding:1rem 0;">
                            No shopping list baskets found for the client records. Make sure shopping events exist for those dates.
                        </p>
                    <?php else: ?>
                        <?php $uniqueConsumptionFamilySizes = array_values(array_unique(array_column($consumptionRateRows, 'familySize'))); sort($uniqueConsumptionFamilySizes); ?>
                        <div class="week-selector" style="margin-bottom: 1rem;">
                            <label for="consumptionFamilyFilter" style="color: var(--page-font-color); font-weight: 500;">Family Size:</label>
                            <select id="consumptionFamilyFilter" class="select" style="padding: 0.5rem 0.75rem; border: 1px solid var(--shadow-and-border-color); border-radius: 0.25rem; background-color: rgba(0,0,0,0.2); color: var(--page-font-color); cursor: pointer; min-width: 180px;">
                                <option value="">All Family Sizes</option>
                                <?php foreach ($uniqueConsumptionFamilySizes as $fs): ?>
                                    <option value="<?= htmlspecialchars($fs) ?>"><?= htmlspecialchars($fs) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="table-wrapper" id="consumptionTotalsWrapper" style="display:none; margin-bottom:1rem;">
                            <table class="report-table" id="consumptionTotalsTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Item Name</th>
                                        <th>Group</th>
                                        <th>Total Items/Day (All Family Sizes)</th>
                                    </tr>
                                </thead>
                                <tbody id="consumptionTotalsTbody"></tbody>
                            </table>
                        </div>
                        <div class="table-wrapper" id="consumptionRateWrapper">
                            <table class="report-table" id="consumptionRateTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Family Size</th>
                                        <th>Item Name</th>
                                        <th>Group</th>
                                        <th>Group Clients</th>
                                        <th>Group Clients/Day</th>
                                        <th>Items per Cart</th>
                                        <th>Items/Day (Consumption Rate)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($consumptionRateRows as $i => $row): ?>
                                        <tr data-family-size="<?= htmlspecialchars($row['familySize']) ?>">
                                            <td class="row-number"><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($row['familySize']) ?></td>
                                            <td><?= htmlspecialchars($row['itemName']) ?></td>
                                            <td style="font-size:0.85rem; color: var(--page-font-color);">
                                                <?= $row['groupName'] ? htmlspecialchars($row['groupName']) . ' (' . $row['groupSize'] . ' items)' : '&mdash;' ?>
                                            </td>
                                            <td><?= htmlspecialchars($row['clientsInGroup']) ?></td>
                                            <td><?= htmlspecialchars($row['groupClientsPerDay']) ?></td>
                                            <td><?= htmlspecialchars($row['itemsPerCart']) ?></td>
                                            <td><strong><?= htmlspecialchars($row['consumptionRate']) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top:1.25rem; font-size:0.9rem; color: var(--inactive-font-color);">
                            <?php if ($consumptionAutoSave['saved'] > 0): ?>
                                <span style="color: rgb(34, 197, 94);">&#10003; <?= $consumptionAutoSave['saved'] ?> rate(s) saved automatically.</span>
                            <?php endif; ?>
                            <?php if ($consumptionAutoSave['updated'] > 0): ?>
                                <span style="color: rgb(34, 197, 94);">&#10003; <?= $consumptionAutoSave['updated'] ?> rate(s) updated.</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        $(function() {

            // ---- Save Monthly Client Count ----
            $('#saveClientBtn').on('click', function() {
                var btn = $(this);
                var familySize = $('#clientFamilySize').val();
                var numClients = $('#numClients').val();
                var $feedback  = $('#clientFeedback');

                if (!familySize || numClients === '') {
                    $feedback.removeClass('feedback-success').addClass('feedback-error').text('Please fill in all fields.');
                    return;
                }
                btn.prop('disabled', true).text('Saving...');
                $feedback.removeClass('feedback-success feedback-error').text('');

                $.post('viewConsumptionRates.php', {
                    action: 'saveClient', familySize: familySize, numClients: numClients
                }, function(data) {
                    if (data.success) {
                        $feedback.removeClass('feedback-error').addClass('feedback-success').text('Saved! Updating rates...');
                        setTimeout(function() { location.reload(); }, 600);
                    } else {
                        $feedback.removeClass('feedback-success').addClass('feedback-error').text(data.error || 'Save failed.');
                        btn.prop('disabled', false).text('Save Client Count');
                        setTimeout(function() { $feedback.text(''); }, 4000);
                    }
                }, 'json').fail(function() {
                    $feedback.removeClass('feedback-success').addClass('feedback-error').text('Server error. Please try again.');
                    btn.prop('disabled', false).text('Save Client Count');
                });
            });

            // ---- Save Distribution Days ----
            $('#saveDistributionBtn').on('click', function() {
                var btn = $(this);
                var distributionDays = $('#distributionDays').val();
                var $feedback        = $('#distributionFeedback');

                if (!distributionDays) {
                    $feedback.removeClass('feedback-success').addClass('feedback-error').text('Please fill in all fields.');
                    return;
                }
                btn.prop('disabled', true).text('Saving...');
                $feedback.removeClass('feedback-success feedback-error').text('');

                $.post('viewConsumptionRates.php', {
                    action: 'saveDistribution', distributionDays: distributionDays
                }, function(data) {
                    if (data.success) {
                        $feedback.removeClass('feedback-error').addClass('feedback-success').text('Saved! Updating rates...');
                        setTimeout(function() { location.reload(); }, 600);
                    } else {
                        $feedback.removeClass('feedback-success').addClass('feedback-error').text(data.error || 'Save failed.');
                        btn.prop('disabled', false).text('Save Distribution Days');
                        setTimeout(function() { $feedback.text(''); }, 4000);
                    }
                }, 'json').fail(function() {
                    $feedback.removeClass('feedback-success').addClass('feedback-error').text('Server error. Please try again.');
                    btn.prop('disabled', false).text('Save Distribution Days');
                });
            });

            // ---- Consumption Rate family size filter ----
            var consumptionRateData = <?= json_encode(array_map(function($r) {
                return [
                    'itemName'        => $r['itemName'],
                    'groupName'       => $r['groupName'],
                    'groupSize'       => $r['groupSize'],
                    'consumptionRate' => $r['consumptionRate'],
                    'familySize'      => $r['familySize'],
                ];
            }, $consumptionRateRows)) ?>;

            function buildTotalsTable() {
                var totals = {};
                var order  = [];
                $.each(consumptionRateData, function(_, row) {
                    var key = row.itemName + '||' + (row.groupName || '');
                    if (!totals[key]) {
                        totals[key] = { itemName: row.itemName, groupName: row.groupName, groupSize: row.groupSize, total: 0, familySizes: [] };
                        order.push(key);
                    }
                    totals[key].total = Math.round((totals[key].total + row.consumptionRate) * 100) / 100;
                    if (totals[key].familySizes.indexOf(row.familySize) === -1) {
                        totals[key].familySizes.push(row.familySize);
                    }
                });
                var $tbody = $('#consumptionTotalsTbody').empty();
                $.each(order, function(i, key) {
                    var t     = totals[key];
                    var grp   = t.groupName ? t.groupName + ' (' + t.groupSize + ' items)' : '&mdash;';
                    var lists = $('<span>').text(t.familySizes.join(', ')).html();
                    var tip   = '<span class="list-tip" data-tooltip="' + lists + '">&#9432;</span>';
                    $tbody.append(
                        '<tr><td class="row-number">' + (i + 1) + '</td>' +
                        '<td>' + $('<span>').text(t.itemName).html() + '</td>' +
                        '<td style="font-size:0.85rem; color:var(--page-font-color);">' + grp + tip + '</td>' +
                        '<td><strong>' + t.total + '</strong></td></tr>'
                    );
                });
            }

            // Show totals by default when "All Family Sizes" is the initial selection
            if ($('#consumptionFamilyFilter').length && $('#consumptionFamilyFilter').val() === '') {
                buildTotalsTable();
                $('#consumptionRateWrapper').hide();
                $('#consumptionTotalsWrapper').show();
            }

            $('#consumptionFamilyFilter').on('change', function() {
                var selected = $(this).val();
                if (!selected) {
                    buildTotalsTable();
                    $('#consumptionRateWrapper').hide();
                    $('#consumptionTotalsWrapper').show();
                } else {
                    $('#consumptionTotalsWrapper').hide();
                    $('#consumptionRateWrapper').show();
                    var visibleIndex = 1;
                    $('#consumptionRateTable tbody tr').each(function() {
                        var rowFamily = $(this).data('family-size');
                        if (rowFamily === selected) {
                            $(this).show();
                            $(this).find('.row-number').text(visibleIndex++);
                        } else {
                            $(this).hide();
                        }
                    });
                }
            });

        });
    </script>
    <script>

    function displayClientCount(element){
        const familySizeSelect = element.value;

        const allClientCountsParentDiv = document.getElementById('all-client-counts');
        const allClientCounts = allClientCountsParentDiv.querySelectorAll('div');

        var selectedCount = "clientCount_"+familySizeSelect;
        for(var i=0; i< allClientCounts.length; i++){
            if(allClientCounts[i].id == selectedCount)
                allClientCounts[i].style.display = "block";
            else
                allClientCounts[i].style.display = "none";
        }
    }
    </script>

</body>
</html>
