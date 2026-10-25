<?php
    // UC-13 Generate Reports (FR-059 to FR-065)
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
    // Reports are admin-only (SRS UC-13 to UC-16)
    if ($accessLevel < 2) {
        header('Location: index.php');
        die();
    }

    require_once('include/input-validation.php');
    require_once('include/output.php');
    require_once('database/dbParticipants.php');

    // Phone numbers are stored as 10 digits; reports show them as (540) 555-0101
    function report_phone($phone) {
        $phone = (string) $phone;
        if (preg_match('/^[0-9]{10}$/', $phone)) {
            return '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6);
        }
        return $phone;
    }

    function report_name($row) {
        return trim($row['first_name'] . ' ' . $row['last_name']);
    }

    /*
     * Reports this page can generate. Each one names its columns, the date its
     * date filter applies to, how to fetch its rows, and how to turn one row
     * into the column values. Distribution (UC-14) and pet / SNV (UC-16)
     * reports are added here as they are built.
     */
    $reportTypes = array(
        'participant_list' => array(
            'title' => 'Current Participant List',
            'date_label' => 'Registration date',
            'columns' => array('ID', 'Name', 'Phone', 'Location', 'Registration Date', 'Status'),
            'fetch' => 'get_participant_report',
            'row' => function ($p) {
                return array($p['id'], report_name($p), report_phone($p['phone']),
                    $p['location'], $p['registration_date'], $p['status']);
            }
        ),
        'participant_status' => array(
            'title' => 'Active / Inactive / Expired Participants',
            'date_label' => 'Registration date',
            'columns' => array('ID', 'Name', 'Phone', 'Location', 'Registration Date', 'Status'),
            'fetch' => 'get_participant_status_report',
            'row' => function ($p) {
                return array($p['id'], report_name($p), report_phone($p['phone']),
                    $p['location'], $p['registration_date'], $p['status']);
            }
        ),
        'flagged' => array(
            'title' => 'Flagged Participants',
            'date_label' => 'Registration date',
            'columns' => array('ID', 'Name', 'Phone', 'Location', 'Status', 'Notes'),
            'fetch' => 'get_flagged_participants_report',
            'row' => function ($p) {
                return array($p['id'], report_name($p), report_phone($p['phone']),
                    $p['location'], $p['status'], (string) $p['notes']);
            }
        )
    );

    $exportFormats = array('csv', 'xlsx', 'pdf');
    $locations = get_participant_locations();

    // Filters, kept as text so the form can show back exactly what was entered
    $filters = array();
    foreach (array('report_type', 'start_date', 'end_date', 'location', 'export') as $field) {
        $filters[$field] = (isset($_GET[$field]) && is_string($_GET[$field])) ? trim($_GET[$field]) : '';
    }

    $submitted = isset($_GET['report_type']);
    $errors = array();
    $report = null;
    $rows = array();

    if ($submitted) {
        if (!isset($reportTypes[$filters['report_type']])) {
            $errors[] = 'Choose a report type from the list.';
        }

        $startValid = $filters['start_date'] === '' || validateDate($filters['start_date']);
        $endValid = $filters['end_date'] === '' || validateDate($filters['end_date']);
        if (!$startValid) {
            $errors[] = 'Enter the start date as a valid date.';
        }
        if (!$endValid) {
            $errors[] = 'Enter the end date as a valid date.';
        }
        // Invalid filter range (UC-13 alternate flow)
        if ($startValid && $endValid && $filters['start_date'] !== '' && $filters['end_date'] !== ''
                && $filters['start_date'] > $filters['end_date']) {
            $errors[] = 'The start date must be on or before the end date.';
        }

        if ($filters['location'] !== '' && !valueConstrainedTo($filters['location'], $locations)) {
            $errors[] = 'Choose a location from the list.';
        }

        if ($filters['export'] !== '' && !valueConstrainedTo($filters['export'], $exportFormats)) {
            $errors[] = 'Choose CSV, Excel, or PDF to export.';
        }

        if (count($errors) == 0) {
            $report = $reportTypes[$filters['report_type']];
            $fetch = $report['fetch'];
            foreach ($fetch($filters['location'], $filters['start_date'], $filters['end_date']) as $result) {
                $rows[] = $report['row']($result);
            }
        }
    }

    // Lines describing the filters, shown above the report and in the PDF
    $filterSummary = array();
    if ($report !== null) {
        $filterSummary[] = 'Location: ' . ($filters['location'] === '' ? 'All locations' : $filters['location']);
        if ($filters['start_date'] === '' && $filters['end_date'] === '') {
            $filterSummary[] = $report['date_label'] . ': any';
        } else {
            $filterSummary[] = $report['date_label'] . ': '
                . ($filters['start_date'] === '' ? 'any' : date('M j, Y', strtotime($filters['start_date'])))
                . ' to '
                . ($filters['end_date'] === '' ? 'any' : date('M j, Y', strtotime($filters['end_date'])));
        }
    }
    $generatedAt = date('M j, Y g:i A');

    // Exports are sent as a download instead of the page (FR-063 to FR-065)
    if ($report !== null && $filters['export'] !== '') {
        $filename = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($report['title'])), '-') . '_' . date('Y-m-d');

        if ($filters['export'] === 'csv') {
            // A cell starting with = + - @ would run as a formula in Excel
            $csvCell = function ($value) {
                $value = (string) $value;
                return ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) ? "'" . $value : $value;
            };
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker so Excel shows accents correctly
            fputcsv($out, $report['columns']);
            foreach ($rows as $row) {
                fputcsv($out, array_map($csvCell, $row));
            }
            fclose($out);
            die();
        }

        if ($filters['export'] === 'xlsx') {
            require_once('vendor/autoload.php');
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Report');
            $sheet->fromArray($report['columns'], null, 'A1');
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            foreach ($rows as $r => $row) {
                foreach (array_values($row) as $c => $value) {
                    // Written as text so nothing in a cell is treated as a formula
                    $sheet->setCellValueExplicit([$c + 1, $r + 2], (string) $value,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
            foreach (range(1, count($report['columns'])) as $c) {
                $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
            }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
            die();
        }

        if ($filters['export'] === 'pdf') {
            require_once('include/dompdf-autoload.php');
            $html = '<html><head><meta charset="utf-8"><style>'
                . 'body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #222; }'
                . 'h1 { font-size: 16px; margin: 0 0 4px 0; color: #2E5E6E; }'
                . 'p { margin: 0 0 2px 0; }'
                . 'table { width: 100%; border-collapse: collapse; margin-top: 10px; }'
                . 'th { background: #2E5E6E; color: #fff; text-align: left; padding: 4px; }'
                . 'td { border-bottom: 1px solid #ccc; padding: 4px; vertical-align: top; }'
                . '</style></head><body>'
                . '<h1>Culpeper Humane Society Pet Pantry: ' . hsc($report['title']) . '</h1>';
            foreach ($filterSummary as $line) {
                $html .= '<p>' . hsc($line) . '</p>';
            }
            $html .= '<p>Generated ' . hsc($generatedAt) . ' &middot; ' . count($rows) . ' record' . (count($rows) == 1 ? '' : 's') . '</p>';
            if (count($rows) == 0) {
                $html .= '<p><strong>No records match the selected filters.</strong></p>';
            } else {
                $html .= '<table><thead><tr>';
                foreach ($report['columns'] as $column) {
                    $html .= '<th>' . hsc($column) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $html .= '<tr>';
                    foreach ($row as $value) {
                        $html .= '<td>' . nl2br(hsc($value)) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }
            $html .= '</body></html>';

            $dompdf = new \Dompdf\Dompdf(array(
                'isRemoteEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
                'tempDir' => sys_get_temp_dir(),
                'fontCache' => sys_get_temp_dir()
            ));
            $dompdf->loadHtml($html);
            $dompdf->setPaper('letter', 'landscape');
            $dompdf->render();
            $dompdf->stream($filename . '.pdf', array('Attachment' => true));
            die();
        }
    }

    // Link to this report with the same filters, for the export buttons
    function report_export_url($filters, $format) {
        $params = $filters;
        $params['export'] = $format;
        return 'participantReports.php?' . http_build_query($params);
    }
?>

<!DOCTYPE html>
<html>
<head>
    <title>CHS Pet Pantry | Reports</title>
    <link href="css/base.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<!-- BANDAID END, REMOVE ONCE SOME GENIUS FIXES -->
    <style>
        .report-table { width: 100%; border-collapse: collapse; text-align: left; }
        .report-table th { background-color: #2E5E6E; color: #fff; padding: .5rem; }
        .report-table td { border-bottom: 1px solid #ccc; padding: .5rem; vertical-align: top; }
        .report-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: .5rem; margin-top: 1rem; }
        .report-actions .button { margin: 0; }
        .print-only { display: none; }

        /* Printable report (FR-062): only the report itself is printed */
        @media print {
            .navbar, .navbar-background, .accessibility-btn, .accessibility-modal-backdrop,
            .hero-header, .no-print, .report-actions { display: none !important; }
            .print-only { display: block !important; }
            body, .main-content-box, .signup-form, .section-box { background: #fff !important; box-shadow: none !important; border: none !important; margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: none !important; }
            .report-table th { background-color: #2E5E6E !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="relative">

<header class="hero-header">
    <div class="center-header">
        <h1>Reports</h1>
    </div>
</header>

<main>
  <div class="main-content-box">
    <form class="signup-form no-print" method="get">
        <div class="text-center spacing-bottom">
            <div class="info-box">
                <p class="sub-text">Choose a report and any filters, then generate it to view, print, or export.</p>
                <p>An asterisk ( <em>*</em> ) indicates a required field.</p>
            </div>
        </div>

        <?php if (count($errors) > 0): ?>
        <div class="error-toast">
            <p>The report was not generated:</p>
            <ul>
                <?php foreach ($errors as $error): ?>
                <li><?php echo hsc($error) ?></li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>

        <fieldset class="section-box mb-4">
            <h3 class="mt-2">Report Options</h3>
            <p class="mb-2">Dates filter on each report's date field (registration date for participant reports). Leave a filter blank to include everything.</p>
            <div class="blue-div"></div>

            <label for="report_type"><em>* </em>Report Type</label>
            <select id="report_type" name="report_type" required>
                <option value="">Select a report</option>
                <?php foreach ($reportTypes as $key => $type): ?>
                <option value="<?php echo hsc($key) ?>"<?php if ($key === $filters['report_type']) echo ' selected' ?>><?php echo hsc($type['title']) ?></option>
                <?php endforeach ?>
            </select>

            <label for="start_date">Start Date</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo hsc($filters['start_date']) ?>">

            <label for="end_date">End Date</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo hsc($filters['end_date']) ?>">

            <label for="location">Location</label>
            <select id="location" name="location">
                <option value="">All locations</option>
                <?php foreach ($locations as $location): ?>
                <option value="<?php echo hsc($location) ?>"<?php if ($location === $filters['location']) echo ' selected' ?>><?php echo hsc($location) ?></option>
                <?php endforeach ?>
            </select>
        </fieldset>

        <input type="submit" value="Generate Report">
    </form>

    <?php if ($report !== null): ?>
    <div class="signup-form" id="report">
        <fieldset class="section-box mb-4">
            <p class="print-only">Culpeper Humane Society Pet Pantry</p>
            <h3><?php echo hsc($report['title']) ?></h3>
            <?php foreach ($filterSummary as $line): ?>
            <p><?php echo hsc($line) ?></p>
            <?php endforeach ?>
            <p class="mb-2">Generated <?php echo hsc($generatedAt) ?> &middot; <?php echo count($rows) ?> record<?php echo count($rows) == 1 ? '' : 's' ?></p>
            <div class="blue-div"></div>

            <?php if (count($rows) == 0): ?>
            <?php // No matching data is an empty result, not an error (UC-13 alternate flow) ?>
            <p>No records match the selected filters.</p>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="report-table">
                    <thead>
                        <tr>
                            <?php foreach ($report['columns'] as $column): ?>
                            <th scope="col"><?php echo hsc($column) ?></th>
                            <?php endforeach ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($row as $value): ?>
                            <td><?php echo nl2br(hsc($value)) ?></td>
                            <?php endforeach ?>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endif ?>

            <div class="report-actions">
                <button type="button" class="button" onclick="window.print()">Print</button>
                <a href="<?php echo hsc(report_export_url($filters, 'csv')) ?>" class="button">Export CSV</a>
                <a href="<?php echo hsc(report_export_url($filters, 'xlsx')) ?>" class="button">Export Excel</a>
                <a href="<?php echo hsc(report_export_url($filters, 'pdf')) ?>" class="button">Export PDF</a>
            </div>
        </fieldset>
    </div>
    <?php endif ?>

    <div class="text-center no-print">
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>
  </div>
</main>

</body>
</html>
