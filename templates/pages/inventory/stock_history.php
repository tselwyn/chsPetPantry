<?php
/** @var \Pfpms\Http\Context $ctx  @var array $product  @var string $onHand  @var array{rows: list<array>, more: bool} $history */
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;

$tz = $ctx->site()['time_zone'] ?? 'America/New_York';
$canReceive = $ctx->can('inventory.receive');
$typeLabels = ['Receipt' => 'Received', 'Distribution' => 'Given out', 'Reversal' => 'Voided or reversed', 'Count Adjustment' => 'Stock count', 'Transfer' => 'Transfer'];
?>
<p><a href="<?= e(url('inventory_stock.php')) ?>">Stock on hand</a></p>
<h1><?= e(ProductRepository::label($product)) ?></h1>
<p class="lead"><?= e($product['category_name']) ?>. In stock at <?= e($ctx->site()['name'] ?? 'this site') ?>: <strong><?= e(ProductService::quantity($onHand)) ?></strong>.</p>

<h2>History</h2>
<?php if (!$history['rows']): ?><p class="meta">Nothing has been received, counted or given out here yet.</p><?php else: ?>
  <?php if ($history['more']): ?><p class="hint">The latest <?= count($history['rows']) ?> changes.</p><?php endif; ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">When</th><th scope="col">What</th><th scope="col" class="num">Change</th><th scope="col" class="num">Stock after</th><th scope="col">By</th></tr></thead>
    <tbody>
    <?php foreach ($history['rows'] as $t):
        $countLink = $t['count_id'] !== null ? '<a href="' . e(url('inventory_count_view.php', ['id' => $t['count_id']])) . '">count of ' . e($t['count_date']) . '</a>' : '';
        $receiptLink = $t['receipt_id'] === null ? '' : ($canReceive
            ? '<a href="' . e(url('inventory_receipt_edit.php', ['id' => $t['receipt_id']])) . '">' . e($t['receipt_name']) . '</a>' : e($t['receipt_name'])); ?>
      <tr>
        <td><?= e(local_time($t['recorded_at'], $tz)) ?></td>
        <td><?php if ($t['offset']): ?>Kept at the <?= $countLink ?>, which already included the change below
          <?php else: ?><?= e($typeLabels[$t['txn_type']] ?? $t['txn_type']) ?><?= $receiptLink !== '' ? ': ' . $receiptLink : ($countLink !== '' ? ': ' . $countLink : '') ?><?php endif; ?></td>
        <td class="num"><?= e(((string) $t['qty_change'])[0] === '-' ? ProductService::quantity((string) $t['qty_change']) : '+' . ProductService::quantity((string) $t['qty_change'])) ?></td>
        <td class="num"><?= $t['balance_after'] !== null ? e(ProductService::quantity($t['balance_after'])) : '' ?></td>
        <td><?= e($t['recorded_by_name']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
