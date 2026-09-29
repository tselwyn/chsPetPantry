<?php
/** @var \Pfpms\Http\Context $ctx  @var list<array> $items  @var bool $showResolved */
$tz = $ctx->site()['time_zone'] ?? 'America/New_York';
?>
<h1>Needs attention</h1>
<p class="lead">
  <?php if ($showResolved): ?>
    Items resolved in the last 30 days. <a href="<?= e(url('notifications.php')) ?>">Show open items</a>
  <?php else: ?>
    Open items for you or your role. <a href="<?= e(url('notifications.php', ['show' => 'resolved'])) ?>">Show recently resolved</a>
  <?php endif; ?>
</p>
<?php if (!$items): ?>
  <p class="meta"><?= $showResolved ? 'Nothing was resolved recently.' : 'Nothing needs your attention right now.' ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">When</th><th scope="col">What</th><th scope="col">Site</th><th scope="col"><?= $showResolved ? 'Resolved' : '<span class="visually-hidden">Action</span>' ?></th></tr></thead>
    <tbody>
    <?php foreach ($items as $n): ?>
      <tr>
        <td><?= e(local_time($n['created_at'], $tz)) ?></td>
        <td><?= e($n['message']) ?></td>
        <td><?= e($n['site_name'] ?? 'All sites') ?></td>
        <td>
          <?php if ($showResolved): ?>
            <?= e(local_time($n['resolved_at'], $tz)) ?><?= $n['resolved_by_name'] ? ' by ' . e($n['resolved_by_name']) : '' ?>
          <?php else: ?>
            <form method="post" action="<?= e(url('notifications.php')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="notification_id" value="<?= (int) $n['notification_id'] ?>">
              <button type="submit" class="button">Mark done</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
