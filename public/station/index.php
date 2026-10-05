<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use Pfpms\Http\Page;
use Pfpms\Http\Response;
use Pfpms\Station\StationShell;

// P2B: the Station's shell (50-design §7.1). Public and sessionless: the same bytes for every tablet, so the service
// worker can verify and cache them. Everything else comes from api/ with the tablet's own key.
Page::start(['public' => true, 'session' => false]);
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (!str_ends_with($path, '/') && !str_ends_with($path, '/index.php')) {
    Response::redirect('station/index.php', 301); // php -S serves /station without the slash; relative URLs need it
}
StationShell::sendHeaders();
echo StationShell::html();
