<?php /** @var \Pfpms\Http\Context $ctx  @var list<array> $sites */ ?>
<div class="page-head">
  <h1>Sites</h1>
  <a class="button button-primary" href="<?= e(url('admin_site_edit.php')) ?>">Add a site</a>
</div>
<?php if (!$sites): ?>
  <p class="meta">No sites yet. Add the first distribution site.</p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">Name</th><th scope="col">Address</th><th scope="col">Time zone</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($sites as $s): ?>
      <tr<?= $s['is_active'] ? '' : ' class="status-inactive"' ?>>
        <td><a href="<?= e(url('admin_site_edit.php', ['id' => $s['site_id']])) ?>"><?= e($s['name']) ?></a></td>
        <td><?= e(implode(', ', array_filter([$s['street_address'], $s['city'], trim(($s['state'] ?? '') . ' ' . ($s['postal_code'] ?? ''))]))) ?></td>
        <td><?= e($s['time_zone']) ?></td>
        <td><?= $s['is_active'] ? 'Active' : '<span class="badge badge-inactive">Inactive</span>' ?></td>
        <td>
          <form method="post" action="<?= e(url('admin_sites.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="site_id" value="<?= (int) $s['site_id'] ?>">
            <button type="submit" name="action" value="<?= $s['is_active'] ? 'deactivate' : 'activate' ?>" class="button">
              <?= $s['is_active'] ? 'Deactivate' : 'Activate' ?>
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
