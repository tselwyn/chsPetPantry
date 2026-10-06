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

    // Choices offered on registrationForm.php
    $states = array('VA', 'MD', 'WV', 'DC', 'NC', 'other');
    $languages = array('English', 'Spanish', 'Other');
    $locations = array('Empower', 'Senior Center', 'Home Delivery');
    $statuses = array('Active', 'Inactive', 'Expired', 'No Service');

    $errors = array();
    $args = array();
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Trimmed only, not sanitize()d: add_participant uses prepared statements
        // and pages escape participant data with hsc() when they show it
        $fields = array(
            'first_name', 'last_name', 'street_address', 'city', 'state', 'zip',
            'phone1', 'email', 'preferred_language',
            'participant_location', 'registration_date', 'participant_status',
            'participant_alert', 'notes', 'participant_consent'
        );
        foreach ($fields as $field) {
            $args[$field] = (isset($_POST[$field]) && is_string($_POST[$field])) ? trim($_POST[$field]) : '';
        }

        $required = array(
            'first_name', 'last_name', 'street_address',
            'phone1', 'email', 'preferred_language',
            'participant_location', 'registration_date', 'participant_status',
            'participant_consent'
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

        if ($args['state'] !== '' && !valueConstrainedTo($args['state'], $states)) {
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

        if ($args['registration_date'] !== '') {
            if (!validateDate($args['registration_date'])) {
                $errors[] = 'Enter a valid registration date.';
            } else if ($args['registration_date'] > date('Y-m-d')) {
                $errors[] = 'The registration date cannot be in the future.';
            }
        }

        if ($args['participant_status'] !== '' && !valueConstrainedTo($args['participant_status'], $statuses)) {
            $errors[] = 'Choose a participant status from the list.';
        }

        if ($args['participant_alert'] !== '' && !valueConstrainedTo($args['participant_alert'], array('Yes', 'No'))) {
            $errors[] = 'Choose Yes or No for Alert / Flag.';
        }

        if ($args['participant_consent'] !== '' && !valueConstrainedTo($args['participant_consent'], array('Yes', 'No'))) {
            $errors[] = 'Choose whether the participant agrees.';
        } else if ($args['participant_consent'] === 'No') {
            $errors[] = 'The participant must agree to the Pet Pantry program rules before they can be registered.';
        }

        if (count($errors) == 0) {
            $participant = new Participant(
                null,
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
                $args['registration_date'],
                $args['participant_status'],
                $args['participant_alert'] === 'Yes' ? 1 : 0,
                $args['notes'] === '' ? null : $args['notes'],
                1
            );
            if (add_participant($participant)) {
                // Redirect so refreshing the confirmation doesn't register them again
                $_SESSION['registered_participant'] = $args['first_name'] . ' ' . $args['last_name'];
                header('Location: participantRegister.php?registered');
                die();
            }
            $errors[] = 'The participant could not be saved. Please try again.';
        }
    }

    // Name shown on the confirmation, once
    $registeredName = null;
    if (isset($_GET['registered']) && isset($_SESSION['registered_participant'])) {
        $registeredName = $_SESSION['registered_participant'];
        unset($_SESSION['registered_participant']);
    }
?>

<!DOCTYPE html>
<html>
<head>
    <title>CCDA | Register Participant</title>
    <link href="css/base.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<!-- BANDAID END, REMOVE ONCE SOME GENIUS FIXES -->
</head>
<body class="relative">

<?php if ($registeredName !== null): ?>
<header class="hero-header">
    <div class="center-header">
        <h1>Pet Pantry Registration</h1>
    </div>
</header>

<main>
    <div class="main-content-box">
        <div class="happy-toast"><?php echo hsc($registeredName) ?> has been registered as a Pet Pantry participant.</div>
        <div class="text-center">
            <a href="participantRegister.php" class="button">Register Another Participant</a>
            <a href="index.php" class="button">Return to Dashboard</a>
        </div>
    </div>
</main>
<?php else: ?>
<?php require_once('registrationForm.php'); ?>

<?php if (count($errors) > 0): ?>
<div id="registration-errors" class="error-toast">
    <p>The participant was not registered:</p>
    <ul>
        <?php foreach ($errors as $error): ?>
        <li><?php echo hsc($error) ?></li>
        <?php endforeach ?>
    </ul>
</div>

<script>
    // Show the errors at the top of the form and put back what was typed,
    // so the form doesn't have to be filled in again
    const form = document.querySelector('form.signup-form');
    form.prepend(document.getElementById('registration-errors'));

    const submitted = <?php echo json_encode($args, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    form.querySelectorAll('[name]').forEach(field => {
        if (!(field.name in submitted)) {
            return;
        }
        if (field.type === 'radio') {
            field.checked = field.value === submitted[field.name];
        } else {
            field.value = submitted[field.name];
        }
    });
</script>
<?php endif ?>
<?php endif ?>

</body>
</html>
