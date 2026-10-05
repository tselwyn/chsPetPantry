<?php
/** @var \Pfpms\Http\Context $ctx  @var ?array $category  @var list<array> $categories  @var list<array> $sheet  @var array<string, string> $cases
 *  @var array<string, string> $units  @var string $stage  @var ?array $review  @var array<string, string> $errors  @var list<int> $stale
 *  @var array<string, string> $seen  @var string $reviewed  @var string $postKey  @var int $idleMinutes  @var ?array $openEvent  @var list<array> $recent */
use Pfpms\Inventory\ProductRepository;
use Pfpms\Inventory\ProductService;

$site = $ctx->site();
$tz = $site['time_zone'] ?? 'America/New_York';
$query = $category ? ['category_id' => $category['category_id']] : [];
$action = url('inventory_count.php', $query);
$byCategory = [];
foreach ($sheet as $p) {
    $byCategory[$p['category_name']][] = $p;
}
?>
<h1>Stock count at <?= e($site['name'] ?? 'this site') ?></h1>
<p class="lead">Count what is on the shelves, in every storage area of this site, and enter it here. The stock of each product you
  count is set to your count. Leave a product blank if you did not count it: its stock stays as it is.</p>

<?php if ($openEvent): ?>
  <div class="flash flash-error banner-warning" role="alert">A distribution event (<?= e($openEvent['event_date']) ?>) is open at this site, so stock
    is moving. You can fill in the sheet, but it can only be posted after the event closes.</div>
<?php endif; ?>

<form method="get" action="<?= e(url('inventory_count.php')) ?>" class="filters">
  <label for="category_id">Count</label>
  <div class="form-actions">
    <select id="category_id" name="category_id">
      <option value="">Every product</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['category_id'] ?>"<?= selected($category !== null && (int) $category['category_id'] === (int) $c['category_id']) ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="button">Show</button>
  </div>
  <p class="hint">A long count can be posted one category at a time. Choosing another category clears figures not yet posted.</p>
</form>

<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div>
<?php elseif ($errors): ?><div class="flash flash-error" role="alert">Some figures need correcting: see the messages below.</div><?php endif; ?>

<?php if ($stage === 'review' && $review !== null): ?>
  <form method="post" action="<?= e($action) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <?php if ($category): ?><input type="hidden" name="category_id" value="<?= (int) $category['category_id'] ?>"><?php endif; ?>
    <?php foreach ($cases as $pid => $v): ?><input type="hidden" name="cases[<?= (int) $pid ?>]" value="<?= e($v) ?>"><?php endforeach; ?>
    <?php foreach ($units as $pid => $v): ?><input type="hidden" name="units[<?= (int) $pid ?>]" value="<?= e($v) ?>"><?php endforeach; ?>
    <?php foreach ($review['lines'] as $line): ?><input type="hidden" name="book[<?= (int) $line['product_id'] ?>]" value="<?= e($line['book']) ?>"><?php endforeach; ?>
    <?php foreach ($seen as $pid => $v): ?><input type="hidden" name="seen[<?= (int) $pid ?>]" value="<?= e($v) ?>"><?php endforeach; ?>
    <input type="hidden" name="site_id" value="<?= (int) $ctx->siteId ?>">
    <input type="hidden" name="reviewed" value="<?= e($reviewed) ?>">
    <input type="hidden" name="post_key" value="<?= e($postKey) ?>">

    <h2>Check the count</h2>
    <p>Posting sets the stock of these <?= count($review['lines']) === 1 ? 'product' : count($review['lines']) . ' products' ?> to what you counted.
      <?php if (array_filter(array_column($review['lines'], 'large'))): ?>Changes marked <span class="change-large">in bold</span> are large: count those again if you are not sure.<?php endif; ?></p>
    <?php $moved = array_filter($review['lines'], fn($l) => $l['moved']); if ($moved): ?>
      <div class="flash flash-info" role="status">The stock of <?= count($moved) === 1 ? '1 product' : count($moved) . ' products' ?> changed after you opened
        the sheet (goods received, voided or given out). If you counted before that reached the shelves, count <?= count($moved) === 1 ? 'it' : 'them' ?> again.</div>
    <?php endif; ?>
    <?php if ($review['pending_items'] > 0): ?>
      <div class="flash flash-info" role="status">Tablets at this site hold <?= (int) $review['pending_items'] ?> item<?= $review['pending_items'] === 1 ? '' : 's' ?>
        not yet sent. Food they gave out before this count will not be taken off stock again when they sync.</div>
    <?php endif; ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Product</th><th scope="col" class="num">In stock now</th><th scope="col" class="num">Counted</th><th scope="col" class="num">Change</th></tr></thead>
      <tbody>
      <?php foreach ($review['lines'] as $line): $large = $line['large']; $changed = in_array($line['product_id'], $stale, true); ?>
        <tr>
          <th scope="row"><?= e(ProductRepository::label($line['product'])) ?> <span class="meta"><?= e($line['product']['category_name']) ?></span>
            <?= $changed ? ' <span class="badge">Stock changed</span>' : '' ?>
            <?= $line['moved'] ? ' <span class="badge">Was ' . e(ProductService::quantity((string) $line['seen'])) . ' when the sheet was opened</span>' : '' ?></th>
          <td class="num"><?= e(ProductService::quantity($line['book'])) ?></td>
          <td class="num"><?= number_format($line['counted']) ?></td>
          <td class="num<?= $large ? ' change-large' : '' ?>"><?= e(($line['change'][0] === '-' ? '' : '+') . ProductService::quantity($line['change'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($review['not_counted']): ?>
      <details>
        <summary><?= count($review['not_counted']) ?> product<?= count($review['not_counted']) === 1 ? '' : 's' ?> with stock left blank (not changed)</summary>
        <ul>
          <?php foreach ($review['not_counted'] as $nc): ?>
            <li><?= e(ProductRepository::label($nc['product'])) ?>: <?= e(ProductService::quantity($nc['book'])) ?> in stock</li>
          <?php endforeach; ?>
        </ul>
        <p class="hint">If any of these are gone, go back and enter 0 for them.</p>
      </details>
    <?php endif; ?>
    <div class="form-actions">
      <button type="submit" name="action" value="post" class="button button-primary"<?= $openEvent ? ' disabled' : '' ?>>Post count</button>
      <button type="submit" name="action" value="change" class="button">Change</button>
    </div>
  </form>
<?php else: ?>
  <form method="post" action="<?= e($action) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <?php if ($category): ?><input type="hidden" name="category_id" value="<?= (int) $category['category_id'] ?>"><?php endif; ?>
    <input type="hidden" name="site_id" value="<?= (int) $ctx->siteId ?>">
    <p class="hint">Enter 0 for a product you looked for and found none of. You are signed out after <?= (int) $idleMinutes ?> minutes without
      saving, so for a long count post it one category at a time, or keep your figures on paper.</p>
    <?php if (!$sheet): ?><p class="meta">There are no products to count<?= $category ? ' in this category' : '' ?>.</p><?php endif; ?>
    <?php foreach ($byCategory as $categoryName => $products): ?>
      <h2><?= e($categoryName) ?></h2>
      <div class="table-wrap">
      <table class="table table-entry">
        <thead><tr><th scope="col">Product</th><th scope="col">Cases</th><th scope="col">Units</th></tr></thead>
        <tbody>
        <?php foreach ($products as $p): $pid = (int) $p['product_id']; $perCase = (int) $p['units_per_case']; ?>
          <tr<?= (int) $p['is_active'] ? '' : ' class="status-inactive"' ?>>
            <th scope="row"><?= e(ProductRepository::label($p)) ?>
              <input type="hidden" name="seen[<?= $pid ?>]" value="<?= e($seen[$pid] ?? (string) $p['quantity_on_hand']) ?>"></th>
            <td>
              <?php if ($perCase > 1): ?>
                <label class="entry-label" for="cases[<?= $pid ?>]">Cases<span class="visually-hidden"> of <?= $perCase ?>: <?= e(ProductRepository::label($p)) ?></span></label>
                <input type="text" inputmode="numeric" <?= field_attrs($errors, "cases[$pid]") ?> maxlength="5" value="<?= e($cases[(string) $pid] ?? '') ?>">
                <span class="meta">of <?= $perCase ?></span>
                <?= field_error($errors, "cases[$pid]") ?>
              <?php else: ?><span class="meta">not in cases</span><?php endif; ?>
            </td>
            <td>
              <label class="entry-label" for="units[<?= $pid ?>]">Units<span class="visually-hidden">: <?= e(ProductRepository::label($p)) ?></span></label>
              <input type="text" inputmode="numeric" <?= field_attrs($errors, "units[$pid]") ?> maxlength="5" value="<?= e($units[(string) $pid] ?? '') ?>">
              <?= field_error($errors, "units[$pid]") ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endforeach; ?>
    <?php if ($sheet): ?>
      <div class="form-actions"><button type="submit" name="action" value="review" class="button button-primary">Review the count</button></div>
    <?php endif; ?>
  </form>
<?php endif; ?>

<section>
  <h2>Recent counts</h2>
  <?php if (!$recent): ?><p class="meta">No counts yet at this site.</p><?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Date</th><th scope="col">Covered</th><th scope="col">Products</th><th scope="col">Counted by</th><th scope="col">Posted</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $c): ?>
        <tr>
          <th scope="row"><a href="<?= e(url('inventory_count_view.php', ['id' => $c['count_id']])) ?>"><?= e($c['count_date']) ?></a></th>
          <td><?= e($c['location']) ?></td>
          <td><?= (int) $c['line_count'] ?></td>
          <td><?= e($c['counted_by_name']) ?></td>
          <td><?= e(local_time($c['posted_at'], $tz)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>
