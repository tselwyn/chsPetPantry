<?php
/** @var \Pfpms\Http\Context $ctx  @var array $filters  @var list<array> $stock  @var list<array> $categories  @var list<array> $species  @var ?array $discrepancies */
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;

$site = $ctx->site();
$byCategory = [];
foreach ($stock as $row) {
    $byCategory[$row['category_name']][] = $row;
}
$problems = $discrepancies !== null ? count($discrepancies['mismatches']) + count($discrepancies['orphans']) : 0;
?>
<div class="page-head">
  <h1>Stock on hand at <?= e($site['name'] ?? 'this site') ?></h1>
  <div>
    <?php if ($ctx->can('inventory.receive')): ?><a class="button" href="<?= e(url('inventory_receipt_edit.php')) ?>">Record goods received</a><?php endif; ?>
    <?php if ($ctx->can('inventory.count')): ?><a class="button" href="<?= e(url('inventory_count.php')) ?>">Count stock</a><?php endif; ?>
  </div>
</div>

<?php if ($problems > 0): ?>
  <div class="flash flash-error banner-warning" role="alert">The stock of <?= $problems ?> product<?= $problems === 1 ? '' : 's' ?> at this site does not add up to
    its history. This is a fault in the records, not on the shelves: report it to the system's maintainers. A stock count puts the stock figure
    right, but this warning stays until the records are repaired.
    <ul><?php foreach (array_merge($discrepancies['mismatches'], $discrepancies['orphans']) as $d):
        $p = ProductRepository::find((int) $d['product_id']); ?>
      <li><?= e($p ? ProductRepository::label($p) : 'Product ' . (int) $d['product_id']) ?>: stock <?= e(ProductService::quantity((string) ($d['quantity_on_hand'] ?? '0'))) ?>,
        history adds up to <?= e(ProductService::quantity((string) $d['ledger_total'])) ?></li>
    <?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="get" action="<?= e(url('inventory_stock.php')) ?>" class="filters">
  <div class="form-grid">
    <div>
      <label for="q">Name or brand</label>
      <input type="text" id="q" name="q" value="<?= e($filters['q'] ?? '') ?>">
    </div>
    <div>
      <label for="category_id">Category</label>
      <select id="category_id" name="category_id">
        <option value="">Any category</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['category_id'] ?>"<?= selected((int) $c['category_id'] === (int) $filters['category_id']) ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
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
        <?php foreach (ProductService::FORMS as $form): ?>
          <option value="<?= e($form) ?>"<?= selected($filters['food_form'] === $form) ?>><?= e($form) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <label class="checkbox"><input type="checkbox" name="in_stock" value="1"<?= selected($filters['in_stock'], 'checked') ?>> Only products in stock</label>
  <div class="form-actions"><button type="submit" class="button">Show</button> <a class="button" href="<?= e(url('inventory_stock.php')) ?>">Clear</a></div>
</form>

<?php if (!$stock): ?><p class="meta">No products match.</p><?php endif; ?>
<?php foreach ($byCategory as $categoryName => $rows): ?>
  <h2><?= e($categoryName) ?></h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">Product</th><th scope="col">Species</th><th scope="col">Food</th><th scope="col" class="num">In stock</th>
      <th scope="col">Last counted</th><th scope="col">Nearest best-before</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $qty = (string) $r['quantity_on_hand']; $perCase = (int) $r['units_per_case']; ?>
      <tr<?= (int) $r['is_active'] ? '' : ' class="status-inactive"' ?>>
        <th scope="row"><a href="<?= e(url('inventory_stock.php', ['product_id' => $r['product_id']])) ?>"><?= e($r['name']) ?></a>
          <?= $r['brand'] !== null ? '<span class="meta">' . e($r['brand']) . '</span>' : '' ?>
          <?= $r['unit_weight_lbs'] !== null ? '<span class="meta">' . e(ProductService::quantity((string) $r['unit_weight_lbs'])) . ' lb</span>' : '' ?>
          <?= (int) $r['is_active'] ? '' : ' <span class="badge badge-inactive">Inactive</span>' ?></th>
        <td><?= e($r['species_name']) ?></td>
        <td><?= e($r['food_form']) ?></td>
        <td class="num<?= $qty[0] === '-' ? ' change-large' : '' ?>"><?= e(ProductService::quantity($qty)) ?>
          <?php if ($perCase > 1 && (float) $qty >= $perCase): ?><br><span class="meta">≈ <?= intdiv((int) $qty, $perCase) ?> cases</span><?php endif; ?></td>
        <td><?= $r['last_counted'] !== null ? e($r['last_counted']) : '<span class="meta">never</span>' ?></td>
        <td><?= $r['shelf_expiry'] !== null ? e($r['shelf_expiry']) : '<span class="meta">none</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endforeach; ?>
<p class="hint">A negative figure means tablets gave out more than the records showed: count that product to correct it.</p>
