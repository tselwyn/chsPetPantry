<?php
    // UC-17 Record Food Received (FR-072): an administrator records pet food
    // CHS buys or is given, so the cost per pound report can be calculated.
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
    // Admin only, checked here and not just by hiding the dashboard card
    if ($accessLevel < 2) {
        header('Location: index.php');
        die();
    }

    require_once('include/input-validation.php');
    require_once('include/output.php');
    require_once('database/dbFoodReceived.php');

    // Largest values the dbfoodreceived columns are expected to hold
    $maxLbs = 99999.99;
    $maxCost = 99999999.99;
    $maxNotes = 1000;
    // How many recent records are listed under the form
    $recentLimit = 25;

    $foodTypes = array('Dog' => 'Dog food', 'Cat' => 'Cat food');

    $errors = array();
    $args = array(
        'received_date' => date('Y-m-d'),
        'food_type' => '',
        'pounds' => '',
        'cost' => '',
        'notes' => ''
    );

    // Remove a record entered by mistake
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_id'])) {
        $deleteId = (is_string($_POST['delete_id']) && ctype_digit($_POST['delete_id'])) ? (int) $_POST['delete_id'] : 0;
        $deleted = $deleteId > 0 && delete_food_received($deleteId);
        header('Location: foodReceived.php?' . ($deleted ? 'deleted' : 'notDeleted') . '#recent');
        die();
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Trimmed only: add_food_received uses a prepared statement and the
        // page escapes values with hsc() when it shows them
        foreach (array_keys($args) as $field) {
            $args[$field] = (isset($_POST[$field]) && is_string($_POST[$field])) ? trim($_POST[$field]) : '';
        }
        // Allow "$1,250.00" as well as "1250"
        $args['cost'] = str_replace(array('$', ','), '', $args['cost']);
        $args['pounds'] = str_replace(',', '', $args['pounds']);

        $required = array(
            'received_date' => 'Date received',
            'food_type' => 'Food type',
            'pounds' => 'Pounds',
            'cost' => 'Cost'
        );
        foreach ($required as $field => $label) {
            if ($args[$field] === '') {
                $errors[] = $label . ' is required.';
            }
        }

        if ($args['received_date'] !== '') {
            if (!validateDate($args['received_date'])) {
                $errors[] = 'Enter a valid date received.';
            } else if ($args['received_date'] > date('Y-m-d')) {
                $errors[] = 'Date received cannot be in the future.';
            }
        }

        if ($args['food_type'] !== '' && !array_key_exists($args['food_type'], $foodTypes)) {
            $errors[] = 'Choose dog food or cat food.';
        }

        if ($args['pounds'] !== '' && (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $args['pounds'])
                || (float) $args['pounds'] <= 0 || (float) $args['pounds'] > $maxLbs)) {
            $errors[] = 'Pounds must be a number greater than 0 and at most ' . number_format($maxLbs, 2) . ', with at most 2 decimal places.';
        }

        if ($args['cost'] !== '' && (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $args['cost']) || (float) $args['cost'] > $maxCost)) {
            $errors[] = 'Cost must be a dollar amount of 0 or more, with at most 2 decimal places (enter 0 for donated food).';
        }

        if (mb_strlen($args['notes']) > $maxNotes) {
            $errors[] = 'Notes must be ' . $maxNotes . ' characters or fewer.';
        }

        if (count($errors) == 0) {
            $id = add_food_received(
                $args['received_date'],
                $args['food_type'],
                (float) $args['pounds'],
                (float) $args['cost'],
                $args['notes'] === '' ? null : $args['notes'],
                $userID
            );
            if ($id !== false) {
                // Redirect so refreshing the page doesn't record the food again
                header('Location: foodReceived.php?saved#recent');
                die();
            }
            $errors[] = 'The food received could not be saved. Please try again.';
        }
    }

    $recent = get_food_received('', '', $recentLimit);
?>

<!DOCTYPE html>
<html>
<head>
    <title>CHS Pet Pantry | Record Food Received</title>
    <link href="css/base.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<!-- BANDAID END, REMOVE ONCE SOME GENIUS FIXES -->
    <style>
        /* A fieldset grows to fit its widest content by default; this lets
           the table scroll inside it on a phone instead of widening the page */
        #recent fieldset { min-width: 0; }
        /* Positioned so the hidden "Actions" header stays inside the scroll area */
        .table-scroll { overflow-x: auto; position: relative; }
        .food-table { width: 100%; border-collapse: collapse; text-align: left; }
        .food-table th { background-color: #2E5E6E; color: #fff; padding: .5rem; }
        .food-table td { border-bottom: 1px solid #ccc; padding: .5rem; vertical-align: top; }
        .food-table td.number { text-align: right; white-space: nowrap; }
        .food-table form { margin: 0; }
        .delete-food-button { background-color: #b91c1c; color: #fff; border: none; border-radius: .375rem; padding: .35rem .75rem; cursor: pointer; }
        .delete-food-button:hover, .delete-food-button:focus { background-color: #7f1d1d; }
        /* Compact, scoped layout: does not change save or delete logic. */
        .main-content-box { max-width: 70rem; }
        .food-received-page { max-width: 54rem; margin: 0 auto; padding: 0 1rem 2rem; }
        .food-received-page .signup-form { width: 100%; max-width: none; margin: 0 auto 1rem; box-sizing: border-box; }
        .food-received-page .section-box { min-width: 0; }
        .food-received-page .food-intro { margin: 0 0 1rem; font-size: .95rem; }
        .food-received-page .food-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .9rem 1rem; }
        .food-received-page .food-field { min-width: 0; }
        .food-received-page .food-field label { display: block; margin: 0 0 .3rem; font-weight: 600; }
        .food-received-page .food-field input, .food-received-page .food-field select,
        .food-received-page .food-field textarea { box-sizing: border-box; width: 100%; margin: 0; }
        .food-received-page .food-field-full { grid-column: 1 / -1; }
        .food-received-page .food-help { font-size: .82rem; margin: .35rem 0 0; color: #526777; }
        .food-received-page .food-submit { display: inline-block; width: auto; min-width: 0; margin: .85rem 0 0; padding: .55rem 1.05rem; border-radius: 4px; border: 0; background: #276b7b; color: #fff; font-weight: 600; cursor: pointer; }
        .food-received-page .food-submit:hover { background: #205766; }
        .food-received-page .food-nav { display: flex; flex-wrap: wrap; justify-content: center; gap: .6rem; margin: 1rem 0; }
        .food-received-page .food-nav .button { display: inline-block; width: auto; margin: 0; padding: .45rem .8rem; font-size: .88rem; }
        .food-received-page .food-table { font-size: .88rem; }
        .food-received-page .food-table tbody tr:nth-child(even) { background: #f5f9fa; }
        .food-received-page .food-table th { white-space: nowrap; }
        .food-received-page .food-table td { overflow-wrap: anywhere; }
        .food-received-page .hero-header h1, .hero-header .center-header h1 { color: #fff; }
        @media (max-width: 650px) { .food-received-page .food-grid { grid-template-columns: 1fr; } }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    </style>
</head>
<body class="relative">

<header class="hero-header">
    <div class="center-header">
        <h1>Record Food Received</h1>
    </div>
</header>

<main>
  <div class="main-content-box">
  <div class="food-received-page">
    <form class="signup-form" method="post">
        <p class="food-intro">Record food CHS purchased or received as a donation for the Cost per Pound report. <em>*</em> indicates a required field.</p>

        <?php if (count($errors) > 0): ?>
        <div class="error-toast" role="alert">
            <p>The food received was not saved:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?php echo hsc($error) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>

        <fieldset class="section-box mb-4">
            <h3 class="mt-2">Food Received</h3>
            <div class="blue-div"></div>
            <div class="food-grid">
                <div class="food-field">
                    <label for="received_date"><em>* </em>Date Received</label>
                    <input type="date" id="received_date" name="received_date" required max="<?php echo date('Y-m-d') ?>" value="<?php echo hsc($args['received_date']) ?>">
                </div>
                <div class="food-field">
                    <label for="food_type"><em>* </em>Food Type</label>
                    <select id="food_type" name="food_type" required>
                        <option value="">Select dog or cat food</option>
                        <?php foreach ($foodTypes as $value => $label): ?>
                        <option value="<?php echo hsc($value) ?>"<?php if ($value === $args['food_type']) echo ' selected' ?>><?php echo hsc($label) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="food-field">
                    <label for="pounds"><em>* </em>Pounds</label>
                    <input type="number" id="pounds" name="pounds" required min="0.01" max="<?php echo $maxLbs ?>" step="0.01" inputmode="decimal" placeholder="Ex. 500" value="<?php echo hsc($args['pounds']) ?>">
                </div>
                <div class="food-field">
                    <label for="cost"><em>* </em>Total Cost ($)</label>
                    <input type="number" id="cost" name="cost" required min="0" max="<?php echo $maxCost ?>" step="0.01" inputmode="decimal" placeholder="Ex. 412.50 or 0 for donated food" aria-describedby="cost_help" value="<?php echo hsc($args['cost']) ?>">
                    <p id="cost_help" class="food-help">Total paid, not price per pound. Enter 0 for donations.</p>
                </div>
                <div class="food-field food-field-full">
                    <label for="notes">Notes (optional)</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="<?php echo $maxNotes ?>" placeholder="Supplier, invoice number, or donor"><?php echo hsc($args['notes']) ?></textarea>
                </div>
            </div>
        </fieldset>

        <input class="food-submit" type="submit" value="Save Food Received">
    </form>

    <div class="signup-form" id="recent">
        <fieldset class="section-box mb-4">
            <h3>Recently Recorded</h3>
            <p class="mb-2">The <?php echo $recentLimit ?> most recent food received records, newest first.</p>
            <div class="blue-div"></div>

            <?php if (isset($_GET['saved'])): ?>
            <div class="happy-toast" role="status">The food received has been saved.</div>
            <?php elseif (isset($_GET['deleted'])): ?>
            <div class="happy-toast" role="status">The food received record has been deleted.</div>
            <?php elseif (isset($_GET['notDeleted'])): ?>
            <div class="error-toast" role="alert">The food received record could not be deleted. Please try again.</div>
            <?php endif ?>

            <?php if (count($recent) == 0): ?>
            <p>No food received has been recorded yet.</p>
            <?php else: ?>
            <div class="table-scroll">
                <table class="food-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Food Type</th>
                            <th scope="col">Pounds</th>
                            <th scope="col">Cost</th>
                            <th scope="col">Cost per Pound</th>
                            <th scope="col">Notes</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $row): ?>
                        <tr>
                            <td><?php echo hsc(date('M j, Y', strtotime($row['received_date']))) ?></td>
                            <td><?php echo hsc($foodTypes[$row['food_type']] ?? $row['food_type']) ?></td>
                            <td class="number"><?php echo hsc(number_format((float) $row['pounds'], 2)) ?></td>
                            <td class="number">$<?php echo hsc(number_format((float) $row['cost'], 2)) ?></td>
                            <td class="number">$<?php echo hsc(number_format((float) $row['cost'] / (float) $row['pounds'], 2)) ?></td>
                            <td><?php echo nl2br(hsc((string) $row['notes'])) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Delete this food received record? This cannot be undone.');">
                                    <input type="hidden" name="delete_id" value="<?php echo (int) $row['id'] ?>">
                                    <button type="submit" class="delete-food-button">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endif ?>
        </fieldset>
    </div>

    <div class="food-nav">
        <a href="reports.php?report_type=cost_per_pound" class="button">Cost per Pound Report</a>
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>
  </div>
  </div>
</main>

</body>
</html>
