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
    require_once('database/dbParticipantAlerts.php');

    // Choices offered on registrationForm.php
    $states = array('VA', 'MD', 'WV', 'DC', 'NC', 'other');
    $languages = array('English', 'Spanish', 'Other');
    $locations = array('Empower', 'Senior Center', 'Home Delivery');
    $statuses = array_keys(participant_statuses());

    $errors = array();
    $args = array();
    $duplicateMatches = array();
    $showDuplicateWarning = false;
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Trimmed only, not sanitize()d: add_participant uses prepared statements
        // and pages escape participant data with hsc() when they show it
        $fields = array(
            'first_name', 'last_name', 'street_address', 'city', 'state', 'zip',
            'phone1', 'email', 'preferred_language',
            'participant_location', 'registration_date', 'participant_status',
            'participant_alert', 'alert_type', 'alert_reason', 'notes'
        );
        foreach ($fields as $field) {
            $args[$field] = (isset($_POST[$field]) && is_string($_POST[$field])) ? trim($_POST[$field]) : '';
        }

        $required = array(
            'first_name', 'last_name', 'street_address',
            'phone1', 'preferred_language',
            'participant_location', 'registration_date', 'participant_status'
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

        // Only an admin can flag a participant; a volunteer's choice is ignored
        if ($accessLevel < 2) {
            $args['participant_alert'] = 'No';
        }
        if ($args['participant_alert'] !== '' && !valueConstrainedTo($args['participant_alert'], array('Flagged', 'No'))) {
            $errors[] = 'Choose Flagged or No for Alert / Flag.';
        }
        if ($args['participant_alert'] === 'Flagged') {
            if (!valueConstrainedTo($args['alert_type'], participant_alert_types())) {
                $errors[] = 'Choose an alert reason for the flagged participant.';
            }
            if (mb_strlen($args['alert_reason']) > 2000) {
                $errors[] = 'Alert details must be 2000 characters or fewer.';
            }
        } else {
            $args['alert_type'] = '';
            $args['alert_reason'] = '';
        }

        // Check the database only after the entered information is valid.
        if (count($errors) === 0) {
            try {
                $duplicateMatches = find_possible_duplicate_participants(
                    $args['first_name'], $args['last_name'], $phone,
                    $args['email'], $args['street_address']
                );
            } catch (Throwable $e) {
                error_log('Duplicate participant check failed: ' . $e->getMessage());
                $errors[] = 'Unable to check for existing participants. Please try again.';
            }

            // A fresh warning requires staff to review the matches and submit again.
            // The confirmation is tied to the exact entered identity details.
            $identity = implode('|', array(
                mb_strtolower($args['first_name']), mb_strtolower($args['last_name']),
                (string) $phone, mb_strtolower($args['email']),
                mb_strtolower($args['street_address'])
            ));
            $expectedConfirmation = hash_hmac('sha256', $identity, session_id());
            $providedConfirmation = isset($_POST['duplicate_confirmation_token']) && is_string($_POST['duplicate_confirmation_token'])
                ? $_POST['duplicate_confirmation_token'] : '';
            $confirmed = isset($_POST['confirm_distinct_participant'])
                && $_POST['confirm_distinct_participant'] === 'Yes'
                && hash_equals($expectedConfirmation, $providedConfirmation);

            if (count($duplicateMatches) > 0 && !$confirmed) {
                $showDuplicateWarning = true;
            }
        }

        if (count($errors) === 0 && !$showDuplicateWarning) {
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
                $args['participant_alert'] === 'Flagged' ? 1 : 0,
                $args['notes'] === '' ? null : $args['notes'],
                null
            );
            $participant_id = add_participant($participant);

            if ($participant_id && $args['participant_alert'] === 'Flagged') {
                // The participant stays Flagged even if the alert can't be saved,
                // so Record Distribution still shows the generic warning
                $reason = $args['alert_reason'] !== '' ? $args['alert_reason'] : 'Flagged at registration.';
                if (!add_participant_alert((int) $participant_id, $args['alert_type'], $reason, (string) $userID)) {
                    error_log('Could not save the registration alert for participant ' . (int) $participant_id);
                }
            }

            if ($participant_id) {
                // Straight on to that participant's pets
                header('Location: registerPet.php?participant_id=' . (int) $participant_id . '&registered');
                die();
            }
            $errors[] = 'The participant could not be saved. Please try again.';
        }
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

<?php require_once('registrationForm.php'); ?>

<?php if ($showDuplicateWarning): ?>
<div id="duplicate-warning" role="alert" style="background:#fffbeb;border:2px solid #d97706;border-radius:10px;padding:20px;margin:16px 0;color:#78350f;">
    <h3 style="font-size:1.2rem;font-weight:bold;">Possible duplicate participant found</h3>
    <p>Review the existing participant(s) before registering a new person. Shared contact information does not necessarily mean the same person.</p>
    <ul style="margin:12px 0;padding-left:22px;list-style:disc;">
    <?php foreach ($duplicateMatches as $match): ?>
        <li style="margin-bottom:12px;">
            <strong><?php echo hsc($match['first_name'] . ' ' . $match['last_name']); ?></strong>
            (ID #<?php echo (int) $match['id']; ?>, <?php echo hsc($match['status']); ?>)
            — <?php echo hsc($match['street_address']); ?>
            <a href="participantEdit.php?id=<?php echo (int) $match['id']; ?>" target="_blank" rel="noopener" style="text-decoration:underline;font-weight:bold;">Review existing record</a>
        </li>
    <?php endforeach; ?>
    </ul>
    <label style="display:block;font-weight:bold;margin-top:12px;">
        <input type="checkbox" name="confirm_distinct_participant" value="Yes" required>
        I reviewed these matches and confirm this is a different participant.
    </label>
    <input type="hidden" name="duplicate_confirmation_token" value="<?php echo hsc($expectedConfirmation); ?>">
    <p style="margin-top:8px;">Check the box and submit the registration form again only if this is a different person.</p>
</div>
<script>
(function () {
    const form = document.querySelector('form.signup-form');
    if (!form) return;
    form.prepend(document.getElementById('duplicate-warning'));
    const submitted = <?php echo json_encode($args, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    form.querySelectorAll('[name]').forEach(field => {
        if (!(field.name in submitted)) return;
        if (field.type === 'radio') field.checked = field.value === submitted[field.name];
        else if (field.name !== 'duplicate_confirmation_token' && field.name !== 'confirm_distinct_participant') field.value = submitted[field.name];
    });
})();
</script>
<?php endif ?>

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

</body>
</html>
