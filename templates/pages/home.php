<?php
/** @var \Pfpms\Http\Context $ctx  @var int $openNotifications  @var bool $policyMissing  @var array $menu */
$site = $ctx->site();
?>
<h1>Welcome, <?= e($ctx->displayName()) ?></h1>
<p class="lead">
  <?php if ($site): ?>
    You are working at <strong><?= e($site['name']) ?></strong>.
  <?php elseif ($ctx->sites): ?>
    <a href="<?= e(url('select_site.php')) ?>">Choose the site you are working at</a> to see its work.
  <?php elseif ($ctx->can('site.manage')): ?>
    No sites have been set up yet.
  <?php else: ?>
    You are not assigned to a site yet. Please ask an Administrator.
  <?php endif; ?>
</p>

<?php if ($policyMissing && $ctx->can('policy.manage')): ?>
  <div class="flash flash-info" role="status">
    No confidentiality agreement has been added yet, so staff are not asked to accept one when they sign in (US-03). Add it under Policy texts.
  </div>
<?php endif; ?>

<?php if ($openNotifications > 0): ?>
  <div class="flash flash-info" role="status">
    You have <?= (int) $openNotifications ?> item<?= $openNotifications === 1 ? '' : 's' ?> needing attention.
    <?php if (is_file(\Pfpms\Http\Request::publicDir() . '/notifications.php')): ?><a href="<?= e(url('notifications.php')) ?>">View</a><?php endif; ?>
  </div>
<?php endif; ?>

<div class="tiles">
  <?php foreach ($menu as $group => $items): ?>
    <?php if ($group === 'Home') continue; ?>
    <section class="tile-group">
      <h2><?= e($group) ?></h2>
      <?php foreach ($items as $item): ?>
        <a class="tile" href="<?= e(url($item['file'])) ?>"><?= e($item['label']) ?></a>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
</div>
<?php if (count($menu) <= 1): ?>
  <p class="meta">More features appear here as they are released.</p>
<?php endif; ?>
