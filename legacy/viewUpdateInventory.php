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

    // Add database includes here

    require_once('database/dbinfo.php');
    require_once('database/dbPersons.php');
    require_once('database/dbInventoryEvent.php');
    require_once('database/dbItemCategory.php');
    require_once('database/dbItemCounts.php');
    require_once('database/dbPalletEvent.php');

    // Check if inventory exists for a date
    if (isset($_GET['action']) && $_GET['action'] === 'checkDate') {
        $date = $_GET['date'] ?? '';
        $events = get_all_inventoryEvents_by_date($date);
        header('Content-Type: application/json');
        echo json_encode(['exists' => !empty($events)]);
        exit;
    }

    /* get the total pallet inventory for each category to show in the pallet column */
    /* Only include active categories - inactive/deleted items on pallets should not transfer to new inventories */
    $pallet_totals = array();
    $pallets = get_all_palletEvents();
    $categories = get_all_active_ItemCategory();
    foreach($categories as $category){
        if ($category->getShopOnly() == 1) continue;
        $pallet_totals[$category->getId()] = 0;
    }
    foreach($pallets as $pallet){
        $palletCounts = get_palletCounts_by_palletEvent($pallet->getId());
        foreach($palletCounts as $count){
            $categoryId = $count->getItemCategory();
            $category = retrieve_ItemCategory($categoryId);
            if ($category && $category->getStatus() == 'Active') {
                $pallet_totals[$categoryId] += $count->getQuantity();
            }
        }
    }


    /* 
    * _POST is empty when the page is first loaded.
    *  Submitting the form on this page reloads the page with data in _POST
    *  if _POST is not empty, process data from form
    */
    $submit_success = false;
    $include_pallets = false;
    $errors = [];
    if (!empty($_POST)) {
        $warehouseItems = array();
        $pantryItems = array();
        $date = null;

        foreach($_POST as $name => $value){
            if($name == "date"){
                $date = $value;
            }
            else if($name == "checkIncludePallets"){
                $include_pallets = true;
            }
            /* warehouse quantities have prefix "warehouse_" */
            else if(strpos($name, 'warehouse_') === 0){
                $categoryId = str_replace('warehouse_', '', $name);

                /* only add items that have values to array */
                if(!empty($value)){

                    /* try to convert value to a number. If it cannot convert, leave it as a string */
                    try{
                        $value = +$value;
                    }
                    catch(TypeError $e){
                        $value = " ";
                    }

                    /* if error is found, empty the arrays and stop checking */
                    if(!is_int($value)){
                        $errors[] = 'Warehouse quantities must be in whole numbers';
                        $warehouseItems = array();
                        $pantryItems = array();
                        break;
                    }
                    else if($value < 0){
                        $errors[] = 'Warehouse quantities cannot be negative';
                        $warehouseItems = array();
                        $pantryItems = array();
                        break;
                    }
                    /* accept items with 0 or greater quantity */
                    else if($value >= 0){
                        $warehouseItems[$categoryId] = $value;
                    }
                }
            }
            /* pantry quantities have prefix "pantry_" */
            else if(strpos($name, 'pantry_') === 0){
                $categoryId = str_replace('pantry_', '', $name);

                /* only add items that have values to array */
                if(!empty($value)){

                    /* try to convert value to a number. If it cannot convert, leave it as a string */
                    try{
                        $value = +$value;
                    }
                    catch(TypeError $e){
                        $value = " ";
                    }

                    /* if error is found, empty the arrays and stop checking */
                    if(!is_int($value)){
                        $errors[] = 'Pantry quantities must be in whole numbers';
                        $warehouseItems = array();
                        $pantryItems = array();
                        break;
                    }
                    else if($value < 0){
                        $errors[] = 'Pantry quantities cannot be negative';
                        $warehouseItems = array();
                        $pantryItems = array();
                        break;
                    }
                    /* accept items with 0 or greater quantity */
                    else if($value >= 0){
                        $pantryItems[$categoryId] = $value;
                    }
                }
            }
        }

        /* build pallet items array (from pallet_totals if checkbox checked, zeros if not) */
        $palletItems = array();
        if($include_pallets){
            foreach($pallet_totals as $categoryId => $quantity){
                $palletItems[$categoryId] = $quantity;
            }
        }

        // OLD CODE - add pallet totals to Warehouse only
        // if($include_pallets){
        //     foreach($pallet_totals as $categoryId => $quantity){
        //         if(isset($warehouseItems[$categoryId])){
        //             $warehouseItems[$categoryId] += $quantity;
        //         }
        //         else{
        //             $warehouseItems[$categoryId] = $quantity;
        //         }
        //     }
        // }

        /* if at least 1 item was entered, create inventory events and add items to database */
        if(empty($errors) && (count($warehouseItems) > 0 || count($pantryItems) > 0 || count($palletItems) > 0)){

            /* auto-fill missing items with 0 for complete analytics data */
            $allCategories = get_all_active_ItemCategory();
            foreach($allCategories as $category){
                // Skip shopping list only items
                if ($category->getShopOnly() == 1) {
                    continue;
                }
                $categoryId = $category->getId();
                if(!isset($warehouseItems[$categoryId])){
                    $warehouseItems[$categoryId] = 0;
                }
                if(!isset($pantryItems[$categoryId])){
                    $pantryItems[$categoryId] = 0;
                }
                if(!isset($palletItems[$categoryId])){
                    $palletItems[$categoryId] = 0;
                }
            }

            /* create or update warehouse, pantry, and pallet events */
            $personId = retrieve_person($userID)->get_personId();
            $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';
            $existingEvents = get_all_inventoryEvents_by_date($date);

            if ($overwrite && !empty($existingEvents)) {
                /* Overwrite - Delete existing events first */
                foreach ($existingEvents as $event) {
                    if ($event->getLocation() == 'Warehouse') {
                        $matches = get_matching_inventoryEvent($event);
                        remove_inventoryEvent($event->getId());
                        if (isset($matches['Pantry'])) {
                            remove_inventoryEvent($matches['Pantry']->getId());
                        }
                        if (isset($matches['Pallet'])) {
                            remove_inventoryEvent($matches['Pallet']->getId());
                        }
                        break;
                    }
                }
            }

            /* Create inventory events */
            $warehouseEventId = add_inventoryEvent($personId, 'Warehouse', $date);
            foreach($warehouseItems as $categoryId => $quantity){
                add_itemCount($warehouseEventId, $categoryId, $quantity);
            }

            $pantryEventId = add_inventoryEvent($personId, 'Pantry', $date);
            foreach($pantryItems as $categoryId => $quantity){
                add_itemCount($pantryEventId, $categoryId, $quantity);
            }

            $palletEventId = add_inventoryEvent($personId, 'Pallet', $date);
            foreach($palletItems as $categoryId => $quantity){
                add_itemCount($palletEventId, $categoryId, $quantity);
            }

            $submit_success = true;
            
            // Redirect to inventory page to view the newly created inventory
            header('Location: inventory.php?week=' . $warehouseEventId);
            exit;
        }
        else{
            /* if errors have already been detected array was emptied. Do not show error for missing data */
            if(empty($errors)){
                $errors[] = 'Enter quantity for at least 1 item';
            }
        }
    }

    /* Previous Inventory column removed
    $previous_event_pair = get_previous_inventoryEvent_pair(new InventoryEvent(0, 0, 0, date('Y-m-d')));

    $prev_item_counts = array();
    if($previous_event_pair[0]){
        $prev_item_counts = get_itemCounts_by_inventoryEvent($previous_event_pair[0]->getId());
    }
    if($previous_event_pair[1]){
        $prev_item_counts = array_merge($prev_item_counts, get_itemCounts_by_inventoryEvent($previous_event_pair[1]->getId()));
    }

    $prev_totals = array();
    foreach($prev_item_counts as $item){
        if(isset($prev_totals[$item->getItemCategory()]))
            $prev_totals[$item->getItemCategory()] += $item->getQuantity();
        else
            $prev_totals[$item->getItemCategory()] = $item->getQuantity();
    }
    */
    

?>
    
<!DOCTYPE html>
<html>
<head>
    <?php require_once('universal.inc') ?>
    <title>Update Inventory | CCDA</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .updateInv-optionRow {
            display: flex;
            align-items: center;
            flex-direction: row;
            justify-content: left;
            gap: 1rem;
        }
        .updateInv-option {
            display: flex;
            align-items: center;
            flex-direction: row;
            width: 45%;
            gap: 1rem;
        }
        .updateInv-optionLabel {
            text-align: right;
        }
        .updateInv-allRows {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            padding: 2rem 1rem;
        }
        .updateInv-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            display: table-row !important;
        }
        .updateInv-label {
            color: var(--page-font-color);
            width: 200px;
            max-width: 400px;
            min-width: 6rem;
            flex-grow: 1;
            flex-grow: 1;
            text-align: right;
            padding: 0rem  .5rem 0rem 0rem;
        }
        .updateInv-qty {
            width: 100px;
            max-width: 100px;
            margin-bottom: 0rem !important;
            padding: 0.4rem 0.6rem !important;
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
            width: auto;
        }
        .generate-btn:hover {
            opacity: 0.85;
        }
        .modify-btn {
            padding: 0.5rem 1.5rem;
            background-color: var(--accent-color);
            color: var(--button-font-color);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            border: 1px solid black;
        }
        .modify-btn:hover,
        .manage-pallet-lnk:hover {
            opacity: 0.85;
        }
        .manage-pallet-lnk {
            white-space: nowrap;
            width: 100%;
            padding: 0.5rem 1rem;
        }
        .manage-pallet-lnk button {
            padding: 0.5rem 1rem;
            background-color: var(--accent-color);
            color: var(--button-font-color);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            margin: 0.25rem;
            text-align: center;
        }
        .pallet-column {
            text-align: left !important;
            display: inline-block;
            width: 100%;
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
            .report-section{
                padding: 0;
            }
        }
    </style>
</head>
<pageheader>
    <h1 class="title">Update Inventory</h1>
</pageheader>
<body>
    <?php require_once('header.php') ?>
    <main>
        <div class="report-container">
            <?php
                /* Display success message after submitting inventory */
                if($submit_success == true){
                    echo("<h4 style=\"color:black;\"><i>Inventory Submitted: ".date("m/d/Y", strtotime($date))." (Warehouse & Pantry & Pallet)</i></h4>");
                }
                /* Display errors from submitting inventory */
                if (!empty($errors)): ?>
                <ul>
                    <?php foreach($errors AS $error): ?>
                        <li><?php echo("<h4 style=\"color:red;\"><i>".$error."</i></h4>"); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <!-- Update Inventory -->
            <div class="report-section">
                <h2>Inventory Input</h2>   
                <form name="invForm" onsubmit="return validateFormDate()" method="POST" action="viewUpdateInventory.php">
                    <div class="updateInv-optionRow">
                        <div class="updateInv-option">
                        </div>
                        <div class="updateInv-option">
                            <label class="updateInv-optionLabel" for="date">Inventory Date:</label>
                            <input type="date" name="date" id="date"
                                value="<?php if (!empty($errors)) echo($_POST['date']); else echo date('Y-m-d');?>">
                        </div>
                    </div>
                    <div class="updateInv-option">
                            <label class="updateInv-optionLabel" for="checkIncludePallets">Include Pallets:</label>
                            <input type="checkbox" id="checkIncludePallets" name="checkIncludePallets" value="1" onclick="showPalletColumn()"
                                <?php if (empty($_POST) || isset($_POST['checkIncludePallets'])) echo("checked");?>>
                            <a href="viewManagePallets.php" class="manage-pallet-lnk"><button type="button" >Manage Pallets</button></a>

                        </div>
                        <div class="table-wrapper">
                        <table class="report-table">
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
                                        <span class="desktop-text">Banana Box</span>
                                        <span class="mobile-text">Ban. Box</span>
                                    </th>
                                    <th>
                                        <span class="desktop-text">Items Per Box</span>
                                        <span class="mobile-text">Items/Box</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $categories = get_all_active_ItemCategory();
                                foreach($categories AS $category): 
                                    // Skip shopping list only items
                                    if ($category->getShopOnly() == 1) {
                                        continue;
                                    }
                                ?>
                                    <tr class="updateInv-row">
                                        <td>
                                            <label class="updateInv-label" for="warehouse_<?php echo($category->getId())?>">
                                                <?php echo($category->getName());?>
                                            </label>
                                        </td>
                                        <td>
                                            <input type="number" class="updateInv-qty" min="0" placeholder="0"
                                                value="<?php if (!empty($errors)) echo($_POST['warehouse_'.$category->getId()]);?>"
                                                name="warehouse_<?php echo($category->getId())?>"
                                                id="warehouse_<?php echo($category->getId())?>">
                                        </td>
                                        <td>
                                            <input type="number" class="updateInv-qty" min="0" placeholder="0"
                                                value="<?php if (!empty($errors)) echo($_POST['pantry_'.$category->getId()]);?>"
                                                name="pantry_<?php echo($category->getId())?>"
                                                id="pantry_<?php echo($category->getId())?>">
                                        </td>
                                        <td>
                                            <div class="pallet-column" style="display: block;">
                                                <?php if(isset($pallet_totals[$category->getId()]))
                                                            echo($pallet_totals[$category->getId()])?>
                                            </div>
                                        </td>
                                        <td style="text-align: center;"><?= $category->getBananaBox() == 1 ? '✓' : '' ?></td>
                                        <td style="text-align: center;"><?php echo($category->getItemsPerBox())?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                    <input type="hidden" id="overwrite" name="overwrite" value="0">
                    <input type="submit" value="Submit Inventory" />
                </form>
                <script>
                    function showPalletColumn() {
                        var checkBox = document.getElementById("checkIncludePallets");
                        var palletColumn = document.getElementsByClassName("pallet-column");
                        if (checkBox.checked == true){
                            for (var i = 0; i < palletColumn.length; i++) {
                                palletColumn[i].style.display = "block";
                            }
                        } else {
                            for (var i = 0; i < palletColumn.length; i++) {
                                palletColumn[i].style.display = "none";
                            }
                        }
                    }
                </script>
                <script>
                    /* if form Date is in the past or the future, confirm before submitting form */
                    /* also check if inventory already exists for the date */
                    function validateFormDate() {
                        var overwriteField = document.getElementById('overwrite');

                        // If already confirmed overwrite, skip inventory check
                        if (overwriteField.value === '1') {
                            return true;
                        }

                        const [formYear,formMonth,formDay] = document.forms["invForm"]["date"].value.split("-");
                        /* check for invalid input */
                        if(formYear == null || formMonth == null || formDay == null){
                            alert("Invalid Date");
                            return false;
                        }
                        /* check for invalid date */
                        else if(isNaN(new Date(document.forms["invForm"]["date"].value))){
                            alert("Invalid Date");
                            return false;
                        }

                        var dateValue = document.forms["invForm"]["date"].value;

                        // Check if inventory exists for this date
                        var xhr = new XMLHttpRequest();
                        xhr.open('GET', 'viewUpdateInventory.php?action=checkDate&date=' + dateValue, false);
                        xhr.send();

                        if (xhr.status === 200) {
                            var response = JSON.parse(xhr.responseText);
                            if (response.exists) {
                                if (confirm("Inventory already exists for " + formMonth + "/" + formDay + "/" + formYear +
                                            "\n\nDo you want to overwrite it?")) {
                                    overwriteField.value = '1';
                                } else {
                                    return false;
                                }
                            }
                        }

                        let currDate = new Date();
                        let compareDates = 0;
                        if(formYear==currDate.getFullYear()){
                            if(formMonth == currDate.getMonth()+1){
                                compareDates = formDay-currDate.getDate();
                            }
                            else{
                                compareDates = formMonth-(currDate.getMonth()+1);
                            }
                        }
                        else{
                            compareDates = formYear-currDate.getFullYear();
                        }

                        if(compareDates == 0){
                            return true;
                        }
                        else if(compareDates < 0){
                            return confirm("PAST DATE: "+formMonth+"/"+formDay+"/"+formYear+
                                            "\nAre you sure you want to submit?");
                        }
                        else if(compareDates > 0){
                            return confirm("FUTURE DATE: "+formMonth+"/"+formDay+"/"+formYear+
                                            "\nAre you sure you want to submit?");
                        }
                    }
                </script>
            </div>

        </div>
    </main>
    

</body>
</html>
