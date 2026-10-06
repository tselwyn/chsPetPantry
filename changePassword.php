<?php
    // Template for new VMS pages. Base your new page on this one

    // Make session information accessible, allowing us to associate
    // data with the logged-in user.
    session_cache_expire(30);
    session_start();
    require_once('include/api.php');
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
    }
    $forced = false;
    if (isset($_SESSION['change-password']) && $_SESSION['change-password']) {
        $forced = true;
    } else if (!$loggedIn) {
        header('Location: login.php');
        die();
    }
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        require_once('include/input-validation.php');
        require_once('domain/Person.php');
        require_once('database/dbPersons.php');
        if ($forced) {
            if (!wereRequiredFieldsSubmitted($_POST, array('new-password'))) {
                echo "Args missing";
                die();
            }
            $newPassword = $_POST['new-password'];
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            change_password($userID, $hash);
            if ($userID == 'vmsroot') {
                $_SESSION['access_level'] = 3;
            } else {
                $user = retrieve_person($userID);
                $_SESSION['access_level'] = $user->get_access_level();
            }
            $_SESSION['logged_in'] = true;
            unset($_SESSION['change-password']);
            header('Location: index.php?pcSuccess');
            die();
        } else {
            if (!wereRequiredFieldsSubmitted($_POST, array('password', 'new-password'))) {
                echo "Args missing";
                die();
            }
            $password = $_POST['password'];
            $newPassword = $_POST['new-password'];
            $reenteredPassword = $_POST['new-password-reenter'];

            $securePassword = isSecurePassword($_POST['new-password']);
            $user = retrieve_person($userID);
            if (!password_verify($password, $user->get_password())) {
                $error1 = true;
            } else if($password == $newPassword) {     // old password is same as new one
                $error2 = true;
            } else if (!$securePassword) {
                $error3 = true;
            } else if ($newPassword !== $reenteredPassword) {
                $error4 = true;
            } else {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                change_password($userID, $hash);
                header('Location: index.php?pcSuccess');
                die();
            }
        }
    }
?>
<!DOCTYPE html>
<html>
<head>
    <?php require_once('universal.inc') ?>
    <title>Change Password | CCDA</title>
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
    <h1 class="title">Change Password</h1>
</pageheader>
<body>
        <?php require_once('header.php') ?>
        <main class="login">
            <?php if (isset($error1)): ?>
                <p class="error-toast">Your entry for Current Password was incorrect.</p>
            <?php elseif (isset($error2)): ?>
                <p class="error-toast">New password must be different from current password.</p>
            <?php elseif (isset($error3)): ?>
                <p class="error-toast">Your new password must contain at least 8 characters, one uppercase letter, one lowercase letter, and one number.</p>    
            <?php elseif (isset($error4)): ?>
                <p class="error-toast">Your new Password must be the same in both locations. </p>
            <?php endif ?>
            <form id="password-change" method="post">
                <?php if (!$forced): ?>
                    <label for="password">Current Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter old password" required>
                <?php else: ?>
                    <p>You must change your password before continuing.</p>
                <?php endif ?>
                <label for="new-password">New Password</label>
                <input type="password" id="new-password" name="new-password" placeholder="Enter new password" required>
                 <p id="password-error" class="error hidden">Password needs to be at least 8 characters long, contain at least one number, one uppercase letter, and one lowercase letter!</p>
                <label for="reenter-new-password">New Password</label>
                <input type="password" id="new-password-reenter" name="new-password-reenter" placeholder="Re-enter new password" required>
                <p id="password-match-error" class="error hidden">Passwords must match!</p>
                <input type="submit" id="submit" name="submit" value="Change Password">
                <?php if (!$forced): ?>
                    <a class="button cancel" href="index.php">Cancel</a>
                <?php endif ?>
            </form>
        </main>
    </body>
</html>