<?php /** @var bool $sent  @var ?string $error */ ?>
<h1>Reset your password</h1>
<?php if ($sent): ?>
  <div class="flash flash-success" role="status">
    If that email address belongs to an account, a reset link is on its way. The link works once and expires soon.
  </div>
  <p class="public-links"><a href="<?= e(url('login.php')) ?>">Back to sign in</a></p>
<?php else: ?>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <p>Enter the email address on your account and we will send you a link to choose a new password.</p>
  <form method="post" action="<?= e(url('forgot_password.php')) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <label for="email">Email address</label>
    <input id="email" name="email" type="email" autocomplete="email" required autofocus>
    <button type="submit" class="button button-primary button-block">Send reset link</button>
  </form>
  <p class="public-links"><a href="<?= e(url('login.php')) ?>">Back to sign in</a></p>
<?php endif; ?>
