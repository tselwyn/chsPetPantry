<?php /** @var list<string> $errors */ ?>
<?php if (!empty($errors)): ?>
  <div class="flash flash-error" role="alert">
    <?php if (count($errors) === 1): ?>
      <?= e($errors[0]) ?>
    <?php else: ?>
      <p>Please correct the following:</p>
      <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </div>
<?php endif; ?>
