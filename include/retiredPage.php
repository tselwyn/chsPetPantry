<?php
/*
 * Retired page guard (SRS NFR-SEC-002, NFR-SEC-003).
 *
 * This app started as the CCDA food pantry system (inventory, volunteer
 * events, groups, email). Those pages aren't part of the CHS Pet Pantry, but
 * the files are still here, and several of them ran with no login check at
 * all (insertAdmin.php could create a superadmin account). Every one of those
 * pages starts with:
 *
 *     <?php require_once __DIR__ . '/include/retiredPage.php'; ?>
 *
 * Opening such a page in a browser shows "Page not found". A retired file
 * that a live page still includes as code (header.php, for example) keeps
 * working, because only a direct visit is blocked. Command-line scripts are
 * not affected.
 *
 * To bring a page back into the Pet Pantry, delete that first line from it.
 */

if (PHP_SAPI === 'cli') {
    return;
}

$pfpmsRetiredCaller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '';
$pfpmsMainScript = $_SERVER['SCRIPT_FILENAME'] ?? '';
if ($pfpmsRetiredCaller === '' || $pfpmsMainScript === ''
        || realpath($pfpmsRetiredCaller) !== realpath($pfpmsMainScript)) {
    // Included by a live page, not opened directly
    unset($pfpmsRetiredCaller, $pfpmsMainScript);
    return;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found | CHS Pet Pantry</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               background: #f4f9fa; color: #173e49; font: 16px/1.5 Nunito, Arial, sans-serif; }
        main { max-width: 28rem; margin: 16px; padding: 2rem; background: #fff; border: 1px solid #c4e3e8;
               border-radius: 12px; text-align: center; box-shadow: 0 6px 20px rgba(0,0,0,.08); }
        h1 { margin: 0 0 .5rem; font-size: 1.5rem; color: #1f5968; }
        a { display: inline-block; margin-top: 1rem; padding: .6rem 1.1rem; border-radius: 6px;
            background: #1f5968; color: #fff; text-decoration: none; font-weight: 700; }
        a:hover, a:focus-visible { background: #17444f; }
    </style>
</head>
<body>
<main>
    <h1>Page not found</h1>
    <p>This page isn't part of the CHS Pet Pantry.</p>
    <a href="index.php">Go to the dashboard</a>
</main>
</body>
</html>
<?php
exit;
