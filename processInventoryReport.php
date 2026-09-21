<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_cache_expire(30);
session_start();

require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['access_level']) || $_SESSION['access_level'] < 1) {
    header('Location: login.php');
    die();
}

//require_once('database/dbPersons.php');
//require_once('database/dbEvents.php');
require_once('database/dbinfo.php');
require_once('database/dbInventoryEvent.php');

// 👉 Add month completeness check function
// function is_month_complete($dateFrom) {
//     $lastDayOfMonth = date("Y-m-t", strtotime($dateFrom));
//     $today = date("Y-m-d");
//     return $today > $lastDayOfMonth;
// }

$selectedWeek = $_POST['week'] ?? '';
$rawItemCategories = $_POST['name'] ?? '';
$selectedItemCategories = is_array($rawItemCategories) ? $rawItemCategories : [$rawItemCategories];

if (in_array('', $selectedItemCategories, true) || empty($selectedItemCategories)) {
    $selectedItemCategories = []; 
} else {
    $selectedItemCategories = array_values(array_filter($selectedItemCategories));
}

$format = $_POST['format'] ?? 'csv';

$con = connect();

// Verify the event exists
if (empty($selectedWeek)) {
    echo "No inventory event selected.";
    exit();
}

$primaryEvent = retrieve_inventoryEvent($selectedWeek);
if (!$primaryEvent) {
    echo "No inventory event found for the selected date.";
    exit();
}

// Get all matching events (Warehouse, Pantry, Pallet triplet)
$eventIds = [$selectedWeek];
$matches = get_matching_inventoryEvent($primaryEvent);
foreach ($matches as $location => $event) {
    if ($event) {
        $eventIds[] = $event->getId();
    }
}

// Sum quantities across all locations (Warehouse, Pantry, Pallet)
$eventPlaceholders = implode(',', array_fill(0, count($eventIds), '?'));
$sql = "SELECT dic.id, dic.name as item_name,
        SUM(dbic.quantity) as boxes,
        dic.itemsPerBox,
        SUM(dbic.quantity) * dic.itemsPerBox as total_count
        FROM dbitemcategory dic
        INNER JOIN dbitemcounts dbic on dic.id = dbic.itemCategoryId
        WHERE dbic.inventoryEventID IN ($eventPlaceholders) AND dic.shopOnly = 0";
$params = $eventIds;
$types = str_repeat('i', count($eventIds));

// managing selected item categories
if (!empty($selectedItemCategories)) {
    // adding placeholders based off of how many items were selected
    $placeholders = implode(',', array_fill(0, count($selectedItemCategories), '?'));
    $sql .= " AND dic.id IN ($placeholders)";
    // for the bind function to work with all the items
    $types .= str_repeat('s', count($selectedItemCategories));
    $params = array_merge($params, $selectedItemCategories);
}

// Group by category to combine quantities from all locations
$sql .= " GROUP BY dic.id, dic.name, dic.itemsPerBox";

$stmt = $con->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();



$reportData = [];
while ($row = $result->fetch_assoc()) {
    $reportData[] = $row;
}

// CSV EXPORT
if ($format === 'csv') {
    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=inventory_report_{$selectedWeek}.csv");
    header("Pragma: no-cache");
    header("Expires: 0");

    $output = fopen('php://output', 'w');
    fputcsv($output, ["Inventory Report"]);

    // Column Headers
    fputcsv($output, ["Item Name", "Boxes", "Items Per Box", "Total Count"]);

    // Data
    foreach ($reportData as $row) {
        fputcsv($output, [
            $row["item_name"],
            $row["boxes"],
            $row["itemsPerBox"],
            $row["total_count"]
        ]);
    }
    fclose($output);
    exit();
}

// XLSX EXPORT
if ($format === 'xlsx') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Inventory Report');

    // Column Headers
    $sheet->setCellValue('A1', 'Item Name');
    $sheet->setCellValue('B1', 'Boxes');
    $sheet->setCellValue('C1', 'Items Per Box');
    $sheet->setCellValue('D1', 'Total Count');

    // Header Style
    $headerStyle = [
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => '88CCEE']
        ]
    ];
    $sheet->getStyle('A1:D1')->applyFromArray($headerStyle);

    // Data
    $rowNum = 2;
    foreach ($reportData as $row) {
        $sheet->setCellValue('A' . $rowNum, $row['item_name']);
        $sheet->setCellValue('B' . $rowNum, $row['boxes']);
        $sheet->setCellValue('C' . $rowNum, $row['itemsPerBox']);
        $sheet->setCellValue('D' . $rowNum, $row['total_count']);
        $rowNum++;
    }

    // Column Sizing
    foreach (range('A', 'D') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="inventory_report_' . $selectedWeek . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

// EXCEL EXPORT - COMMENTED OUT
// header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
// header("Content-Disposition: attachment; filename=inventory_report.xls");
// header("Pragma: no-cache");
// header("Expires: 0");
//
// echo "\xEF\xBB\xBF";
// echo "<html><head><meta charset='UTF-8'></head><body>";
// echo "<table border='1' style='border-collapse: collapse; font-family: Arial, sans-serif; text-align: center;'>";
//
// // Report Title
// echo "<tr><th colspan='4' >Inventory Report</th></tr>";
//
// // Column Headers
// echo "<tr>
//         <th style='background-color: #88CCEE; padding: 5px;'>Item Name</th>
//         <th style='background-color: #AA4499; padding: 5px;'>Boxes</th>
//         <th style='background-color: #DDCC77; padding: 5px;'>Items Per Box</th>
//         <th style='background-color: #88CCEE; padding: 5px;'>Total Count</th>
//         </tr>";
//
// // Data Rows
// foreach ($reportData as $row) {
//     echo "<tr>
//             <td style='background-color: #EAEAEA; padding: 5px; text-align: center;'>{$row["item_name"]}</td>
//             <td style='padding: 5px;'>{$row["boxes"]}</td>
//             <td style='padding: 5px;'>{$row["itemsPerBox"]}</td>
//             <td style='padding: 5px;'>{$row["total_count"]}</td>
//             </tr>";
// }
//
// echo "</table>";
// echo "</body></html>";
// exit();
?>
