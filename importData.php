<?php
    // Import Participants & Pets: an administrator uploads a spreadsheet of
    // households and their pets, reviews a preview, and imports the rows that
    // passed the same checks as Register Participant and Register Pet.
    session_cache_expire(30);
    session_start();
    require_once(__DIR__ . '/include/sessionTimeout.php'); // 30-minute inactivity sign-out (NFR-SEC-005)

    $accessLevel = isset($_SESSION['_id']) ? $_SESSION['access_level'] : 0;
    // Admin only, checked here and not just by hiding the dashboard card
    if ($accessLevel < 2) {
        header('Location: index.php');
        die();
    }

    require_once('include/output.php');
    require_once('include/import.php');
    require_once('database/dbParticipants.php');
    require_once('database/dbImport.php');

    $locations = array('Empower', 'Senior Center', 'Home Delivery');

    // Blank template to fill in
    if (isset($_GET['template'])) {
        import_send_template($locations);
        die();
    }

    $errors = array();
    $defaultLocation = '';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'cancel') {
            unset($_SESSION['import_preview']);
            header('Location: importData.php');
            die();
        }

        if ($action === 'import') {
            $preview = isset($_SESSION['import_preview']) ? $_SESSION['import_preview'] : null;
            $token = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
            if (!$preview || !hash_equals($preview['token'], $token)) {
                header('Location: importData.php?expired');
                die();
            }
            // Possible duplicates are imported only when staff tick them
            $confirmed = isset($_POST['import_duplicate']) && is_array($_POST['import_duplicate'])
                ? array_map('intval', $_POST['import_duplicate']) : array();
            $toImport = array();
            foreach ($preview['households'] as $index => $household) {
                if ($household['errors']) continue;
                if ($household['duplicates'] && !in_array($index, $confirmed, true)) continue;
                $toImport[] = $household;
            }
            if (!$toImport) {
                $errors[] = 'Nothing was selected to import.';
            } else {
                try {
                    $saved = import_households($toImport);
                    unset($_SESSION['import_preview']);
                    header('Location: importData.php?imported=' . $saved['participants'] . '&pets=' . $saved['pets']);
                    die();
                } catch (Throwable $e) {
                    error_log('Import failed: ' . $e->getMessage());
                    $errors[] = 'The import failed and nothing was saved. Please try again.';
                }
            }
        }

        if ($action === 'preview') {
            unset($_SESSION['import_preview']);
            $defaultLocation = isset($_POST['default_location']) && is_string($_POST['default_location']) ? $_POST['default_location'] : '';
            if ($defaultLocation !== '' && !in_array($defaultLocation, $locations, true)) {
                $defaultLocation = '';
            }
            $file = isset($_FILES['import_file']) ? $_FILES['import_file'] : null;
            if (!$file || !is_array($file['error']) && $file['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Choose a file to upload.';
            } else if (is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                $errors[] = 'The file did not upload. Make sure it is smaller than ' . (IMPORT_MAX_FILE_BYTES / 1024 / 1024) . ' MB and try again.';
            } else if ($file['size'] > IMPORT_MAX_FILE_BYTES) {
                $errors[] = 'The file must be smaller than ' . (IMPORT_MAX_FILE_BYTES / 1024 / 1024) . ' MB.';
            } else {
                try {
                    $sheet = import_read_spreadsheet($file['tmp_name'], $file['name']);
                    // The header is the first row with anything in it
                    $headerIndex = 0;
                    while ($headerIndex < count($sheet) && implode('', array_map('import_cell_text', $sheet[$headerIndex])) === '') {
                        $headerIndex++;
                    }
                    if ($headerIndex >= count($sheet)) {
                        throw new RuntimeException('The first sheet in the file is empty.');
                    }
                    list($map, $missing) = import_map_headers($sheet[$headerIndex]);
                    if ($missing) {
                        $labels = array_intersect_key(import_columns(), array_flip($missing));
                        throw new RuntimeException('The sheet is missing these columns: ' . implode(', ', $labels)
                            . '. The first row must hold the column headers. Download the template to see them.');
                    }
                    if (!in_array('location', $map, true) && $defaultLocation === '') {
                        throw new RuntimeException('Your sheet has no Location column. Choose the location these participants belong to, then upload again.');
                    }
                    $result = import_build_households($map, array_slice($sheet, $headerIndex + 1), $headerIndex + 2, $defaultLocation, $locations);
                    if (!$result['households']) {
                        throw new RuntimeException('The sheet has headers but no rows to import.');
                    }
                    // Check participants already in the system (FR-018), as registration does
                    foreach ($result['households'] as &$household) {
                        $household['duplicates'] = array();
                        if ($household['errors']) continue;
                        $p = $household['participant'];
                        $household['duplicates'] = find_possible_duplicate_participants(
                            $p['first_name'], $p['last_name'], $p['phone'], $p['email'], $p['street_address'], 3);
                    }
                    unset($household);
                    $_SESSION['import_preview'] = array(
                        'token' => bin2hex(random_bytes(16)),
                        'file' => $file['name'],
                        'mapped' => array_map('import_field_label', array_values($map)),
                        'ignored' => import_ignored_headers($sheet[$headerIndex], $map),
                        'households' => $result['households']
                    );
                    // Redirect so a refresh doesn't ask to resend the file
                    header('Location: importData.php#preview');
                    die();
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                } catch (Throwable $e) {
                    error_log('Import preview failed: ' . $e->getMessage());
                    $errors[] = 'The file could not be checked. Please try again.';
                }
            }
        }
    }

    $preview = isset($_SESSION['import_preview']) ? $_SESSION['import_preview'] : null;
    $ready = $duplicates = $problems = array();
    $readyPets = 0;
    $withWarnings = 0;
    if ($preview) {
        foreach ($preview['households'] as $index => $household) {
            if ($household['errors']) {
                $problems[$index] = $household;
            } else if ($household['duplicates']) {
                $duplicates[$index] = $household;
            } else {
                $ready[$index] = $household;
                $readyPets += count($household['pets']);
            }
            if (!$household['errors'] && $household['warnings']) $withWarnings++;
        }
    }

    /*
     * Headers in the sheet that the import doesn't use, so staff can see
     * a column was left out
     */
    function import_ignored_headers($headerRow, $map) {
        $ignored = array();
        foreach ($headerRow as $index => $header) {
            $text = import_cell_text($header);
            if ($text !== '' && !isset($map[$index])) $ignored[] = $text;
        }
        return $ignored;
    }

    // Download the blank .xlsx template
    function import_send_template($locations) {
        $file = tempnam(sys_get_temp_dir(), 'tpl');
        import_write_template($file, $locations);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="pet-pantry-import-template.xlsx"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: max-age=0');
        readfile($file);
        unlink($file);
    }

    // "Max (Dog), Luna (Cat)"
    function import_pet_summary($pets) {
        if (!$pets) return 'No pets';
        return implode(', ', array_map(function ($pet) {
            return $pet['name'] . ' (' . $pet['species'] . ')';
        }, $pets));
    }

    function import_address($p) {
        $cityLine = trim($p['city'] . ($p['state'] !== '' ? ', ' . $p['state'] : '') . ' ' . $p['zip']);
        return $p['street_address'] . ($cityLine !== '' ? ', ' . $cityLine : '');
    }

    // A phone as (540) 555-0101, or a dash when the sheet had none
    function import_phone($p) {
        return $p['phone'] === '' ? '&mdash;' : formatPhoneNumber($p['phone']);
    }

    // What was saved blank or adjusted, for staff to fill in later
    function import_print_warnings($household) {
        if (!$household['warnings']) return;
        echo '<ul class="check-later">';
        foreach ($household['warnings'] as $warning) {
            echo '<li>Row ' . (int) $warning[0] . ': ' . hsc($warning[1]) . '</li>';
        }
        echo '</ul>';
    }

    function import_row_list($rows) {
        return (count($rows) > 1 ? 'Rows ' : 'Row ') . implode(', ', $rows);
    }
?>

<!DOCTYPE html>
<html>
<head>
    <title>CHS Pet Pantry | Import Participants & Pets</title>
    <link href="css/base.css" rel="stylesheet">
<!-- BANDAID FIX FOR HEADER BEING WEIRD -->
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<!-- BANDAID END, REMOVE ONCE SOME GENIUS FIXES -->
    <style>
        .main-content-box { max-width: 70rem; }
        .import-page { max-width: 62rem; margin: 0 auto; padding: 0 1rem 2rem; }
        .import-page .signup-form { width: 100%; max-width: none; margin: 0 auto 1rem; box-sizing: border-box; }
        .import-page fieldset { min-width: 0; }
        .import-page .import-intro { margin: 0 0 .75rem; font-size: .95rem; }
        .import-page .import-steps { margin: 0 0 1rem 1.25rem; padding: 0; list-style: decimal; font-size: .95rem; }
        .import-page .import-steps li { margin: .2rem 0; }
        .import-page .import-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .9rem 1rem; }
        .import-page .import-field { min-width: 0; }
        .import-page .import-field label { display: block; margin: 0 0 .3rem; font-weight: 600; }
        .import-page .import-field input, .import-page .import-field select { box-sizing: border-box; width: 100%; margin: 0; }
        .import-page .import-help { font-size: .82rem; margin: .35rem 0 0; color: #526777; }
        .import-page .import-button { display: inline-block; width: auto; min-width: 0; margin: .85rem .5rem 0 0; padding: .55rem 1.05rem; border-radius: 4px; border: 0; background: #276b7b; color: #fff; font-weight: 600; cursor: pointer; text-decoration: none; }
        .import-page .import-button:hover { background: #205766; }
        .import-page .import-button.secondary { background: #e5eef1; color: #1f4a56; }
        .import-page .import-button.secondary:hover { background: #d3e2e7; }
        .import-page .import-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; margin: 0 0 1rem; }
        .import-page .import-stat { border: 1px solid #d3e2e7; border-radius: 6px; padding: .6rem .8rem; background: #f5f9fa; }
        .import-page .import-stat strong { display: block; font-size: 1.5rem; color: #1f4a56; }
        .import-page .import-stat.warn strong { color: #92400e; }
        .import-page .import-stat.bad strong { color: #b91c1c; }
        .import-page .table-scroll { overflow-x: auto; position: relative; }
        .import-page .import-table { width: 100%; border-collapse: collapse; text-align: left; font-size: .88rem; }
        .import-page .import-table th { background-color: #2E5E6E; color: #fff; padding: .5rem; white-space: nowrap; }
        .import-page .import-table td { border-bottom: 1px solid #ccc; padding: .5rem; vertical-align: top; overflow-wrap: anywhere; }
        .import-page .import-table tbody tr:nth-child(even) { background: #f5f9fa; }
        .import-page .import-table ul { margin: 0; padding-left: 1.1rem; list-style: disc; }
        .import-page .import-table .check-later { color: #92400e; font-size: .82rem; }
        .import-page .import-filter { margin: 0 0 .75rem; font-size: .9rem; }
        .import-page tr.no-warnings.hidden-row { display: none; }
        .import-page .import-nav { display: flex; flex-wrap: wrap; justify-content: center; gap: .6rem; margin: 1rem 0; }
        .import-page .import-nav .button { display: inline-block; width: auto; margin: 0; padding: .45rem .8rem; font-size: .88rem; }
        .import-page .muted { color: #526777; font-size: .85rem; }
        .hero-header h1, .hero-header .center-header h1 { color: #fff; }
        @media (max-width: 650px) {
            .import-page .import-grid, .import-page .import-summary { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="relative">

<header class="hero-header">
    <div class="center-header">
        <h1>Import Participants &amp; Pets</h1>
    </div>
</header>

<main>
  <div class="main-content-box">
  <div class="import-page">

    <?php if (isset($_GET['imported'])): ?>
    <div class="happy-toast" role="status">
        Imported <?php echo (int) $_GET['imported'] ?> participant<?php echo (int) $_GET['imported'] === 1 ? '' : 's' ?>
        and <?php echo (int) ($_GET['pets'] ?? 0) ?> pet<?php echo (int) ($_GET['pets'] ?? 0) === 1 ? '' : 's' ?>.
    </div>
    <?php elseif (isset($_GET['expired'])): ?>
    <div class="error-toast" role="alert">That preview has expired. Upload the file again.</div>
    <?php endif ?>

    <?php if (count($errors) > 0): ?>
    <div class="error-toast" role="alert">
        <ul>
            <?php foreach ($errors as $error): ?>
            <li><?php echo hsc($error) ?></li>
            <?php endforeach ?>
        </ul>
    </div>
    <?php endif ?>

    <?php if (!$preview): ?>
    <form class="signup-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="preview">
        <fieldset class="section-box mb-4">
            <h3 class="mt-2">Upload a Spreadsheet</h3>
            <div class="blue-div"></div>
            <p class="import-intro">Add many participants and their pets at once from an Excel or CSV file.</p>
            <ol class="import-steps">
                <li><a href="importData.php?template">Download the template</a> and fill it in, one row per pet. The Instructions tab explains each column.</li>
                <li>Upload it here. Nothing is saved yet: you'll see what will be added, possible duplicates, and any rows that need fixing.</li>
                <li>Review and click <strong>Import</strong>. Rows with problems are left out, so you can fix them and upload the file again.</li>
            </ol>
            <div class="import-grid">
                <div class="import-field">
                    <label for="import_file"><em>* </em>Spreadsheet</label>
                    <input type="file" id="import_file" name="import_file" required accept=".xlsx,.xls,.csv" aria-describedby="file_help">
                    <p id="file_help" class="import-help">.xlsx, .xls or .csv, up to <?php echo IMPORT_MAX_FILE_BYTES / 1024 / 1024 ?> MB and <?php echo number_format(IMPORT_MAX_ROWS) ?> rows. Only the first sheet is read.</p>
                </div>
                <div class="import-field">
                    <label for="default_location">Location</label>
                    <select id="default_location" name="default_location" aria-describedby="location_help">
                        <option value="">From the sheet's Location column</option>
                        <?php foreach ($locations as $location): ?>
                        <option value="<?php echo hsc($location) ?>"<?php if ($location === $defaultLocation) echo ' selected' ?>><?php echo hsc($location) ?></option>
                        <?php endforeach ?>
                    </select>
                    <p id="location_help" class="import-help">Where these participants are served. Required if your sheet has no Location column; otherwise used for rows where it is blank.</p>
                </div>
            </div>
            <button type="submit" class="import-button">Upload and Preview</button>
        </fieldset>
    </form>

    <?php else: ?>
    <form class="signup-form" method="post" id="preview">
        <input type="hidden" name="token" value="<?php echo hsc($preview['token']) ?>">
        <fieldset class="section-box mb-4">
            <h3 class="mt-2">Preview: <?php echo hsc($preview['file']) ?></h3>
            <div class="blue-div"></div>
            <p class="import-intro">Nothing has been saved yet.</p>

            <div class="import-summary">
                <div class="import-stat"><strong><?php echo count($ready) ?></strong>new participant<?php echo count($ready) === 1 ? '' : 's' ?> with <?php echo $readyPets ?> pet<?php echo $readyPets === 1 ? '' : 's' ?> ready to import</div>
                <div class="import-stat warn"><strong><?php echo count($duplicates) ?></strong>possible duplicate<?php echo count($duplicates) === 1 ? '' : 's' ?>, skipped unless ticked</div>
                <div class="import-stat warn"><strong><?php echo $withWarnings ?></strong>to import with details to fill in later (see "Check later")</div>
                <div class="import-stat bad"><strong><?php echo count($problems) ?></strong>household<?php echo count($problems) === 1 ? '' : 's' ?> with problems, will not be imported</div>
            </div>

            <p class="muted">Columns used: <?php echo hsc(implode(', ', $preview['mapped'])) ?>.
            <?php if ($preview['ignored']): ?><br>Columns ignored: <?php echo hsc(implode(', ', $preview['ignored'])) ?>.<?php endif ?></p>
        </fieldset>

        <?php if ($problems): ?>
        <fieldset class="section-box mb-4">
            <h3>Needs Fixing</h3>
            <p class="mb-2">These households will be left out. Fix the rows in your spreadsheet and upload it again; households already imported will show as duplicates and be skipped.</p>
            <div class="blue-div"></div>
            <div class="table-scroll">
                <table class="import-table">
                    <thead><tr><th scope="col">Row</th><th scope="col">Participant</th><th scope="col">Problem</th></tr></thead>
                    <tbody>
                        <?php foreach ($problems as $household): ?>
                        <?php $p = $household['participant']; ?>
                        <tr>
                            <td><?php echo hsc(import_row_list($household['rows'])) ?></td>
                            <td><?php echo hsc(trim($p['first_name'] . ' ' . $p['last_name'])) ?: '<em>No name</em>' ?></td>
                            <td><ul>
                                <?php foreach ($household['errors'] as $error): ?>
                                <li>Row <?php echo (int) $error[0] ?>: <?php echo hsc($error[1]) ?></li>
                                <?php endforeach ?>
                            </ul></td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </fieldset>
        <?php endif ?>

        <?php if ($duplicates): ?>
        <fieldset class="section-box mb-4">
            <h3>Possible Duplicates</h3>
            <p class="mb-2">These match a participant already in the system by phone, email, name, or last name and street. Tick a row only if it is a different person.</p>
            <div class="blue-div"></div>
            <div class="table-scroll">
                <table class="import-table">
                    <thead><tr><th scope="col">Import anyway?</th><th scope="col">Row</th><th scope="col">From the sheet</th><th scope="col">Already in the system</th></tr></thead>
                    <tbody>
                        <?php foreach ($duplicates as $index => $household): ?>
                        <?php $p = $household['participant']; ?>
                        <tr>
                            <td><input type="checkbox" name="import_duplicate[]" value="<?php echo (int) $index ?>" aria-label="Import <?php echo hsc($p['first_name'] . ' ' . $p['last_name']) ?> anyway"></td>
                            <td><?php echo hsc(import_row_list($household['rows'])) ?></td>
                            <td><strong><?php echo hsc($p['first_name'] . ' ' . $p['last_name']) ?></strong><br>
                                <?php echo hsc(import_address($p)) ?><br><?php echo import_phone($p) ?><br>
                                <span class="muted"><?php echo hsc(import_pet_summary($household['pets'])) ?></span>
                                <?php import_print_warnings($household) ?></td>
                            <td><ul>
                                <?php foreach ($household['duplicates'] as $match): ?>
                                <li><a href="participantEdit.php?id=<?php echo (int) $match['id'] ?>" target="_blank"><?php echo hsc(format_participant_id($match['id'])) ?></a>
                                    <?php echo hsc($match['first_name'] . ' ' . $match['last_name']) ?>, <?php echo hsc($match['street_address']) ?>
                                    (<?php echo hsc($match['status']) ?>)</li>
                                <?php endforeach ?>
                            </ul></td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </fieldset>
        <?php endif ?>

        <?php if ($ready): ?>
        <fieldset class="section-box mb-4">
            <h3>Ready to Import</h3>
            <div class="blue-div"></div>
            <?php if ($withWarnings): ?>
            <label class="import-filter"><input type="checkbox" id="only_warnings"> Show only participants with details to check later</label>
            <?php endif ?>
            <div class="table-scroll">
                <table class="import-table">
                    <thead><tr><th scope="col">Row</th><th scope="col">Participant</th><th scope="col">Address</th><th scope="col">Phone</th><th scope="col">Location</th><th scope="col">Registered</th><th scope="col">Pets</th><th scope="col">Check later</th></tr></thead>
                    <tbody>
                        <?php foreach ($ready as $household): ?>
                        <?php $p = $household['participant']; ?>
                        <tr<?php if (!$household['warnings']) echo ' class="no-warnings"' ?>>
                            <td><?php echo hsc(import_row_list($household['rows'])) ?></td>
                            <td><?php echo hsc($p['first_name'] . ' ' . $p['last_name']) ?></td>
                            <td><?php echo hsc(import_address($p)) ?></td>
                            <td style="white-space: nowrap"><?php echo import_phone($p) ?></td>
                            <td><?php echo hsc($p['location']) ?></td>
                            <td style="white-space: nowrap"><?php echo hsc(date('M j, Y', strtotime($p['registration_date']))) ?></td>
                            <td><?php echo hsc(import_pet_summary($household['pets'])) ?></td>
                            <td><?php import_print_warnings($household) ?></td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </fieldset>
        <?php endif ?>

        <?php if ($withWarnings): ?>
        <script>
            document.getElementById('only_warnings').addEventListener('change', function () {
                var hide = this.checked;
                document.querySelectorAll('tr.no-warnings').forEach(function (row) { row.classList.toggle('hidden-row', hide); });
            });
        </script>
        <?php endif ?>

        <?php if ($ready || $duplicates): ?>
        <button type="submit" class="import-button" name="action" value="import" onclick="return confirm('Import these participants and pets now?');">Import</button>
        <?php endif ?>
        <button type="submit" class="import-button secondary" name="action" value="cancel" formnovalidate>Cancel and Upload a Different File</button>
    </form>
    <?php endif ?>

    <div class="import-nav">
        <a href="participantSearch.php" class="button">Participant Search</a>
        <a href="index.php" class="button">Return to Dashboard</a>
    </div>
  </div>
  </div>
</main>

</body>
</html>
