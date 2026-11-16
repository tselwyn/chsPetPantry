<?php
session_start();

require_once('include/input-validation.php');
require_once('database/dbinfo.php');

$error = '';
$token = $_GET['token'] ?? '';

if (!is_string($token) ||
    !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    exit('Invalid or expired password reset link.');
}

$tokenHash = hash('sha256', $token);
$con = connect();

// Check that the token exists and is still valid.
$stmt = $con->prepare(
    "SELECT person_id, token_type
     FROM dbpasswordresets
     WHERE token_hash = ?
       AND used_at IS NULL
       AND expires_at > NOW()
     LIMIT 1"
);

$stmt->bind_param('s', $tokenHash);
$stmt->execute();
$result = $stmt->get_result();
$reset = $result->fetch_assoc();
$stmt->close();

if (!$reset) {
    http_response_code(400);
    exit('Invalid or expired password reset link.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new-password'] ?? '';
    $confirmPassword = $_POST['new-password-reenter'] ?? '';

    if (!is_string($newPassword) ||
        !is_string($confirmPassword)) {
        $error = 'Invalid password input.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (!isSecurePassword($newPassword)) {
        $error = 'Password must have at least 8 characters, '
               . 'including uppercase, lowercase, and a number.';
    } else {
        $passwordHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        try {
            $con->begin_transaction();

            // Lock the reset record to prevent reuse.
            $stmt = $con->prepare(
                "SELECT person_id, token_type
                 FROM dbpasswordresets
                 WHERE token_hash = ?
                   AND used_at IS NULL
                   AND expires_at > NOW()
                 FOR UPDATE"
            );
            $stmt->bind_param('s', $tokenHash);
            $stmt->execute();
            $lockedReset = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$lockedReset) {
                throw new RuntimeException(
                    'Invalid or expired password reset link.'
                );
            }

            $personId = (int)$lockedReset['person_id'];

            // Initial setup links can only be used before
// the account has established its first password.
if ($lockedReset['token_type'] === 'setup') {
    $stmt = $con->prepare(
        "SELECT password
         FROM dbpersons
         WHERE personId = ?
           AND status = 'Active'
         FOR UPDATE"
    );
    $stmt->bind_param('i', $personId);
    $stmt->execute();
    $person = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$person || $person['password'] !== 'SET-A-PASSWORD') {
        throw new RuntimeException(
            'This account has already been set up.'
        );
    }
}

            // Update the account password.
            $stmt = $con->prepare(
                "UPDATE dbpersons
                 SET password = ?,
                     force_password_change = 0
                 WHERE personId = ?
                   AND status = 'Active'"
            );
            $stmt->bind_param(
                'si',
                $passwordHash,
                $personId
            );
            $stmt->execute();
            $updated = $stmt->affected_rows;
            $stmt->close();

            if ($updated !== 1) {
                throw new RuntimeException(
                    'Unable to update this account.'
                );
            }

            // Invalidate every outstanding reset
            // token for this account.
            $stmt = $con->prepare(
                "UPDATE dbpasswordresets
                 SET used_at = NOW()
                 WHERE person_id = ?
                   AND used_at IS NULL"
            );
            $stmt->bind_param('i', $personId);
            $stmt->execute();
            $stmt->close();

            $con->commit();

            header('Location: login.php?pcSuccess');
            exit();

        } catch (Throwable $e) {
            $con->rollback();
            error_log('Password reset failed: ' . $e->getMessage());
            $error = 'Unable to reset password. '
                   . 'Please request a new link.';
        }
    }
}

$con->close();
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

        <a href="login.php" class="back-btn">← Back</a>

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