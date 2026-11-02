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
    // Volunteers and admins (SRS UC-07)
    if ($accessLevel < 1) {
        header('Location: index.php');
        die();
    }

    require_once('include/output.php');
    require_once('database/dbParticipants.php');
    require_once('database/dbParticipantDistributions.php');

    // UC-07 View Participant Distribution History. Read only: nothing on this
    // page changes a distribution.
    $id = (isset($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $participant = $id > 0 ? retrieve_participant($id) : null;

    $distributions = array();
    $selected = null;
    $selectedMissing = false;
    if ($participant !== null) {
        $distributions = get_participant_distributions($participant->get_id());

        // One distribution picked from the list for more detail
        if (isset($_GET['distribution'])) {
            if (ctype_digit($_GET['distribution'])) {
                $selected = get_participant_distribution((int) $_GET['distribution']);
            }
            // Only show distributions that belong to this participant
            if ($selected === null || (int) $selected->get_participant_id() !== (int) $participant->get_id()) {
                $selected = null;
                $selectedMissing = true;
            }
        }
    }

    // Totals shown above the list
    $totalDog = 0;
    $totalCat = 0;
    foreach ($distributions as $distribution) {
        $totalDog += (float) $distribution->get_dog_food_lbs();
        $totalCat += (float) $distribution->get_cat_food_lbs();
    }

    function history_date($date) {
        return date('M j, Y', strtotime($date));
    }

    function history_lbs($lbs) {
        return number_format((float) $lbs, 2);
    }
?>

<!DOCTYPE html>
<html>
<head>
    <title>CCDA | Distribution History</title>
    <link href="css/base.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<!-- BANDAID END, REMOVE ONCE SOME GENIUS FIXES -->
    <style>
        .distribution-detail dt { font-weight: bold; margin-top: 0.75rem; }
        .distribution-detail dd { margin: 0.25rem 0 0 0; }

        /* Totals above the distribution list */
        .history-totals {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .history-total {
            flex: 1 1 10rem;
            background-color: var(--main-color);
            border-left: 6px solid var(--accent-color);
            border-radius: 8px;
            padding: 1rem 1.25rem;
            color: white;
            text-align: center;
        }
        .history-total-value {
            font-size: 2rem;
            font-weight: bold;
            line-height: 1.2;
        }
        .history-total-unit {
            font-size: 1.1rem;
            font-weight: normal;
        }
        .history-total-label {
            margin-top: 0.25rem;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--accent-color);
        }
    </style>
</head>
<body class="relative">

<header class="hero-header">
    <div class="center-header">
        <h1>Distribution History</h1>
    </div>
</header>

<main>
  <div class="main-content-box">
<?php if ($participant === null): ?>
    <?php // Reached without a participant: ask whose history to show ?>
    <form class="signup-form" method="get" action="participantSearch.php">
        <div class="text-center spacing-bottom">
            <h2 class="mb-8">Find a Participant</h2>
            <div class="info-box">
                <p class="sub-text">Search for the participant, then select Distribution History beside them in the results.</p>
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
    <div class="signup-form">
        <div class="text-center spacing-bottom">
            <h2 class="mb-8"><?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?></h2>
            <div class="info-box">
                <p class="sub-text">Participant: <?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?> &middot; ID <?php echo (int) $participant->get_id() ?> &middot; Status: <?php echo hsc($participant->get_status()) ?></p>
            </div>
        </div>

        <?php if ($selectedMissing): ?>
        <div class="error-toast">That distribution could not be found for this participant.</div>
        <?php endif ?>

        <?php if ($selected !== null): ?>
        <fieldset class="section-box mb-4" id="distribution-detail">
            <h3>Distribution on <?php echo hsc(history_date($selected->get_distribution_date())) ?></h3>
            <p class="mb-2"><strong>Participant:</strong> <?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?> (ID <?php echo (int) $participant->get_id() ?>)</p>
            <p class="mb-2">Full details of this distribution.</p>
            <div class="blue-div"></div>

            <dl class="distribution-detail">
                <dt>Distribution Date</dt>
                <dd><?php echo hsc(history_date($selected->get_distribution_date())) ?></dd>
                <dt>Location</dt>
                <dd><?php echo hsc($selected->get_location()) ?></dd>
                <dt>Number of Pets Receiving Food</dt>
                <dd><?php echo (int) $selected->get_pets_served() ?></dd>
                <dt>Dog Food</dt>
                <dd><?php echo hsc(history_lbs($selected->get_dog_food_lbs())) ?> lbs</dd>
                <dt>Cat Food</dt>
                <dd><?php echo hsc(history_lbs($selected->get_cat_food_lbs())) ?> lbs</dd>
                <dt>Processed By</dt>
                <dd><?php echo hsc($selected->get_processed_by_name()) ?></dd>
                <dt>Recorded</dt>
                <dd><?php echo $selected->get_created_at() ? hsc(date('M j, Y g:i A', strtotime($selected->get_created_at()))) : '&mdash;' ?></dd>
                <dt>Notes</dt>
                <dd><?php echo (string) $selected->get_notes() !== '' ? nl2br(hsc($selected->get_notes())) : 'No notes.' ?></dd>
            </dl>

            <div class="text-center mt-2">
                <a href="distributionHistory.php?id=<?php echo (int) $participant->get_id() ?>" class="button">Back to All Distributions</a>
            </div>
        </fieldset>
        <?php endif ?>

        <fieldset class="section-box mb-4">
            <h3>Prior Distributions</h3>
            <?php if (count($distributions) > 0): ?>
            <p class="mb-2">Food distributions recorded for <?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?>, newest first. Select View for more detail.</p>
            <?php endif ?>
            <div class="blue-div"></div>

            <?php if (count($distributions) == 0): ?>
            <div class="info-box text-center">
                <p class="mb-2"><strong>No distribution history yet</strong></p>
                <p class="mb-2"><?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?> has not received any pet food from the pantry so far.</p>
                <p class="mb-2">Each time food is given to this participant, record it with <strong>Record Distribution</strong>. It will then appear here with the date, location, number of pets, and the amount of dog and cat food given.</p>
            </div>
            <div class="text-center mt-2">
                <a href="distributionRecord.php?id=<?php echo (int) $participant->get_id() ?>" class="button">Record a Distribution</a>
            </div>
            <?php else: ?>
            <div class="history-totals">
                <div class="history-total">
                    <div class="history-total-value"><?php echo count($distributions) ?></div>
                    <div class="history-total-label">Distribution<?php if (count($distributions) != 1) echo 's' ?></div>
                </div>
                <div class="history-total">
                    <div class="history-total-value"><?php echo hsc(history_lbs($totalDog)) ?> <span class="history-total-unit">lbs</span></div>
                    <div class="history-total-label">Total Dog Food</div>
                </div>
                <div class="history-total">
                    <div class="history-total-value"><?php echo hsc(history_lbs($totalCat)) ?> <span class="history-total-unit">lbs</span></div>
                    <div class="history-total-label">Total Cat Food</div>
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Location</th>
                            <th>Pets</th>
                            <th>Dog Food (lbs)</th>
                            <th>Cat Food (lbs)</th>
                            <th>Notes</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($distributions as $distribution): ?>
                        <?php
                            // Long notes are shortened here; the detail view shows all of it
                            $notes = (string) $distribution->get_notes();
                            $shortNotes = mb_strlen($notes) > 60 ? mb_substr($notes, 0, 60) . '…' : $notes;
                        ?>
                        <tr<?php if ($selected !== null && (int) $selected->get_id() === (int) $distribution->get_id()) echo ' style="font-weight: bold;"' ?>>
                            <td><?php echo hsc(history_date($distribution->get_distribution_date())) ?></td>
                            <td><?php echo hsc($distribution->get_location()) ?></td>
                            <td><?php echo (int) $distribution->get_pets_served() ?></td>
                            <td><?php echo hsc(history_lbs($distribution->get_dog_food_lbs())) ?></td>
                            <td><?php echo hsc(history_lbs($distribution->get_cat_food_lbs())) ?></td>
                            <td><?php echo hsc($shortNotes) ?></td>
                            <td><a href="distributionHistory.php?id=<?php echo (int) $participant->get_id() ?>&amp;distribution=<?php echo (int) $distribution->get_id() ?>#distribution-detail">View</a></td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endif ?>
        </fieldset>
    </div>

    <div class="text-center">
        <a href="participantEdit.php?id=<?php echo (int) $participant->get_id() ?>" class="button">Back to Participant</a>
        <a href="participantSearch.php" class="button">Back to Participant Search</a>
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>
<?php endif ?>
  </div>
</main>

</body>
</html>
