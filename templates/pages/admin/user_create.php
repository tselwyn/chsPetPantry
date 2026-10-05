<?php /** @var array $values  @var array<string,string> $errors  @var list<array> $sites  @var list<int> $selectedSites */ ?>
<h1>Invite a person</h1>
<p class="lead">The person gets an email with a link to choose their own password. Until they use it, the account shows as Invited.</p>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('admin_user_create.php')) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <label for="username">Username</label>
  <input type="text" <?= field_attrs($errors, 'username') ?> maxlength="50" autocapitalize="none" autocomplete="off" spellcheck="false" value="<?= e($values['username']) ?>" required>
  <p class="hint">What they type to sign in, for example jsmith. Letters, digits, dots, dashes and underscores.</p>
  <?= field_error($errors, 'username') ?>
  <?php $selfEdit = false; include APP_ROOT . '/templates/partials/user_fields.php'; ?>
  <div class="form-actions">
    <button type="submit" class="button button-primary">Send invitation</button>
    <a class="button" href="<?= e(url('admin_users.php')) ?>">Cancel</a>
  </div>
</form>
