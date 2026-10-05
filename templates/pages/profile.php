<?php /** @var \Pfpms\Http\Context $ctx  @var array $values  @var list<string> $errors  @var bool $conflict */ ?>
<h1>My profile</h1>
<?php if ($conflict): ?>
  <div class="flash flash-error" role="alert">Your profile was changed somewhere else while you were editing. The latest version is shown; please make your change again.</div>
<?php endif; ?>
<?php include APP_ROOT . '/templates/partials/form_errors.php'; ?>
<form method="post" action="<?= e(url('profile.php')) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="row_version" value="<?= (int) $values['row_version'] ?>">
  <label for="display_name">Display name</label>
  <input id="display_name" name="display_name" type="text" maxlength="100" value="<?= e($values['display_name']) ?>">
  <p class="hint">How your name appears to colleagues. Leave blank to use <?= e($ctx->user['first_name'] . ' ' . $ctx->user['last_name']) ?>.</p>
  <label for="phone">Phone</label>
  <input id="phone" name="phone" type="tel" autocomplete="tel" value="<?= e($values['phone']) ?>">
  <fieldset>
    <legend>Notifications</legend>
    <label class="checkbox"><input type="checkbox" name="notify_email" value="1"<?= $values['notify_email'] ? ' checked' : '' ?>> Email me about items that need my attention</label>
  </fieldset>
  <dl class="readonly-list">
    <dt>Username</dt><dd><?= e($ctx->user['username']) ?></dd>
    <dt>Email</dt><dd><?= e($ctx->user['email']) ?></dd>
    <dt>Role</dt><dd><?= e($ctx->role()) ?></dd>
    <dt>Sites</dt><dd><?= e(implode(', ', array_column($ctx->sites, 'name')) ?: 'None') ?></dd>
  </dl>
  <p class="hint">Your role, sites and email are managed by an Administrator.</p>
  <div class="form-actions">
    <button type="submit" class="button button-primary">Save profile</button>
    <a class="button" href="<?= e(url('change_password.php')) ?>">Change password</a>
  </div>
</form>
