<?php
/** @var \Pfpms\Http\Context $ctx  @var list<array> $allSpecies  @var ?array $species  @var list<array> $breeds  @var array $addValues
 *  @var array<string,string> $addErrors  @var ?int $editId  @var ?string $editValue  @var array<string,string> $editErrors */
$pageUrl = $species ? url('admin_breeds.php', ['species_id' => $species['species_id']]) : url('admin_breeds.php');
?>
<div class="page-head">
  <h1><?= $species ? 'Breeds of ' . e($species['name']) : 'Breeds' ?></h1>
</div>

<?php if (!$allSpecies): ?>
  <p class="meta">There are no species yet. <a href="<?= e(url('admin_species.php')) ?>">Add a species</a> first.</p>
<?php else: ?>
  <form method="get" action="<?= e(url('admin_breeds.php')) ?>" class="form form-narrow">
    <label for="species_id">Species</label>
    <select id="species_id" name="species_id">
      <?php foreach ($allSpecies as $s): ?>
        <option value="<?= (int) $s['species_id'] ?>"<?= selected($species !== null && (int) $s['species_id'] === (int) $species['species_id']) ?>>
          <?= e($s['name']) ?><?= $s['is_active'] ? '' : ' (inactive)' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-actions">
      <button type="submit" class="button">Show breeds</button>
    </div>
  </form>
<?php endif; ?>

<?php if ($species): ?>
  <p class="meta">Deactivating a breed stops it being offered for new pets. Pets already recorded keep it, and volunteers can still type a breed that is not listed.</p>

  <?php if (!$breeds): ?>
    <p class="meta">No breeds listed for <?= e($species['name']) ?> yet.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Breed</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($breeds as $b): ?>
        <tr<?= $b['is_active'] ? '' : ' class="status-inactive"' ?>>
          <td>
            <?php if ((int) $b['breed_id'] === $editId): ?>
              <form method="post" action="<?= e($pageUrl) ?>" class="inline-form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="breed_id" value="<?= (int) $b['breed_id'] ?>">
                <label for="edit_name" class="visually-hidden">New name for <?= e($b['name']) ?></label>
                <input type="text" <?= field_attrs($editErrors, 'edit_name') ?> maxlength="60" value="<?= e($editValue ?? $b['name']) ?>" required autofocus>
                <?= field_error($editErrors, 'edit_name') ?>
                <button type="submit" name="action" value="rename" class="button button-primary">Save name</button>
                <a class="button" href="<?= e($pageUrl) ?>">Cancel</a>
              </form>
            <?php else: ?>
              <?= e($b['name']) ?>
            <?php endif; ?>
          </td>
          <td><?= $b['is_active'] ? 'Active' : '<span class="badge badge-inactive">Inactive</span>' ?></td>
          <td>
            <?php if ((int) $b['breed_id'] !== $editId): ?>
              <a class="button" href="<?= e(url('admin_breeds.php', ['species_id' => $species['species_id'], 'edit' => $b['breed_id']])) ?>">Rename</a>
            <?php endif; ?>
            <form method="post" action="<?= e($pageUrl) ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="breed_id" value="<?= (int) $b['breed_id'] ?>">
              <button type="submit" name="action" value="<?= $b['is_active'] ? 'deactivate' : 'activate' ?>" class="button">
                <?= $b['is_active'] ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <h2>Add a <?= e($species['name']) ?> breed</h2>
  <form method="post" action="<?= e($pageUrl) ?>" class="form form-narrow" novalidate>
    <?= csrf_field() ?>
    <label for="name">Breed name</label>
    <input type="text" <?= field_attrs($addErrors, 'name') ?> maxlength="60" value="<?= e($addValues['name']) ?>" required>
    <?= field_error($addErrors, 'name') ?>
    <div class="form-actions">
      <button type="submit" name="action" value="add" class="button button-primary">Add breed</button>
    </div>
  </form>
<?php endif; ?>
