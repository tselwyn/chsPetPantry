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

    require_once('include/output.php');
    require_once('database/dbParticipants.php');

    // Most results shown at once; type more to narrow the search
    $resultLimit = 100;
    $term = isset($_GET['q']) ? trim($_GET['q']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>CCDA | Participant Search</title>
    <link href="css/normal_tw.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<style>
        body, main {
        background-color: #1F1F21;
        }

        .text-blue-700,
        .text-blue-700:visited {
        color: black !important;
        }

        .info-section .info-text {
         color: #C9AB81 !important;
        }

        .blue-div {
        background-color: #C9AB81 !important;
        }

        .main-content-box label {
        color: #000000 !important;
        }

        .sub-text {
        color: black !important;
        }

        .main-content-box table,
        .main-content-box table thead,
        .main-content-box table tbody,
        .main-content-box table tr,
        .main-content-box table th,
        .main-content-box table td {
            background-color: #1F1F21 !important;
            color: #C9AB81 !important;
            border: 1px solid #C9AB81 !important;
        }

        .main-content-box table a.text-blue-700,
        .main-content-box table a.text-blue-700:visited {
            color: #C9AB81 !important;
        }

        .main-content-box table thead.bg-blue-400 th {
            background-color: #1F1F21 !important;
        }

        .main-content-box table a.participant-edit-link {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 6px;
            background-color: #C9AB81;
            color: #1F1F21 !important;
            font-weight: bold;
            white-space: nowrap;
            text-decoration: none;
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
    <div class="main-content-box w-[80%] p-8">

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
                        echo '
                        <div class="overflow-x-auto">
                            <table>
                                <thead class="bg-blue-400">
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
                            $alert = $participant->get_alert() ? ' <strong>(Alert)</strong>' : '';
                            $registered = date('M j, Y', strtotime($participant->get_registration_date()));
                            echo '
                                    <tr>
                                        <td><a href="participantEdit.php?id=' . (int) $participant->get_id() . '" class="text-blue-700 underline">' . (int) $participant->get_id() . '</a></td>
                                        <td>' . hsc($participant->get_first_name()) . $alert . '</td>
                                        <td>' . hsc($participant->get_last_name()) . '</td>
                                        <td>' . hsc($participant->get_full_address()) . '</td>
                                        <td><a href="tel:' . hsc($participant->get_phone()) . '" class="text-blue-700 underline">' . formatPhoneNumber($participant->get_phone()) . '</a></td>
                                        <td>' . hsc($participant->get_location()) . '</td>
                                        <td>' . hsc($participant->get_status()) . '</td>
                                        <td>' . $participant->get_pet_count() . '</td>
                                        <td>' . hsc($registered) . '</td>
                                        <td><a href="participantEdit.php?id=' . (int) $participant->get_id() . '" class="participant-edit-link">View / Update</a></td>
                                    </tr>';
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

    <div class="info-section">
        <div class="blue-div"></div>
        <p class="info-text">
            Use this tool to look up a Pet Pantry participant by ID, first or last name, street address, city, zip code, or phone number.
        </p>
    </div>
</main>

</body>
</html>
