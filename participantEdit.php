
<?php
    // Make session information accessible, allowing us to associate
    // data with the logged-in user.
    session_cache_expire(30);
    session_start();
    require_once(__DIR__ . '/include/sessionTimeout.php'); // 30-minute inactivity sign-out (NFR-SEC-005)

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

    // All signed-in users can add alerts; only admins can resolve them.
    $canResolveAlert = $accessLevel >= 2;
    if (empty($_SESSION['participant_alert_csrf'])) {
        $_SESSION['participant_alert_csrf'] = bin2hex(random_bytes(32));
    }

    $errors = array();
    $alertErrors = array();
    $args = array();
    if ($participant !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alert_action'])) {
        $validToken = isset($_POST['alert_csrf']) && is_string($_POST['alert_csrf'])
            && hash_equals($_SESSION['participant_alert_csrf'], $_POST['alert_csrf']);
        if (!$validToken) {
            $alertErrors[] = 'Your session expired. Reload the page and try again.';
        } elseif ($_POST['alert_action'] === 'add') {
            $type = isset($_POST['alert_type']) && is_string($_POST['alert_type']) ? $_POST['alert_type'] : '';
            $reason = isset($_POST['alert_reason']) && is_string($_POST['alert_reason']) ? trim($_POST['alert_reason']) : '';
            if (!in_array($type, participant_alert_types(), true) || $reason === '' || mb_strlen($reason) > 2000) {
                $alertErrors[] = 'Choose an alert type and enter a reason (up to 2000 characters).';
            } elseif (add_participant_alert($id, $type, $reason, $userID)) {
                header('Location: participantEdit.php?id=' . $id . '&alertAdded=1#participant-alerts');
                exit;
            } else {
                $alertErrors[] = 'Unable to add the alert. Please try again.';
            }
        } elseif ($_POST['alert_action'] === 'resolve') {
            $alertId = isset($_POST['alert_id']) && is_string($_POST['alert_id']) && ctype_digit($_POST['alert_id']) ? (int) $_POST['alert_id'] : 0;
            if (!$canResolveAlert) {
                http_response_code(403);
                $alertErrors[] = 'Only administrators can resolve alerts.';
            } elseif ($alertId > 0 && resolve_participant_alert($alertId, $id, $userID)) {
                header('Location: participantEdit.php?id=' . $id . '&alertResolved=1#participant-alerts');
                exit;
            } else {
                $alertErrors[] = 'Unable to resolve this alert.';
            }
        } else {
            $alertErrors[] = 'Invalid alert action.';
        }
    }
    $activeAlerts = $participant !== null ? get_participant_alerts($id) : [];
    $allAlerts = $participant !== null ? get_participant_alerts($id, true) : [];

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

    if ($participant !== null && $_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['alert_action'])) {
        // Trimmed only, not sanitize()d: update_participant uses prepared statements
        // and pages escape participant data with hsc() when they show it
        foreach (array_keys($args) as $field) {
            // Alerts are managed separately, never through the participant edit form.
            if ($field === 'participant_alert') {
                continue;
            }
            $args[$field] = (isset($_POST[$field]) && is_string($_POST[$field])) ? trim($_POST[$field]) : '';
        }

        $required = array(
            'first_name', 'last_name', 'street_address',
            'phone1', 'preferred_language',
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
                ($participant->get_alert() || count($activeAlerts) > 0) ? 1 : 0,
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
    <title>CHS Pet Pantry | Participant Information</title>
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

    /* A fieldset grows to fit its widest content by default; this lets the
       pets and distribution tables scroll inside it on a phone instead of
       widening the page */
    #pets fieldset,
    #distribution-history fieldset {
        min-width: 0;
    }

    /* Positioned so the hidden "Actions" headings stay inside the scroll area */
    #pets table {
        font-size: 1rem;
        position: relative;
    }

    /* Room between columns so headings like Name and Species don't run together */
    #pets th,
    #pets td {
        padding: 0.5rem;
        vertical-align: top;
    }

    /* Special medical needs, on a full-width line under the pet's row */
    #pets tr.has-medical td {
        padding-bottom: 0.25rem;
    }

    #pets tr.pet-medical-row td {
        padding-top: 0;
        padding-bottom: 0.75rem;
        color: #374151;
    }

    /* Edit and Delete are stacked so the table fits the
       page on a laptop without scrolling sideways */
    #pets td.pet-actions .button,
    #pets td.pet-actions button {
        display: block;
        width: 100%;
        margin: 0 0 0.25rem 0;
        padding: 0.35rem 0.6rem;
        font-size: 1rem;
        white-space: nowrap;
    }

    #pets td.pet-actions form {
        display: block;
        margin: 0;
    }


    /* Compact, responsive participant editing form */
    .participant-edit-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 20px; }
    .participant-edit-grid .participant-field { min-width: 0; }
    .participant-edit-grid .participant-field label { display: block; margin: 0 0 5px; font-weight: 600; }
    .participant-edit-grid .participant-field input,
    .participant-edit-grid .participant-field select,
    .participant-edit-grid .participant-field textarea { box-sizing: border-box; width: 100%; margin: 0; }
    .participant-edit-grid .participant-field-full { grid-column: 1 / -1; }
    /* Compact participant summary: override large global .signup-form/.button rules */
    .signup-form.participant-summary {
        box-sizing: border-box; padding: 14px 16px; margin-bottom: 14px;
        border: 1px solid #b9dce3; border-radius: 7px;
        background: #f7fbfc; text-align: left;
    }
    .signup-form.participant-summary h2 {
        color: #173d50 !important; -webkit-text-fill-color: #173d50 !important;
        font-size: 1.35rem !important; font-weight: 700; line-height: 1.25;
        margin: 0 0 5px; text-align: left;
    }
    .signup-form.participant-summary .participant-meta {
        color: #425563; font-size: .88rem; line-height: 1.5; margin: 0;
    }
    .signup-form.participant-summary .participant-alert-notice {
        color: #744b14; background: #fff6e6; border-left: 3px solid #c78c33;
        border-radius: 3px; padding: 7px 10px; margin: 10px 0 0;
        font-size: .85rem; line-height: 1.45;
    }
    .signup-form.participant-summary .participant-alert-notice a { color: #205b73; text-decoration: underline; }
    .signup-form.participant-summary .participant-quick-actions {
        display: flex; align-items: center; justify-content: flex-start;
        gap: 7px; flex-wrap: wrap; margin-top: 12px;
        padding: 0; background: transparent; border: 0;
    }
    .signup-form.participant-summary .participant-quick-actions a.button {
        box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center;
        width: auto !important; min-width: 0 !important; min-height: 0 !important;
        max-width: none !important; flex: 0 0 auto;
        padding: 6px 10px !important; margin: 0 !important;
        font-size: .83rem !important; font-weight: 600; line-height: 1.25 !important;
        color: #20566b !important; background: #fff !important;
        border: 1px solid #a9ccd6 !important; border-radius: 5px;
        box-shadow: none !important; text-decoration: none; white-space: nowrap;
    }
    .signup-form.participant-summary .participant-quick-actions a.button:hover,
    .signup-form.participant-summary .participant-quick-actions a.button:focus-visible {
        background: #e6f4f7 !important; border-color: #4e9cae !important;
    }
    .signup-form.participant-summary .participant-quick-actions a.primary-action {
        background: #206b80 !important; color: #fff !important; border-color: #206b80 !important;
    }
    .signup-form.participant-summary .participant-quick-actions a.primary-action:hover,
    .signup-form.participant-summary .participant-quick-actions a.primary-action:focus-visible {
        background: #155367 !important; color: #fff !important;
    }
    @media (max-width: 680px) { .participant-edit-grid { grid-template-columns: 1fr; } }

    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    #pets button.delete-pet-button {
        background-color: #8b0000;
    }

    #pets button.delete-pet-button:hover {
        background-color: #660000;
    }

    /* Compact controls in the lower sections; override full-width global buttons. */
    #participant-alerts .alert-add-form button,
    #pets .pet-add-action .button,
    #distribution-history .distribution-empty-action .button,
    .participant-footer-links .button {
        display: inline-flex !important; width: auto !important; min-width: 0 !important;
        max-width: none !important; min-height: 0 !important;
        padding: 7px 13px !important; margin: 0 !important;
        font-size: .85rem !important; line-height: 1.3 !important;
        border-radius: 5px; text-decoration: none; box-shadow: none !important;
    }
    #participant-alerts .alert-add-form button,
    #pets .pet-add-action .button,
    #distribution-history .distribution-empty-action .button {
        background: #206b80 !important; color: white !important; border: 1px solid #206b80 !important;
    }
    #participant-alerts .alert-add-form button:hover,
    #pets .pet-add-action .button:hover,
    #distribution-history .distribution-empty-action .button:hover { background: #155367 !important; }
    #pets .pet-table-scroll, #distribution-history .distribution-table-scroll { overflow-x: auto; }
    #pets table { width: 100%; border-collapse: collapse; font-size: .86rem; }
    #pets th { background: #f2f8fa; color: #23495b; font-weight: 700; white-space: nowrap; }
    #pets th, #pets td { padding: 9px 7px; border-bottom: 1px solid #e1ebef; vertical-align: middle; }
    #pets td.pet-actions { min-width: 112px; white-space: nowrap; }
    #pets td.pet-actions .button,
    #pets td.pet-actions button {
        display: inline-flex !important; align-items: center; justify-content: center;
        width: auto !important; min-width: 0 !important; max-width: none !important;
        min-height: 0 !important; margin: 0 !important; padding: 5px 8px !important;
        font-size: .78rem !important; line-height: 1.2 !important;
        border-radius: 4px; box-shadow: none !important;
    }
    #pets td.pet-actions form { display: inline-block !important; margin: 0 0 0 4px !important; }
    #pets td.pet-actions .button { background: #28546a !important; color: #fff !important; }
    #pets td.pet-actions .delete-pet-button { background: #8b2020 !important; color: #fff !important; }
    #pets .pet-add-action { text-align: left; margin-top: 14px; }
    #distribution-history .distribution-empty-action { margin-top: 12px; }
    .participant-footer-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin: 18px auto; }
    .participant-footer-links .button { color: #20566b !important; background: #fff !important; border: 1px solid #a9ccd6 !important; }
    @media (max-width: 560px) { #pets td.pet-actions { min-width: 75px; } #pets td.pet-actions form { margin: 4px 0 0 !important; } }
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
    <div class="signup-form participant-summary" aria-label="Participant summary and quick actions">
        <h2><?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?></h2>
        <p class="participant-meta"><strong>Participant ID:</strong> <?php echo hsc($participant->get_display_id()) ?>
            &nbsp; <strong>Status:</strong> <?php echo hsc($participant->get_status()) ?>
            &nbsp; <strong>Location:</strong> <?php echo hsc($participant->get_location()) ?></p>
        <?php if ($participant->get_alert() || count($activeAlerts) > 0): ?>
        <p class="participant-alert-notice"><strong>Attention:</strong> This participant has an active alert or flag. <a href="#participant-alerts">Review alerts</a> before distribution.</p>
        <?php endif ?>
        <nav class="participant-quick-actions" aria-label="Participant quick actions">
            <a class="button primary-action" href="distributionRecord.php?id=<?php echo (int) $participant->get_id() ?>">Record Distribution</a>
            <a class="button" href="#pets">Pets</a>
            <a class="button" href="#participant-alerts">Alerts</a>
            <a class="button" href="#distribution-history">Distribution History</a>
            <a class="button" href="#participant-details">Edit Participant</a>
        </nav>
    </div>
    <form class="signup-form" method="post" id="participant-details">
        <p class="mb-2">Update participant details below. An asterisk (<em>*</em>) indicates a required field.</p>

        <?php if (isset($_GET['saved']) && count($errors) == 0): ?>
        <div class="happy-toast">The participant's information has been saved.</div>
        <?php endif ?>

        <?php if (isset($_GET['distributed']) && count($errors) == 0): ?>
        <div class="happy-toast">The food distribution has been recorded.</div>
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
            <div class="participant-edit-grid">

                <div class="participant-field">
            <label for="participant_id">Participant ID</label>
            <input type="text" id="participant_id" value="<?php echo hsc($participant->get_display_id()) ?>" readonly disabled>

                </div>
                <div class="participant-field">
            <label for="first_name"><em>* </em>First Name</label>
            <input type="text" id="first_name" name="first_name" required maxlength="50" placeholder="Enter first name" value="<?php echo hsc($args['first_name']) ?>">

                </div>
                <div class="participant-field">
            <label for="last_name"><em>* </em>Last Name</label>
            <input type="text" id="last_name" name="last_name" required maxlength="50" placeholder="Enter last name" value="<?php echo hsc($args['last_name']) ?>">

                </div>
                <div class="participant-field participant-field-full">
            <label for="street_address"><em>* </em>Street Address</label>
            <input type="text" id="street_address" name="street_address" required maxlength="100" placeholder="Enter street address" value="<?php echo hsc($args['street_address']) ?>">

                </div>
                <div class="participant-field">
            <label for="city">City</label>
            <input type="text" id="city" name="city" maxlength="50" placeholder="Enter city" value="<?php echo hsc($args['city']) ?>">

                </div>
                <div class="participant-field">
            <label for="state">State</label>
            <select id="state" name="state">
                <option value=""<?php if ($args['state'] === '') echo ' selected' ?>>Select a state</option>
                <?php participant_options($states, $args['state']) ?>
            </select>

                </div>
                <div class="participant-field">
            <label for="zip">ZIP Code</label>
            <input type="text" id="zip" name="zip" pattern="[0-9]{5}" maxlength="5" inputmode="numeric" title="Please enter a 5-digit ZIP code" placeholder="Enter ZIP code" value="<?php echo hsc($args['zip']) ?>">
                </div>
            </div>
        </fieldset>

        <fieldset class="section-box mb-4">
            <h3>Contact Information</h3>
            <p class="mb-2">The participant's contact information.</p>
            <div class="blue-div"></div>
            <div class="participant-edit-grid">

                <div class="participant-field">
            <label for="phone1"><em>* </em>Phone Number</label>
            <input type="tel" id="phone1" name="phone1" required pattern="(\D{0,1})\d{3}(\D{0,2})\d{3}(.{0,1})\d{4}" placeholder="Ex. (555) 555-5555" title="Please enter the phone number as 555-555-5555" value="<?php echo hsc($args['phone1']) ?>">

                </div>
                <div class="participant-field">
            <label for="email">E-mail (Optional)</label>
            <input type="email" id="email" name="email" maxlength="100" placeholder="Enter e-mail address" value="<?php echo hsc($args['email']) ?>">

                </div>
                <div class="participant-field">
            <label for="preferred_language"><em>* </em>Preferred Language</label>
            <select id="preferred_language" name="preferred_language" required>
                <?php participant_options($languages, $args['preferred_language']) ?>
            </select>
                </div>
            </div>
        </fieldset>

        <fieldset class="section-box mb-4">
            <h3>Pet Pantry Information</h3>
            <p class="mb-2">The participant's Pet Pantry registration information.</p>
            <div class="blue-div"></div>
            <div class="participant-edit-grid">

                <div class="participant-field">
            <label for="registration_date">Registration Date</label>
            <input type="date" id="registration_date" value="<?php echo hsc($participant->get_registration_date()) ?>" readonly disabled>

                </div>
                <div class="participant-field">
            <label for="participant_status"><em>* </em>Participant Status</label>
            <select id="participant_status" name="participant_status" required>
                <?php participant_options($statuses, $args['participant_status']) ?>
            </select>

                </div>
                <div class="participant-field">
            <label for="participant_location"><em>* </em>Participant Location</label>
            <select id="participant_location" name="participant_location" required>
                <?php participant_options($locations, $args['participant_location']) ?>
            </select>

                </div>
                <div class="participant-field">
            <label for="participant_alert">Alert / Flag</label>
            <input type="text" id="participant_alert" value="<?php echo ($participant->get_alert() || count($activeAlerts) > 0) ? 'Flagged' : 'No' ?>" readonly disabled>
            <p class="mb-2">Use the Participant Alerts section below to add or resolve alerts.</p>

                </div>
                <div class="participant-field participant-field-full">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="4" placeholder="Enter any additional notes about the participant..."><?php echo hsc($args['notes']) ?></textarea>
                </div>
            </div>
        </fieldset>

        <input type="submit" value="Save Changes">
    </form>

    <div class="signup-form" id="participant-alerts">
        <fieldset class="section-box mb-4">
            <h3>Participant Alerts</h3>
            <p>Any signed-in user can add an alert. Only administrators can resolve one.</p>
            <div class="blue-div"></div>
            <?php if (isset($_GET['alertAdded'])): ?><div class="happy-toast">Alert added.</div><?php endif ?>
            <?php if (isset($_GET['alertResolved'])): ?><div class="happy-toast">Alert resolved.</div><?php endif ?>
            <?php foreach ($alertErrors as $message): ?>
                <div class="error-toast"><?php echo hsc($message) ?></div>
            <?php endforeach ?>
            <?php if (count($activeAlerts) === 0): ?>
                <p>No active categorized alerts.</p>
            <?php else: ?>
                <?php foreach ($activeAlerts as $alert): ?>
                    <div class="info-box" style="margin: 1rem 0; padding: 1rem; border-left: 4px solid #d97706;">
                        <strong><?php echo hsc($alert['alert_type']) ?></strong>
                        <p><?php echo nl2br(hsc($alert['reason'])) ?></p>
                        <small>Added <?php echo hsc($alert['created_at']) ?> by <?php echo hsc($alert['created_by']) ?></small>
                        <?php if ($canResolveAlert): ?>
                            <form method="post" onsubmit="return confirm('Resolve this alert?');">
                                <input type="hidden" name="alert_action" value="resolve">
                                <input type="hidden" name="alert_id" value="<?php echo (int) $alert['id'] ?>">
                                <input type="hidden" name="alert_csrf" value="<?php echo hsc($_SESSION['participant_alert_csrf']) ?>">
                                <button type="submit">Resolve Alert</button>
                            </form>
                        <?php endif ?>
                    </div>
                <?php endforeach ?>
            <?php endif ?>
            <?php if ($participant->get_alert() && count($activeAlerts) === 0): ?>
                <p><strong>Legacy flag:</strong> This participant is flagged in the original system. The existing flag has been preserved.</p>
            <?php endif ?>
            <h4>Add Alert</h4>
            <form method="post" class="alert-add-form">
                <input type="hidden" name="alert_action" value="add">
                <input type="hidden" name="alert_csrf" value="<?php echo hsc($_SESSION['participant_alert_csrf']) ?>">
                <label for="alert_type">Alert Type</label>
                <select id="alert_type" name="alert_type" required>
                    <option value="">Select an alert type</option>
                    <?php foreach (participant_alert_types() as $type): ?>
                        <option value="<?php echo hsc($type) ?>"><?php echo hsc($type) ?></option>
                    <?php endforeach ?>
                </select>
                <label for="alert_reason">Reason</label>
                <textarea id="alert_reason" name="alert_reason" rows="3" maxlength="2000" required></textarea>
                <button type="submit">Add Alert</button>
            </form>
            <?php if (count($allAlerts) > count($activeAlerts)): ?>
                <h4>Resolved Alert History</h4>
                <?php foreach ($allAlerts as $alert): ?>
                    <?php if ($alert['resolved_at'] !== null): ?>
                        <p><?php echo hsc($alert['alert_type']) ?> — <?php echo hsc($alert['reason']) ?> (resolved <?php echo hsc($alert['resolved_at']) ?>)</p>
                    <?php endif ?>
                <?php endforeach ?>
            <?php endif ?>
        </fieldset>
    </div>

    <?php
    // Active pets are listed first. Rehomed, Deceased and Inactive pets are
    // listed under Former Pets so their records stay reachable; Deleted pets
    // are left out (get_pets_by_participant skips them).
    $allPets = get_pets_by_participant($participant->get_id());
    $pets = array_filter($allPets, function ($pet) {
        return $pet->get_status() === 'Active';
    });
    $formerPets = array_filter($allPets, function ($pet) {
        return $pet->get_status() !== 'Active';
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
            <div class="pet-table-scroll">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Species</th>
                            <th>Age (Years)</th>
                            <th>Spay/Neuter</th>
                            <th>Rabies</th>
                            <th>Counseling</th>
                            <th><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pets as $pet): ?>
                        <?php $medicalNeeds = (string) $pet->get_special_medical_needs() ?>
                        <tr<?php if ($medicalNeeds !== '') echo ' class="has-medical"' ?>>
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
                                <a href="petEdit.php?id=<?php echo (int) $pet->get_id() ?>" class="button">Edit</a>
                                <form method="post" action="petDelete.php" onsubmit="return confirm(<?php echo hsc(json_encode('Delete ' . $pet->get_name() . '? This cannot be undone from this page.')) ?>);">
                                    <input type="hidden" name="pet_id" value="<?php echo (int) $pet->get_id() ?>">
                                    <button type="submit" class="delete-pet-button">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php if ($medicalNeeds !== ''): ?>
                        <tr class="pet-medical-row">
                            <td colspan="7"><strong>Special medical needs:</strong> <?php echo nl2br(hsc($medicalNeeds)) ?></td>
                        </tr>
                        <?php endif ?>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endif ?>

            <?php if (count($formerPets) > 0): ?>
            <h4 class="mt-2">Former Pets</h4>
            <p class="mb-2">Pets that were rehomed or have died. They are kept for this participant's history and are not counted as current pets.</p>
            <div class="pet-table-scroll">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Species</th>
                            <th>Status</th>
                            <th><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($formerPets as $pet): ?>
                        <tr>
                            <td><?php echo hsc($pet->get_name()) ?></td>
                            <td><?php echo hsc($pet->get_species()) ?></td>
                            <td><?php echo hsc($pet->get_status()) ?></td>
                            <td class="pet-actions">
                                <a href="petEdit.php?id=<?php echo (int) $pet->get_id() ?>" class="button">Edit</a>
                            </td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endif ?>

            <div class="pet-add-action">
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
            <div class="distribution-empty-action"><a class="button" href="distributionRecord.php?id=<?php echo (int) $participant->get_id() ?>">Record First Distribution</a></div>
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

    <nav class="participant-footer-links" aria-label="Page navigation">
        <a href="participantSearch.php" class="button">Back to Participant Search</a>
        <a href="index.php" class="button">Return to Dashboard</a>
    </nav>
<?php endif ?>
  </div>
</main>

</body>
</html>
