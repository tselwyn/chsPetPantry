<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Pfpms\Db;
use Pfpms\Http\Page;
use Pfpms\Http\Response;

// Health check for deploys and monitoring. Public, so it reports only ok / not ok.
Page::start(['public' => true]);
try {
    $pending = (int) Db::pdo()->query("SELECT COUNT(*) FROM schema_version WHERE status NOT IN ('Applied', 'Skipped')")->fetchColumn();
    Response::json(['status' => $pending === 0 ? 'ok' : 'migrations_pending', 'version' => APP_VERSION], $pending === 0 ? 200 : 503);
} catch (Throwable) {
    Response::json(['status' => 'database_unavailable'], 503);
}
