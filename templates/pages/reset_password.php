<?php /** @var bool $valid  @var string $token  @var list<string> $errors  @var int $minLength */ ?>
<h1>Choose a new password</h1>
<?php if (!$valid): ?>
  <div class="flash flash-error" role="alert">This reset link is not valid. It may have expired or already been used.</div>
  <p class="public-links"><a href="<?= e(url('forgot_password.php')) ?>">Request a new link</a></p>
<?php else: ?>
  <?php include APP_ROOT . '/templates/partials/form_errors.php'; ?>
  <form method="post" action="<?= e(url('reset_password.php')) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label for="new_password">New password</label>
    <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="<?= (int) $minLength ?>" required autofocus>
    <p class="hint">At least <?= (int) $minLength ?> characters. A short sentence you can remember works well.</p>
    <label for="confirm_password">Confirm new password</label>
    <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
    <button type="submit" class="button button-primary button-block">Save new password</button>
  </form>
<?php endif; ?>
