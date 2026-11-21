<?php require_once __DIR__ . '/include/retiredPage.php'; // retired CCDA page: blocked when opened directly ?>
<?php
require_once 'database/dbShifts.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shiftID = $_POST['shiftID'];
    $description = $_POST['description'];

    if (!empty($shiftID) && !empty($description)) {
        clockOutByShiftID($shiftID, $description);
        echo "Success";
    } else {
        echo "Missing shiftID or description.";
    }
}
?>
