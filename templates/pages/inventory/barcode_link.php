<?php
/** @var \Pfpms\Http\Context $ctx  @var string $raw  @var array<string,string> $errors  @var ?int $productId  @var ?string $code
 *  @var ?array $linked  @var list<array> $products */
use Pfpms\Inventory\Barcode;
use Pfpms\Inventory\ProductRepository;

$byCategory = [];
foreach ($products as $p) {
    $byCategory[$p['category_name']][] = $p;
}
?>
<h1>Link a barcode</h1>
<p class="lead">When a bag or can is not recognised, link its barcode to the product it is. It is then recognised at every site.</p>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>

<form method="get" action="<?= e(url('inventory_barcode_link.php')) ?>" class="form form-narrow" novalidate>
  <label for="code">Barcode</label>
  <input type="text" <?= field_attrs($errors, 'code') ?> maxlength="40" autocomplete="off" value="<?= isset($errors['code']) ? e($raw) : '' ?>" autofocus>
  <?= field_error($errors, 'code') ?>
  <p class="hint">Scan it, or type the digits under the bars including the last one. The box is cleared after each look-up, ready for the next scan.</p>
  <div class="form-actions"><button type="submit" class="button">Look it up</button></div>
</form>

<?php if ($code !== null && $linked !== null): ?>
  <div class="flash flash-info" role="status">
    <strong><?= e(Barcode::display($code)) ?></strong> is <?= e(ProductRepository::label($linked)) ?> (<?= e($linked['category_name']) ?>).
    <?php if ($ctx->can('catalog.manage')): ?>
      <a href="<?= e(url('inventory_product_edit.php', ['id' => $linked['product_id']])) ?>">Open the product</a> to remove or move the barcode if that is wrong.
    <?php else: ?>
      If that is wrong, ask an Administrator to move it.
    <?php endif; ?>
  </div>
<?php elseif ($code !== null): ?>
  <section>
    <h2><?= e(Barcode::display($code)) ?> is not linked yet</h2>
    <?php if (Barcode::storeSpecific($code)): ?>
      <p class="flash flash-info">This is an in-store code: another shop may use it for a different product.</p>
    <?php endif; ?>
    <?php if (!$products): ?>
      <p class="meta">There are no active products to link it to. An Administrator adds products in the catalogue.</p>
    <?php else: ?>
      <form method="post" action="<?= e(url('inventory_barcode_link.php')) ?>" class="form form-narrow" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="code" value="<?= e($raw) ?>">
        <label for="product_id">It is</label>
        <select <?= field_attrs($errors, 'product_id') ?> required>
          <option value="">Choose the product</option>
          <?php foreach ($byCategory as $categoryName => $rows): ?>
            <optgroup label="<?= e($categoryName) ?>">
              <?php foreach ($rows as $p): ?>
                <option value="<?= (int) $p['product_id'] ?>"<?= selected((int) $p['product_id'] === $productId) ?>><?= e(ProductRepository::label($p)) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <?= field_error($errors, 'product_id') ?>
        <p class="hint">Not in the list? An Administrator adds it to the catalogue first.</p>
        <div class="form-actions"><button type="submit" class="button button-primary">Link barcode</button></div>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>
