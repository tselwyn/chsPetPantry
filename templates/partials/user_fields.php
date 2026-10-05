<?php
/**
 * Account detail fields shared by the invite and edit forms.
 * @var array $values  @var array<string,string> $errors  @var list<array> $sites  @var list<int> $selectedSites
 * @var bool $selfEdit  true when an Administrator edits their own account (role, sites, dates, email locked)
 */
$lock = !empty($selfEdit) ? ' disabled' : '';
?>
<div class="form-grid">
  <div>
    <label for="first_name">First name</label>
    <input type="text" <?= field_attrs($errors, 'first_name') ?> maxlength="50" autocomplete="off" value="<?= e($values['first_name']) ?>" required>
    <?= field_error($errors, 'first_name') ?>
  </div>
  <div>
    <label for="last_name">Last name</label>
    <input type="text" <?= field_attrs($errors, 'last_name') ?> maxlength="50" autocomplete="off" value="<?= e($values['last_name']) ?>" required>
    <?= field_error($errors, 'last_name') ?>
  </div>
</div>

<label for="email">Email</label>
<input type="email" <?= field_attrs($errors, 'email') ?> maxlength="100" autocomplete="off" value="<?= e($values['email']) ?>" required<?= $lock ?>>
<?= field_error($errors, 'email') ?>
<?php if (isset($errors['existing_user_id'])): ?>
  <p class="hint"><a href="<?= e(url('admin_user_edit.php', ['id' => $errors['existing_user_id']])) ?>">Open the existing account</a></p>
<?php endif; ?>

<label for="phone">Phone (optional)</label>
<input type="tel" <?= field_attrs($errors, 'phone') ?> value="<?= e($values['phone']) ?>">
<?= field_error($errors, 'phone') ?>

<label for="role">Role</label>
<select <?= field_attrs($errors, 'role') ?><?= $lock ?>>
  <?php foreach (['Volunteer' => 'Volunteer: registers participants, records distributions and referrals',
                  'Coordinator' => 'Coordinator: runs events, stock and site settings, plus everything a Volunteer does',
                  'Administrator' => 'Administrator: everything, at every site',
                  'Board' => 'Board: read-only summaries, no participant data'] as $role => $label): ?>
    <option value="<?= e($role) ?>"<?= selected($values['role'] === $role) ?>><?= e($label) ?></option>
  <?php endforeach; ?>
</select>
<?= field_error($errors, 'role') ?>

<fieldset<?= isset($errors['sites']) ? ' aria-describedby="sites-error"' : '' ?>>
  <legend>Sites (Volunteers and Coordinators only)</legend>
  <?php if (!$sites): ?>
    <p class="meta">No active sites. Add one under Setup &gt; Sites.</p>
  <?php endif; ?>
  <?php foreach ($sites as $site): ?>
    <label class="checkbox">
      <input type="checkbox" name="sites[]" value="<?= (int) $site['site_id'] ?>"<?= selected(in_array((int) $site['site_id'], $selectedSites, true), 'checked') ?><?= $lock ?>>
      <?= e($site['name']) ?><?= $site['is_active'] ? '' : ' <span class="meta">(site closed; kept until you untick it)</span>' ?>
    </label>
  <?php endforeach; ?>
  <p class="hint">Administrators and Board members see every site through their role.</p>
</fieldset>
<?= field_error($errors, 'sites') ?>

<div class="form-grid">
  <div>
    <label for="start_date">Start date</label>
    <input type="date" <?= field_attrs($errors, 'start_date') ?> value="<?= e($values['start_date']) ?>"<?= $lock ?>>
    <?= field_error($errors, 'start_date') ?>
  </div>
  <div>
    <label for="expiry_date">End date (optional)</label>
    <input type="date" <?= field_attrs($errors, 'expiry_date') ?> value="<?= e($values['expiry_date']) ?>"<?= $lock ?>>
    <?= field_error($errors, 'expiry_date') ?>
  </div>
</div>
<p class="hint">After the end date the person can no longer sign in. Leave it blank for no end.</p>

<label for="onboarding_completed_at">Programme rules and data-handling training completed on</label>
<input type="date" <?= field_attrs($errors, 'onboarding_completed_at') ?> value="<?= e(substr((string) $values['onboarding_completed_at'], 0, 10)) ?>">
<?= field_error($errors, 'onboarding_completed_at') ?>

<label class="checkbox">
  <input type="checkbox" <?= field_attrs($errors, 'can_extract_identifiable') ?> value="1"<?= selected(!empty($values['can_extract_identifiable']), 'checked') ?><?= $lock ?>>
  May export reports that identify participants (Administrators only; every export is logged)
</label>
<?= field_error($errors, 'can_extract_identifiable') ?>
