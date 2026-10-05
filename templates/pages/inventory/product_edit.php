<?php
/** @var \Pfpms\Http\Context $ctx  @var ?array $product  @var array $values  @var array<string,string> $errors  @var array $barcodeValues
 *  @var ?string $confirmDeactivate  @var string $revision  @var list<array> $categories  @var list<array> $species  @var bool $used
 *  @var list<array> $barcodes  @var list<array> $holdings  @var list<array> $moveTargets */
use Pfpms\Inventory\Barcode;
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;

$formLabels = ['Dry' => 'Dry food', 'Wet' => 'Wet food', 'Treat' => 'Treats', 'Other' => 'Other'];
?>
<h1><?= $product ? 'Edit ' . e(ProductRepository::label($product)) : 'Add a product' ?></h1>
<?php $barcodeForm = $barcodeValues['barcode'] !== null; // a barcode row was posted: its message is shown with the barcodes ?>
<?php if (isset($errors['_form']) && !$barcodeForm): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>

<form method="post" action="<?= e(url('inventory_product_edit.php', $product ? ['id' => $product['product_id']] : [])) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <?php if ($product): ?>
    <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
    <input type="hidden" name="revision" value="<?= e($revision) ?>">
  <?php endif; ?>
  <label for="category_id">Category</label>
  <select <?= field_attrs($errors, 'category_id') ?> required>
    <option value="">Choose a category</option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= (int) $c['category_id'] ?>"<?= selected((string) $c['category_id'] === (string) $values['category_id']) ?>><?= e($c['name']) ?><?= $c['status'] === 'Active' ? '' : ' (inactive)' ?></option>
    <?php endforeach; ?>
  </select>
  <?= field_error($errors, 'category_id') ?>

  <label for="name">Name</label>
  <input type="text" <?= field_attrs($errors, 'name') ?> maxlength="100" value="<?= e($values['name']) ?>" required>
  <?= field_error($errors, 'name') ?>

  <label for="brand">Brand (optional)</label>
  <input type="text" <?= field_attrs($errors, 'brand') ?> maxlength="60" value="<?= e($values['brand']) ?>">
  <?= field_error($errors, 'brand') ?>

  <div class="form-grid">
    <div>
      <label for="species_id">For</label>
      <select <?= field_attrs($errors, 'species_id') ?> required>
        <option value="">Choose a species</option>
        <?php foreach ($species as $s): ?>
          <option value="<?= (int) $s['species_id'] ?>"<?= selected((string) $s['species_id'] === (string) $values['species_id']) ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?= field_error($errors, 'species_id') ?>
    </div>
    <div>
      <label for="food_form">Food</label>
      <select <?= field_attrs($errors, 'food_form') ?> required>
        <option value="">Choose</option>
        <?php foreach (ProductService::FORMS as $form): ?>
          <option value="<?= e($form) ?>"<?= selected($values['food_form'] === $form) ?>><?= e($formLabels[$form]) ?></option>
        <?php endforeach; ?>
      </select>
      <?= field_error($errors, 'food_form') ?>
    </div>
  </div>
  <?php if ($used): ?><p class="hint">This product has been received, counted or given out, so its species and food cannot change.</p><?php endif; ?>

  <div class="form-grid">
    <div>
      <label for="unit_weight">Weight of one bag or can</label>
      <input type="text" inputmode="decimal" <?= field_attrs($errors, 'unit_weight') ?> maxlength="7" value="<?= e((string) $values['unit_weight']) ?>">
      <?= field_error($errors, 'unit_weight') ?>
    </div>
    <div>
      <label for="weight_unit">In</label>
      <select id="weight_unit" name="weight_unit">
        <option value="lb"<?= selected(($values['weight_unit'] ?? 'lb') !== 'oz') ?>>pounds (lb)</option>
        <option value="oz"<?= selected(($values['weight_unit'] ?? 'lb') === 'oz') ?>>ounces (oz)</option>
      </select>
    </div>
  </div>
  <p class="hint">Needed for dry and wet food: allotments are in pounds. Cans are usually labelled in ounces (a 5.5 oz can is kept as 0.34 lb).
    Changing the weight later does not change food already given out.</p>

  <div class="form-actions">
    <button type="submit" name="action" value="save" class="button button-primary"><?= $product ? 'Save product' : 'Add product' ?></button>
    <a class="button" href="<?= e(url('inventory_catalogue.php')) ?>">Back to the catalogue</a>
  </div>
</form>

<?php if ($product): ?>
  <section id="barcodes">
    <h2>Barcodes</h2>
    <?php if (isset($errors['_form']) && $barcodeForm): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
    <p class="hint">A barcode linked here is recognised at every site. Scan it or type the digits under the bars.</p>
    <?php if (!$barcodes): ?><p class="meta">No barcodes yet.</p><?php endif; ?>
    <?php foreach ($barcodes as $b):
        $mine = $barcodeValues['barcode'] === $b['barcode']; // the row the last post was about: its errors and typed values
        $rid = 'reason-' . $b['barcode'];
        $tid = 'to-' . $b['barcode'];
        $rowErrors = $mine ? array_filter([$rid => $errors['reason'] ?? null, $tid => $errors['to_product_id'] ?? null]) : []; ?>
      <div class="barcode-row">
        <p><strong><?= e(Barcode::display($b['barcode'])) ?></strong>
          <span class="meta">linked by <?= e($b['linked_by_name']) ?> on <?= e(local_time($b['linked_at'], $ctx->site()['time_zone'] ?? 'America/New_York')) ?></span></p>
        <form method="post" action="<?= e(url('inventory_product_edit.php', ['id' => $product['product_id']])) ?>#barcodes" class="form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
          <input type="hidden" name="barcode" value="<?= e($b['barcode']) ?>">
          <div>
            <label for="<?= e($rid) ?>">Reason (needed to remove or move it)</label>
            <input type="text" id="<?= e($rid) ?>" name="reason" maxlength="255" value="<?= $mine ? e($barcodeValues['reason']) : '' ?>"
              <?= isset($rowErrors[$rid]) ? 'aria-invalid="true" aria-describedby="' . e($rid) . '-error"' : '' ?>>
            <?= field_error($rowErrors, $rid) ?>
          </div>
          <div>
            <label for="<?= e($tid) ?>">Move to</label>
            <select id="<?= e($tid) ?>" name="to_product_id"<?= isset($rowErrors[$tid]) ? ' aria-invalid="true" aria-describedby="' . e($tid) . '-error"' : '' ?>>
              <option value="">Choose a product</option>
              <?php foreach ($moveTargets as $t): ?>
                <option value="<?= (int) $t['product_id'] ?>"<?= selected($mine && $barcodeValues['to_product_id'] === (int) $t['product_id']) ?>><?= e($t['category_name'] . ': ' . ProductRepository::label($t)) ?></option>
              <?php endforeach; ?>
            </select>
            <?= field_error($rowErrors, $tid) ?>
          </div>
          <div class="form-actions">
            <button type="submit" name="action" value="move" class="button">Move</button>
            <button type="submit" name="action" value="unlink" class="button">Remove</button>
          </div>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if ((int) $product['is_active']): ?>
      <form method="post" action="<?= e(url('inventory_product_edit.php', ['id' => $product['product_id']])) ?>#barcodes" class="form form-narrow" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
        <label for="code">Add a barcode</label>
        <input type="text" <?= field_attrs($errors, 'code') ?> maxlength="40" autocomplete="off" value="<?= e($barcodeValues['code']) ?>">
        <?= field_error($errors, 'code') ?>
        <div class="form-actions"><button type="submit" name="action" value="link" class="button">Link barcode</button></div>
      </form>
    <?php endif; ?>
  </section>

  <section>
    <h2><?= (int) $product['is_active'] ? 'Stop receiving this product' : 'Receive this product again' ?></h2>
    <?php if ($holdings): ?>
      <p>In stock: <?= e(implode(', ', array_map(fn($h) => $h['site_name'] . ' ' . ProductService::quantity($h['quantity_on_hand']), $holdings))) ?>.</p>
    <?php endif; ?>
    <?php if ($confirmDeactivate !== null): ?><div class="flash flash-info" role="status"><?= e($confirmDeactivate) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('inventory_product_edit.php', ['id' => $product['product_id']])) ?>" class="form-actions">
      <?= csrf_field() ?>
      <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
      <?php if ((int) $product['is_active']): ?>
        <?php if ($holdings): ?><input type="hidden" name="confirm" value="<?= $confirmDeactivate !== null ? '1' : '0' ?>"><?php endif; ?>
        <button type="submit" name="action" value="deactivate" class="button"><?= $confirmDeactivate !== null ? 'Yes, deactivate it' : 'Deactivate this product' ?></button>
        <span class="hint">It will no longer appear on new receipts or barcode links. Any stock can still be given out and counted.</span>
      <?php else: ?>
        <button type="submit" name="action" value="activate" class="button">Reactivate this product</button>
      <?php endif; ?>
    </form>
  </section>
<?php endif; ?>
