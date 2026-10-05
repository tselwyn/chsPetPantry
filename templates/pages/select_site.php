<?php /** @var \Pfpms\Http\Context $ctx  @var ?string $next */ ?>
<h1>Choose a site</h1>
<?php if (!$ctx->sites): ?>
  <div class="flash flash-info" role="status">
    You are not assigned to any site yet. Please ask an Administrator to give you access.
  </div>
<?php else: ?>
  <p class="lead">Which site are you working at?</p>
  <form method="post" action="<?= e(url('select_site.php')) ?>" class="site-choices">
    <?= csrf_field() ?>
    <?php if ($next): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
    <?php foreach ($ctx->sites as $site): ?>
      <button type="submit" name="site_id" value="<?= (int) $site['site_id'] ?>"
              class="button button-tile<?= $site['site_id'] === $ctx->siteId ? ' is-current' : '' ?>">
        <?= e($site['name']) ?>
        <?php if ($site['site_id'] === $ctx->siteId): ?><span class="tile-note">Current</span><?php endif; ?>
      </button>
    <?php endforeach; ?>
  </form>
<?php endif; ?>
