<?php
/** @var ?array $band  @var array $values  @var array<string,string> $errors  @var ?array $species  @var list<array> $speciesChoices
 *  @var bool $pictureChosen  @var bool $removePicture  @var int $maxMb */
?>
<h1><?= $band ? 'Edit ' . e($band['name']) . ($species ? ' (' . e($species['name']) . ')' : '') : 'Add a size band' ?></h1>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('admin_size_band_edit.php')) ?>" enctype="multipart/form-data" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <?php if ($band): ?>
    <input type="hidden" name="size_band_id" value="<?= (int) $band['size_band_id'] ?>">
    <dl class="readonly-list"><dt>Species</dt><dd><?= e($species['name'] ?? '') ?></dd></dl>
  <?php else: ?>
    <label for="species_id">Species</label>
    <select <?= field_attrs($errors, 'species_id') ?> required>
      <option value="">Choose a species</option>
      <?php foreach ($speciesChoices as $s): ?>
        <option value="<?= (int) $s['species_id'] ?>"<?= selected((string) $s['species_id'] === (string) $values['species_id']) ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?= field_error($errors, 'species_id') ?>
  <?php endif; ?>

  <label for="name">Name</label>
  <input type="text" <?= field_attrs($errors, 'name') ?> maxlength="20" value="<?= e($values['name']) ?>" required>
  <?= field_error($errors, 'name') ?>
  <p class="hint">For example Small, Medium or Large.</p>

  <div class="form-grid">
    <div>
      <label for="min_weight_lbs">From (lb)</label>
      <input type="text" <?= field_attrs($errors, 'min_weight_lbs') ?> inputmode="decimal" maxlength="5" value="<?= e($values['min_weight_lbs']) ?>" required>
      <?= field_error($errors, 'min_weight_lbs') ?>
    </div>
    <div>
      <label for="max_weight_lbs">Up to, but not including (lb)</label>
      <input type="text" <?= field_attrs($errors, 'max_weight_lbs') ?> inputmode="decimal" maxlength="5" value="<?= e($values['max_weight_lbs']) ?>">
      <?= field_error($errors, 'max_weight_lbs') ?>
    </div>
  </div>
  <p class="hint">A pet weighing exactly the upper weight belongs to the next band. Leave "Up to" blank for the heaviest band, which has no upper limit.</p>

  <?php if ($band && $band['picture_path'] !== null): ?>
    <p>Current picture:</p>
    <img class="thumb" src="<?= e(url('file.php', ['kind' => 'size_band', 'id' => $band['size_band_id']])) ?>" alt="Current picture for <?= e($band['name']) ?>" width="160">
    <label class="checkbox"><input type="checkbox" name="remove_picture" value="1"<?= selected($removePicture, 'checked') ?>> Remove the picture</label>
  <?php endif; ?>

  <label for="picture"><?= $band && $band['picture_path'] !== null ? 'Replace the picture' : 'Picture' ?></label>
  <input type="hidden" name="MAX_FILE_SIZE" value="<?= $maxMb * 1048576 ?>">
  <input type="file" <?= field_attrs($errors, 'picture') ?> accept="image/jpeg,image/png,image/webp">
  <?= field_error($errors, 'picture') ?>
  <p class="hint">A JPEG, PNG or WebP picture of up to <?= $maxMb ?> MB that shows a typical pet of this size. Large pictures are made smaller.</p>
  <?php if ($errors && $pictureChosen && !isset($errors['picture'])): ?>
    <p class="hint">Please choose the picture again: it is not kept when something else needs correcting.</p>
  <?php endif; ?>

  <div class="form-actions">
    <button type="submit" class="button button-primary"><?= $band ? 'Save size band' : 'Add size band' ?></button>
    <a class="button" href="<?= e(url('admin_size_bands.php')) ?>">Cancel</a>
  </div>
</form>
