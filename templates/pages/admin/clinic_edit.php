<?php /** @var ?array $clinic  @var array $values  @var array<string,string> $errors */ ?>
<h1><?= $clinic ? 'Edit ' . e($clinic['name']) : 'Add a clinic' ?></h1>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<?php if ($clinic && $clinic['status'] === 'Suspended'): ?>
  <p class="meta"><span class="badge badge-inactive">Suspended</span> No new referrals can be sent to this clinic. Reactivate it from the clinic list.</p>
<?php endif; ?>
<form method="post" action="<?= e(url('admin_clinic_edit.php')) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <?php if ($clinic): ?><input type="hidden" name="clinic_id" value="<?= (int) $clinic['clinic_id'] ?>"><?php endif; ?>

  <label for="name">Clinic name</label>
  <input type="text" <?= field_attrs($errors, 'name') ?> maxlength="100" value="<?= e($values['name']) ?>" required>
  <?= field_error($errors, 'name') ?>

  <label class="checkbox"><input type="checkbox" name="is_partner" value="1"<?= selected(!empty($values['is_partner']), 'checked') ?>> Partner clinic (takes our spay/neuter vouchers)</label>
  <label class="checkbox"><input type="checkbox" name="offers_low_cost_vaccination" value="1"<?= selected(!empty($values['offers_low_cost_vaccination']), 'checked') ?>> Offers low-cost vaccinations</label>

  <label for="street_address">Street address</label>
  <input type="text" <?= field_attrs($errors, 'street_address') ?> maxlength="100" value="<?= e($values['street_address']) ?>">
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

  <label for="phone">Phone</label>
  <input type="tel" <?= field_attrs($errors, 'phone') ?> maxlength="20" value="<?= e($values['phone']) ?>">
  <?= field_error($errors, 'phone') ?>

  <label for="hours_text">Opening hours</label>
  <input type="text" <?= field_attrs($errors, 'hours_text') ?> maxlength="255" value="<?= e($values['hours_text']) ?>">
  <?= field_error($errors, 'hours_text') ?>
  <p class="hint">Printed on vouchers as written, e.g. "Mon&ndash;Fri 8am&ndash;5pm, Sat 9am&ndash;noon".</p>

  <label for="directions_url">Directions link</label>
  <input type="text" <?= field_attrs($errors, 'directions_url') ?> inputmode="url" maxlength="255" value="<?= e($values['directions_url']) ?>">
  <?= field_error($errors, 'directions_url') ?>
  <p class="hint">A map link starting with https://. Leave blank if there is none.</p>

  <div class="form-grid">
    <div>
      <label for="voucher_rate">Voucher amount ($)</label>
      <input type="text" <?= field_attrs($errors, 'voucher_rate') ?> inputmode="decimal" maxlength="10" value="<?= e($values['voucher_rate']) ?>">
      <?= field_error($errors, 'voucher_rate') ?>
    </div>
    <div>
      <label for="period_capacity">Referrals per period</label>
      <input type="text" <?= field_attrs($errors, 'period_capacity') ?> inputmode="numeric" maxlength="6" value="<?= e($values['period_capacity']) ?>">
      <?= field_error($errors, 'period_capacity') ?>
    </div>
    <div>
      <label for="current_wait_days">Current wait (days)</label>
      <input type="text" <?= field_attrs($errors, 'current_wait_days') ?> inputmode="numeric" maxlength="6" value="<?= e($values['current_wait_days']) ?>">
      <?= field_error($errors, 'current_wait_days') ?>
    </div>
  </div>
  <p class="hint">Leave the amount, capacity or wait blank if you do not know them yet.</p>

  <div class="form-actions">
    <button type="submit" class="button button-primary"><?= $clinic ? 'Save clinic' : 'Add clinic' ?></button>
    <a class="button" href="<?= e(url('admin_clinics.php')) ?>">Cancel</a>
  </div>
</form>
