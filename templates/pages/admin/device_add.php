<?php
/** @var \Pfpms\Http\Context $ctx  @var array $values  @var array<string, string> $errors  @var string $formKey */
?>
<p><a href="<?= e(url('admin_devices.php')) ?>">Devices</a></p>
<h1>Register a tablet</h1>
<p class="lead">Add the tablet here, then print its registration sheet. On the tablet, the installed Station app scans the sheet to register itself.</p>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<?php if (!$ctx->sites): ?>
  <p class="meta">You have no sites at the moment, so you cannot add a tablet.</p>
<?php else: ?>
  <form method="post" action="<?= e(url('admin_device_edit.php')) ?>" class="form form-narrow" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="form_key" value="<?= e($formKey) ?>">
    <label for="site_id">Site</label>
    <select <?= field_attrs($errors, 'site_id') ?> required>
      <option value="">Choose a site</option>
      <?php foreach ($ctx->sites as $s): ?>
        <option value="<?= (int) $s['site_id'] ?>"<?= selected((string) $s['site_id'] === (string) $values['site_id']) ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?= field_error($errors, 'site_id') ?>
    <label for="label">Tablet name</label>
    <input type="text" <?= field_attrs($errors, 'label') ?> maxlength="50" value="<?= e($values['label']) ?>" required>
    <?= field_error($errors, 'label') ?>
    <p class="hint">Name it by where it is used, for example Front desk 1. Do not use a person's name.</p>
    <div class="form-actions">
      <button type="submit" class="button button-primary">Add tablet</button>
      <a class="button" href="<?= e(url('admin_devices.php')) ?>">Cancel</a>
    </div>
  </form>
<?php endif; ?>
