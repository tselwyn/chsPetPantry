<?php /** @var \Pfpms\Http\Context $ctx  @var array $values  @var array<string,string> $errors  @var list<array> $postalCodes  @var list<array> $sites */ ?>
<?php $activeCount = count(array_filter($postalCodes, fn(array $p): bool => (bool) $p['is_active'])); ?>
<div class="page-head">
  <h1>Service area ZIP codes</h1>
</div>
<p class="lead">Participants must live in one of these ZIP codes to be registered, unless an Administrator approves an exception. Each ZIP code can name its nearest site, which is suggested when someone registers or moves.</p>

<h2>Add ZIP codes</h2>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('admin_service_area.php')) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <label for="postal_codes">ZIP codes</label>
  <textarea <?= field_attrs($errors, 'postal_codes') ?> rows="5" spellcheck="false"><?= e($values['postal_codes']) ?></textarea>
  <?= field_error($errors, 'postal_codes') ?>
  <p class="hint">Separate ZIP codes with commas, spaces or new lines. ZIP+4 codes are shortened to the first 5 digits. Adding a deactivated ZIP code makes it active again.</p>

  <label for="site_id">Nearest site (optional)</label>
  <select <?= field_attrs($errors, 'site_id') ?>>
    <option value="">No site</option>
    <?php foreach ($sites as $s): ?>
      <option value="<?= (int) $s['site_id'] ?>"<?= selected((string) $s['site_id'] === (string) $values['site_id']) ?>><?= e($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <?= field_error($errors, 'site_id') ?>
  <p class="hint">Given to the ZIP codes added or reactivated now. ZIP codes already in the list keep their site; change it in the table below.</p>

  <div class="form-actions">
    <button type="submit" name="action" value="add" class="button button-primary">Add ZIP codes</button>
  </div>
</form>

<h2>ZIP codes in the list</h2>
<?php if (!$postalCodes): ?>
  <p class="meta">No ZIP codes yet. Until some are added, every address counts as outside the service area.</p>
<?php else: ?>
  <p class="meta"><?= $activeCount ?> active<?= $activeCount < count($postalCodes) ? ', ' . (count($postalCodes) - $activeCount) . ' deactivated' : '' ?>.</p>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">ZIP code</th><th scope="col">Nearest site</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($postalCodes as $p): ?>
      <?php $zip = (string) $p['postal_code']; ?>
      <tr<?= $p['is_active'] ? '' : ' class="status-inactive"' ?>>
        <td><?= e($zip) ?></td>
        <td>
          <form method="post" action="<?= e(url('admin_service_area.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="postal_code" value="<?= e($zip) ?>">
            <label class="visually-hidden" for="site-<?= e($zip) ?>">Nearest site for <?= e($zip) ?></label>
            <select id="site-<?= e($zip) ?>" name="site_id">
              <option value="">No site</option>
              <?php foreach ($sites as $s): ?>
                <option value="<?= (int) $s['site_id'] ?>"<?= selected((int) $s['site_id'] === (int) $p['site_id']) ?>><?= e($s['name']) ?></option>
              <?php endforeach; ?>
              <?php if ($p['site_id'] !== null && !$p['site_is_active']): ?>
                <option value="<?= (int) $p['site_id'] ?>" selected><?= e($p['site_name']) ?> (inactive site)</option>
              <?php endif; ?>
            </select>
            <button type="submit" name="action" value="set_site" class="button">Save site</button>
          </form>
        </td>
        <td><?= $p['is_active'] ? 'Active' : '<span class="badge badge-inactive">Inactive</span>' ?></td>
        <td>
          <form method="post" action="<?= e(url('admin_service_area.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="postal_code" value="<?= e($zip) ?>">
            <button type="submit" name="action" value="<?= $p['is_active'] ? 'deactivate' : 'activate' ?>" class="button">
              <?= $p['is_active'] ? 'Deactivate' : 'Activate' ?>
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
