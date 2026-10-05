<?php /** @var ?string $error  @var string $identifier  @var ?string $next */ ?>
<h1>Sign in</h1>
<?php if ($error): ?>
  <div class="flash flash-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>
<form method="post" action="<?= e(url('login.php')) ?>" class="form" novalidate>
  <?= csrf_field() ?>
  <?php if ($next): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
  <label for="identifier">Username or email</label>
  <input id="identifier" name="identifier" type="text" autocomplete="username" autocapitalize="none" spellcheck="false"
         value="<?= e($identifier) ?>" required autofocus>
  <label for="password">Password</label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>
  <button type="submit" class="button button-primary button-block">Sign in</button>
</form>
<p class="public-links"><a href="<?= e(url('forgot_password.php')) ?>">Forgot your password?</a></p>
