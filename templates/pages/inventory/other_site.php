<?php
/** @var \Pfpms\Http\Context $ctx  @var string $what  @var int $siteId  @var string $siteName  @var string $next */
?>
<h1><?= e($what) ?> is at <?= e($siteName) ?></h1>
<p class="lead">You are working at <?= e($ctx->site()['name'] ?? 'another site') ?>. Switch to <?= e($siteName) ?> to see it.</p>
<form method="post" action="<?= e(url('select_site.php')) ?>" class="form-actions">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <button type="submit" name="site_id" value="<?= (int) $siteId ?>" class="button button-primary">Switch to <?= e($siteName) ?></button>
  <a class="button" href="<?= e(url('index.php')) ?>">Stay at <?= e($ctx->site()['name'] ?? 'this site') ?></a>
</form>
