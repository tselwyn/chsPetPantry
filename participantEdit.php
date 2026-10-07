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
    // admin-only access
    if ($accessLevel < 2) {
        header('Location: index.php');
        die();
    }

    require_once('include/input-validation.php');
    require_once('include/output.php');
    require_once('database/dbParticipants.php');

    // Choices offered on the form, same as registrationForm.php
    $states = array('VA' => 'Virginia', 'MD' => 'Maryland', 'WV' => 'West Virginia',
        'DC' => 'District of Columbia', 'NC' => 'North Carolina', 'other' => 'Other');
    $languages = array('English', 'Spanish', 'Other');
    $locations = array('Empower', 'Senior Center', 'Home Delivery');
    $statuses = array('Active', 'Inactive', 'Expired', 'No Service');

    $id = (isset($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $participant = $id > 0 ? retrieve_participant($id) : null;

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
            'participant_alert' => $participant->get_alert() ? 'Yes' : 'No',
            'notes' => (string) $participant->get_notes()
        );
    }

    if ($participant !== null && $_SERVER["REQUEST_METHOD"] == "POST") {
        // Trimmed only, not sanitize()d: update_participant uses prepared statements
        // and pages escape participant data with hsc() when they show it
        foreach (array_keys($args) as $field) {
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

        if ($args['participant_status'] !== '' && !valueConstrainedTo($args['participant_status'], $statuses)) {
            $errors[] = 'Choose a participant status from the list.';
        }

        if ($args['participant_alert'] !== '' && !valueConstrainedTo($args['participant_alert'], array('Yes', 'No'))) {
            $errors[] = 'Choose Yes or No for Alert / Flag.';
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
                $args['participant_alert'] === 'Yes' ? 1 : 0,
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
            <select id="participant_alert" name="participant_alert">
                <?php participant_options(array('No', 'Yes'), $args['participant_alert']) ?>
            </select>

            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="4" placeholder="Enter any additional notes about the participant..."><?php echo hsc($args['notes']) ?></textarea>
        </fieldset>

        <input type="submit" value="Save Changes">
    </form>

    <div class="text-center">
        <a href="participantSearch.php" class="button">Back to Participant Search</a>
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>
<?php endif ?>
  </div>
</main>

</body>
</html>
