<?php
/** @var \Pfpms\Http\Context $ctx  @var ?array $category  @var array $values  @var array<string,string> $errors  @var string $revision  @var int $maxUnits */
?>
<h1><?= $category ? 'Edit ' . e($category['name']) : 'Add a category' ?></h1>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('inventory_category_edit.php', $category ? ['id' => $category['category_id']] : [])) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <?php if ($category): ?>
    <input type="hidden" name="category_id" value="<?= (int) $category['category_id'] ?>">
    <input type="hidden" name="revision" value="<?= e($revision) ?>">
  <?php endif; ?>
  <label for="name">Name</label>
  <input type="text" <?= field_attrs($errors, 'name') ?> maxlength="50" value="<?= e($values['name']) ?>" required>
  <?= field_error($errors, 'name') ?>
  <p class="hint">For example Dry dog food. Each product also has its own species and food form.</p>

  <label for="units_per_case">Units per case</label>
  <input type="text" inputmode="numeric" <?= field_attrs($errors, 'units_per_case') ?> maxlength="3" value="<?= e((string) $values['units_per_case']) ?>" required>
  <?= field_error($errors, 'units_per_case') ?>
  <p class="hint">How many bags or cans come in one case, so goods received and stock counts can be entered as cases plus
    loose units. Use 1 when this food comes singly. Stock is always kept in units.</p>

  <label class="checkbox"><input type="checkbox" name="is_banana_box" value="1"<?= selected(!empty($values['is_banana_box']), 'checked') ?>>
    Arrives or is stored in banana boxes (mixed boxes rather than manufacturer cases)</label>

  <div class="form-actions">
    <button type="submit" name="action" value="save" class="button button-primary"><?= $category ? 'Save category' : 'Add category' ?></button>
    <a class="button" href="<?= e(url('inventory_catalogue.php')) ?>">Back to the catalogue</a>
  </div>
</form>

<?php if ($category): ?>
  <form method="post" action="<?= e(url('inventory_category_edit.php', ['id' => $category['category_id']])) ?>" class="form-actions">
    <?= csrf_field() ?>
    <input type="hidden" name="category_id" value="<?= (int) $category['category_id'] ?>">
    <?php if ($category['status'] === 'Active'): ?>
      <button type="submit" name="action" value="deactivate" class="button">Deactivate this category</button>
      <span class="hint">Only a category with no active products can be deactivated.</span>
    <?php else: ?>
      <button type="submit" name="action" value="activate" class="button">Reactivate this category</button>
    <?php endif; ?>
  </form>
<?php endif; ?>
