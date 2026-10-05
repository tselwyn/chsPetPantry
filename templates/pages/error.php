<?php /** @var int $status  @var string $message  @var ?string $incident */ ?>
<h1><?= $status === 404 ? 'Not found' : ($status === 403 ? 'Not allowed' : 'Something went wrong') ?></h1>
<div class="flash flash-error" role="alert"><?= e($message) ?></div>
<?php if ($incident): ?>
  <p class="meta">If this keeps happening, tell an Administrator this reference: <strong><?= e($incident) ?></strong></p>
<?php endif; ?>
<p class="public-links"><a href="<?= e(url('index.php')) ?>">Go to the home page</a></p>
