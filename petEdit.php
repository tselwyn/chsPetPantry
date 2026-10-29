<?php
    // Edit a pet's information, opened from the pets table on participantEdit.php
    // Make session information accessible, allowing us to associate
    // data with the logged-in user.
    session_cache_expire(30);
    session_start();

    $loggedIn = false;
    $accessLevel = 0;
    $userID = null;
    if (isset($_SESSION['_id'])) {
        $loggedIn = true;
        // 0 = not logged in, 1 = standard user, 2 = manager (Admin), 3 super admin (TBI)
        $accessLevel = $_SESSION['access_level'];
        $userID = $_SESSION['_id'];
    }
    // Volunteers and admins can both edit pets (SRS UC-05)
    if ($accessLevel < 1) {
        header('Location: index.php');
        die();
    }

    require_once('include/output.php');
    require_once('include/pet-form.php');
    require_once('database/dbParticipants.php');
    require_once('database/dbPets.php');

    $id = (isset($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $pet = $id > 0 ? get_pet_by_id($id) : null;
    // A deleted pet is kept only for history, so it is treated as not found
    if ($pet !== null && $pet->get_status() === 'Deleted') {
        $pet = null;
    }
    $owner = $pet !== null ? retrieve_participant($pet->get_participant_id()) : null;

    $errors = array();
    $args = array();
    if ($pet !== null) {
        // What the form shows until something is submitted
        $args = array(
            'name' => $pet->get_name(),
            'species' => $pet->get_species(),
            'breed' => (string) $pet->get_breed(),
            'sex' => $pet->get_sex(),
            'date_of_birth' => (string) $pet->get_date_of_birth(),
            'dob_is_estimate' => (string) $pet->get_dob_is_estimate(),
            'weight_lbs' => (string) $pet->get_weight_lbs(),
            'food_type' => (string) $pet->get_food_type(),
            'food_restriction' => (string) $pet->get_food_restriction(),
            'notes' => (string) $pet->get_notes(),
            'spay_neuter' => $pet->get_spay_neuter_status(),
            'rabies_status' => $pet->get_rabies_status()
        );
    }

    if ($pet !== null && $_SERVER["REQUEST_METHOD"] == "POST") {
        foreach (array_keys($args) as $field) {
            $args[$field] = (isset($_POST[$field]) && is_string($_POST[$field])) ? trim($_POST[$field]) : '';
        }

        $errors = pet_form_errors($args);

        if (count($errors) == 0) {
            if (update_pet(pet_from_form($pet->get_id(), $pet->get_participant_id(), $args, $pet->get_status()))) {
                // Back to the participant; the redirect also stops a refresh from saving again
                header('Location: participantEdit.php?id=' . (int) $pet->get_participant_id() . '&petSaved#pets');
                die();
            }
            $errors[] = 'The pet could not be saved. Please try again.';
        }
    }
?>

<!DOCTYPE html>
<html>
<head>
    <title>CHS Pet Pantry | Edit Pet</title>
    <link href="css/base.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<!-- BANDAID END, REMOVE ONCE SOME GENIUS FIXES -->
</head>
<body class="relative">

<header class="hero-header">
    <div class="center-header">
        <h1>Edit Pet</h1>
    </div>
</header>

<main>
  <div class="main-content-box">
<?php if ($pet === null): ?>
    <div class="signup-form">
        <div class="error-toast">That pet could not be found.</div>
    </div>

    <div class="text-center">
        <a href="participantSearch.php" class="button">Back to Participant Search</a>
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>
<?php else: ?>
    <form class="signup-form" method="post">
        <div class="text-center spacing-bottom">
            <h2 class="mb-8"><?php echo hsc($pet->get_name()) ?></h2>
            <div class="info-box">
                <p class="sub-text">
                    <?php if ($owner !== null): ?>
                    Owned by <?php echo hsc($owner->get_first_name() . ' ' . $owner->get_last_name()) ?> (participant ID <?php echo (int) $owner->get_id() ?>).
                    <?php endif ?>
                    Update the pet's information below.
                </p>
                <p>An asterisk ( <em>*</em> ) indicates a required field.</p>
            </div>
        </div>

        <?php if (count($errors) > 0): ?>
        <div class="error-toast">
            <p>The pet was not saved:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?php echo hsc($error) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>

        <fieldset class="section-box mb-4">
            <h3 class="mt-2">Pet Information</h3>
            <div class="blue-div"></div>

            <?php require('petFormFields.php') ?>
        </fieldset>

        <input type="submit" value="Save Changes">
    </form>

    <div class="text-center">
        <a href="participantEdit.php?id=<?php echo (int) $pet->get_participant_id() ?>#pets" class="button">Cancel</a>
    </div>
<?php endif ?>
  </div>
</main>

</body>
</html>
