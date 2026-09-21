<?php
    session_cache_expire(30);
    session_start();

    $loggedIn = false;
    $accessLevel = 0;
    $userID = null;
    $personId = null;
    if (isset($_SESSION['_id'])) {
        $loggedIn = true;
        $accessLevel = $_SESSION['access_level'];
        $userID = $_SESSION['_id'];
        $personId = $_SESSION['_personId'];
    }

    // Add database includes here

    require_once('database/dbinfo.php');
    require_once('database/dbPersons.php');
    require_once('database/dbPalletEvent.php');
    require_once('database/dbItemCategory.php');
    require_once('database/dbPalletCounts.php');

    /* 
    * _POST is empty when the page is first loaded.
    *  Submitting the form on this page reloads the page with data in _POST
    *  if _POST is not empty, process data from form
    */
    
    $errors = [];

    $pallet_name = "Pallet";

    $categories_by_row = array();
    $quantities_by_row = array();
    $expirations_by_row = array();

    $quantities_by_cat = array();
    $expirations_by_cat = array();

    if (!empty($_POST)) {
        if(isset($_POST["cancel_button"])){
            header('Location: viewManagePallets.php');
            die();
        }

        foreach($_POST as $key => $value){
            if($key == "name"){
                if($value == "Pallet"){
                    $pallet_name = "PALLET_PLACEHOLDER_NAME";
                }
                else if(!pallet_name_unique($value)){
                    $errors[] = "Pallet name already exists";
                }
                else if(empty($value)){
                    $errors[] = "Pallet name cannot be empty";
                }
                else{
                    $pallet_name = $value;
                }
                continue;
            }
            $key_parts = explode("_", $key);
            if(count($key_parts) != 2){
                continue;
            }
            $key_type = $key_parts[0];
            $key_id = $key_parts[1];
            if($key_type == "category"){
                $categories_by_row[] = $value;
            }
            else if($key_type == "qty"){
                $quantities_by_row[] = $value;
            }
            else if($key_type == "exp"){
                $expirations_by_row[] = $value;
            }
        }

        // check for duplicate allCategories$allCategories
        $dupe_category = array();
        foreach(array_count_values($categories_by_row) as $cat => $count)
            if($count > 1) $dupe_category[] = $cat;

        foreach($dupe_category as $cat){
            $category = retrieve_ItemCategory($cat);
            if(!empty($category)){
                $errors[] = "Duplicate Category: " . $category->getName();
            }
        }

        foreach($categories_by_row as $id_key => $categoryId){
            if(empty($categoryId)){
                /* if quantity is filled out or expiration date is filled out 
                 * but category is not, show error for missing category. 
                 * if category and quantity are both empty, no error. */
                if(!empty($quantities_by_row[$id_key]) || !empty($expirations_by_row[$id_key])){
                    $errors[] = "Missing Category on row " . ($id_key+1);
                }
                continue;
            }

            $quantity = $quantities_by_row[$id_key];
            $expiration = "";
            if(isset($expirations_by_row[$id_key]))
                $expiration = $expirations_by_row[$id_key];

            if(empty($quantity)){
                $category = retrieve_ItemCategory($categoryId);
                if(!empty($category)){
                    $errors[] = "Missing Quantity for: " . $category->getName();
                }
                else{
                    $errors[] = "Missing Quantity on row " . ($id_key+1);
                }
                continue;
            }

            /* try to convert quantity to a number. If it cannot convert, leave it as a string */
            try{
                $quantity = +$quantity;
            }
            catch(TypeError  $e){ 
                $quantity = " ";
            }

            /* if error is found, empty the array of items and stop checking */
            if(!is_int($quantity)){
                $errors[] = 'Quantities must be in whole numbers';
                break;
            }
            else if($quantity < 0){
                $errors[] = 'Quantities must be greater than 0';
                break;
            }
            /* accept items with 0 or greater quantity */
            else if($quantity >= 0){
                $quantities_by_cat[$categoryId] = $quantity;
                $expirations_by_cat[$categoryId] = $expiration;
            }
        }

        if(count($quantities_by_cat) == 0 && empty($errors)){
            $errors[] = 'Add at least 1 item to the pallet';
        }
        else if(count($quantities_by_cat) > 0 && empty($errors)){
            $palletEventId = add_palletEvent($pallet_name, $personId);
            if($pallet_name == "PALLET_PLACEHOLDER_NAME"){
                $pallet_name = "Pallet " . $palletEventId;
                $name_counter = 1;
                while(!pallet_name_unique($pallet_name)){
                    $name_counter++;
                    $pallet_name = "Pallet " . $palletEventId . " (" . $name_counter . ")";
                }
                update_palletEvent_name($palletEventId, $pallet_name);
            }
            foreach($quantities_by_cat as $categoryId => $quantity){
                $expiration = $expirations_by_cat[$categoryId];
                add_palletCount($palletEventId, $categoryId, $quantity, $expiration);
            }
            header('Location: viewManagePallets.php');
            die();
        } 
    }    

?>
    
<!DOCTYPE html>
<html>
<head>
    <?php require_once('universal.inc') ?>
    <title>Add Pallet | CCDA</title>
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
        }
        .report-table th {
            background-color: var(--main-color);
            color: var(--button-font-color);
            font-weight: 500;
            text-align:center;
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
        .updateInv-exp {
            max-width: 8rem;
            margin-bottom: 0rem !important;
            padding: 0.4rem 0.6rem !important;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            font-size: 0.9rem;
        }
        .updateInv-nameRow {
            display: flex;
            align-items: center;
            flex-direction: row;
            justify-content: left;
            gap: 1rem;
        }
        .updateInv-name {
            display: flex;
            align-items: center;
            flex-direction: row;
            gap: 1rem;
        }
        .updateInv-nameLabel {
            text-align: right;
                width: 200px;
                max-width: 400px;
                min-width: 6rem;
                flex-grow: 1;
                text-align: right;
                padding: 0rem  .5rem 0rem 0rem;
        }
        .updateInv-nameInput {
            width: 200px;
            max-width: 400px;
            margin-bottom: .5rem !important;
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
            width: auto;
            margin-bottom: 1rem;
        }
        .modify-btn:hover {
            opacity: 0.85;
        }
        .modify-save-btn,
        .modify-cancel-btn {
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
        .modify-delete-btn:hover{
            opacity: 0.75;
            background-color: darkred;
        }
        .modify-save-btn:hover,
        .modify-cancel-btn:hover {
            opacity: 0.85;
        }
        .modifyUsers-formBtns{
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .delete-row-btn {
            background-color: darkred;
            color: var(--button-font-color);
        }
        .delete-row-btn:hover{
            opacity: 0.75;
            background-color: darkred;
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
                margin-right: 10%;
            }
        }
    </style>
</head>
<pageheader>
    <h1 class="title">Add New Pallet</h1>
</pageheader>
<body>
    <?php require_once('header.php') ?>
    <main>
        <div class="report-container">
            <?php 
                /* Display errors from submitting pallet */
                if (!empty($errors)): ?>
                <ul>
                    <?php foreach($errors AS $error): ?>
                        <li><?php echo("<h4 style=\"color:red;\"><i>".$error."</i></h4>"); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <!-- Add Pallet -->
            <div class="report-section">
                <h2>Pallet Input</h2>              
                <form name="palletForm" method="POST" action="viewAddPallet.php">
                    <div class="updateInv-nameRow">
                        <div class="updateInv-name">
                            <label class="updateInv-nameLabel" for="name">Pallet Name:</label>
                            <input type="text" class="updateInv-nameInput" min="0" placeholder="Qty" 
                                            value="<?= $pallet_name == "PALLET_PLACEHOLDER_NAME" ? 'Pallet' : $pallet_name ?>"
                                            name="name" 
                                            id="name">
                        </div>
                    </div>
                        <div class="table-wrapper">
                            <table class="report-table" id="palletTable">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Boxes</th>
                                        <th>Banana Box</th>
                                        <th>Items Per Box</th>
                                        <th>Expiration Date</th>
                                        <th> </th>
                                    </tr>
                                </thead>
                                <tbody>
                                     
                        <?php $allCategories = get_all_active_ItemCategory(); ?>
                        <?php $row_count = 0; ?>
                        <?php foreach($categories_by_row AS $categoryid): ?>
                            <?php
                                // Skip rows for shopping list only items
                                if (!empty($categoryid)) {
                                    $rowCategory = retrieve_ItemCategory($categoryid);
                                    if ($rowCategory && $rowCategory->getShopOnly() == 1) {
                                        $row_count++;
                                        continue;
                                    }
                                }
                            ?>
                            <?php if(empty($categoryid)) : ?>
                                <tr class="rowClass">
                                    <div class="updateInv-row">
                                        <td>
                                            <select name="category_<?php echo($row_count); ?>" id="category_<?php echo($row_count); ?>" onchange="updateCategoryColumns(this)">
                                                <option value="">-- Select Category --</option>
                                                <?php foreach($allCategories AS $category): ?>
                                                    <?php if ($category->getShopOnly() == 1) { continue; } ?>
                                                    <option value="<?php echo($category->getId()."_".$category->getBananaBox()."_".$category->getItemsPerBox())?>"><?php echo($category->getName())?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="number" class="updateInv-qty" min="0" placeholder="Qty" 
                                                value="<?php echo(isset($quantities_by_row[$row_count]) ? $quantities_by_row[$row_count] : ''); ?>"
                                                name="qty_<?php echo($row_count); ?>" 
                                                id="qty_<?php echo($row_count); ?>"></td>
                                        <td><div style="text-align: center;" class="bb_<?php echo($row_count); ?>"></div></td>
                                        <td><div style="text-align: center;" class="ipb_<?php echo($row_count); ?>"></div></td>
                                        <td><input type="date" class="updateInv-exp"
                                                value="<?php echo(isset($expirations_by_row[$row_count]) ? $expirations_by_row[$row_count] : ''); ?>"
                                                name="exp_<?php echo($row_count); ?>" 
                                                id="exp_<?php echo($row_count); ?>"></td>
                                        <td style="text-align: center;"><button type="button" class="delete-row-btn" onclick="removeRow(this)">Remove</button></td>

                                    </div>
                                </tr> 
                            <?php else : ?>
                                <?php $category = retrieve_ItemCategory($categoryid); ?>
                                <tr class="rowClass">
                                    <div class="updateInv-row">
                                        <td>
                                            <select name="category_<?php echo($row_count); ?>" id="category_<?php echo($row_count); ?>" onchange="updateCategoryColumns(this)">
                                                <option value="">-- Select Category --</option>
                                                <?php foreach($allCategories AS $cat): ?>
                                                    <?php if ($cat->getShopOnly() == 1) { continue; } ?>
                                                    <option value="<?php echo($cat->getId()."_".$cat->getBananaBox()."_".$cat->getItemsPerBox())?>" <?php if($cat->getId() == $category->getId()) echo("selected")?>><?php echo($cat->getName())?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><input type="number" class="updateInv-qty" min="0" placeholder="Qty" 
                                                value="<?php echo(isset($quantities_by_row[$row_count]) ? $quantities_by_row[$row_count] : ''); ?>"
                                                name="qty_<?php echo($row_count); ?>" 
                                                id="qty_<?php echo($row_count); ?>"></td>
                                        <td><div style="text-align: center;" class="bb_<?php echo($row_count); ?>"><?php echo($category->getBananaBox() == 1 ? '✓' : '')?></div></td>
                                        <td><div style="text-align: center;" class="ipb_<?php echo($row_count); ?>"><?php echo($category->getItemsPerBox())?></div></td>
                                        <td><input type="date" class="updateInv-exp"
                                                value="<?php echo(isset($expirations_by_row[$row_count]) ? $expirations_by_row[$row_count] : ''); ?>"
                                                name="exp_<?php echo($row_count); ?>" 
                                                id="exp_<?php echo($row_count); ?>"></td>
                                        <td style="text-align: center;"><button type="button" class="delete-row-btn" onclick="removeRow(this)">Remove</button></td>
                                    </div>
                                </tr>
                            <?php endif; ?>
                            <?php $row_count++; ?>
                        <?php endforeach; ?>
                        <?php if(empty($categories_by_row)) : ?>
                            <tr class="rowClass">
                                <div class="updateInv-row">
                                    <td>
                                        <select name="category_0" id="category_0" onchange="updateCategoryColumns(this)">
                                            <option value="">-- Select Category --</option>
                                            <?php foreach($allCategories AS $category): ?>
                                                <?php if ($category->getShopOnly() == 1) { continue; } ?>
                                                <option value="<?php echo($category->getId()."_".$category->getBananaBox()."_".$category->getItemsPerBox())?>"><?php echo($category->getName())?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" class="updateInv-qty" min="0" placeholder="Qty" 
                                            name="qty_0" 
                                            id="qty_0"></td>
                                    <td><div style="text-align: center;" class="bb_0"></div></td>
                                    <td><div style="text-align: center;" class="ipb_0"></div></td>
                                    <td><input type="date" class="updateInv-exp"
                                                name="exp_0" 
                                                id="exp_0"></td>
                                    <td style="text-align: center;"><button type="button" class="delete-row-btn" onclick="removeRow(this)">Remove</button></td>
                                </div>
                            </tr> 
                            <?php $row_count++; ?>
                        <?php endif; ?>
                        <div id="row-counter-element" data-count="<?php echo $row_count; ?>"></div>
                        
                                </tbody>
                            </table>
                            <div style="margin-bottom: 1rem;"></div>
                            <button type="button" class="modify-btn" onclick="addCategoryRow()">Add Row</button>
                        </div>
                    </div>
                    
                    <div class="modifyUsers-formBtns">
                        <button name="save_button" class="modify-save-btn">Save New Pallet</button>
                    </div>
                    <div class="modifyUsers-formBtns">
                        <button name="cancel_button" class="modify-cancel-btn" formnovalidate>Cancel</button>
                    </div>
                    
                    
                </form>
            </div>

        </div>

        <script>
            const element = document.getElementById('row-counter-element');
            var row_count = parseInt(element.getAttribute('data-count'), 10);

            function addCategoryRow() {
                // Find a <table> element with id="palletTable":
                var table = document.getElementById("palletTable");

                // Create an empty <tr> element and add it to the end of the table:
                var row = table.insertRow();

                // Insert new cells (<td> elements) at the 1st and 2nd position of the "new" <tr> element:
                var name = row.insertCell(0);
                var quantity = row.insertCell(1);
                var bananaBox = row.insertCell(2);
                var itemsPerBox = row.insertCell(3);
                var expiration = row.insertCell(4);
                var removeRow = row.insertCell(5);
                
                let dropdown = document.querySelector('[id^=category_]');
                let new_dropdown = dropdown.cloneNode(true);
                new_dropdown.name = 'category_' + row_count;
                new_dropdown.id = 'category_' + row_count;
                new_dropdown.selectedIndex = 0;
                name.append(new_dropdown);

                let qty_input = document.querySelector('[id^=qty_]');
                let new_qty_input = qty_input.cloneNode(true);
                new_qty_input.name = 'qty_' + row_count;
                new_qty_input.id = 'qty_' + row_count;
                new_qty_input.value = "";
                quantity.append(new_qty_input);

                // Add some text to the new cells:
                bananaBox.innerHTML = "<div style=\"text-align: center;\" class=\"bb_" + row_count + "\"></div>";
                itemsPerBox.innerHTML = "<div style=\"text-align: center;\" class=\"ipb_" + row_count + "\"></div>";

                let exp_input = document.querySelector('[id^=exp_]');
                let new_exp_input = exp_input.cloneNode(true);
                new_exp_input.name = 'exp_' + row_count;
                new_exp_input.id = 'exp_' + row_count;
                new_exp_input.value = "";
                expiration.append(new_exp_input);

                removeRow.innerHTML = "<button type=\"button\" class=\"delete-row-btn\" onclick=\"removeRow(this)\">Remove</button>";

                row_count++;
            }

            function updateCategoryColumns(element){
                const rowId = element.id.split("_")[1];
                const categoryId = element.value.split("_")[0];
                const bananaBox = element.value.split("_")[1];  
                const itemsPerBox = element.value.split("_")[2];
                if(categoryId == ""){
                    document.querySelector(".bb_" + rowId).innerHTML = "";
                    document.querySelector(".ipb_" + rowId).innerHTML = "";
                    return;
                }
                const isBananaBox = bananaBox == 1 ? '✓' : '';
                document.querySelector(".bb_" + rowId).innerHTML = isBananaBox;
                document.querySelector(".ipb_" + rowId).innerHTML = itemsPerBox;
            }

            function removeRow(button){
                const table = document.getElementById("palletTable");
                if(table.rows.length <= 2){
                    alert("Pallet must have at least 1 item");
                    return;
                }
                button.closest("tr").remove();
                
            }

        </script>
    </main>
</body>
</html>
