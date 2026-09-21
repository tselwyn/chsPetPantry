<?php

    session_start();
    ini_set("display_errors",1);
    error_reporting(E_ALL);

    require_once('include/input-validation.php');
    require_once('domain/Person.php');
    require_once('database/dbPersons.php');
    require_once('emailEncryption.php');

    if (!isset($_GET['email'])) {
        die('Invalid Link');
    }

    $email = decryptEmail($_GET['email']);
    $user = retrieve_person_by_email($email);

    if(!$user) {
        die("invalid user");
    }

    $userID = $user->get_id();
    $error = "";
    
    if ($_SERVER["REQUEST_METHOD"] == "POST") {        
            if (!wereRequiredFieldsSubmitted($_POST, array('new-password', 'new-password-reenter'))) {
                echo "Args missing";
                die();
            }
            $newPassword = $_POST['new-password'];
            $reenteredPassword = $_POST['new-password-reenter'];
            $securePassword = isSecurePassword($newPassword);

            if ($newPassword !== $reenteredPassword) {
                $error = "Passwords you entered do not match";
            } elseif(!$securePassword) {
                $error = "Password needs to be at least 8 characters long, contain at least one number, one uppercase letter, and one lowercase letter!";

            }else {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                change_password($userID, $hash);
                header('Location: index.php?pcSuccess');
                echo "Success";
                exit();
            }
        }
    

?>
<!DOCTYPE html>
<html>
    <?php require_once('universal.inc') ?>
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
        .updateInv-qty {
            width: 80px;
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
            gap: 0.5rem;
            margin-top: 1.5rem;
        }
        .modify-save-btn {
            padding: 0.6rem 1.5rem;
            background-color: var(--accent-color);
            color: var(--button-font-color);
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            font-weight: 500;
            font-size: 1rem;
        }
        .modify-save-btn:hover {
            opacity: 0.85;
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
            .updateInv-qty {
                width: 80px;
            }
        }
    </style>
    <head>
        <link rel="stylesheet" href="include/base.css">
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <link rel="stylesheet" href="css/base.css" type="text/css" />
        

        <link rel="icon" type="image/x-icon" href="images/ccda-logo-white.svg">

        <title>CCDA | Change Password</title>
    </head>
    <body>
        <?php require_once('header.php') ?>

        <a href="https://jenniferp231.sg-host.com/login.php" class="back-btn">← Back</a>

        <h1>Change Password</h1>
        <main class="login">
            <?php if (!empty($error)): ?>
                <p style="color:red;"><?php echo $error; ?></p>
            <?php endif; ?>
            <form id="password-change" method="post" >
                <label for="new-password">New Password</label>
                <input type="password" id="new-password" name="new-password" placeholder="Enter new password" required>
                <label for="new-password-reenter">Re-entered New Password</label>
                <input type="password" id="new-password-reenter" name="new-password-reenter" placeholder="Re-enter new password" required>
                <input type="submit" id="submit" name="submit" value="Change Password">
            </form>
        </main>
    </body>
</html>