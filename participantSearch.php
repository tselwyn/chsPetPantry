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

    require_once('include/output.php');
    require_once('database/dbParticipants.php');
    require_once('database/dbPets.php');

    // Most results shown at once; type more to narrow the search
    $resultLimit = 100;
    $term = isset($_GET['q']) ? trim($_GET['q']) : null;

    // The hidden row under a participant's result listing their pets
    function participant_search_pets_row($participantId, $pets) {
        echo '
                                    <tr class="pets-row" id="pets-' . $participantId . '" hidden>
                                        <td colspan="10">';
        if (count($pets) == 0) {
            echo 'This participant has no pets registered.';
        } else {
            echo '
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th>Name</th>
                                                        <th>Species</th>
                                                        <th>Breed</th>
                                                        <th>Age (Years)</th>
                                                        <th>Sex</th>
                                                        <th>Spayed/Neutered</th>
                                                        <th>Rabies Current</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>';
            foreach ($pets as $pet) {
                $age = $pet->get_age_years();
                echo '
                                                    <tr>
                                                        <td>' . hsc($pet->get_name()) . '</td>
                                                        <td>' . hsc($pet->get_species()) . '</td>
                                                        <td>' . hsc($pet->get_breed()) . '</td>
                                                        <td>' . ($age === null ? 'Unknown' : hsc($age)) . '</td>
                                                        <td>' . hsc($pet->get_sex()) . '</td>
                                                        <td>' . hsc($pet->get_spay_neuter_status()) . '</td>
                                                        <td>' . hsc($pet->get_rabies_status()) . '</td>
                                                        <td>' . hsc($pet->get_status()) . '</td>
                                                    </tr>';
            }
            echo '
                                                </tbody>
                                            </table>';
        }
        echo '</td>
                                    </tr>';
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>CHS Pet Pantry | Participant Search</title>
    <link href="css/normal_tw.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<style>
        /* CHS colors, matching the dashboard and login page. normal_tw.css
           still has the old CCDA blue, so this page overrides it. */
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

        .sub-text {
            color: #4b5563;
        }

        .search-card input[type="text"]:focus {
            outline: none;
            border-color: var(--chs-accent);
            box-shadow: 0 0 0 2px var(--chs-accent);
        }

        .blue-button {
            background-color: var(--chs-teal);
            font-weight: 600;
            transition: background-color .3s;
        }

        .blue-button:hover,
        .blue-button:focus-visible {
            background-color: var(--chs-teal-dark);
        }

        .blue-button:focus-visible,
        .return-button:focus-visible,
        .participant-results a:focus-visible {
            outline: 2px solid var(--chs-accent);
            outline-offset: 2px;
        }

        .return-button {
            display: inline-block;
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

        .participant-results {
            border: 1px solid var(--chs-border);
            border-radius: 0.5rem;
        }

        .participant-results table {
            border: none;
            font-size: 1rem;
            color: #374151;
        }

        .participant-results th {
            background-color: var(--chs-teal);
            color: white;
            padding: 0.75rem 1rem;
            white-space: nowrap;
        }

        .participant-results td {
            padding: 0.75rem 1rem;
        }

        .participant-results tbody tr {
            border-bottom: 1px solid var(--chs-border);
        }

        .participant-results tbody tr:last-child {
            border-bottom: none;
        }

        .participant-results tbody tr:hover {
            background-color: var(--chs-tint);
        }

        .participant-results a.participant-link {
            color: var(--chs-teal);
            font-weight: 600;
            text-decoration: underline;
            white-space: nowrap;
        }

        .participant-results .alert-flag {
            display: inline-block;
            margin-left: 0.25rem;
            padding: 0 0.5rem;
            border-radius: 9999px;
            background-color: #fef2f2;
            color: #991b1b;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .participant-results a.participant-edit-link {
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

        .participant-results a.participant-edit-link:hover {
            background-color: var(--chs-teal-dark);
        }

        .info-section .blue-div {
            background-color: var(--chs-accent);
        }

        /* Pet count and View Pets open the pets row under a participant */
        .participant-results button.pet-count-toggle {
            color: var(--chs-teal);
            font-weight: 600;
            text-decoration: underline;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
        }

        .participant-results button.view-pets-button {
            display: inline-block;
            margin-top: 0.25rem;
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            border: 2px solid var(--chs-teal);
            background-color: white;
            color: var(--chs-teal);
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
        }

        .participant-results button.view-pets-button:hover,
        .participant-results button.view-pets-button[aria-expanded="true"] {
            background-color: var(--chs-tint);
        }

        .participant-results button:focus-visible {
            outline: 2px solid var(--chs-accent);
            outline-offset: 2px;
        }

        .participant-results tbody tr.pets-row,
        .participant-results tbody tr.pets-row:hover {
            background-color: var(--chs-tint);
        }

        .participant-results .pets-row table {
            width: 100%;
            background-color: white;
            border: 1px solid var(--chs-border);
            font-size: 0.9rem;
        }

        .participant-results .pets-row th {
            background-color: var(--chs-tint);
            color: var(--chs-teal);
            padding: 0.5rem 0.75rem;
        }

        .participant-results .pets-row td {
            padding: 0.5rem 0.75rem;
        }
</style>
<!-- BANDAID END, REMOVE ONCE SOME GENIUS FIXES -->
</head>
<body>

<header class="hero-header">
    <div class="center-header">
        <h1>Participant Search</h1>
    </div>
</header>

<main>
    <div class="search-card w-[80%] p-8">

        <div class="text-center mb-8">
            <h2>Find a Participant</h2>
            <p class="sub-text">Search Pet Pantry participants by ID, name, address, or phone number. Select View / Update beside a participant to see or change their information.</p>
        </div>

        <form id="participant-search" class="space-y-6" method="get">

        <?php
            if ($term !== null) {
                if ($term === '') {
                    echo '<div class="error-block">Enter an ID, name, address, or phone number to search.</div>';
                } else if (mb_strlen($term) > PARTICIPANT_SEARCH_MAX_LENGTH) {
                    echo '<div class="error-block">Use at most ' . PARTICIPANT_SEARCH_MAX_LENGTH . ' characters.</div>';
                } else {
                    echo "<h3>Search Results</h3>";
                    $participants = find_participants($term, $resultLimit);

                    if (count($participants) > $resultLimit) {
                        $participants = array_slice($participants, 0, $resultLimit);
                        echo '<div class="error-block">Showing the first ' . $resultLimit . ' matches only. Type more of the name, address, or phone number to narrow the search.</div>';
                    }

                    if (count($participants) > 0) {
                        $petsByParticipant = get_pets_by_participants(array_map(function ($participant) {
                            return $participant->get_id();
                        }, $participants));
                        echo '
                        <div class="participant-results overflow-x-auto">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>First</th>
                                        <th>Last</th>
                                        <th>Address</th>
                                        <th>Phone</th>
                                        <th>Location</th>
                                        <th>Status</th>
                                        <th>Pets</th>
                                        <th>Registered</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>';
                        foreach ($participants as $participant) {
                            $participantId = (int) $participant->get_id();
                            $alert = $participant->get_alert() ? ' <span class="alert-flag">Alert</span>' : '';
                            $registered = date('M j, Y', strtotime($participant->get_registration_date()));
                            $toggle = 'data-pets-toggle aria-expanded="false" aria-controls="pets-' . $participantId . '"';
                            echo '
                                    <tr>
                                        <td><a href="participantEdit.php?id=' . (int) $participant->get_id() . '" class="participant-link">' . (int) $participant->get_id() . '</a></td>
                                        <td>' . hsc($participant->get_first_name()) . $alert . '</td>
                                        <td>' . hsc($participant->get_last_name()) . '</td>
                                        <td>' . hsc($participant->get_full_address()) . '</td>
                                        <td><a href="tel:' . hsc($participant->get_phone()) . '" class="participant-link">' . formatPhoneNumber($participant->get_phone()) . '</a></td>
                                        <td>' . hsc($participant->get_location()) . '</td>
                                        <td>' . hsc($participant->get_status()) . '</td>
                                        <td><button type="button" class="pet-count-toggle" ' . $toggle . ' aria-label="' . (int) $participant->get_pet_count() . ' active pets. Show pets">' . (int) $participant->get_pet_count() . '</button></td>
                                        <td>' . hsc($registered) . '</td>
                                        <td><a href="participantEdit.php?id=' . (int) $participant->get_id() . '" class="participant-edit-link">View / Update</a><br><a href="distributionRecord.php?id=' . (int) $participant->get_id() . '" class="participant-edit-link">Record Distribution</a><br><button type="button" class="view-pets-button" ' . $toggle . '>View Pets</button></td>
                                    </tr>';
                            participant_search_pets_row($participantId, $petsByParticipant[$participantId] ?? array());
                        }
                        echo '
                                </tbody>
                            </table>
                        </div>';
                    } else {
                        echo '<div class="error-block">No participants match "' . hsc($term) . '".</div>';
                    }
                    echo '<h3>Search Again</h3>';
                }
            }
        ?>

            <div>
                <label for="q">ID, Name, Address, or Phone Number</label>
                <input type="text" id="q" name="q" class="w-full" maxlength="<?php echo PARTICIPANT_SEARCH_MAX_LENGTH; ?>" value="<?php if ($term !== null) echo hsc($term); ?>" placeholder="Ex. 12, Maria Gonzalez, 412 Princess Anne St, or 540-555-0101" autofocus>
            </div>

            <div class="text-center pt-4">
                <input type="submit" value="Search" class="blue-button">
            </div>

        </form>
    </div>

    <div class="text-center mt-6">
        <a href="index.php" class="return-button">Return to Dashboard</a>
    </div>

    <script>
        // The pet count and View Pets both show or hide the pets row under a result
        document.querySelectorAll('[data-pets-toggle]').forEach(button => {
            button.addEventListener('click', () => {
                const rowId = button.getAttribute('aria-controls');
                const row = document.getElementById(rowId);
                row.hidden = !row.hidden;
                document.querySelectorAll('[aria-controls="' + rowId + '"]').forEach(toggle => {
                    toggle.setAttribute('aria-expanded', row.hidden ? 'false' : 'true');
                    if (toggle.classList.contains('view-pets-button')) {
                        toggle.textContent = row.hidden ? 'View Pets' : 'Hide Pets';
                    }
                });
            });
        });
    </script>

    <div class="info-section">
        <div class="blue-div"></div>
        <p class="info-text">
            Use this tool to look up a Pet Pantry participant by ID, first or last name, street address, city, zip code, or phone number.
        </p>
    </div>
</main>

</body>
</html>
