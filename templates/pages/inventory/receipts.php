<?php
/** @var \Pfpms\Http\Context $ctx  @var array $filters  @var list<array> $receipts */
use Pfpms\Inventory\ReceiptService;

$site = $ctx->site();
?>
<div class="page-head">
  <h1>Goods received at <?= e($site['name'] ?? 'this site') ?></h1>
  <a class="button button-primary" href="<?= e(url('inventory_receipt_edit.php')) ?>">Record goods received</a>
</div>
<p class="lead">Each delivery or donation is a receipt: its lines are added to this site's stock when it is saved. A mistake is
  voided, not deleted, so the stock history stays complete.</p>

<form method="get" action="<?= e(url('inventory_receipts.php')) ?>" class="filters">
  <div class="form-grid">
    <div>
      <label for="q">Name</label>
      <input type="text" id="q" name="q" maxlength="50" value="<?= e($filters['q'] ?? '') ?>">
    </div>
    <div>
      <label for="from">Received from</label>
      <input type="date" id="from" name="from" value="<?= e($filters['from'] ?? '') ?>">
    </div>
    <div>
      <label for="to">to</label>
      <input type="date" id="to" name="to" value="<?= e($filters['to'] ?? '') ?>">
    </div>
  </div>
  <div class="form-actions"><button type="submit" class="button">Show</button> <a class="button" href="<?= e(url('inventory_receipts.php')) ?>">Clear</a></div>
</form>

<section>
  <h2>Receipts</h2>
  <?php if (!$receipts): ?><p class="meta">No receipts match.</p><?php else: ?>
    <?php if (count($receipts) >= 200): ?><p class="hint">Showing the latest 200. Narrow the dates to see older receipts.</p><?php endif; ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Receipt</th><th scope="col">Received</th><th scope="col">By</th><th scope="col">Lines</th><th scope="col">Units</th>
        <th scope="col">Earliest best-before</th><th scope="col">Status</th></tr></thead>
      <tbody>
      <?php foreach ($receipts as $r): $status = ReceiptService::status($r); ?>
        <tr<?= $status === 'Voided' ? ' class="status-inactive"' : '' ?>>
          <th scope="row"><a href="<?= e(url('inventory_receipt_edit.php', ['id' => $r['receipt_id']])) ?>"><?= e($r['name']) ?></a></th>
          <td><?= e($r['received_on']) ?></td>
          <td><?= e($r['received_by_name']) ?></td>
          <td><?= (int) $r['line_count'] ?></td>
          <td><?= number_format((int) $r['units']) ?></td>
          <td><?= $r['earliest_expiry'] !== null ? e($r['earliest_expiry']) : '<span class="meta">none</span>' ?></td>
          <td><?= $status === 'Posted' ? 'Posted' : '<span class="badge badge-inactive">' . e($status) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>
