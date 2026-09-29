<?php /** @var ?array $site  @var array $values  @var array<string,string> $errors  @var list<string> $zones */ ?>
<h1><?= $site ? 'Edit ' . e($site['name']) : 'Add a site' ?></h1>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('admin_site_edit.php')) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <?php if ($site): ?><input type="hidden" name="site_id" value="<?= (int) $site['site_id'] ?>"><?php endif; ?>

  <label for="name">Site name</label>
  <input type="text" <?= field_attrs($errors, 'name') ?> maxlength="100" value="<?= e($values['name']) ?>" required>
  <?= field_error($errors, 'name') ?>

  <label for="street_address">Street address</label>
  <input type="text" <?= field_attrs($errors, 'street_address') ?> maxlength="100" autocomplete="street-address" value="<?= e($values['street_address']) ?>">
  <?= field_error($errors, 'street_address') ?>

  <div class="form-grid">
    <div>
      <label for="city">City</label>
      <input type="text" <?= field_attrs($errors, 'city') ?> maxlength="50" value="<?= e($values['city']) ?>">
      <?= field_error($errors, 'city') ?>
    </div>
    <div>
      <label for="state">State</label>
      <input type="text" <?= field_attrs($errors, 'state') ?> maxlength="2" value="<?= e($values['state']) ?>">
      <?= field_error($errors, 'state') ?>
    </div>
    <div>
      <label for="postal_code">ZIP code</label>
      <input type="text" <?= field_attrs($errors, 'postal_code') ?> inputmode="numeric" maxlength="10" value="<?= e($values['postal_code']) ?>">
      <?= field_error($errors, 'postal_code') ?>
    </div>
  </div>

  <label for="time_zone">Time zone</label>
  <select <?= field_attrs($errors, 'time_zone') ?>>
    <?php foreach ($zones as $zone): ?>
      <option value="<?= e($zone) ?>"<?= selected($zone === $values['time_zone']) ?>><?= e(str_replace('_', ' ', $zone)) ?></option>
    <?php endforeach; ?>
  </select>
  <?= field_error($errors, 'time_zone') ?>
  <p class="hint">Distributions are dated in the site's own time zone.</p>

  <div class="form-actions">
    <button type="submit" class="button button-primary"><?= $site ? 'Save site' : 'Add site' ?></button>
    <a class="button" href="<?= e(url('admin_sites.php')) ?>">Cancel</a>
  </div>
</form>
