<?php
/**
 * @var \Pfpms\Http\Context $ctx  @var array<string, array{0:string,1:bool}> $lists  @var string $listKey  @var bool $speciesSpecific
 * @var list<array> $rows  @var list<array> $species  @var ?array $editing  @var array $values  @var array<string,string> $errors
 */
$listName = $lists[$listKey][0];
?>
<div class="page-head">
  <h1>Choice lists</h1>
</div>
<form method="get" action="<?= e(url('admin_lookups.php')) ?>" class="form form-narrow">
  <label for="list">List</label>
  <select id="list" name="list">
    <?php foreach ($lists as $key => [$name]): ?>
      <option value="<?= e($key) ?>"<?= selected($key === $listKey) ?>><?= e($name) ?></option>
    <?php endforeach; ?>
  </select>
  <div class="form-actions"><button type="submit" class="button">Show list</button></div>
</form>

<h2><?= e($listName) ?></h2>
<p class="hint">Forms offer the active values in this order. Changing the wording is safe: records keep pointing at the same value.
  Deactivate a value to stop offering it; records that already use it keep it.</p>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<?php if (!$rows): ?>
  <p class="meta">This list has no values yet. Add the first one below.</p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr>
      <th scope="col">Wording</th>
      <?php if ($speciesSpecific): ?><th scope="col">Species</th><?php endif; ?>
      <th scope="col">Code</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $row):
        $sameGroup = static fn(?array $other): bool => $other !== null && (int) ($other['species_id'] ?? 0) === (int) ($row['species_id'] ?? 0);
        $canUp = $sameGroup($rows[$i - 1] ?? null);
        $canDown = $sameGroup($rows[$i + 1] ?? null);
        $id = (int) $row['lookup_id']; ?>
      <tr<?= $row['is_active'] ? '' : ' class="status-inactive"' ?>>
        <td><?= e($row['label']) ?></td>
        <?php if ($speciesSpecific): ?><td><?= e($row['species_name']) ?></td><?php endif; ?>
        <td class="meta"><?= e($row['value_code']) ?></td>
        <td><?= $row['is_active'] ? 'Active' : '<span class="badge badge-inactive">Inactive</span>' ?></td>
        <td>
          <form method="post" action="<?= e(url('admin_lookups.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="list" value="<?= e($listKey) ?>">
            <input type="hidden" name="lookup_id" value="<?= $id ?>">
            <?php if ($canUp): ?><button type="submit" name="action" value="up" class="button">Move up<span class="visually-hidden">: <?= e($row['label']) ?></span></button><?php endif; ?>
            <?php if ($canDown): ?><button type="submit" name="action" value="down" class="button">Move down<span class="visually-hidden">: <?= e($row['label']) ?></span></button><?php endif; ?>
            <button type="submit" name="action" value="<?= $row['is_active'] ? 'deactivate' : 'activate' ?>" class="button">
              <?= $row['is_active'] ? 'Deactivate' : 'Activate' ?><span class="visually-hidden">: <?= e($row['label']) ?></span>
            </button>
          </form>
          <a class="button" href="<?= e(url('admin_lookups.php', ['list' => $listKey, 'edit' => $id])) ?>#value-form">Change wording<span class="visually-hidden">: <?= e($row['label']) ?></span></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php if ($editing): ?>
  <h2 id="value-form">Change the wording of "<?= e($editing['label']) ?>"</h2>
  <form method="post" action="<?= e(url('admin_lookups.php')) ?>" class="form form-narrow" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="list" value="<?= e($listKey) ?>">
    <input type="hidden" name="lookup_id" value="<?= (int) $editing['lookup_id'] ?>">
    <label for="label">Wording</label>
    <input type="text" <?= field_attrs($errors, 'label') ?> maxlength="100" value="<?= e($values['label']) ?>" required>
    <?= field_error($errors, 'label') ?>
    <p class="hint">The code "<?= e($editing['value_code']) ?>" stays the same, so records that use this value are not affected.</p>
    <div class="form-actions">
      <button type="submit" name="action" value="rename" class="button button-primary">Save wording</button>
      <a class="button" href="<?= e(url('admin_lookups.php', ['list' => $listKey])) ?>">Cancel</a>
    </div>
  </form>
<?php else: ?>
  <h2 id="value-form">Add a value</h2>
  <form method="post" action="<?= e(url('admin_lookups.php')) ?>" class="form form-narrow" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="list" value="<?= e($listKey) ?>">
    <label for="label">Wording</label>
    <input type="text" <?= field_attrs($errors, 'label') ?> maxlength="100" value="<?= e($values['label']) ?>" required>
    <?= field_error($errors, 'label') ?>
    <?php if ($speciesSpecific): ?>
      <label for="species_id">Species</label>
      <select <?= field_attrs($errors, 'species_id') ?> required>
        <option value="">Choose a species</option>
        <?php foreach ($species as $s): ?>
          <option value="<?= (int) $s['species_id'] ?>"<?= selected((string) $s['species_id'] === (string) $values['species_id']) ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?= field_error($errors, 'species_id') ?>
    <?php endif; ?>
    <p class="hint">New values go to the end of the list.</p>
    <div class="form-actions">
      <button type="submit" name="action" value="add" class="button button-primary">Add value</button>
    </div>
  </form>
<?php endif; ?>
