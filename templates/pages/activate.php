<?php /** @var ?array $account  @var string $token  @var array<string,string> $errors  @var int $minLength */ ?>
<h1>Choose your password</h1>
<?php if ($account === null): ?>
  <div class="flash flash-error" role="alert"><?= e($errors['_form'] ?? 'This link is not valid. It may have expired or already been used.') ?></div>
  <p>Ask an Administrator to send you a new link.</p>
  <p class="public-links"><a href="<?= e(url('login.php')) ?>">Go to sign in</a></p>
<?php else: ?>
  <p>Welcome, <?= e($account['first_name']) ?>. Your username is <strong><?= e($account['username']) ?></strong>.</p>
  <?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('activate.php')) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <input type="text" name="username" value="<?= e($account['username']) ?>" autocomplete="username" hidden>
    <label for="new_password">New password</label>
    <input type="password" <?= field_attrs($errors, 'new_password') ?> autocomplete="new-password" minlength="<?= (int) $minLength ?>" required autofocus>
    <?= field_error($errors, 'new_password') ?>
    <p class="hint">At least <?= (int) $minLength ?> characters. A short sentence you can remember works well. Avoid your name.</p>
    <label for="confirm_password">Type it again</label>
    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
    <button type="submit" class="button button-primary button-block">Set password</button>
  </form>
<?php endif; ?>
