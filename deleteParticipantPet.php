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

// Only admins can access this page
if ($accessLevel < 2) {
    header('Location: index.php');
    die();
}

require_once('database/dbParticipants.php');
require_once('database/dbPets.php');

// Mark pet as inactive when the "Mark Inactive" button is clicked
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_pet'])) {

    $petID = $_POST['pet_id'] ?? '';

    if ($petID !== '') {
        deactivate_pet($petID);

        header(
    'Location: deleteParticipantPet.php?participant_id=' .
    urlencode($_GET['participant_id'])
);
die();
    }
}

// Delete a pet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pet'])) {

    $petID = $_POST['pet_id'] ?? '';

    if ($petID !== '') {

        delete_pet($petID);

        header(
            'Location: deleteParticipantPet.php?participant_id=' .
            urlencode($_GET['participant_id'])
        );

        die();
    }
}

// Mark a pet as active again
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_pet'])) {

    $petID = $_POST['pet_id'] ?? '';

    if ($petID !== '') {

        activate_pet($petID);

        header(
            'Location: deleteParticipantPet.php?participant_id=' .
            urlencode($_GET['participant_id'])
        );

        die();
    }
}

// Mark participant inactive
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_participant'])) {

    $participantID = $_POST['participant_id'] ?? '';

    if ($participantID !== '') {

        deactivate_participant($participantID);

        header(
            'Location: deleteParticipantPet.php?participant_id=' .
            urlencode($participantID)
        );

        die();
    }
}


// Mark participant active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_participant'])) {

    $participantID = $_POST['participant_id'] ?? '';

    if ($participantID !== '') {

        activate_participant($participantID);

        header(
            'Location: deleteParticipantPet.php?participant_id=' .
            urlencode($participantID)
        );

        die();
    }
}


// Delete participant
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_participant'])) {

    $participantID = $_POST['participant_id'] ?? '';

    if ($participantID !== '') {

        delete_participant($participantID);

        header(
            'Location: deleteParticipantPet.php?participant_id=' .
            urlencode($participantID)
        );

        die();
    }
}

// Search results
$searchResults = [];
$searchTerm = '';

if (isset($_GET['q'])) {
    $searchTerm = trim($_GET['q']);

    if ($searchTerm !== '') {
        $searchResults = find_participants($searchTerm, 100, true);
    }
}

// Selected participant
$selectedParticipant = null;
$participantPets = [];

if (isset($_GET['participant_id'])) {

    $participantID = $_GET['participant_id'];

    $selectedParticipant = retrieve_participant($participantID);

    if ($selectedParticipant) {
        $participantPets = get_pets_by_participant($participantID, true);
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="./css/base.css" rel="stylesheet">

    <title>Remove Participant / Pet</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f7f7f7;
            margin: 0;
        }

        .page-container {
            width: 90%;
            max-width: 900px;
            margin: 50px auto;
        }

        .remove-box {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            border: 1px solid #ccc;
        }

        .remove-box h2 {
            color: #1f5968;
            margin-top: 0;
        }

        .search-form {
            margin-top: 25px;
        }

        .search-form label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .search-form input {
            width: 100%;
            padding: 10px;
            font-size: 16px;
            box-sizing: border-box;
            margin-bottom: 15px;
        }

        .search-button {
            background-color: #1f5968;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
        }

        .search-button:hover {
            background-color: #174550;
        }

        .search-results {
    margin-top: 30px;
}

.search-results h3 {
    color: #1f5968;
}

.participant-result {
    border: 1px solid #ccc;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}

.participant-result p {
    margin: 5px 0;
}

.select-button {
    display: inline-block;
    margin-top: 10px;
    background-color: #1f5968;
    color: white;
    text-decoration: none;
    padding: 8px 15px;
    border-radius: 5px;
}

.select-button:hover {
    background-color: #174550;
}

.selected-participant {
    margin-top: 30px;
    border-top: 2px solid #1f5968;
    padding-top: 20px;
}

.selected-participant h3 {
    color: #1f5968;
}

.participant-info,
.pet-result {
    border: 1px solid #ccc;
    border-radius: 8px;
    padding: 15px;
    margin-top: 10px;
}

.pet-result {
    margin-bottom: 10px;
}

.participant-info p,
.pet-result p {
    margin: 5px 0;
}

.remove-button {
    background-color: #b94a48;
    color: white;
    border: none;
    padding: 8px 15px;
    margin-top: 10px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
}

.remove-button:hover {
    background-color: #943b39;
}

.delete-button {
    background-color: #8b0000;
    color: white;
    border: none;
    padding: 8px 15px;
    margin-top: 10px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
}

.delete-button:hover {
    background-color: #660000;
}

.active-button {
    background-color: #2e7d32;
    color: white;
    border: none;
    padding: 8px 15px;
    margin-top: 10px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
}

.active-button:hover {
    background-color: #1f5c23;
}

    </style>
</head>

<body>

<?php require 'header.php'; ?>

<div class="page-container">

    <div class="remove-box">

        <h2>Remove Participant / Pet</h2>

        <p>
            Search for the participant whose account or pet you would like to remove.
        </p>

        <form class="search-form"
              method="get"
              action="deleteParticipantPet.php">

            <label for="q">
                Participant ID, Name, Address, or Phone Number
            </label>

            <input
                type="text"
                id="q"
                name="q"
                maxlength="<?php echo PARTICIPANT_SEARCH_MAX_LENGTH; ?>"
                placeholder="Ex. 12, Maria Gonzalez, or 540-555-0101"
                required
            >

            <button class="search-button" type="submit">
                Search
            </button>

        </form>

        <?php if (isset($_GET['q'])): ?>

    <div class="search-results">

        <h3>Search Results</h3>

        <?php if (empty($searchResults)): ?>

            <p>No participants were found.</p>

        <?php else: ?>

            <?php foreach ($searchResults as $participant): ?>

                <div class="participant-result">

                    <strong>
                        <?php
                        echo htmlspecialchars(
                            $participant->get_first_name() . ' ' .
                            $participant->get_last_name()
                        );
                        ?>
                    </strong>

                    <p>
                        Participant ID:
                        <?php echo htmlspecialchars($participant->get_id()); ?>
                    </p>

                    <p>
                        <?php
                        echo htmlspecialchars($participant->get_street_address());
                        ?>
                    </p>

                    <a class="select-button"
                       href="deleteParticipantPet.php?participant_id=<?php echo urlencode($participant->get_id()); ?>">
                        Select Participant
                    </a>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

<?php endif; ?>

    <?php if ($selectedParticipant): ?>

    <div class="selected-participant">

        <h3>Selected Participant</h3>

        <div class="participant-info">

            <strong>
                <?php
                echo htmlspecialchars(
                    $selectedParticipant->get_first_name() . ' ' .
                    $selectedParticipant->get_last_name()
                );
                ?>
            </strong>

            <p>
                Participant ID:
                <?php echo htmlspecialchars($selectedParticipant->get_id()); ?>
            </p>

            <p>
                <?php echo htmlspecialchars($selectedParticipant->get_street_address()); ?>
            </p>

            <p>
                Status:
                <?php echo htmlspecialchars($selectedParticipant->get_status()); ?>
            </p>
            <?php if ($selectedParticipant->get_status() === 'Active'): ?>

    <!-- Mark Participant Inactive -->
    <form method="post" action="deleteParticipantPet.php">

        <input type="hidden"
               name="participant_id"
               value="<?php echo htmlspecialchars($selectedParticipant->get_id()); ?>">

        <button type="submit"
                name="deactivate_participant"
                class="remove-button"
                onclick="return confirm('Are you sure you want to mark this participant as inactive?');">
            Mark Inactive
        </button>

    </form>

    <!-- Delete Participant -->
    <form method="post" action="deleteParticipantPet.php">

        <input type="hidden"
               name="participant_id"
               value="<?php echo htmlspecialchars($selectedParticipant->get_id()); ?>">

        <button type="submit"
                name="delete_participant"
                class="delete-button"
                onclick="return confirm('Are you sure you want to delete this participant?');">
            Delete Participant
        </button>

    </form>


<?php elseif ($selectedParticipant->get_status() === 'Inactive'): ?>

    <p><strong>This participant is inactive.</strong></p>

    <!-- Mark Participant Active -->
    <form method="post" action="deleteParticipantPet.php">

        <input type="hidden"
               name="participant_id"
               value="<?php echo htmlspecialchars($selectedParticipant->get_id()); ?>">

        <button type="submit"
                name="activate_participant"
                class="active-button"
                onclick="return confirm('Are you sure you want to mark this participant as active again?');">
            Mark Active
        </button>

    </form>

    <!-- Delete Participant -->
    <form method="post" action="deleteParticipantPet.php">

        <input type="hidden"
               name="participant_id"
               value="<?php echo htmlspecialchars($selectedParticipant->get_id()); ?>">

        <button type="submit"
                name="delete_participant"
                class="delete-button"
                onclick="return confirm('Are you sure you want to delete this participant?');">
            Delete Participant
        </button>

    </form>


<?php elseif ($selectedParticipant->get_status() === 'Deleted'): ?>

    <p><strong>This participant has been deleted.</strong></p>

<?php endif; ?>

        </div>


        <h3>Pets</h3>

        <?php if (empty($participantPets)): ?>

            <p>This participant does not have any pets registered.</p>

        <?php else: ?>

            <?php foreach ($participantPets as $pet): ?>

                <div class="pet-result">

                    <strong>
                        🐾 <?php echo htmlspecialchars($pet->get_name()); ?>
                    </strong>

                    <p>
                        Species:
                        <?php echo htmlspecialchars($pet->get_species()); ?>
                    </p>

                    <p>
                        Breed:
                        <?php echo htmlspecialchars($pet->get_breed() ?? 'Not provided'); ?>
                    </p>

                    <p>
                        Status:
                        <?php echo htmlspecialchars($pet->get_status()); ?>
                    </p>

                    <?php if ($pet->get_status() === 'Active'): ?>

    <!-- Mark Inactive -->
    <form method="post"
          action="deleteParticipantPet.php?participant_id=<?php
              echo urlencode($selectedParticipant->get_id());
          ?>">

        <input type="hidden"
               name="pet_id"
               value="<?php echo htmlspecialchars($pet->get_id()); ?>">

        <button type="submit"
                name="remove_pet"
                class="remove-button"
                onclick="return confirm('Are you sure you want to mark this pet as inactive?');">
            Mark Inactive
        </button>

    </form>

    <!-- Delete Pet -->
    <form method="post"
          action="deleteParticipantPet.php?participant_id=<?php
              echo urlencode($selectedParticipant->get_id());
          ?>">

        <input type="hidden"
               name="pet_id"
               value="<?php echo htmlspecialchars($pet->get_id()); ?>">

        <button type="submit"
                name="delete_pet"
                class="delete-button"
                onclick="return confirm('Are you sure you want to delete this pet?');">
            Delete Pet
        </button>

    </form>


<?php elseif ($pet->get_status() === 'Inactive'): ?>

    <p><strong>This pet is inactive.</strong></p>

    <!-- Mark Active -->
<form method="post"
      action="deleteParticipantPet.php?participant_id=<?php
          echo urlencode($selectedParticipant->get_id());
      ?>">

    <input type="hidden"
           name="pet_id"
           value="<?php echo htmlspecialchars($pet->get_id()); ?>">

    <button type="submit"
            name="activate_pet"
            class="active-button"
            onclick="return confirm('Are you sure you want to mark this pet as active again?');">
        Mark Active
    </button>

</form>

    <!-- An inactive pet can still be deleted -->
    <form method="post"
          action="deleteParticipantPet.php?participant_id=<?php
              echo urlencode($selectedParticipant->get_id());
          ?>">

        <input type="hidden"
               name="pet_id"
               value="<?php echo htmlspecialchars($pet->get_id()); ?>">

        <button type="submit"
                name="delete_pet"
                class="delete-button"
                onclick="return confirm('Are you sure you want to delete this pet?');">
            Delete Pet
        </button>

    </form>


<?php elseif ($pet->get_status() === 'Deleted'): ?>

    <p><strong>This pet has been deleted.</strong></p>

<?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

<?php endif; ?>

    </div>

</div>

</body>
</html>