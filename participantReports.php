<?php

session_cache_expire(30);
session_start();

require_once('database/dbParticipants.php');

$loggedIn = false;
$accessLevel = 0;

if (isset($_SESSION['_id'])) {
    $loggedIn = true;
    $accessLevel = $_SESSION['access_level'];
}

// Only admins/managers can view reports
if ($accessLevel < 2) {
    header('Location: index.php');
    die();
}

?>

<!DOCTYPE html>
<html>

<head>
    <?php require_once('universal.inc') ?>
    <title>CHS Pet Pantry | Participant Reports</title>

    <style>
        .report-container {
            width: 80%;
            max-width: 700px;
            margin: auto;
        }

        .report-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .report-form select,
        .report-form input {
            padding: .5rem;
        }

        .report-table {
            width: 100%;
            margin-top: 1rem;
            border-collapse: collapse;
        }

        .report-table th,
        .report-table td {
            border: 1px solid #ccc;
            padding: .5rem;
            text-align: left;
        }

        .report-table th {
            background-color: var(--main-color);
            color: white;
        }

    </style>
</head>

<body>

<?php require_once('header.php'); ?>

<h1>Participant Reports</h1>

<?php

$reportType = '';

if (isset($_GET['report_type'])) {
    $reportType = $_GET['report_type'];
}

$selectedLocation = '';

if (isset($_GET['location'])) {
    $selectedLocation = $_GET['location'];
}

$startDate = '';

if (isset($_GET['start_date'])) {
    $startDate = $_GET['start_date'];
}

$endDate = '';

if (isset($_GET['end_date'])) {
    $endDate = $_GET['end_date'];
}

?>

<main class="report-container">

    <form class="report-form" method="get">

        <div>
            <label for="report_type">Report Type</label>

            <select id="report_type" name="report_type" required>

                <option value="">Select a Report</option>

                <option value="participant_list">
                    Current Participant List
                </option>

                <option value="participant_status">
                    Active / Inactive / Expired Participants
                </option>

                <option value="participants_served">
                    Participants Served by Month / Year
                </option>

                <option value="dogs_served">
                    Number of Dogs Served
                </option>

                <option value="cats_served">
                    Number of Cats Served
                </option>

                <option value="dog_food">
                    Pounds of Dog Food Distributed
                </option>

                <option value="cat_food">
                    Pounds of Cat Food Distributed
                </option>

                <option value="distribution_history">
                    Distribution History
                </option>

                <option value="spay_neuter">
                    Pets Needing Spay / Neuter
                </option>

                <option value="rabies">
                    Pets Needing Rabies Vaccination
                </option>

                <option value="flagged">
                    Flagged Participants
                </option>

            </select>
        </div>


        <div>
            <label for="start_date">Start Date</label>
            <input type="date"
                id="start_date"
                name="start_date"
                value="<?php echo htmlspecialchars($startDate); ?>">
        </div>


        <div>
            <label for="end_date">End Date</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
        </div>


        <div>
            <label for="location">Location</label>

            <select id="location" name="location">

    <option value="">All Locations</option>

    <?php

    $locations = get_participant_locations();

    foreach ($locations as $location) {

    $selected = '';

    if ($location == $selectedLocation) {
        $selected = 'selected';
    }

    echo "<option value=\"" . htmlspecialchars($location) . "\" $selected>"
         . htmlspecialchars($location)
         . "</option>";
}

    ?>

</select>
        </div>


        <div>
            <input type="submit" value="Generate Report">
        </div>

    </form>

    <?php

if ($reportType == 'participant_list') {

    $participants = get_participant_report($selectedLocation, $startDate, $endDate);

    echo "<h2>Current Participant List</h2>";

    if (count($participants) == 0) {

        echo "<p>No participants found.</p>";

    } else {

        echo "<table class='report-table'>";

        echo "<tr>
                <th>ID</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Location</th>
                <th>Registration Date</th>
                <th>Status</th>
              </tr>";

        foreach ($participants as $participant) {

            echo "<tr>";

            echo "<td>" . htmlspecialchars($participant['id']) . "</td>";

            echo "<td>" .
                 htmlspecialchars($participant['first_name']) . " " .
                 htmlspecialchars($participant['last_name']) .
                 "</td>";

            echo "<td>" . htmlspecialchars($participant['phone']) . "</td>";

            echo "<td>" . htmlspecialchars($participant['location']) . "</td>";

            echo "<td>" . htmlspecialchars($participant['registration_date']) . "</td>";

            echo "<td>" . htmlspecialchars($participant['status']) . "</td>";

            echo "</tr>";
        }

        echo "</table>";
    }
}

if ($reportType == 'participant_status') {

    $participants = get_participant_status_report(
        $selectedLocation,
        $startDate,
        $endDate
    );

    echo "<h2>Active / Inactive / Expired Participants</h2>";

    if (count($participants) == 0) {

        echo "<p>No participants found.</p>";

    } else {

        echo "<table class='report-table'>";

        echo "<tr>
                <th>ID</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Location</th>
                <th>Registration Date</th>
                <th>Status</th>
              </tr>";

        foreach ($participants as $participant) {

            echo "<tr>";

            echo "<td>" . htmlspecialchars($participant['id']) . "</td>";

            echo "<td>" .
                 htmlspecialchars($participant['first_name']) . " " .
                 htmlspecialchars($participant['last_name']) .
                 "</td>";

            echo "<td>" . htmlspecialchars($participant['phone']) . "</td>";

            echo "<td>" . htmlspecialchars($participant['location']) . "</td>";

            echo "<td>" . htmlspecialchars($participant['registration_date']) . "</td>";

            echo "<td>" . htmlspecialchars($participant['status']) . "</td>";

            echo "</tr>";
        }

        echo "</table>";
    }
}

if ($reportType == 'flagged') {

    $participants = get_flagged_participants_report(
        $selectedLocation,
        $startDate,
        $endDate
    );

    echo "<h2>Flagged Participants</h2>";

    if (count($participants) == 0) {

        echo "<p>No flagged participants found.</p>";

    } else {

        echo "<table class='report-table'>";

        echo "<tr>
                <th>ID</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Location</th>
                <th>Status</th>
                <th>Notes</th>
              </tr>";

        foreach ($participants as $participant) {

            echo "<tr>";

            echo "<td>" . htmlspecialchars($participant['id']) . "</td>";

            echo "<td>" .
                 htmlspecialchars($participant['first_name']) . " " .
                 htmlspecialchars($participant['last_name']) .
                 "</td>";

            echo "<td>" . htmlspecialchars($participant['phone']) . "</td>";

            echo "<td>" . htmlspecialchars($participant['location']) . "</td>";

            echo "<td>" . htmlspecialchars($participant['status']) . "</td>";

            echo "<td>" . htmlspecialchars($participant['notes']) . "</td>";

            echo "</tr>";
        }

        echo "</table>";
    }
}



?>

</main>

</body>

</html>