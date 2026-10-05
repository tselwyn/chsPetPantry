<h1>Sign out</h1>
<form method="post" action="<?= e(url('logout.php')) ?>" class="form-actions">
  <?= csrf_field() ?>
  <button type="submit" class="button button-primary">Sign out now</button>
  <a class="button" href="<?= e(url('index.php')) ?>">Cancel</a>
</form>
