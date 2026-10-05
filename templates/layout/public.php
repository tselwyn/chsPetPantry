<?php
/** Layout for pages used before signing in (login, password reset, errors). */
/** @var string $content  @var list<array{type:string,message:string}> $flash */
use Pfpms\Settings;

try {
    $org = Settings::string('organisation_name', 'CHS Pet Pantry');
} catch (\Throwable) {
    $org = 'CHS Pet Pantry'; // the error page must render even when the database is down
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($title ?? 'Sign in') . ' | ' . $org) ?></title>
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <link rel="icon" href="<?= e(asset('img/paw.svg')) ?>" type="image/svg+xml">
</head>
<body class="public">
<main id="main" class="public-card">
  <div class="public-brand">
    <?php include APP_ROOT . '/templates/partials/paw.php'; ?>
    <span><?= e($org) ?></span>
  </div>
  <?php foreach ($flash as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
  <p class="public-version">Version <?= e(APP_VERSION) ?></p>
</main>
</body>
</html>
