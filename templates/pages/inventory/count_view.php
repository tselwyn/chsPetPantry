<?php
/** @var \Pfpms\Http\Context $ctx  @var array $count  @var list<array> $lines */
use Pfpms\Inventory\Ledger;
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;
use Pfpms\Validation\Validator;

$tz = $ctx->site()['time_zone'] ?? 'America/New_York';
?>
<p><?php if ($ctx->can('inventory.count')): ?><a href="<?= e(url('inventory_count.php')) ?>">Stock count</a> · <?php endif; ?>
  <a href="<?= e(url('inventory_stock.php')) ?>">Stock on hand</a></p>
<h1>Stock count, <?= e($count['count_date']) ?></h1>
<dl class="readonly-list">
  <dt>Site</dt><dd><?= e($count['site_name']) ?></dd>
  <dt>Covered</dt><dd><?= e($count['location']) ?></dd>
  <dt>Counted by</dt><dd><?= e($count['counted_by_name']) ?></dd>
  <dt>Posted</dt><dd><?= e(local_time($count['posted_at'], $tz)) ?></dd>
</dl>
<p class="hint">A count cannot be changed or voided. If a figure was wrong, count that product again.</p>

<div class="table-wrap">
<table class="table">
  <thead><tr><th scope="col">Product</th><th scope="col" class="num">Stock before</th><th scope="col" class="num">Counted</th><th scope="col" class="num">Change</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l):
      $change = $l['change_units'] !== null ? Ledger::toHundredths((string) $l['change_units']) : 0;
      $before = (int) $l['counted'] * 100 - $change; ?>
    <tr>
      <th scope="row"><?= e(ProductRepository::label($l)) ?> <span class="meta"><?= e($l['category_name']) ?></span></th>
      <td class="num"><?= e(ProductService::quantity(Validator::fromUnits($before, 2))) ?></td>
      <td class="num"><?= number_format((int) $l['counted']) ?></td>
      <td class="num"><?= e(($change < 0 ? '' : '+') . ProductService::quantity(Validator::fromUnits($change, 2))) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
