<?php /** @var bool $forced  @var list<string> $errors  @var int $minLength */ ?>
<h1><?= $forced ? 'Choose your own password' : 'Change password' ?></h1>
<?php if ($forced): ?>
  <p class="lead">Before you continue, replace the temporary password you were given with one only you know.</p>
<?php endif; ?>
<?php include APP_ROOT . '/templates/partials/form_errors.php'; ?>
<form method="post" action="<?= e(url('change_password.php')) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <label for="current_password"><?= $forced ? 'Temporary password' : 'Current password' ?></label>
  <input id="current_password" name="current_password" type="password" autocomplete="current-password" required autofocus>
  <label for="new_password">New password</label>
  <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="<?= (int) $minLength ?>" required>
  <p class="hint">At least <?= (int) $minLength ?> characters. Avoid your name and common passwords.</p>
  <label for="confirm_password">Confirm new password</label>
  <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
  <div class="form-actions">
    <button type="submit" class="button button-primary">Save password</button>
    <?php if (!$forced): ?><a class="button" href="<?= e(url('index.php')) ?>">Cancel</a><?php endif; ?>
  </div>
</form>
