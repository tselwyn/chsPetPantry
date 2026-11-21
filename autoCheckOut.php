<?php require_once __DIR__ . '/include/retiredPage.php'; // retired CCDA page: blocked when opened directly ?>
<?php
$_SERVER['SERVER_NAME']='localhost';
require_once 'database/dbShifts.php';
auto_checkout_missing_shifts();
?>
