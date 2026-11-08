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
    <form class="signup-form" method="post">
        <div class="text-center spacing-bottom">
            <div class="info-box">
                <p class="sub-text">Record pet food CHS bought or was given. These records are used for the Cost per Pound report.</p>
                <p>An asterisk ( <em>*</em> ) indicates a required field.</p>
            </div>
        </div>

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

            <label for="received_date"><em>* </em>Date Received</label>
            <input type="date" id="received_date" name="received_date" required max="<?php echo date('Y-m-d') ?>" value="<?php echo hsc($args['received_date']) ?>">

            <label for="food_type"><em>* </em>Food Type</label>
            <select id="food_type" name="food_type" required>
                <option value="">Select dog or cat food</option>
                <?php foreach ($foodTypes as $value => $label): ?>
                <option value="<?php echo hsc($value) ?>"<?php if ($value === $args['food_type']) echo ' selected' ?>><?php echo hsc($label) ?></option>
                <?php endforeach ?>
            </select>

            <label for="pounds"><em>* </em>Pounds</label>
            <input type="number" id="pounds" name="pounds" required min="0.01" max="<?php echo $maxLbs ?>" step="0.01" inputmode="decimal" placeholder="Ex. 500" value="<?php echo hsc($args['pounds']) ?>">

            <label for="cost"><em>* </em>Total Cost ($)</label>
            <input type="number" id="cost" name="cost" required min="0" max="<?php echo $maxCost ?>" step="0.01" inputmode="decimal" placeholder="Ex. 412.50, or 0 for donated food" aria-describedby="cost_help" value="<?php echo hsc($args['cost']) ?>">
            <p id="cost_help" class="mb-2">The total paid for this food, not the price per pound. Enter 0 for donated food.</p>

            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="3" maxlength="<?php echo $maxNotes ?>" placeholder="Ex. supplier, invoice number, or donor"><?php echo hsc($args['notes']) ?></textarea>
        </fieldset>

        <input type="submit" value="Save Food Received">
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

    <div class="text-center">
        <a href="reports.php?report_type=cost_per_pound" class="button">Cost per Pound Report</a>
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>
  </div>
</main>

</body>
</html>
