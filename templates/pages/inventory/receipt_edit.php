<?php
/** @var \Pfpms\Http\Context $ctx  @var ?array $receipt  @var array $header  @var array<int, array> $rows  @var int $rowCount  @var int $maxRows
 *  @var string $stage  @var ?array $plan  @var array<string, string> $decisions  @var array<string, string> $errors
 *  @var array<string, string> $headerErrors  @var array<string, string> $voidErrors  @var array $voidValues  @var string $revision
 *  @var string $reviewed  @var string $postKey  @var list<array> $products  @var list<array> $lines */
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;
use Pfpms\Inventory\ReceiptService;

$tz = $ctx->site()['time_zone'] ?? 'America/New_York';
$action = url('inventory_receipt_edit.php', $receipt ? ['id' => $receipt['receipt_id']] : []);
$byCategory = [];
foreach ($products as $p) {
    $byCategory[$p['category_name']][] = $p;
}
$quantityText = static function (array $line): string {
    if ($line['cases'] > 0) {
        return $line['cases'] . ' × ' . $line['per_case'] . ($line['units'] > 0 ? ' + ' . $line['units'] : '') . ' = ' . number_format($line['quantity']);
    }
    return number_format($line['quantity']);
};
$openLines = array_filter($lines, fn($l) => !(int) $l['voided']);
?>
<p><a href="<?= e(url('inventory_receipts.php')) ?>">Goods received</a></p>
<h1><?= $receipt ? 'Receipt ' . e($receipt['name']) : 'Record goods received' ?></h1>

<?php if ($receipt): ?>
  <dl class="readonly-list">
    <dt>Site</dt><dd><?= e($receipt['site_name']) ?></dd>
    <dt>Received</dt><dd><?= e($receipt['received_on']) ?></dd>
    <dt>Recorded by</dt><dd><?= e($receipt['received_by_name']) ?></dd>
    <?php if ($receipt['notes'] !== null): ?><dt>Notes</dt><dd class="pre-line"><?= e($receipt['notes']) ?></dd><?php endif; ?>
  </dl>

  <section id="lines">
    <h2>Lines</h2>
    <?php if (isset($voidErrors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($voidErrors['_form']) ?></div><?php endif; ?>
    <form method="post" action="<?= e($action) ?>#lines" class="form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="receipt_id" value="<?= (int) $receipt['receipt_id'] ?>">
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col"><span class="visually-hidden">Void</span></th><th scope="col">Product</th><th scope="col">Units</th>
          <th scope="col">Best before</th><th scope="col">In stock here now</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
          <tr<?= (int) $l['voided'] ? ' class="status-inactive"' : '' ?>>
            <td><?php if (!(int) $l['voided']): ?>
              <input type="checkbox" id="line-<?= (int) $l['receipt_line_id'] ?>" name="line_ids[]" value="<?= (int) $l['receipt_line_id'] ?>"
                <?= selected(in_array((int) $l['receipt_line_id'], $voidValues['line_ids'], true), 'checked') ?>
                aria-label="Void <?= e(ProductRepository::label($l)) ?>">
            <?php endif; ?></td>
            <th scope="row"><?= e(ProductRepository::label($l)) ?> <span class="meta"><?= e($l['category_name']) ?></span>
              <?= (int) $l['voided'] ? ' <span class="badge badge-inactive">Voided</span>' : '' ?></th>
            <td><?= number_format((int) $l['quantity']) ?></td>
            <td><?= $l['expiration'] !== null ? e($l['expiration']) : '<span class="meta">none</span>' ?></td>
            <td><?= e(ProductService::quantity((string) ($l['quantity_on_hand'] ?? '0'))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if ($openLines): ?>
        <label for="reason">Reason for voiding</label>
        <input type="text" <?= field_attrs($voidErrors, 'reason') ?> maxlength="255" value="<?= e($voidValues['reason']) ?>">
        <?= field_error($voidErrors, 'reason') ?>
        <p class="hint">Void a line entered by mistake, then enter it again if needed. The stock goes back down by what the line added, unless a
          stock count since then already corrected it. Goods that have been given out cannot be voided.</p>
        <div class="form-actions">
          <button type="submit" name="action" value="void_lines" class="button">Void the ticked lines</button>
          <?php if (count($openLines) > 1): ?><button type="submit" name="action" value="void_all" class="button">Void the whole receipt</button><?php endif; ?>
        </div>
      <?php endif; ?>
    </form>
  </section>

  <section id="details">
    <h2>Correct the details</h2>
    <?php if (isset($headerErrors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($headerErrors['_form']) ?></div><?php endif; ?>
    <form method="post" action="<?= e($action) ?>#details" class="form form-narrow" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="receipt_id" value="<?= (int) $receipt['receipt_id'] ?>">
      <input type="hidden" name="revision" value="<?= e($revision) ?>">
      <label for="name">Name</label>
      <input type="text" <?= field_attrs($headerErrors, 'name') ?> maxlength="50" value="<?= e($header['name']) ?>">
      <?= field_error($headerErrors, 'name') ?>
      <label for="received_on">Received on</label>
      <input type="date" <?= field_attrs($headerErrors, 'received_on') ?> value="<?= e($header['received_on']) ?>">
      <?= field_error($headerErrors, 'received_on') ?>
      <label for="notes">Notes</label>
      <textarea <?= field_attrs($headerErrors, 'notes') ?> maxlength="<?= ReceiptService::NOTES_MAX ?>"><?= e($header['notes']) ?></textarea>
      <?= field_error($headerErrors, 'notes') ?>
      <p class="hint">Leave the name blank to use the automatic name RCPT-<?= (int) $receipt['receipt_id'] ?>. Changing these does not change the stock.</p>
      <div class="form-actions"><button type="submit" name="action" value="header" class="button">Save details</button></div>
    </form>
  </section>

  <section id="add-lines">
    <h2>Add lines that were missed</h2>
<?php endif; ?>

<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div>
<?php elseif ($errors): ?><div class="flash flash-error" role="alert">Some rows need correcting: see the messages below.</div><?php endif; ?>

<?php if ($stage === 'review' && $plan !== null): ?>
  <form method="post" action="<?= e($action) ?><?= $receipt ? '#add-lines' : '' ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <?php if ($receipt): ?><input type="hidden" name="receipt_id" value="<?= (int) $receipt['receipt_id'] ?>"><?php else: ?>
      <input type="hidden" name="name" value="<?= e($header['name']) ?>">
      <input type="hidden" name="received_on" value="<?= e($header['received_on']) ?>">
      <input type="hidden" name="notes" value="<?= e($header['notes']) ?>">
    <?php endif; ?>
    <input type="hidden" name="row_count" value="<?= (int) $rowCount ?>">
    <input type="hidden" name="site_id" value="<?= (int) $ctx->siteId ?>">
    <input type="hidden" name="reviewed" value="<?= e($reviewed) ?>">
    <input type="hidden" name="post_key" value="<?= e($postKey) ?>">
    <?php foreach ($rows as $n => $row): foreach (['product' => 'product_id', 'cases' => 'cases', 'units' => 'units', 'expiration' => 'expiration'] as $input => $key): ?>
      <input type="hidden" name="<?= e($input) ?>[<?= (int) $n ?>]" value="<?= e($row[$key] ?? '') ?>">
    <?php endforeach; endforeach; ?>

    <p class="lead">Check the <?= count($plan['lines']) === 1 ? 'line' : count($plan['lines']) . ' lines' ?> before posting:
      <?= number_format($plan['total']) ?> units<?= $receipt ? '' : ', received on ' . e($plan['header']['received_on']) ?>.
      <?= !$receipt && $plan['header']['name'] !== null ? 'Receipt name: ' . e($plan['header']['name']) . '.' : '' ?></p>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Product</th><th scope="col">Units</th><th scope="col">Best before</th></tr></thead>
      <tbody>
      <?php foreach ($plan['lines'] as $n => $line): ?>
        <tr>
          <th scope="row"><?= e(ProductRepository::label($line['product'])) ?> <span class="meta"><?= e($line['product']['category_name']) ?></span></th>
          <td><?= e($quantityText($line)) ?></td>
          <td><?= $line['expiration'] !== null ? e($line['expiration']) : '<span class="meta">none</span>' ?></td>
        </tr>
        <?php if (isset($plan['counted'][$n])): $count = $plan['counted'][$n]; $field = "decision[$n]"; ?>
          <tr><td colspan="3">
            <fieldset<?= isset($errors[$field]) ? ' aria-describedby="' . e($field) . '-error"' : '' ?>>
              <legend>This product was counted on <?= e($count['count_date']) ?> (posted <?= e(local_time($count['posted_at'], $tz)) ?>).
                Were these goods on the shelves then?</legend>
              <label class="checkbox"><input type="radio" name="<?= e($field) ?>" value="add"<?= selected(($decisions[(string) $n] ?? '') === 'add', 'checked') ?>>
                No, they arrived after the count: add them to stock</label>
              <label class="checkbox"><input type="radio" name="<?= e($field) ?>" value="skip"<?= selected(($decisions[(string) $n] ?? '') === 'skip', 'checked') ?>>
                Yes, the count already has them: do not add them again</label>
              <?= field_error($errors, $field) ?>
            </fieldset>
          </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="form-actions">
      <button type="submit" name="action" value="post" class="button button-primary"><?= $receipt ? 'Add these lines' : 'Post receipt' ?></button>
      <button type="submit" name="action" value="change" class="button">Change</button>
    </div>
  </form>
<?php else: ?>
  <form method="post" action="<?= e($action) ?><?= $receipt ? '#add-lines' : '' ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <?php if ($receipt): ?>
      <input type="hidden" name="receipt_id" value="<?= (int) $receipt['receipt_id'] ?>">
    <?php else: ?>
      <div class="form-grid">
        <div>
          <label for="name">Name (optional)</label>
          <input type="text" <?= field_attrs($errors, 'name') ?> maxlength="50" value="<?= e($header['name']) ?>">
          <?= field_error($errors, 'name') ?>
        </div>
        <div>
          <label for="received_on">Received on</label>
          <input type="date" <?= field_attrs($errors, 'received_on') ?> value="<?= e($header['received_on']) ?>" required>
          <?= field_error($errors, 'received_on') ?>
        </div>
      </div>
      <p class="hint">Left blank, the receipt is named RCPT- and its number. Names are shared by every site, so include the donor or the date.</p>
      <label for="notes">Notes (optional)</label>
      <textarea <?= field_attrs($errors, 'notes') ?> maxlength="<?= ReceiptService::NOTES_MAX ?>"><?= e($header['notes']) ?></textarea>
      <?= field_error($errors, 'notes') ?>
    <?php endif; ?>
    <input type="hidden" name="row_count" value="<?= (int) $rowCount ?>">
    <input type="hidden" name="site_id" value="<?= (int) $ctx->siteId ?>">
    <p class="hint">One row per product and best-before date. Enter cases (for products that come in cases), loose units, or both.</p>
    <div class="table-wrap">
    <table class="table table-entry">
      <thead><tr><th scope="col">Product</th><th scope="col">Cases</th><th scope="col">Units</th><th scope="col">Best before</th></tr></thead>
      <tbody>
      <?php for ($n = 0; $n < $rowCount; $n++): $row = $rows[$n] ?? []; ?>
        <tr>
          <td>
            <label class="entry-label" for="product[<?= $n ?>]">Product<span class="visually-hidden">, row <?= $n + 1 ?></span></label>
            <select <?= field_attrs($errors, "product[$n]") ?>>
              <option value="">Choose a product</option>
              <?php foreach ($byCategory as $categoryName => $choices): ?>
                <optgroup label="<?= e($categoryName) ?>">
                  <?php foreach ($choices as $p): ?>
                    <option value="<?= (int) $p['product_id'] ?>"<?= selected((string) $p['product_id'] === (string) ($row['product_id'] ?? '')) ?>>
                      <?= e(ProductRepository::label($p)) ?><?= (int) $p['units_per_case'] > 1 ? ' (case of ' . (int) $p['units_per_case'] . ')' : '' ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <?= field_error($errors, "product[$n]") ?>
          </td>
          <td>
            <label class="entry-label" for="cases[<?= $n ?>]">Cases<span class="visually-hidden">, row <?= $n + 1 ?></span></label>
            <input type="text" inputmode="numeric" <?= field_attrs($errors, "cases[$n]") ?> maxlength="5" size="5" value="<?= e($row['cases'] ?? '') ?>">
            <?= field_error($errors, "cases[$n]") ?>
          </td>
          <td>
            <label class="entry-label" for="units[<?= $n ?>]">Units<span class="visually-hidden">, row <?= $n + 1 ?></span></label>
            <input type="text" inputmode="numeric" <?= field_attrs($errors, "units[$n]") ?> maxlength="5" size="5" value="<?= e($row['units'] ?? '') ?>">
            <?= field_error($errors, "units[$n]") ?>
          </td>
          <td>
            <label class="entry-label" for="expiration[<?= $n ?>]">Best before<span class="visually-hidden">, row <?= $n + 1 ?></span></label>
            <input type="date" <?= field_attrs($errors, "expiration[$n]") ?> value="<?= e($row['expiration'] ?? '') ?>">
            <?= field_error($errors, "expiration[$n]") ?>
          </td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    </div>
    <?php if (!$products): ?><p class="meta">There are no active products yet. Add them to the product catalogue first.</p><?php endif; ?>
    <div class="form-actions">
      <button type="submit" name="action" value="review" class="button button-primary">Review</button>
      <?php if ($rowCount < $maxRows): ?><button type="submit" name="action" value="more" class="button">Add more rows</button><?php endif; ?>
      <?php if (!$receipt): ?><a class="button" href="<?= e(url('inventory_receipts.php')) ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
<?php endif; ?>
<?php if ($receipt): ?>
  </section>
<?php endif; ?>
