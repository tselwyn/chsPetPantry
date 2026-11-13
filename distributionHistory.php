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
    <link href="css/normal_tw.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<!-- BANDAID END, REMOVE ONCE SOME GENIUS FIXES -->
    <style>
        .distribution-detail dt { font-weight: bold; margin-top: 0.75rem; }
        .distribution-detail dd { margin: 0.25rem 0 0 0; }

        /* CHS colors and layout, copied from participantSearch.php so both
           pages look the same. normal_tw.css still has the old CCDA blue. */
        :root {
            --chs-teal: #1f5968;
            --chs-teal-dark: #17444f;
            --chs-accent: #50bfd3;
            --chs-tint: #eef7f9;
            --chs-border: #d3e4e8;

            /* Names used by the participant card and totals below */
            --main-color: var(--chs-teal);
            --accent-color: var(--chs-accent);
            --standout-background: #f9fbfc;
            --shadow-and-border-color: var(--chs-border);
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
            font-size: 1.35rem;
            font-weight: 700;
        }

        .sub-text {
            color: #4b5563;
        }

        .search-card input[type="text"]:focus {
            outline: none;
            border-color: var(--chs-accent);
            box-shadow: 0 0 0 2px var(--chs-accent);
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

        .error-block {
            border-left: 4px solid #dc2626;
            background-color: #fef2f2;
            color: #991b1b;
            text-align: left;
            font-weight: 500;
        }

        .search-card .blue-div {
            background-color: var(--chs-accent);
        }

        /* Sections inside the card (detail view, distribution list) */
        .history-section {
            margin-bottom: 1.5rem;
        }

        .empty-history {
            border: 1px dashed var(--chs-border);
            border-radius: 0.5rem;
            background-color: var(--chs-tint);
            padding: 1.25rem;
            text-align: center;
            color: #374151;
        }

        /* Participant summary at the top of the page */
        .participant-card {
            background-color: var(--standout-background);
            border: 1px solid var(--shadow-and-border-color);
            border-top: 6px solid var(--main-color);
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
        }
        .participant-card-label {
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #555;
        }
        .participant-card-name {
            font-size: 2.25rem;
            font-weight: bold;
            line-height: 1.2;
            color: var(--main-color);
            margin: 0.25rem 0 1.25rem 0;
        }
        .participant-card-details {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.75rem;
        }
        .participant-detail {
            flex: 0 1 9.5rem;
            background-color: white;
            border: 1px solid var(--shadow-and-border-color);
            border-radius: 6px;
            padding: 0.6rem 0.75rem;
        }
        .participant-detail-label {
            display: block;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #555;
        }
        .participant-detail-value {
            display: block;
            margin-top: 0.2rem;
            font-size: 1.15rem;
            font-weight: bold;
            color: var(--main-color);
        }
        .status-badge {
            display: inline-block;
            padding: 0.1rem 0.65rem;
            border-radius: 999px;
            font-size: 1rem;
            color: white;
            background-color: #6b6b6b;
        }
        .status-active   { background-color: #5D7E61; }
        .status-inactive { background-color: #6b6b6b; }
        .status-expired  { background-color: #B07D2B; }
        .status-dnd      { background-color: #A66654; }
        .status-deleted  { background-color: #2f2f2f; }

        /* Distribution table, matching the participant search results table */
        .history-results {
            --chs-teal: #1f5968;
            --chs-teal-dark: #17444f;
            --chs-accent: #50bfd3;
            --chs-tint: #eef7f9;
            --chs-border: #d3e4e8;
            border: 1px solid var(--chs-border);
            border-radius: 0.5rem;
        }
        .history-results table {
            width: 100%;
            border: none;
            border-collapse: collapse;
            text-align: left;
            font-size: 1rem;
            color: #374151;
        }
        .history-results th {
            background-color: var(--chs-teal);
            color: white;
            padding: 0.75rem 1rem;
            white-space: nowrap;
        }
        .history-results td {
            padding: 0.75rem 1rem;
            vertical-align: top;
        }
        .history-results .num { text-align: right; }
        .history-results .nowrap { white-space: nowrap; }
        .history-results .muted { color: #9ca3af; }
        .history-results tbody tr {
            border-bottom: 1px solid var(--chs-border);
        }
        .history-results tbody tr:last-child {
            border-bottom: none;
        }
        .history-results tbody tr:hover,
        .history-results tbody tr.selected-row {
            background-color: var(--chs-tint);
        }
        .history-results tbody tr.selected-row td:first-child {
            box-shadow: inset 4px 0 0 var(--chs-accent);
        }
        .history-results a.history-view-link {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            background-color: var(--chs-teal);
            color: white;
            font-weight: 600;
            white-space: nowrap;
            text-decoration: none;
            transition: background-color .3s;
        }
        .history-results a.history-view-link:hover {
            background-color: var(--chs-teal-dark);
        }
        .history-results a.history-view-link:focus-visible {
            outline: 2px solid var(--chs-accent);
            outline-offset: 2px;
        }

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
  <div class="search-card w-[80%] p-8">
<?php if ($participant === null): ?>
    <?php // Reached without a participant: ask whose history to show ?>
    <form class="space-y-6" method="get" action="participantSearch.php">
        <div class="text-center mb-8">
            <h2>Find a Participant</h2>
            <p class="sub-text">Search for the participant, then select Distribution History beside them in the results.</p>
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
    <div>
        <?php
            $status = (string) $participant->get_status();
            $statusClass = in_array($status, array('Active', 'Inactive', 'Expired', 'DND', 'Deleted'), true)
                ? 'status-' . strtolower($status) : 'status-other';
            $phone = (string) $participant->get_phone();
        ?>
        <div class="participant-card mb-8">
            <div class="participant-card-label">Distribution history for</div>
            <h2 class="participant-card-name"><?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?></h2>
            <div class="participant-card-details">
                <div class="participant-detail">
                    <span class="participant-detail-label">Participant ID</span>
                    <span class="participant-detail-value"><?php echo hsc($participant->get_display_id()) ?></span>
                </div>
                <div class="participant-detail">
                    <span class="participant-detail-label">Status</span>
                    <span class="participant-detail-value"><span class="status-badge <?php echo $statusClass ?>"><?php echo hsc($status) ?></span></span>
                </div>
                <div class="participant-detail">
                    <span class="participant-detail-label">Phone</span>
                    <span class="participant-detail-value"><?php echo $phone !== '' ? hsc(formatPhoneNumber($phone)) : '&mdash;' ?></span>
                </div>
                <div class="participant-detail">
                    <span class="participant-detail-label">Location</span>
                    <span class="participant-detail-value"><?php echo (string) $participant->get_location() !== '' ? hsc($participant->get_location()) : '&mdash;' ?></span>
                </div>
                <div class="participant-detail">
                    <span class="participant-detail-label">Active Pets</span>
                    <span class="participant-detail-value"><?php echo (int) count_active_pets($participant->get_id()) ?></span>
                </div>
            </div>
        </div>

        <?php if ($selectedMissing): ?>
        <div class="error-block">That distribution could not be found for this participant.</div>
        <?php endif ?>

        <?php if ($selected !== null): ?>
        <section class="history-section" id="distribution-detail">
            <h3>Distribution on <?php echo hsc(history_date($selected->get_distribution_date())) ?></h3>
            <p class="mb-2"><strong>Participant:</strong> <?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?> (ID <?php echo hsc($participant->get_display_id()) ?>)</p>
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
                <a href="distributionHistory.php?id=<?php echo (int) $participant->get_id() ?>" class="return-button">Back to All Distributions</a>
            </div>
        </section>
        <?php endif ?>

        <section class="history-section">
            <h3>Prior Distributions</h3>
            <?php if (count($distributions) > 0): ?>
            <p class="mb-2">Food distributions recorded for <?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?>, newest first. Select View for more detail.</p>
            <?php endif ?>
            <div class="blue-div"></div>

            <?php if (count($distributions) == 0): ?>
            <div class="empty-history">
                <p class="mb-2"><strong>No distribution history yet</strong></p>
                <p class="mb-2"><?php echo hsc($participant->get_first_name() . ' ' . $participant->get_last_name()) ?> has not received any pet food from the pantry so far.</p>
                <p class="mb-2">Each time food is given to this participant, record it with <strong>Record Distribution</strong>. It will then appear here with the date, location, number of pets, and the amount of dog and cat food given.</p>
            </div>
            <div class="text-center mt-2">
                <a href="distributionRecord.php?id=<?php echo (int) $participant->get_id() ?>" class="blue-button">Record a Distribution</a>
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
            <div class="history-results overflow-x-auto">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Location</th>
                            <th class="num">Pets</th>
                            <th class="num">Dog Food (lbs)</th>
                            <th class="num">Cat Food (lbs)</th>
                            <th>Processed By</th>
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
                            $processedBy = (string) $distribution->get_processed_by_name();
                            $isSelected = $selected !== null && (int) $selected->get_id() === (int) $distribution->get_id();
                        ?>
                        <tr<?php if ($isSelected) echo ' class="selected-row"' ?>>
                            <td class="nowrap"><strong><?php echo hsc(history_date($distribution->get_distribution_date())) ?></strong></td>
                            <td><?php echo hsc($distribution->get_location()) ?></td>
                            <td class="num"><?php echo (int) $distribution->get_pets_served() ?></td>
                            <td class="num"><?php echo hsc(history_lbs($distribution->get_dog_food_lbs())) ?></td>
                            <td class="num"><?php echo hsc(history_lbs($distribution->get_cat_food_lbs())) ?></td>
                            <td><?php echo $processedBy !== '' ? hsc($processedBy) : '<span class="muted">&mdash;</span>' ?></td>
                            <td><?php echo $shortNotes !== '' ? hsc($shortNotes) : '<span class="muted">&mdash;</span>' ?></td>
                            <td><a href="distributionHistory.php?id=<?php echo (int) $participant->get_id() ?>&amp;distribution=<?php echo (int) $distribution->get_id() ?>#distribution-detail" class="history-view-link"><?php echo $isSelected ? 'Viewing' : 'View' ?></a></td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endif ?>
        </section>
    </div>

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
