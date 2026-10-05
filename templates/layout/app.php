<?php
/** @var string $content  @var \Pfpms\Http\Context $ctx  @var list<array{type:string,message:string}> $flash */
use Pfpms\Settings;
use Pfpms\View\Menu;

$org = Settings::string('organisation_name', 'CHS Pet Pantry');
$site = $ctx->site();
$menu = Menu::for($ctx);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($title ?? 'Home') . ' | ' . $org) ?></title>
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/print.css')) ?>" media="print">
  <link rel="icon" href="<?= e(asset('img/paw.svg')) ?>" type="image/svg+xml">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
  <a class="brand" href="<?= e(url('index.php')) ?>">
    <?php include APP_ROOT . '/templates/partials/paw.php'; ?>
    <span><?= e($org) ?></span>
  </a>
  <div class="topbar-site">
    <?php if ($site): ?>
      <span class="site-name" title="Current site"><?= e($site['name']) ?></span>
      <?php if (count($ctx->sites) > 1): ?>
        <a class="link-small" href="<?= e(url('select_site.php')) ?>">Change site</a>
      <?php endif; ?>
    <?php elseif ($ctx->sites): ?>
      <a class="link-small" href="<?= e(url('select_site.php')) ?>">Choose a site</a>
    <?php endif; ?>
  </div>
  <details class="user-menu">
    <summary><?= e($ctx->displayName()) ?> <span class="role-badge"><?= e($ctx->role()) ?></span></summary>
    <div class="user-menu-panel">
      <a href="<?= e(url('profile.php')) ?>">My profile</a>
      <a href="<?= e(url('change_password.php')) ?>">Change password</a>
      <form method="post" action="<?= e(url('logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="link-button">Sign out</button>
      </form>
    </div>
  </details>
</header>
<?php if (count($menu) > 1 || count(reset($menu) ?: []) > 1): ?>
<nav class="mainnav" aria-label="Main">
  <a class="mainnav-link" href="<?= e(url('index.php')) ?>">Home</a>
  <?php foreach ($menu as $group => $items): ?>
    <?php if ($group === 'Home') continue; ?>
    <details class="mainnav-group">
      <summary><?= e($group) ?></summary>
      <div class="mainnav-panel">
        <?php foreach ($items as $item): ?>
          <a href="<?= e(url($item['file'])) ?>"><?= e($item['label']) ?></a>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<main id="main" class="container">
  <?php foreach ($flash as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<footer class="footer">
  <span><?= e($org) ?></span>
  <span>Version <?= e(APP_VERSION) ?></span>
</footer>
</body>
</html>
