<?php
    session_cache_expire(30);
    session_start();
    ini_set("display_errors",1);
    error_reporting(E_ALL);
    $loggedIn = false;
    $accessLevel = 0;
    $userID = null;
    if (isset($_SESSION['_id'])) {
        $loggedIn = true;
        // 0 = not logged in, 1 = standard user, 2 = manager (Admin), 3 super admin (TBI)
        $accessLevel = $_SESSION['access_level'];
        $userID = $_SESSION['_id'];
        $personId = $_SESSION['_personId'];
    }

    // Was an ID supplied?
    if ($_SERVER["REQUEST_METHOD"] == "GET" && !isset($_GET['id'])) {
        header('Location: index.php');
        die();
    } 

    // Is user authorized to view this page?
    if ($accessLevel < 2) {
        header('Location: index.php');
        die();
    }
    
    require_once('include/input-validation.php');
    require_once('database/dbPersons.php');

    // Does the person exist?
    $thePerson = retrieve_person_by_personId($_GET['id']);
    if (!$thePerson) {
        echo "That user does not exist";
        die();
    }
    
    // Is user authorized to view this page?
    if ($accessLevel < 2) {
        header('Location: index.php');
        die();
    }    

    /* 
    * _POST is empty when the page is first loaded.
    *  Submitting the form on this page reloads the page with data in _POST
    *  if _POST is not empty, process data from form
    */
    $submit_success = false;
    $errors = [];
    if (!empty($_POST)) {
        if(isset($_POST["cancel_button"])){
            header('Location: viewAuditUsers.php');
            die();
        }
        else if(isset($_POST["deactivate_button"])){
            if($thePerson->get_Id() == $userID){
                $errors[] = "Unable to Deactivate your own account while in use";
            }
            else{
                deactivate_person($thePerson->get_personId());
                header('Location: viewAuditUsers.php');
                die();
            }
            
        }
        else if(isset($_POST["activate_button"])){
            activate_person($thePerson->get_personId());
            header('Location: viewAuditUsers.php');
            die();
        }
        else if(isset($_POST["delete_button"])){
            if($thePerson->get_Id() == $userID){
                $errors[] = "Unable to Delete your own account while in use";
            }
            else{
                delete_person($thePerson->get_personId());
                header('Location: viewAuditUsers.php');
                die();
            }
            
        }
        else if(isset($_POST["save_button"])){
            $id = $_POST["id"];
            $first_name = $_POST["fname"];
            $last_name = $_POST["lname"];
            $email = $_POST["email"];
            if(isset($_POST["role"])){
                $type = $_POST["role"];
            }
            else{
                $type = $thePerson->get_type();
            }
            if($thePerson->get_id() != $id && retrieve_person($_POST["id"])){
                $errors[] = "Username already exists";
            }
            if(!validateEmail($email) && !empty($email)){
                $errors[] = "Invalid email";
            }
            if($accessLevel < 3 && $type == "Superadmin"){
                $errors[] = "Unable to change role";
            }
            if(empty($errors)){
                if(update_person_by_personId($thePerson->get_personId(), $id, $first_name, $last_name, $email, $type)){
                    if($thePerson->get_personId() == $personId){
                        $_SESSION['_id'] = $id;
                        $_SESSION['f_name'] = $first_name;
                        $_SESSION['l_name'] = $last_name;  
                    }
                    header('Location: viewAuditUsers.php');
                    die();
                }
                else{
                    $errors[] = "Unable to update information";
                }
            }
            
        }
    }
?>
    
<!DOCTYPE html>
<html>
<head>
    <?php require_once('universal.inc') ?>
    <title>Modify User | CCDA</title>
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
            max-width: 300px;
            margin-right: 30%;
            padding: 0.4rem 0.6rem;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 0.25rem;
            background-color: rgba(0,0,0,0.2);
            color: var(--page-font-color);
            font-size: 0.9rem;
        }
        .modify-table {
            width: 100%;
            border-collapse: collapse;
        }
        .modify-table-label {
            padding: 0.75rem 1rem;
            text-align: right;
            color: var(--page-font-color);
        }
        .modify-table-input{
            padding: 0.75rem 1rem;
            text-align: left;
            color: var(--page-font-color);
        }
        .modify-table th {
            background-color: var(--main-color);
            color: var(--button-font-color);
            font-weight: 500;
        }
        .modify-role-select {
            max-width: 300px;
            margin-right: 30%;
            padding: 0.4rem 0.6rem;
        }
        .modify-status-label {
            max-width: 300px;
            margin-right: 30%;
            padding: 0.4rem 0.6rem;
        }
        .modify-save-btn,
        .modify-cancel-btn,
        .modify-delete-btn,
        .modify-activate-btn,
        .modify-deactivate-btn {
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
        .modify-deactivate-btn {
            color: red;
        }
        .modify-activate-btn {
            color: green;
        }
        .modify-delete-btn:hover{
            opacity: 0.75;
            background-color: darkred;
        }
        .modify-save-btn:hover,
        .modify-cancel-btn:hover,
        .modify-activate-btn:hover,
        .modify-deactivate-btn:hover,
        .generate-btn:hover {
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
    <h1 class="title">Modify User</h1>
</pageheader>
<body>
    <?php require_once('header.php') ?>
    <main>
        <div class="report-container">
            <?php 
                /* Display success message after submitting inventory */
                if($submit_success == true){
                    echo("<h4 style=\"color:black;\"><i>Inventory Submitted: ".date("F jS, Y", strtotime($date))."  -  ".$location."</i></h4>");
                }
                /* Display errors from submitting inventory */
                if (!empty($errors)): ?>
                <ul>
                    <?php foreach($errors AS $error): ?>
                        <li><?php echo("<h4 style=\"color:red;\"><i>Error: ".$error."</i></h4>"); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <!-- Update Inventory -->
            <div class="report-section">
                <h2>User: <?php echo $thePerson->get_id();?></h2>              
                <form name="invForm" onsubmit="return validateFormDate()" method="POST" action="viewModifyUser.php?id=<?php echo $thePerson->get_personId();?>">
                    <div class="updateInv-row">
                        <table class="modify-table">
                            <tbody>
                                <tr>
                                    <td class="modify-table-label"><label class="updateInv-label" for="id">Username: </label></td>
                                    <td class="modify-table-input">
                                        <input type="text" class="updateInv-qty" 
                                            value="<?php echo($thePerson->get_id())?>"
                                            name="id" 
                                            id="id"
                                            required>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="modify-table-label"><label class="updateInv-label" for="fname">First Name: </label></td>
                                    <td class="modify-table-input"><input type="text" class="updateInv-qty" 
                                        value="<?php echo($thePerson->get_first_name())?>"
                                        name="fname" 
                                        id="fname"
                                        required>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="modify-table-label"><label class="updateInv-label" for="lname">Last Name: </label></td>
                                    <td class="modify-table-input"><input type="text" class="updateInv-qty" 
                                        value="<?php echo($thePerson->get_last_name())?>"
                                        name="lname" 
                                        id="lname"
                                        required>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="modify-table-label"><label class="updateInv-label" for="email">Email: </label></td>
                                    <td class="modify-table-input"><input type="text" class="updateInv-qty" 
                                        value="<?php echo($thePerson->get_email())?>"
                                        name="email" 
                                        id="email"
                                        required>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="modify-table-label"><label class="updateInv-label" for="role">Role: </label></td>
                                    <td class="modify-table-input"><select name="role" class="modify-role-select" id="role" 
                                            <?php if ($thePerson->get_personId() == $personId) echo("disabled");?>>
                                            <?php if ($accessLevel >= 3):?> 
                                                <option value="Superadmin">Superadmin</option>
                                            <?php endif;?>
                                            <option value="Admin"
                                                <?php if ($thePerson->get_type() == "Admin") echo("selected");?>
                                                >Admin</option>
                                            <option value="Inventory_counter" 
                                                <?php if ($thePerson->get_type() == "Inventory_counter") echo("selected");?>
                                                >Inventory_counter</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="modify-table-label"><label class="updateInv-label">Status: </label></td>
                                    <td class="modify-table-input">
                                        <?php
                                            if($thePerson->get_status() == "Active"){
                                                echo '
                                                    <label name="status_label" class="modify-status-label" style="color: green;font-weight: 500;">Active</label>
                                                ';
                                            }
                                            else if($thePerson->get_status() == "Inactive"){
                                                echo '
                                                    <label name="status_label" class="modify-status-label" style="color: red;font-weight: 500;">Inactive</label>
                                                ';
                                            }
                                            else if($thePerson->get_status() == "Deleted"){
                                                echo '
                                                    <label name="status_label" class="modify-status-label" style="color: black;font-weight: 500;">Deleted</label>
                                                ';
                                            }
                                        ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-bottom: 4rem;"></div>
                    <div class="modifyUsers-formBtns">
                        <button name="save_button" class="modify-save-btn">Save Changes</button>
                        <button name="cancel_button" class="modify-cancel-btn" formnovalidate>Cancel</button>
                        <hr>
                        <?php
                            if($thePerson->get_status() == "Active"){
                                echo '
                                    <button name="deactivate_button" class="modify-deactivate-btn" formnovalidate>Deactivate</button>
                                ';
                            }
                            else {
                                echo '
                                    <button name="activate_button" class="modify-activate-btn" formnovalidate>Activate</button>
                                ';
                            }
                        ?>
                        <hr>
                        <button name="delete_button" name="delete_button" class="modify-delete-btn" 
                            onclick="return confirm('Are you sure you want to\nDELETE USER: <?php echo $thePerson->get_id();?>?')"
                            formnovalidate>Delete User
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </main>
    

</body>
</html>
