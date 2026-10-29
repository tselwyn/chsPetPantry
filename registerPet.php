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

// Volunteers and admins can register pets (SRS UC-05)
if ($accessLevel < 1) {
    header('Location: index.php');
    die();
}

require_once('include/output.php');
require_once('database/dbParticipants.php');
require_once('database/dbPets.php');

// The participant the pet is for: from the URL when coming from participant
// registration, "Save & Add Another Pet" or the participant page, otherwise
// chosen from the dropdown
$selected_participant_id = '';
if (isset($_GET['participant_id']) && ctype_digit($_GET['participant_id'])) {
    $selected_participant_id = $_GET['participant_id'];
}
if (isset($_POST['participant_id']) && is_string($_POST['participant_id']) && ctype_digit($_POST['participant_id'])) {
    $selected_participant_id = $_POST['participant_id'];
}

// A deleted participant is kept only for history, so it is treated as not found
$selectedParticipant = $selected_participant_id !== '' ? retrieve_participant((int) $selected_participant_id) : null;
if ($selectedParticipant !== null && $selectedParticipant->get_status() === 'Deleted') {
    $selectedParticipant = null;
}
$participantNotFound = $selected_participant_id !== '' && $selectedParticipant === null;
if ($participantNotFound) {
    $selected_participant_id = '';
}

$participants = $selectedParticipant === null ? get_all_participants() : array();

$errors = array();

// What the form shows until something is submitted
$args = array(
    'name' => '',
    'species' => '',
    'breed' => '',
    'sex' => 'Unknown',
    'date_of_birth' => '',
    'dob_is_estimate' => '1',
    'weight_lbs' => '',
    'food_type' => 'dry',
    'food_restriction' => '',
    'notes' => '',
    'spay_neuter' => 'Unknown',
    'rabies_status' => 'Unknown'
);

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // "Save & Add Another Pet" or "Done"
    $action = (isset($_POST['action']) && $_POST['action'] === 'done') ? 'done' : 'another';

    // Get information submitted by the form
    foreach (array_keys($args) as $field) {
        $args[$field] = (isset($_POST[$field]) && is_string($_POST[$field])) ? trim($_POST[$field]) : '';
    }

    // "Done" with nothing typed in just finishes; there is no pet to save
    $petEntered = $args['name'] !== '' || $args['species'] !== '' || $args['breed'] !== ''
        || $args['date_of_birth'] !== '' || $args['weight_lbs'] !== ''
        || $args['food_restriction'] !== '' || $args['notes'] !== '';

    if ($action === 'done' && !$petEntered) {
        if ($selectedParticipant !== null) {
            header('Location: participantEdit.php?id=' . (int) $selectedParticipant->get_id() . '#pets');
        } else {
            header('Location: index.php');
        }
        die();
    }

    // Make sure required fields were entered
    if ($selectedParticipant === null) {
        $errors[] = 'Please select a participant.';
    }

    if ($args['name'] === '') {
        $errors[] = 'Please enter the pet name.';
    }

    if ($args['species'] === '') {
        $errors[] = 'Please select a species.';
    }

    // Spay/neuter and rabies are Yes, No, or Unknown (FR-029)
    if (!in_array($args['spay_neuter'], array('Yes', 'No', 'Unknown'), true)) {
        $errors[] = 'Please select whether the pet is spayed/neutered.';
    }

    if (!in_array($args['rabies_status'], array('Yes', 'No', 'Unknown'), true)) {
        $errors[] = 'Please select whether the pet has a rabies vaccination.';
    }

    if (count($errors) == 0) {

        // Optional database fields should be NULL when left blank.
        // New pets are active automatically.
        $pet = new Pet(
            null,
            $selectedParticipant->get_id(),
            $args['name'],
            $args['species'],
            $args['breed'] === '' ? null : $args['breed'],
            $args['sex'],
            $args['date_of_birth'] === '' ? null : $args['date_of_birth'],
            $args['dob_is_estimate'],
            $args['weight_lbs'] === '' ? null : $args['weight_lbs'],
            $args['spay_neuter'],
            $args['rabies_status'],
            $args['food_type'] === '' ? null : $args['food_type'],
            $args['food_restriction'] === '' ? null : $args['food_restriction'],
            $args['notes'] === '' ? null : $args['notes'],
            'Active'
        );

        $pet_id = add_pet($pet);

        if ($pet_id) {
            // Redirect so refreshing the page doesn't resubmit the form
            if ($action === 'done') {
                header('Location: participantEdit.php?id=' . (int) $selectedParticipant->get_id() . '&petAdded#pets');
            } else {
                // Save the pet's name temporarily for the confirmation
                $_SESSION['registered_pet'] = $args['name'];
                header('Location: registerPet.php?participant_id=' . (int) $selectedParticipant->get_id() . '&added');
            }
            die();
        }
        $errors[] = 'The pet could not be saved. Please try again.';
    }
}

// Name of the pet just saved, shown once
$registeredPetName = null;
if (isset($_GET['added']) && isset($_SESSION['registered_pet'])) {
    $registeredPetName = $_SESSION['registered_pet'];
    unset($_SESSION['registered_pet']);
}

$existingPets = $selectedParticipant !== null ? get_pets_by_participant($selectedParticipant->get_id()) : array();

// Writes the <option>s of a select, marking the current value
function pet_options($choices, $current) {
    foreach ($choices as $value => $label) {
        $selected = ((string) $value === (string) $current) ? ' selected' : '';
        echo '<option value="' . hsc($value) . '"' . $selected . '>' . hsc($label) . '</option>';
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>CHS Pet Pantry | Register Pet</title>

    <link href="css/base.css" rel="stylesheet">

    <?php
    $tailwind_mode = true;
    require_once('header.php');
    ?>

    <style>
        #participant_name,
        #participant_number {
            background-color: #f5f5f5 !important;
            color: #333 !important;
            opacity: 1 !important;
            -webkit-text-fill-color: #333 !important;
            cursor: not-allowed;
        }

        .pet-form-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        .pet-form-buttons button {
            width: auto;
            flex: 1;
            max-width: 20rem;
        }
    </style>
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

	<form class="signup-form" method="post"<?php if ($selectedParticipant !== null) echo ' action="registerPet.php?participant_id=' . (int) $selectedParticipant->get_id() . '"' ?>>

        <?php if (isset($_GET['registered']) && $selectedParticipant !== null && count($errors) == 0): ?>
        <div class="happy-toast">
            <?php echo hsc($selectedParticipant->get_first_name() . ' ' . $selectedParticipant->get_last_name()) ?>
            has been registered as a Pet Pantry participant. Add their pets below.
        </div>
        <?php endif ?>

        <?php if ($registeredPetName !== null): ?>
        <div class="happy-toast">
            <?php echo hsc($registeredPetName) ?> has been registered. Add another pet, or select Done.
        </div>
        <?php endif ?>

        <?php if ($participantNotFound): ?>
        <div class="error-toast">That participant could not be found. Choose one from the list.</div>
        <?php endif ?>

        <?php if (count($errors) > 0): ?>
        <div class="error-toast">
            <p>The pet was not registered:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?php echo hsc($error) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>

        <fieldset class="section-box mb-4">
            <h3 class="mt-2">Participant</h3>
            <div class="blue-div"></div>

<?php if ($selectedParticipant !== null): ?>
            <label for="participant_name">Participant Name</label>
            <input type="text" id="participant_name" value="<?php echo hsc($selectedParticipant->get_first_name() . ' ' . $selectedParticipant->get_last_name()) ?>" readonly disabled>

            <label for="participant_number">Participant ID</label>
            <input type="text" id="participant_number" value="<?php echo (int) $selectedParticipant->get_id() ?>" readonly disabled>

            <input type="hidden" name="participant_id" value="<?php echo (int) $selectedParticipant->get_id() ?>">

            <?php if (count($existingPets) > 0): ?>
            <p class="mt-2">Pets already registered for this participant:</p>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Species</th>
                            <th>Breed</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($existingPets as $existingPet): ?>
                        <tr>
                            <td><?php echo hsc($existingPet->get_name()) ?></td>
                            <td><?php echo hsc($existingPet->get_species()) ?></td>
                            <td><?php echo hsc($existingPet->get_breed()) ?></td>
                            <td><?php echo hsc($existingPet->get_status()) ?></td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endif ?>
<?php else: ?>
            <label for="participant_id"><em>* </em>Participant</label>
            <select id="participant_id" name="participant_id" required>
                <option value="">Select Participant</option>
                <?php foreach ($participants as $participant): ?>
                <option value="<?php echo (int) $participant->get_id() ?>">
                    <?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name() . ' (ID ' . $participant->get_id() . ')') ?>
                </option>
                <?php endforeach ?>
            </select>
<?php endif ?>
        </fieldset>

        <fieldset class="section-box mb-4">

            <h3 class="mt-2">Pet Information</h3>
            <p class="mb-2">
            Please enter the pet's information.
            </p>

            <div class="blue-div"></div>

        <label for="name">
            <em>* </em>Name
        </label>
        <input
            type="text"
            id="name"
            name="name"
            required
            placeholder="Enter pet's name"
            value="<?php echo hsc($args['name']) ?>"
        >

        <label for="species">
            <em>* </em>Species
        </label>
        <select id="species" name="species" required>
            <?php pet_options(array('' => 'Select Species', 'Dog' => 'Dog', 'Cat' => 'Cat', 'Other' => 'Other'), $args['species']) ?>
        </select>

        <label for="breed">Breed</label>
        <input
            type="text"
            id="breed"
            name="breed"
            placeholder="Enter breed"
            value="<?php echo hsc($args['breed']) ?>"
        >

        <label for="date_of_birth">Date of Birth</label>
        <input
            type="date"
            id="date_of_birth"
            name="date_of_birth"
            value="<?php echo hsc($args['date_of_birth']) ?>"
        >

        <label for="dob_is_estimate">Is this date estimated?</label>
        <select id="dob_is_estimate" name="dob_is_estimate">
            <?php pet_options(array('1' => 'Yes', '0' => 'No'), $args['dob_is_estimate']) ?>
        </select>

        <label for="weight_lbs">Weight(lbs)</label>
        <input
            type="number"
            id="weight_lbs"
            name="weight_lbs"
            min="0"
            step="0.1"
            placeholder="Enter weight"
            value="<?php echo hsc($args['weight_lbs']) ?>"
        >

        <label for="sex">Sex</label>
        <select id="sex" name="sex">
            <?php pet_options(array('Unknown' => 'Unknown', 'Male' => 'Male', 'Female' => 'Female'), $args['sex']) ?>
        </select>

        <label for="spay_neuter">Spay/Neuter Status</label>
        <select id="spay_neuter" name="spay_neuter" required>
            <?php pet_options(array('Unknown' => 'Unknown', 'Yes' => 'Yes', 'No' => 'No'), $args['spay_neuter']) ?>
        </select>

        <label for="rabies_status">Rabies Vaccination Status</label>
        <select id="rabies_status" name="rabies_status" required>
            <?php pet_options(array('Unknown' => 'Unknown', 'Yes' => 'Yes', 'No' => 'No'), $args['rabies_status']) ?>
        </select>

        <label for="food_type">Food Type</label>
        <select id="food_type" name="food_type">
            <?php pet_options(array('dry' => 'Dry', 'wet' => 'Wet', 'either' => 'Either'), $args['food_type']) ?>
        </select>

        <label for="food_restriction">Food Restrictions</label>
        <textarea id="food_restriction" name="food_restriction" placeholder="Enter any food restrictions"><?php echo hsc($args['food_restriction']) ?></textarea>

        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" placeholder="Enter any additional notes"><?php echo hsc($args['notes']) ?></textarea>

        </fieldset>

        <!-- Done skips the browser's required-field check so it can finish
             without a pet; a partly filled pet is still checked when saved -->
        <div class="pet-form-buttons">
            <button type="submit" name="action" value="another">Save &amp; Add Another Pet</button>
            <button type="submit" name="action" value="done" formnovalidate>Done</button>
        </div>
        <p class="text-center mt-2">Done saves the pet above if one was entered, then opens the participant's page.</p>

    </form>
   </div>
</main>

</body>
</html>
