<?php
/** @var \Pfpms\Http\Context $ctx  @var array $filters  @var list<array> $categories  @var list<array> $products  @var list<array> $species */

$canManage = $ctx->can('catalog.manage');
$canLink = $ctx->can('catalog.barcode_link');
$byCategory = [];
foreach ($products as $p) {
    $byCategory[$p['category_name']][] = $p;
}
?>
<div class="page-head">
  <h1>Product catalogue</h1>
  <div>
    <?php if ($canLink): ?><a class="button" href="<?= e(url('inventory_barcode_link.php')) ?>">Link a barcode</a><?php endif; ?>
    <?php if ($canManage): ?>
      <a class="button" href="<?= e(url('inventory_category_edit.php')) ?>">Add a category</a>
      <a class="button button-primary" href="<?= e(url('inventory_product_edit.php')) ?>">Add a product</a>
    <?php endif; ?>
  </div>
</div>
<p class="lead">The food the pantry receives and gives out. Stock is kept in units (bags or cans); dry and wet food also have a
  weight per unit so allotments can be checked in pounds.</p>

<form method="get" action="<?= e(url('inventory_catalogue.php')) ?>" class="filters">
  <div class="form-grid">
    <div>
      <label for="q">Name or brand</label>
      <input type="text" id="q" name="q" value="<?= e($filters['q'] ?? '') ?>">
    </div>
    <div>
      <label for="species_id">Species</label>
      <select id="species_id" name="species_id">
        <option value="">Any species</option>
        <?php foreach ($species as $s): ?>
          <option value="<?= (int) $s['species_id'] ?>"<?= selected((int) $s['species_id'] === (int) $filters['species_id']) ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="food_form">Food</label>
      <select id="food_form" name="food_form">
        <option value="">Any</option>
        <?php foreach (['Dry', 'Wet', 'Treat', 'Other'] as $form): ?>
          <option value="<?= e($form) ?>"<?= selected($filters['food_form'] === $form) ?>><?= e($form) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="status">Show</label>
      <select id="status" name="status">
        <?php foreach (['active' => 'Active products', 'inactive' => 'Inactive products', 'all' => 'All products'] as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= selected($filters['status'] === $value) ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-actions"><button type="submit" class="button">Show</button> <a class="button" href="<?= e(url('inventory_catalogue.php')) ?>">Clear</a></div>
</form>

<section>
  <h2>Products</h2>
  <?php if (!$products): ?>
    <p class="meta">No products match.<?= $canManage && !$categories ? ' Add a category first, then its products.' : '' ?></p>
  <?php endif; ?>
  <?php foreach ($byCategory as $categoryName => $rows): ?>
    <h3><?= e($categoryName) ?></h3>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Product</th><th scope="col">Species</th><th scope="col">Food</th><th scope="col">Weight per unit</th><th scope="col">Barcodes</th>
        <?php if ($canManage): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $p): ?>
        <tr<?= (int) $p['is_active'] ? '' : ' class="status-inactive"' ?>>
          <th scope="row"><?= e($p['name']) ?><?= $p['brand'] !== null ? ' <span class="meta">' . e($p['brand']) . '</span>' : '' ?>
            <?= (int) $p['is_active'] ? '' : ' <span class="badge badge-inactive">Inactive</span>' ?></th>
          <td><?= e($p['species_name']) ?></td>
          <td><?= e($p['food_form']) ?></td>
          <td><?= $p['unit_weight_lbs'] !== null ? e($p['unit_weight_lbs']) . ' lb' : '<span class="meta">none</span>' ?></td>
          <td><?= (int) $p['barcodes'] ?></td>
          <?php if ($canManage): ?><td><a class="button" href="<?= e(url('inventory_product_edit.php', ['id' => $p['product_id']])) ?>">Edit</a></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endforeach; ?>
</section>

<section>
  <h2>Categories</h2>
  <?php if (!$categories): ?>
    <p class="meta">No categories yet.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Category</th><th scope="col">Units per case</th><th scope="col">Banana boxes</th><th scope="col">Active products</th>
        <?php if ($canManage): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($categories as $c): ?>
        <tr<?= $c['status'] === 'Active' ? '' : ' class="status-inactive"' ?>>
          <th scope="row"><?= e($c['name']) ?><?= $c['status'] === 'Active' ? '' : ' <span class="badge badge-inactive">Inactive</span>' ?></th>
          <td><?= (int) $c['units_per_case'] === 1 ? '<span class="meta">single units</span>' : (int) $c['units_per_case'] ?></td>
          <td><?= (int) $c['is_banana_box'] ? 'Yes' : '<span class="meta">No</span>' ?></td>
          <td><?= (int) $c['active_products'] ?></td>
          <?php if ($canManage): ?><td><a class="button" href="<?= e(url('inventory_category_edit.php', ['id' => $c['category_id']])) ?>">Edit</a></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>
