<?php
    // UC-08 Record SNV / Counseling Information (FR-027, FR-029, FR-043)
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
    // Volunteers and admins can both record SNV / counseling information
    if ($accessLevel < 1) {
        header('Location: index.php');
        die();
    }

    require_once('include/input-validation.php');
    require_once('include/output.php');
    require_once('database/dbParticipants.php');
    require_once('database/dbPets.php');

    // Spay/neuter and rabies vaccination choices (FR-029)
    $snvStatuses = array('Yes', 'No', 'Unknown');

    $id = (isset($_GET['id']) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
    $pet = $id > 0 ? get_pet_by_id($id) : null;
    // A deleted pet is kept only for history, so it is treated as not found
    if ($pet !== null && $pet->get_status() === 'Deleted') {
        $pet = null;
    }
    $owner = $pet !== null ? retrieve_participant($pet->get_participant_id()) : null;

    // participantEdit.php is admin-only, so volunteers come back to this page after saving
    $canViewParticipant = $accessLevel >= 2;

    $errors = array();
    $args = array();
    if ($pet !== null) {
        // What the form shows until something is submitted
        $args = array(
            'spay_neuter_status' => $pet->get_spay_neuter_status(),
            'rabies_status' => $pet->get_rabies_status(),
            'counseling_provided' => ''
        );
    }

    if ($pet !== null && $_SERVER["REQUEST_METHOD"] == "POST") {
        foreach (array_keys($args) as $field) {
            $args[$field] = (isset($_POST[$field]) && is_string($_POST[$field])) ? trim($_POST[$field]) : '';
        }

        if (!valueConstrainedTo($args['spay_neuter_status'], $snvStatuses)) {
            $errors[] = 'Choose Yes, No, or Unknown for Spay/Neuter Status.';
        }

        if (!valueConstrainedTo($args['rabies_status'], $snvStatuses)) {
            $errors[] = 'Choose Yes, No, or Unknown for Rabies Vaccination Status.';
        }

        if ($args['counseling_provided'] !== '' && $args['counseling_provided'] !== 'Yes') {
            $errors[] = 'Counseling provided must be checked or left unchecked.';
        }

        if (count($errors) == 0) {
            // Counseling is only recorded when the pet is not both spayed/neutered and
            // vaccinated; a Yes / Yes pet is saved without a counseling record
            $needsCounseling = $args['spay_neuter_status'] !== 'Yes' || $args['rabies_status'] !== 'Yes';
            $counseling = ($needsCounseling && $args['counseling_provided'] === 'Yes') ? 'Yes' : 'No';

            if (update_pet_snv($pet->get_id(), $args['spay_neuter_status'], $args['rabies_status'], $counseling, $userID)) {
                // Redirect so refreshing the page doesn't submit the form again
                if ($canViewParticipant) {
                    header('Location: participantEdit.php?id=' . (int) $pet->get_participant_id() . '&snv=1');
                } else {
                    header('Location: petSNV.php?id=' . (int) $pet->get_id() . '&snv=1');
                }
                die();
            }
            $errors[] = 'The pet could not be saved. Please try again.';
        }
    }

    $needsCounseling = $pet !== null && ($args['spay_neuter_status'] !== 'Yes' || $args['rabies_status'] !== 'Yes');
?>

<!DOCTYPE html>
<html>
<head>
    <title>CHS Pet Pantry | SNV / Counseling</title>
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
        <h1>SNV / Counseling</h1>
    </div>
</header>

<main>
  <div class="main-content-box">
<?php if ($pet === null): ?>
    <div class="signup-form">
        <div class="error-toast">That pet could not be found.</div>
    </div>

    <div class="text-center">
        <?php if ($canViewParticipant): ?>
        <a href="participantSearch.php" class="button">Back to Participant Search</a>
        <?php endif ?>
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>
<?php else: ?>
    <form class="signup-form" method="post">
        <div class="text-center spacing-bottom">
            <h2 class="mb-8"><?php echo hsc($pet->get_name()) ?></h2>
            <div class="info-box">
                <p class="sub-text">Review and update this pet's spay/neuter and rabies vaccination status. Choose Unknown rather than guessing.</p>
            </div>
        </div>

        <?php if (isset($_GET['snv']) && count($errors) == 0): ?>
        <div class="happy-toast">Spay/neuter and vaccination information updated.</div>
        <?php endif ?>

        <?php if (count($errors) > 0): ?>
        <div class="error-toast">
            <p>The pet was not saved:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?php echo hsc($error) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>

        <fieldset class="section-box mb-4">
            <h3 class="mt-2">Pet</h3>
            <p class="mb-2">The pet and its current status on file.</p>
            <div class="blue-div"></div>

            <label for="owner_name">Owner</label>
            <input type="text" id="owner_name" value="<?php echo $owner !== null ? hsc($owner->get_first_name() . ' ' . $owner->get_last_name()) : '' ?>" readonly disabled>

            <label for="pet_name">Pet Name</label>
            <input type="text" id="pet_name" value="<?php echo hsc($pet->get_name()) ?>" readonly disabled>

            <label for="species">Species</label>
            <input type="text" id="species" value="<?php echo hsc($pet->get_species()) ?>" readonly disabled>

            <label for="current_spay_neuter">Current Spay/Neuter Status</label>
            <input type="text" id="current_spay_neuter" value="<?php echo hsc($pet->get_spay_neuter_status()) ?>" readonly disabled>

            <label for="current_rabies">Current Rabies Vaccination Status</label>
            <input type="text" id="current_rabies" value="<?php echo hsc($pet->get_rabies_status()) ?>" readonly disabled>

            <label for="current_counseling">Counseling Status</label>
            <input type="text" id="current_counseling" value="<?php
                echo hsc($pet->get_counseling_status());
                if ($pet->get_counseling_status() === 'Yes' && $pet->get_counseling_date()) {
                    echo hsc(' (' . date('M j, Y', strtotime($pet->get_counseling_date())) . ', by ' . $pet->get_counseled_by() . ')');
                }
            ?>" readonly disabled>
        </fieldset>

        <fieldset class="section-box mb-4">
            <h3>Update Status</h3>
            <p class="mb-2">Change either status if the participant has new information.</p>
            <div class="blue-div"></div>

            <label for="spay_neuter_status"><em>* </em>Spay/Neuter Status</label>
            <select id="spay_neuter_status" name="spay_neuter_status" required>
                <?php foreach ($snvStatuses as $status): ?>
                <option value="<?php echo hsc($status) ?>"<?php if ($status === $args['spay_neuter_status']) echo ' selected' ?>><?php echo hsc($status) ?></option>
                <?php endforeach ?>
            </select>

            <label for="rabies_status"><em>* </em>Rabies Vaccination Status</label>
            <select id="rabies_status" name="rabies_status" required>
                <?php foreach ($snvStatuses as $status): ?>
                <option value="<?php echo hsc($status) ?>"<?php if ($status === $args['rabies_status']) echo ' selected' ?>><?php echo hsc($status) ?></option>
                <?php endforeach ?>
            </select>
        </fieldset>

        <fieldset class="section-box mb-4" id="counseling-section"<?php if (!$needsCounseling) echo ' style="display: none;"' ?>>
            <h3>Counseling</h3>
            <p class="mb-2">This pet is not both spayed/neutered and vaccinated. Record whether the participant was told about free services.</p>
            <div class="blue-div"></div>

            <?php if ($pet->get_counseling_status() === 'Yes'): ?>
            <p class="mb-2">Counseling is already on file. Check the box again only if it was provided again today.</p>
            <?php endif ?>

            <label for="counseling_provided">
                <input type="checkbox" id="counseling_provided" name="counseling_provided" value="Yes"<?php if ($args['counseling_provided'] === 'Yes') echo ' checked' ?>>
                Counseling provided (free spay/neuter and vaccination services)
            </label>
        </fieldset>

        <div class="happy-toast" id="no-counseling"<?php if ($needsCounseling) echo ' style="display: none;"' ?>>No counseling needed: pet is spayed/neutered and vaccinated</div>

        <input type="submit" value="Save">
    </form>

    <div class="text-center">
        <?php if ($canViewParticipant): ?>
        <a href="participantEdit.php?id=<?php echo (int) $pet->get_participant_id() ?>" class="button">Back to participant</a>
        <?php endif ?>
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>

    <script>
        // Counseling is only offered while either status is No or Unknown;
        // the server applies the same rule when the form is saved
        (function () {
            var spayNeuter = document.getElementById('spay_neuter_status');
            var rabies = document.getElementById('rabies_status');
            var counseling = document.getElementById('counseling-section');
            var checkbox = document.getElementById('counseling_provided');
            var noCounseling = document.getElementById('no-counseling');

            function updateCounseling() {
                var needed = spayNeuter.value !== 'Yes' || rabies.value !== 'Yes';
                counseling.style.display = needed ? '' : 'none';
                noCounseling.style.display = needed ? 'none' : '';
                if (!needed) {
                    checkbox.checked = false;
                }
            }

            spayNeuter.addEventListener('change', updateCounseling);
            rabies.addEventListener('change', updateCounseling);
        })();
    </script>
<?php endif ?>
  </div>
</main>

</body>
</html>
