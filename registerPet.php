<?php
// Start the session so we can access the logged-in user's information
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

// Only admins can access pet registration
if ($accessLevel < 2) {
    header('Location: index.php');
    die();
}

require_once('database/dbParticipants.php');
require_once('database/dbPets.php');

$participants = get_all_participants();
//if coming from participant registration get participantID from URL
$selected_participant_id = $_GET['participant_id'] ?? '';

$success = false;
$errors = array();

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Get information submitted by the form
    $participant_id = $_POST['participant_id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $species = $_POST['species'] ?? '';
    $breed = trim($_POST['breed'] ?? '');
    $sex = $_POST['sex'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $dob_is_estimate = $_POST['dob_is_estimate'] ?? '1';
    $weight_lbs = $_POST['weight_lbs'] ?? '';
    $food_type = $_POST['food_type'] ?? '';
    $food_restriction = $_POST['food_restriction'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $spay_neuter = $_POST['spay_neuter'] ?? '';

    // Make sure required fields were entered
    if ($participant_id === '') {
        $errors[] = 'Please select a participant.';
    }

    if ($name === '') {
        $errors[] = 'Please enter the pet name.';
    }

    if ($species === '') {
        $errors[] = 'Please select a species.';
    }

    if ($spay_neuter === '') {
        $errors[] = 'Please select whether the pet is spayed/neutered.';
    }

    // Convert Yes/No into the value stored by the database
    $is_altered = ($spay_neuter === 'yes') ? 1 : 0;

    // Optional database fields should be NULL when left blank
    $breed = ($breed === '') ? null : $breed;
    $date_of_birth = ($date_of_birth === '') ? null : $date_of_birth;
    $weight_lbs = ($weight_lbs === '') ? null : $weight_lbs;
    $food_type = ($food_type === '') ? null : $food_type;
    $food_restriction = ($food_restriction === '') ? null : $food_restriction;
    $notes = ($notes === '') ? null : $notes;

    // New pets are active automatically
    $status = 'Active';

    if (count($errors) == 0) {

        $pet = new Pet(
            null,
            $participant_id,
            $name,
            $species,
            $breed,
            $sex,
            $date_of_birth,
            $dob_is_estimate,
            $weight_lbs,
            $is_altered,
            $food_type,
            $food_restriction,
            $notes,
            $status
        );

        $pet_id = add_pet($pet);

        if ($pet_id) {
           //Save the pet's name temporarily for the confirmation page
           $_SESSION['registered_pet'] = $name;
           //redirect so refreshing the page doesn't resubmit the form
           header("Location: registerPet.php?registered");
           die();
        } else {
            $errors[] = 'The pet could not be saved. Please try again.';
        }
    } 
    }

        //name shown on the confirmation page
        $registeredPetName = null;
        if(isset($_GET['registered']) && isset($_SESSION['registered_pet'])) {
            $registeredPetName = $_SESSION['registered_pet'];
            unset($_SESSION['registered_pet']);
        }

    ?>


<!-- imports -->
<script src="https://nosir.github.io/cleave.js/dist/cleave.min.js"></script>
<script src="https://nosir.github.io/cleave.js/dist/cleave-phone.i18n.js"></script>

<!DOCTYPE html>
<html>

<head>
    <title>CHS Pet Pantry | Register Pet</title>

    <link href="css/base.css" rel="stylesheet">

    <?php
    $tailwind_mode = true;
    require_once('header.php');
    ?>
</head>

<body class="relative">

<!-- Hero Section with Title -->
<header class="hero-header"> 
    <div class="center-header">
        <h1>Pet Registration</h1>
    </div>
</header>

<main>
  <div class="main-content-box">

  <?php if ($registeredPetName !== null): ?>
    <div class="happy-toast">
        <?php echo htmlspecialchars($registeredPetName); ?>
        has been successfully registered!
    </div>

    <div class="text-center">
        <a href="registerPet.php" class="button">
            Register Another Pet
        </a>
        <a href="index.php" class="button">
            Return to Dashboard
        </a>
    </div>
    <?php else: ?>

	<form class="signup-form" method="post">
        
        
        <fieldset class="section-box mb-4">

            <h3 class="mt-2">Pet Information</h3>
            <p class="mb-2">
            Please enter the pet's information.
            </p>

            <div class="blue-div"></div>

           <label for="participant_id">* Participant</label>

<?php if ($selected_participant_id !== ''): ?>

    <?php
    $selectedParticipantName = '';

    foreach ($participants as $participant) {
        if ($participant->get_id() == $selected_participant_id) {
            $selectedParticipantName =
                $participant->get_first_name() . ' ' .
                $participant->get_last_name();
            break;
        }
    }
    ?>

    <input
        type="text"
        value="<?php echo htmlspecialchars($selectedParticipantName); ?>"
        readonly
    >

    <input
        type="hidden"
        name="participant_id"
        value="<?php echo htmlspecialchars($selected_participant_id); ?>"
    >

<?php else: ?>

    <select id="participant_id" name="participant_id" required>

        <option value="">Select Participant</option>

        <?php foreach ($participants as $participant): ?>

            <option value="<?php echo $participant->get_id(); ?>">
                <?php
                echo htmlspecialchars(
                    $participant->get_first_name() . ' ' .
                    $participant->get_last_name()
                );
                ?>
            </option>

        <?php endforeach; ?>

    </select>

<?php endif; ?>

        <label for="name">
            <em>* </em>Name
        </label>
        <input
            type="text"
            id="name"
            name="name"
            required
            placeholder="Enter pet's name"
        >
        
        <label for="species">
            <em>* </em>Species
        </label>
        <select id="species" name="species" required>
            <option value="">Select Species</option>
            <option value="Dog">Dog</option>
            <option value="Cat">Cat</option>
            <option value="Other">Other</option>
        </select>

        <label for="breed">Breed</label>
        <input
            type="text"
            id="breed"
            name="breed"
            placeholder="Enter breed"
        >

        <label for="date_of_birth">Date of Birth</label>
        <input
            type="date"
            id="date_of_birth"
            name="date_of_birth"
        >

        <label for="dob_is_estimate">Is this date estimated?</label>
        <select id="dob_is_estimate" name="dob_is_estimate">
        <option value="1">Yes</option>
        <option value="0">No</option>
        </select>

        <label for="weight_lbs">Weight(lbs)</label>
        <input
            type="number"
            id="weight_lbs"
            name="weight_lbs"
            min="0"
            step="0.1"
            placeholder="Enter weight"
        >

        <label for="sex">Sex</label>
        <select id="sex" name="sex">
            <option value="Unknown">Unknown</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
        </select>

        <label for="spay_neuter">Spay/Neuter</label>
        <select id="spay_neuter" name="spay_neuter" required>
            <option value="yes">Yes</option>
            <option value="no">No</option>
        </select>

        <label for="food_type">Food Type</label>
        <select id="food_type" name="food_type">
            <option value="dry">Dry</option>
            <option value="wet">Wet</option>
            <option value="either">Either</option>
        </select>

        <label for="food_restriction">Food Restrictions</label>
        <textarea id="food_restriction" name="food_restriction" placeholder="Enter any food restrictions"></textarea>

        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" placeholder="Enter any additional notes"></textarea>

        </fieldset>

        

<?php if (count($errors) > 0): ?>

    <div class="error-toast">
        <p>The pet was not registered:</p>

        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

<?php endif; ?>

<p class="text-center notice"></p>
<input type="submit" value="Save Pet">

    </form>
    <?php endif; ?>
   </div> 
</main>

</body>
</html>
            