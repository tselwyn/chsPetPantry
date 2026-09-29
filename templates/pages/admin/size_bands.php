<?php
/** @var \Pfpms\Http\Context $ctx  @var list<array> $species  @var array<int, list<array>> $bandsBySpecies */
use Pfpms\Reference\SizeBandService;
?>
<div class="page-head">
  <h1>Size bands</h1>
</div>
<p class="meta">A pet's size band decides its food allotment. Each range includes its lower weight but not its upper one,
  so bands can meet without overlapping: a 25 lb dog is in "25 to under 60 lb", not "0 to under 25 lb".</p>

<?php if (!$species): ?>
  <p class="meta">There are no species yet. <a href="<?= e(url('admin_species.php')) ?>">Add a species</a> first.</p>
<?php endif; ?>

<?php foreach ($species as $s): ?>
  <?php $bands = $bandsBySpecies[(int) $s['species_id']] ?? []; ?>
  <section>
    <div class="page-head">
      <h2><?= e($s['name']) ?><?= $s['is_active'] ? '' : ' <span class="badge badge-inactive">Inactive</span>' ?></h2>
      <a class="button" href="<?= e(url('admin_size_band_edit.php', ['species_id' => $s['species_id']])) ?>">Add a <?= e($s['name']) ?> size band</a>
    </div>
    <?php if (!$bands): ?>
      <p class="meta">No size bands for <?= e($s['name']) ?> yet.</p>
    <?php else: ?>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Picture</th><th scope="col">Name</th><th scope="col">Weight</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($bands as $b): ?>
          <tr>
            <td>
              <?php if ($b['picture_path'] !== null): ?>
                <img class="thumb" src="<?= e(url('file.php', ['kind' => 'size_band', 'id' => $b['size_band_id']])) ?>" alt="Picture for <?= e($b['name']) ?>" width="64" loading="lazy">
              <?php else: ?>
                <span class="meta">No picture</span>
              <?php endif; ?>
            </td>
            <td><a href="<?= e(url('admin_size_band_edit.php', ['id' => $b['size_band_id']])) ?>"><?= e($b['name']) ?></a></td>
            <td><?= e(SizeBandService::rangeLabel($b['min_weight_lbs'], $b['max_weight_lbs'])) ?></td>
            <td>
              <a class="button" href="<?= e(url('admin_size_band_edit.php', ['id' => $b['size_band_id']])) ?>">Edit</a>
              <?php if ($b['in_use']): ?>
                <span class="meta">In use, so it cannot be deleted</span>
              <?php else: ?>
                <form method="post" action="<?= e(url('admin_size_bands.php')) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="size_band_id" value="<?= (int) $b['size_band_id'] ?>">
                  <button type="submit" name="action" value="delete" class="button">Delete</button>
                </form>
                <?php if (!empty($b['in_draft'])): ?><span class="meta">Deleting also removes it from the draft allotment version.</span><?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php $gaps = SizeBandService::gaps($bands); ?>
      <?php if ($gaps): ?>
        <p class="hint">Weights no <?= e($s['name']) ?> size band covers: <?= e(implode('; ', $gaps)) ?>. A pet of those weights cannot be given a band.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
