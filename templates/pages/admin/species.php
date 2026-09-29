<?php
/** @var \Pfpms\Http\Context $ctx  @var list<array> $species  @var array $addValues  @var array<string,string> $addErrors
 *  @var ?int $editId  @var ?string $editValue  @var array<string,string> $editErrors */
?>
<div class="page-head">
  <h1>Species</h1>
</div>
<p class="meta">Deactivating a species stops it being offered for new pets and products. Pets already recorded keep it, and its breeds and size bands are kept.</p>

<?php if (!$species): ?>
  <p class="meta">No species yet. Add the first one below.</p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">Name</th><th scope="col">Breeds</th><th scope="col">Size bands</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($species as $s): ?>
      <tr<?= $s['is_active'] ? '' : ' class="status-inactive"' ?>>
        <td>
          <?php if ((int) $s['species_id'] === $editId): ?>
            <form method="post" action="<?= e(url('admin_species.php')) ?>" class="inline-form" novalidate>
              <?= csrf_field() ?>
              <input type="hidden" name="species_id" value="<?= (int) $s['species_id'] ?>">
              <label for="edit_name" class="visually-hidden">New name for <?= e($s['name']) ?></label>
              <input type="text" <?= field_attrs($editErrors, 'edit_name') ?> maxlength="30" value="<?= e($editValue ?? $s['name']) ?>" required autofocus>
              <?= field_error($editErrors, 'edit_name') ?>
              <button type="submit" name="action" value="rename" class="button button-primary">Save name</button>
              <a class="button" href="<?= e(url('admin_species.php')) ?>">Cancel</a>
            </form>
          <?php else: ?>
            <?= e($s['name']) ?>
          <?php endif; ?>
        </td>
        <td><a href="<?= e(url('admin_breeds.php', ['species_id' => $s['species_id']])) ?>"><?= (int) $s['breed_count'] ?> <?= (int) $s['breed_count'] === 1 ? 'breed' : 'breeds' ?></a></td>
        <td><a href="<?= e(url('admin_size_bands.php')) ?>"><?= (int) $s['size_band_count'] ?> <?= (int) $s['size_band_count'] === 1 ? 'size band' : 'size bands' ?></a></td>
        <td><?= $s['is_active'] ? 'Active' : '<span class="badge badge-inactive">Inactive</span>' ?></td>
        <td>
          <?php if ((int) $s['species_id'] !== $editId): ?>
            <a class="button" href="<?= e(url('admin_species.php', ['edit' => $s['species_id']])) ?>">Rename</a>
          <?php endif; ?>
          <form method="post" action="<?= e(url('admin_species.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="species_id" value="<?= (int) $s['species_id'] ?>">
            <button type="submit" name="action" value="<?= $s['is_active'] ? 'deactivate' : 'activate' ?>" class="button">
              <?= $s['is_active'] ? 'Deactivate' : 'Activate' ?>
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<h2>Add a species</h2>
<form method="post" action="<?= e(url('admin_species.php')) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <label for="name">Species name</label>
  <input type="text" <?= field_attrs($addErrors, 'name') ?> maxlength="30" value="<?= e($addValues['name']) ?>" required>
  <?= field_error($addErrors, 'name') ?>
  <div class="form-actions">
    <button type="submit" name="action" value="add" class="button button-primary">Add species</button>
  </div>
</form>
