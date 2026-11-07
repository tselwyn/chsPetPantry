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
    require_once('database/dbParticipantDistributions.php');

    // Same choices as the participant location on participantEdit.php
    $locations = array('Empower', 'Senior Center', 'Home Delivery');

    // Largest values the dbparticipantdistributions columns are expected to hold
    $maxPets = 50;
    $maxLbs = 999.99;
    $maxNotes = 1000;
    $maxProcessedBy = 100;

    $id = (isset($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $participant = $id > 0 ? retrieve_participant($id) : null;

    $errors = array();
    $args = array();
    $warnings = array();
    if ($participant !== null) {
        // Read unresolved categorized alerts each time the page is requested,
        // including POST requests, so acknowledgement is enforced server-side.
        // Each warning is shown with its alert reason as a heading
        $activeAlerts = get_participant_alerts($participant->get_id());
        $alertWarnings = array();
        foreach ($activeAlerts as $alert) {
            $alertWarnings[] = array(
                'type' => $alert['alert_type'],
                'reason' => $alert['reason'],
                'added' => 'Added ' . date('M j, Y', strtotime($alert['created_at'])) . ' by ' . $alert['created_by']
            );
            $warnings[] = $alert['alert_type'] . ': ' . $alert['reason'];
        }

        // Keep a generic warning for any old flag without categorized alerts.
        if ($participant->get_alert() && count($activeAlerts) === 0) {
            $warnings[] = 'This participant is Flagged (Alert / Flag on their record).';
        }
        if ($participant->get_status() === 'DND') {
            $warnings[] = 'This participant is marked DND (Do Not Distribute).';
        } else if ($participant->get_status() !== 'Active') {
            $warnings[] = 'This participant\'s status is ' . $participant->get_status() . ', not Active.';
        }

        if (!in_array($participant->get_location(), $locations)) {
            $locations[] = $participant->get_location();
        }

        // What the form shows until something is submitted
        $petCount = count_active_pets($participant->get_id());
        $args = array(
            'distribution_date' => date('Y-m-d'),
            'distribution_location' => $participant->get_location(),
            'pets_served' => $petCount > 0 ? (string) $petCount : '',
            'dog_food_lbs' => '',
            'cat_food_lbs' => '',
            'processed_by_name' => '',
            'notes' => '',
            'acknowledge_warning' => ''
        );
    }

    if ($participant !== null && $_SERVER["REQUEST_METHOD"] == "POST") {
        // Trimmed only, not sanitize()d: add_participant_distribution uses prepared
        // statements and pages escape the data with hsc() when they show it
        foreach (array_keys($args) as $field) {
            $args[$field] = (isset($_POST[$field]) && is_string($_POST[$field])) ? trim($_POST[$field]) : '';
        }

        // Each missing field is named so the user knows what to correct
        $required = array(
            'distribution_date' => 'Distribution date',
            'distribution_location' => 'Distribution location',
            'pets_served' => 'Number of pets receiving food',
            'processed_by_name' => 'Processed By (your name)'
        );
        foreach ($required as $field => $label) {
            if ($args[$field] === '') {
                $errors[] = $label . ' is required.';
            }
        }

        if ($args['distribution_date'] !== '') {
            if (!validateDate($args['distribution_date'])) {
                $errors[] = 'Enter a valid distribution date.';
            } else if ($args['distribution_date'] > date('Y-m-d')) {
                $errors[] = 'Distribution date cannot be in the future.';
            }
        }

        if ($args['distribution_location'] !== '' && !valueConstrainedTo($args['distribution_location'], $locations)) {
            $errors[] = 'Choose a distribution location from the list.';
        }

        if ($args['pets_served'] !== '') {
            if (!ctype_digit($args['pets_served']) || (int) $args['pets_served'] < 1 || (int) $args['pets_served'] > $maxPets) {
                $errors[] = 'Number of pets receiving food must be a whole number from 1 to ' . $maxPets . '.';
            }
        }

        // Dog and cat food are each optional, but at least one has to be given out
        $quantities = array('dog_food_lbs' => 'Dog food', 'cat_food_lbs' => 'Cat food');
        $quantitiesValid = true;
        foreach ($quantities as $field => $label) {
            if ($args[$field] !== '' && (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $args[$field]) || (float) $args[$field] > $maxLbs)) {
                $errors[] = $label . ' quantity must be a number of pounds from 0 to ' . $maxLbs . ', with at most 2 decimal places.';
                $quantitiesValid = false;
            }
        }
        if ($quantitiesValid && (float) $args['dog_food_lbs'] <= 0 && (float) $args['cat_food_lbs'] <= 0) {
            $errors[] = 'Enter a dog food quantity, a cat food quantity, or both.';
        }

        if (mb_strlen($args['processed_by_name']) > $maxProcessedBy) {
            $errors[] = 'Processed By must be ' . $maxProcessedBy . ' characters or fewer.';
        }

        if (mb_strlen($args['notes']) > $maxNotes) {
            $errors[] = 'Notes must be ' . $maxNotes . ' characters or fewer.';
        }

        if (count($warnings) > 0 && $args['acknowledge_warning'] !== 'Yes') {
            $errors[] = 'Confirm that you have reviewed the warning for this participant.';
        }

        if (count($errors) == 0) {
            // Logins are shared, so the volunteer types their name; the login's
            // id is kept as well
            $distribution = new ParticipantDistribution(
                null,
                $participant->get_id(),
                $args['distribution_date'],
                $args['distribution_location'],
                (int) $args['pets_served'],
                (float) $args['dog_food_lbs'],
                (float) $args['cat_food_lbs'],
                $userID,
                $args['notes'] === '' ? null : $args['notes'],
                $args['processed_by_name']
            );
            if (add_participant_distribution($distribution)) {
                // Redirect so refreshing the page doesn't record the distribution again
                header('Location: participantEdit.php?id=' . $participant->get_id() . '&distributed#distribution-history');
                die();
            }
            $errors[] = 'The distribution could not be saved. Please try again.';
        }
    }
?>

<!DOCTYPE html>
<html>
<head>
    <title>CCDA | Record Food Distribution</title>
    <link href="css/normal_tw.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<!-- BANDAID END, REMOVE ONCE SOME GENIUS FIXES -->
    <style>
        /* CHS colors and layout, copied from participantSearch.php so the
           pages look the same. normal_tw.css still has the old CCDA blue. */
        :root {
            --chs-teal: #1f5968;
            --chs-teal-dark: #17444f;
            --chs-accent: #50bfd3;
            --chs-tint: #eef7f9;
            --chs-border: #d3e4e8;
        }

        body, main {
            background-color: white;
        }

        .hero-header {
            background-color: var(--chs-teal);
        }

        /* Not .main-content-box: normal_tw.css makes that blue with a layered
           !important, which no rule on this page can override */
        .search-card {
            background-color: white;
            border: 1px solid var(--chs-border);
            border-radius: 0.75rem;
            color: #374151;
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }

        .search-card h2,
        .search-card h3,
        .search-card label {
            color: var(--chs-teal);
        }

        .search-card h3 {
            margin-top: 0;
            font-size: 1.35rem;
            font-weight: 700;
        }

        .sub-text {
            color: #4b5563;
        }

        .search-card .blue-div {
            background-color: var(--chs-accent);
        }

        /* Form controls */
        .search-card input:focus,
        .search-card select:focus,
        .search-card textarea:focus {
            outline: none;
            border-color: var(--chs-accent);
            box-shadow: 0 0 0 2px var(--chs-accent);
        }

        .search-card textarea {
            display: block;
            width: 100%;
            margin-bottom: 0.5rem;
            border: 2px solid #d1d5db;
            border-radius: 0.375rem;
            padding: 0.5rem;
        }

        .search-card input[readonly] {
            background-color: #f3f4f6;
            color: #4b5563;
        }

        .search-card input[type="checkbox"] {
            width: auto;
            margin: 0 0.5rem 0 0;
            vertical-align: middle;
        }

        /* Each group of fields */
        .form-section {
            border: 1px solid var(--chs-border);
            border-radius: 0.5rem;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-section .section-help {
            color: #4b5563;
            margin-bottom: 0.5rem;
        }

        .acknowledge-label {
            font-size: 1rem;
            font-weight: 600;
        }

        .blue-button {
            display: inline-block;
            background-color: var(--chs-teal);
            font-weight: 600;
            transition: background-color .3s;
        }

        .blue-button:hover,
        .blue-button:focus-visible {
            background-color: var(--chs-teal-dark);
        }

        .blue-button:focus-visible,
        .return-button:focus-visible {
            outline: 2px solid var(--chs-accent);
            outline-offset: 2px;
        }

        .return-button {
            display: inline-block;
            margin: 0.25rem;
            background-color: white;
            border: 2px solid var(--chs-teal);
            color: var(--chs-teal);
            font-weight: 600;
        }

        .return-button:hover {
            background-color: var(--chs-tint);
        }

        .error-block,
        .warning-block {
            margin-bottom: 1rem;
            padding: 1rem;
            border-radius: 0.5rem;
            text-align: left;
            font-weight: 500;
        }

        .error-block {
            border-left: 4px solid #dc2626;
            background-color: #fef2f2;
            color: #991b1b;
        }

        .warning-block {
            border-left: 4px solid #d97706;
            background-color: #fffbeb;
            color: #92400e;
        }

        .warning-block .alert-list {
            list-style: none;
            margin: 0.5rem 0;
            padding: 0;
        }

        .warning-block .alert-list li {
            margin-bottom: 0.5rem;
            padding: 0.5rem 0.75rem;
            border: 1px solid #fcd34d;
            border-radius: 0.375rem;
            background-color: white;
        }

        .warning-block .alert-type {
            display: inline-block;
            margin-bottom: 0.25rem;
            padding: 0.1rem 0.6rem;
            border-radius: 999px;
            background-color: #d97706;
            color: white;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .warning-block .alert-reason {
            display: block;
        }

        .warning-block .alert-meta {
            display: block;
            font-size: 0.85rem;
            font-weight: normal;
            color: #a16207;
        }

        .error-block ul,
        .warning-block ul:not(.alert-list) {
            list-style: disc;
            margin: 0.5rem 0 0.5rem 1.5rem;
        }
    </style>
</head>
<body>

<header class="hero-header">
    <div class="center-header">
        <h1>Record Food Distribution</h1>
    </div>
</header>

<main>
  <div class="search-card w-[80%] p-8">
<?php if ($participant === null): ?>
    <?php // Reached without a participant: ask who the distribution is for ?>
    <form class="space-y-6" method="get" action="participantSearch.php">
        <div class="text-center mb-8">
            <h2>Find a Participant</h2>
            <p class="sub-text">Search for the participant receiving food, then select Record Distribution beside them in the results.</p>
        </div>

        <?php if (isset($_GET['id'])): ?>
        <div class="error-block">That participant could not be found.</div>
        <?php endif ?>

        <div>
            <label for="q">ID, Name, Address, or Phone Number</label>
            <input type="text" id="q" name="q" class="w-full" required maxlength="<?php echo PARTICIPANT_SEARCH_MAX_LENGTH ?>" placeholder="Ex. 12, Maria Gonzalez, or 540-555-0101" autofocus>
        </div>

        <div class="text-center pt-4">
            <input type="submit" value="Search" class="blue-button">
        </div>
    </form>
<?php else: ?>
    <form method="post">
        <div class="text-center mb-8">
            <h2><?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?></h2>
            <p class="sub-text">Record a food distribution for this participant.</p>
            <p class="sub-text">An asterisk ( <em>*</em> ) indicates a required field.</p>
        </div>

        <?php if (count($warnings) > 0): ?>
        <div class="warning-block" role="alert">
            <p><strong>Warning: review before completing this distribution.</strong></p>
            <?php if (count($alertWarnings) > 0): ?>
            <ul class="alert-list">
                <?php foreach ($alertWarnings as $alertWarning): ?>
                <li>
                    <span class="alert-type"><?php echo hsc($alertWarning['type']) ?></span>
                    <span class="alert-reason"><?php echo nl2br(hsc($alertWarning['reason'])) ?></span>
                    <span class="alert-meta"><?php echo hsc($alertWarning['added']) ?></span>
                </li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
            <?php if (count($warnings) > count($alertWarnings)): ?>
            <ul>
                <?php foreach (array_slice($warnings, count($alertWarnings)) as $warning): ?>
                <li><?php echo hsc($warning) ?></li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
            <?php if ((string) $participant->get_notes() !== ''): ?>
            <p>Participant notes: <?php echo nl2br(hsc($participant->get_notes())) ?></p>
            <?php endif ?>
        </div>
        <?php endif ?>

        <?php if (count($errors) > 0): ?>
        <div class="error-block">
            <p>The distribution was not saved:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?php echo hsc($error) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>

        <fieldset class="form-section">
            <h3>Participant</h3>
            <p class="section-help">The participant receiving this distribution.</p>
            <div class="blue-div"></div>

            <label for="participant_id">Participant ID</label>
            <input type="text" id="participant_id" value="<?php echo (int) $participant->get_id() ?>" readonly disabled>

            <label for="participant_name">Name</label>
            <input type="text" id="participant_name" value="<?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?>" readonly disabled>

            <label for="participant_status">Participant Status</label>
            <input type="text" id="participant_status" value="<?php echo hsc($participant->get_status()) ?>" readonly disabled>
        </fieldset>

        <fieldset class="form-section">
            <h3>Distribution</h3>
            <p class="section-help">When and where the food was given out.</p>
            <div class="blue-div"></div>

            <label for="distribution_date"><em>* </em>Distribution Date</label>
            <input type="date" id="distribution_date" name="distribution_date" required max="<?php echo date('Y-m-d') ?>" value="<?php echo hsc($args['distribution_date']) ?>">

            <label for="distribution_location"><em>* </em>Distribution Location</label>
            <select id="distribution_location" name="distribution_location" required>
                <?php foreach ($locations as $location): ?>
                <option value="<?php echo hsc($location) ?>"<?php if ($location === $args['distribution_location']) echo ' selected' ?>><?php echo hsc($location) ?></option>
                <?php endforeach ?>
            </select>

            <label for="processed_by_name"><em>* </em>Processed By (your name)</label>
            <input type="text" id="processed_by_name" name="processed_by_name" required maxlength="<?php echo $maxProcessedBy ?>" placeholder="Enter your first and last name" autocomplete="off" value="<?php echo hsc($args['processed_by_name']) ?>">
        </fieldset>

        <fieldset class="form-section">
            <h3>Food Given</h3>
            <p class="section-help">Enter a dog food quantity, a cat food quantity, or both.</p>
            <div class="blue-div"></div>

            <label for="pets_served"><em>* </em>Number of Pets Receiving Food</label>
            <input type="number" id="pets_served" name="pets_served" required min="1" max="<?php echo $maxPets ?>" step="1" placeholder="Enter number of pets" value="<?php echo hsc($args['pets_served']) ?>">

            <label for="dog_food_lbs">Dog Food (lbs)</label>
            <input type="number" id="dog_food_lbs" name="dog_food_lbs" min="0" max="<?php echo $maxLbs ?>" step="0.01" placeholder="Enter pounds of dog food" value="<?php echo hsc($args['dog_food_lbs']) ?>">

            <label for="cat_food_lbs">Cat Food (lbs)</label>
            <input type="number" id="cat_food_lbs" name="cat_food_lbs" min="0" max="<?php echo $maxLbs ?>" step="0.01" placeholder="Enter pounds of cat food" value="<?php echo hsc($args['cat_food_lbs']) ?>">

            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="4" maxlength="<?php echo $maxNotes ?>" placeholder="Enter any notes about this distribution..."><?php echo hsc($args['notes']) ?></textarea>
        </fieldset>

        <?php if (count($warnings) > 0): ?>
        <fieldset class="form-section">
            <label for="acknowledge_warning" class="acknowledge-label">
                <input type="checkbox" id="acknowledge_warning" name="acknowledge_warning" value="Yes" required<?php if ($args['acknowledge_warning'] === 'Yes') echo ' checked' ?>>
                <em>* </em>I have reviewed the warning above and this distribution should still be recorded.
            </label>
        </fieldset>
        <?php endif ?>

        <div class="text-center pt-4">
            <input type="submit" value="Save Distribution" class="blue-button">
        </div>
    </form>
<?php endif ?>
  </div>

  <div class="text-center mt-6">
<?php if ($participant !== null): ?>
    <a href="participantEdit.php?id=<?php echo (int) $participant->get_id() ?>" class="return-button">Back to Participant</a>
    <a href="participantSearch.php" class="return-button">Back to Participant Search</a>
<?php endif ?>
    <a href="index.php" class="return-button">Return to Dashboard</a>
  </div>
</main>

</body>
</html>
