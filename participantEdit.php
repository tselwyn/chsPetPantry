<?php
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
    // Volunteers and admins (SRS UC-02 to UC-07)
    if ($accessLevel < 1) {
        header('Location: index.php');
        die();
    }

    require_once('include/input-validation.php');
    require_once('include/output.php');
    require_once('database/dbParticipants.php');
    require_once('database/dbParticipantDistributions.php');
    require_once('database/dbPets.php');

    // Choices offered on the form, same as registrationForm.php
    $states = array('VA' => 'Virginia', 'MD' => 'Maryland', 'WV' => 'West Virginia',
        'DC' => 'District of Columbia', 'NC' => 'North Carolina', 'other' => 'Other');
    $languages = array('English', 'Spanish', 'Other');
    $locations = array('Empower', 'Senior Center', 'Home Delivery');
    $statuses = participant_statuses();

    $id = (isset($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $participant = $id > 0 ? retrieve_participant($id) : null;

    // Only an admin can set or clear the Alert / Flag; volunteers see it read-only
    $canChangeAlert = $accessLevel >= 2;

    $errors = array();
    $args = array();
    if ($participant !== null) {
        // A value already saved for this participant stays selectable even if
        // it is no longer one of the choices above
        if ($participant->get_state() != '' && !isset($states[$participant->get_state()])) {
            $states[$participant->get_state()] = $participant->get_state();
        }
        if (!in_array($participant->get_preferred_language(), $languages)) {
            $languages[] = $participant->get_preferred_language();
        }
        if (!in_array($participant->get_location(), $locations)) {
            $locations[] = $participant->get_location();
        }

        // What the form shows until something is submitted
        $args = array(
            'first_name' => $participant->get_first_name(),
            'last_name' => $participant->get_last_name(),
            'street_address' => $participant->get_street_address(),
            'city' => (string) $participant->get_city(),
            'state' => (string) $participant->get_state(),
            'zip' => (string) $participant->get_zip(),
            'phone1' => $participant->get_phone(),
            'email' => $participant->get_email(),
            'preferred_language' => $participant->get_preferred_language(),
            'participant_location' => $participant->get_location(),
            'participant_status' => $participant->get_status(),
            'participant_alert' => $participant->get_alert() ? 'Flagged' : 'No',
            'notes' => (string) $participant->get_notes()
        );
    }

    if ($participant !== null && $_SERVER["REQUEST_METHOD"] == "POST") {
        // Trimmed only, not sanitize()d: update_participant uses prepared statements
        // and pages escape participant data with hsc() when they show it
        foreach (array_keys($args) as $field) {
            // A volunteer's Alert / Flag stays as saved, whatever is submitted
            if ($field === 'participant_alert' && !$canChangeAlert) {
                continue;
            }
            $args[$field] = (isset($_POST[$field]) && is_string($_POST[$field])) ? trim($_POST[$field]) : '';
        }

        $required = array(
            'first_name', 'last_name', 'street_address',
            'phone1', 'email', 'preferred_language',
            'participant_location', 'participant_status'
        );
        if (!wereRequiredFieldsSubmitted($args, $required, false)) {
            $errors[] = 'Please fill in every required field.';
        }

        // Longest values the dbparticipants columns hold
        $maxLengths = array(
            'first_name' => array('First name', 50),
            'last_name' => array('Last name', 50),
            'street_address' => array('Street address', 100),
            'city' => array('City', 50),
            'email' => array('E-mail', 100)
        );
        foreach ($maxLengths as $field => $limit) {
            if (mb_strlen($args[$field]) > $limit[1]) {
                $errors[] = $limit[0] . ' must be ' . $limit[1] . ' characters or fewer.';
            }
        }

        $phone = validateAndFilterPhoneNumber($args['phone1']);
        if ($args['phone1'] !== '' && !$phone) {
            $errors[] = 'Enter a 10-digit phone number.';
        }

        if ($args['email'] !== '' && !validateEmail($args['email'])) {
            $errors[] = 'Enter a valid e-mail address.';
        }

        if ($args['state'] !== '' && !isset($states[$args['state']])) {
            $errors[] = 'Choose a state from the list.';
        }

        if ($args['zip'] !== '' && !preg_match('/^[0-9]{5}$/', $args['zip'])) {
            $errors[] = 'Enter a 5-digit ZIP code.';
        }

        if ($args['preferred_language'] !== '' && !valueConstrainedTo($args['preferred_language'], $languages)) {
            $errors[] = 'Choose a preferred language from the list.';
        }

        if ($args['participant_location'] !== '' && !valueConstrainedTo($args['participant_location'], $locations)) {
            $errors[] = 'Choose a participant location from the list.';
        }

        if ($args['participant_status'] !== '' && !isset($statuses[$args['participant_status']])) {
            $errors[] = 'Choose a participant status from the list.';
        }

        if ($args['participant_alert'] !== '' && !valueConstrainedTo($args['participant_alert'], array('Flagged', 'No'))) {
            $errors[] = 'Choose Flagged or No for Alert / Flag.';
        }

        if (count($errors) == 0) {
            // The id, registration date and consent are kept as they were
            $updated = new Participant(
                $participant->get_id(),
                $args['first_name'],
                $args['last_name'],
                $args['street_address'],
                $args['city'] === '' ? null : $args['city'],
                $args['state'] === '' ? null : $args['state'],
                $args['zip'] === '' ? null : $args['zip'],
                $phone,
                $args['email'],
                $args['preferred_language'],
                $args['participant_location'],
                $participant->get_registration_date(),
                $args['participant_status'],
                $args['participant_alert'] === 'Flagged' ? 1 : 0,
                $args['notes'] === '' ? null : $args['notes'],
                $participant->get_consent()
            );
            if (update_participant($updated)) {
                // Redirect so refreshing the page doesn't submit the form again
                header('Location: participantEdit.php?id=' . $participant->get_id() . '&saved');
                die();
            }
            $errors[] = 'The participant could not be saved. Please try again.';
        }
    }

    // Writes the <option>s of a select, marking the current value
    function participant_options($choices, $current) {
        foreach ($choices as $value => $label) {
            // Lists without their own labels show the value itself
            if (is_int($value)) {
                $value = $label;
            }
            $selected = ((string) $value === (string) $current) ? ' selected' : '';
            echo '<option value="' . hsc($value) . '"' . $selected . '>' . hsc($label) . '</option>';
        }
    }
?>

<!DOCTYPE html>
<html>
<head>
    <title>CCDA | Participant Information</title>
    <link href="css/base.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>

<style>
    #participant_id,
    #registration_date,
    #participant_alert[readonly] {
        background-color: #f5f5f5 !important;
        color: #333 !important;
        opacity: 1 !important;
        -webkit-text-fill-color: #333 !important;
        cursor: not-allowed;
    }

    /* Edit and Delete sit beside SNV / Counseling in each pet row */
    #pets td.pet-actions {
        white-space: nowrap;
    }

    #pets td.pet-actions .button,
    #pets td.pet-actions button {
        width: auto;
        margin: 0.125rem;
    }

    #pets td.pet-actions form {
        display: inline;
    }

    #pets button.delete-pet-button {
        background-color: #8b0000;
    }

    #pets button.delete-pet-button:hover {
        background-color: #660000;
    }
</style>
<!-- BANDAID END, REMOVE ONCE SOME GENIUS FIXES -->
</head>
<body class="relative">

<header class="hero-header">
    <div class="center-header">
        <h1>Participant Information</h1>
    </div>
</header>

<main>
  <div class="main-content-box">
<?php if ($participant === null): ?>
    <?php // Reached from the dashboard without an id: ask who to look up ?>
    <form class="signup-form" method="get" action="participantSearch.php">
        <div class="text-center spacing-bottom">
            <h2 class="mb-8">Find a Participant</h2>
            <div class="info-box">
                <p class="sub-text">Search for the participant to view or update, then select View / Update beside them in the results.</p>
            </div>
        </div>

        <?php if (isset($_GET['id'])): ?>
        <div class="error-toast">That participant could not be found.</div>
        <?php endif ?>

        <fieldset class="section-box mb-4">
            <label for="q">ID, Name, Address, or Phone Number</label>
            <input type="text" id="q" name="q" required maxlength="<?php echo PARTICIPANT_SEARCH_MAX_LENGTH ?>" placeholder="Ex. 12, Maria Gonzalez, or 540-555-0101" autofocus>
        </fieldset>

        <input type="submit" value="Search">
    </form>

    <div class="text-center">
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>
<?php else: ?>
    <form class="signup-form" method="post">
        <div class="text-center spacing-bottom">
            <h2 class="mb-8"><?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?></h2>
            <div class="info-box">
                <p class="sub-text">View this participant's information and update it below.</p>
                <p>An asterisk ( <em>*</em> ) indicates a required field.</p>
            </div>
        </div>

        <?php if (isset($_GET['saved']) && count($errors) == 0): ?>
        <div class="happy-toast">The participant's information has been saved.</div>
        <?php endif ?>

        <?php if (isset($_GET['distributed']) && count($errors) == 0): ?>
        <div class="happy-toast">The food distribution has been recorded.</div>
        <?php endif ?>

        <?php if (isset($_GET['snv']) && count($errors) == 0): ?>
        <div class="happy-toast">Spay/neuter and vaccination information updated.</div>
        <?php endif ?>

        <?php if (count($errors) > 0): ?>
        <div class="error-toast">
            <p>The participant was not saved:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?php echo hsc($error) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>

        <fieldset class="section-box mb-4">
            <h3 class="mt-2">Participant Information</h3>
            <p class="mb-2">The participant ID is assigned automatically and cannot be changed.</p>
            <div class="blue-div"></div>

            <label for="participant_id">Participant ID</label>
            <input type="text" id="participant_id" value="<?php echo (int) $participant->get_id() ?>" readonly disabled>

            <label for="first_name"><em>* </em>First Name</label>
            <input type="text" id="first_name" name="first_name" required maxlength="50" placeholder="Enter first name" value="<?php echo hsc($args['first_name']) ?>">

            <label for="last_name"><em>* </em>Last Name</label>
            <input type="text" id="last_name" name="last_name" required maxlength="50" placeholder="Enter last name" value="<?php echo hsc($args['last_name']) ?>">

            <label for="street_address"><em>* </em>Street Address</label>
            <input type="text" id="street_address" name="street_address" required maxlength="100" placeholder="Enter street address" value="<?php echo hsc($args['street_address']) ?>">

            <label for="city">City</label>
            <input type="text" id="city" name="city" maxlength="50" placeholder="Enter city" value="<?php echo hsc($args['city']) ?>">

            <label for="state">State</label>
            <select id="state" name="state">
                <option value=""<?php if ($args['state'] === '') echo ' selected' ?>>Select a state</option>
                <?php participant_options($states, $args['state']) ?>
            </select>

            <label for="zip">ZIP Code</label>
            <input type="text" id="zip" name="zip" pattern="[0-9]{5}" maxlength="5" inputmode="numeric" title="Please enter a 5-digit ZIP code" placeholder="Enter ZIP code" value="<?php echo hsc($args['zip']) ?>">
        </fieldset>

        <fieldset class="section-box mb-4">
            <h3>Contact Information</h3>
            <p class="mb-2">The participant's contact information.</p>
            <div class="blue-div"></div>

            <label for="phone1"><em>* </em>Phone Number</label>
            <input type="tel" id="phone1" name="phone1" required pattern="(\D{0,1})\d{3}(\D{0,2})\d{3}(.{0,1})\d{4}" placeholder="Ex. (555) 555-5555" title="Please enter the phone number as 555-555-5555" value="<?php echo hsc($args['phone1']) ?>">

            <label for="email"><em>* </em>E-mail</label>
            <input type="email" id="email" name="email" required maxlength="100" placeholder="Enter e-mail address" value="<?php echo hsc($args['email']) ?>">

            <label for="preferred_language"><em>* </em>Preferred Language</label>
            <select id="preferred_language" name="preferred_language" required>
                <?php participant_options($languages, $args['preferred_language']) ?>
            </select>
        </fieldset>

        <fieldset class="section-box mb-4">
            <h3>Pet Pantry Information</h3>
            <p class="mb-2">The participant's Pet Pantry registration information.</p>
            <div class="blue-div"></div>

            <label for="registration_date">Registration Date</label>
            <input type="date" id="registration_date" value="<?php echo hsc($participant->get_registration_date()) ?>" readonly disabled>

            <label for="participant_status"><em>* </em>Participant Status</label>
            <select id="participant_status" name="participant_status" required>
                <?php participant_options($statuses, $args['participant_status']) ?>
            </select>

            <label for="participant_location"><em>* </em>Participant Location</label>
            <select id="participant_location" name="participant_location" required>
                <?php participant_options($locations, $args['participant_location']) ?>
            </select>

            <label for="participant_alert">Alert / Flag</label>
            <?php if ($canChangeAlert): ?>
            <select id="participant_alert" name="participant_alert">
                <?php participant_options(array('No', 'Flagged'), $args['participant_alert']) ?>
            </select>
            <?php else: ?>
            <input type="text" id="participant_alert" value="<?php echo hsc($args['participant_alert']) ?>" readonly disabled>
            <p class="mb-2">Only an administrator can change the Alert / Flag.</p>
            <?php endif ?>

            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="4" placeholder="Enter any additional notes about the participant..."><?php echo hsc($args['notes']) ?></textarea>
        </fieldset>

        <input type="submit" value="Save Changes">
    </form>

    <?php
    // Only Active pets are listed; Inactive and Deleted pets are managed on deleteParticipantPet.php
    $pets = array_filter(get_pets_by_participant($participant->get_id()), function ($pet) {
        return $pet->get_status() === 'Active';
    });
    ?>
    <div class="signup-form" id="pets">
        <fieldset class="section-box mb-4">
            <h3>Pets</h3>
            <p class="mb-2">This participant's active pets and their spay/neuter, rabies vaccination, and counseling status.</p>
            <div class="blue-div"></div>

            <?php if (isset($_GET['petAdded'])): ?>
            <div class="happy-toast">The pet has been registered.</div>
            <?php elseif (isset($_GET['petSaved'])): ?>
            <div class="happy-toast">The pet's information has been saved.</div>
            <?php elseif (isset($_GET['petDeleted'])): ?>
            <div class="happy-toast">The pet has been deleted.</div>
            <?php elseif (isset($_GET['petNotDeleted'])): ?>
            <div class="error-toast">The pet could not be deleted. Please try again.</div>
            <?php endif ?>

            <?php if (count($pets) == 0): ?>
            <p>This participant has no active pets.</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Species</th>
                            <th>Age (Years)</th>
                            <th>Spay/Neuter</th>
                            <th>Rabies</th>
                            <th>Counseling</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pets as $pet): ?>
                        <tr>
                            <td><?php echo hsc($pet->get_name()) ?></td>
                            <td><?php echo hsc($pet->get_species()) ?></td>
                            <td><?php echo $pet->get_age_years() === null ? 'Unknown' : hsc($pet->get_age_years()) ?></td>
                            <td><?php echo hsc($pet->get_spay_neuter_status()) ?></td>
                            <td><?php echo hsc($pet->get_rabies_status()) ?></td>
                            <td>
                                <?php echo hsc($pet->get_counseling_status()) ?>
                                <?php if ($pet->get_counseling_status() === 'Yes' && $pet->get_counseling_date()): ?>
                                (<?php echo hsc(date('M j, Y', strtotime($pet->get_counseling_date()))) ?>)
                                <?php endif ?>
                            </td>
                            <td class="pet-actions">
                                <a href="petSNV.php?id=<?php echo (int) $pet->get_id() ?>" class="button">SNV / Counseling</a>
                                <a href="petEdit.php?id=<?php echo (int) $pet->get_id() ?>" class="button">Edit</a>
                                <form method="post" action="petDelete.php" onsubmit="return confirm(<?php echo hsc(json_encode('Delete ' . $pet->get_name() . '? This cannot be undone from this page.')) ?>);">
                                    <input type="hidden" name="pet_id" value="<?php echo (int) $pet->get_id() ?>">
                                    <button type="submit" class="delete-pet-button">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endif ?>

            <div class="text-center mt-2">
                <a href="registerPet.php?participant_id=<?php echo (int) $participant->get_id() ?>" class="button">Add a Pet</a>
            </div>
        </fieldset>
    </div>

    <?php $distributions = get_participant_distributions($participant->get_id()) ?>
    <div class="signup-form" id="distribution-history">
        <fieldset class="section-box mb-4">
            <h3>Distribution History</h3>
            <p class="mb-2">Food distributions recorded for this participant, newest first.</p>
            <div class="blue-div"></div>

            <?php if (count($distributions) == 0): ?>
            <p>No distributions have been recorded for this participant.</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Location</th>
                            <th>Pets</th>
                            <th>Dog Food (lbs)</th>
                            <th>Cat Food (lbs)</th>
                            <th>Processed By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($distributions as $distribution): ?>
                        <tr>
                            <td><?php echo hsc(date('M j, Y', strtotime($distribution->get_distribution_date()))) ?></td>
                            <td><?php echo hsc($distribution->get_location()) ?></td>
                            <td><?php echo (int) $distribution->get_pets_served() ?></td>
                            <td><?php echo hsc($distribution->get_dog_food_lbs()) ?></td>
                            <td><?php echo hsc($distribution->get_cat_food_lbs()) ?></td>
                            <td><?php echo hsc($distribution->get_processed_by_name()) ?></td>
                            <td><?php echo nl2br(hsc($distribution->get_notes())) ?></td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endif ?>
        </fieldset>
    </div>

    <div class="text-center">
        <a href="distributionRecord.php?id=<?php echo (int) $participant->get_id() ?>" class="button">Record Distribution</a>
        <a href="participantSearch.php" class="button">Back to Participant Search</a>
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>
<?php endif ?>
  </div>
</main>

</body>
</html>
