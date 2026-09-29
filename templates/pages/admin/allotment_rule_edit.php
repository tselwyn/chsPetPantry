<?php
/** @var \Pfpms\Http\Context $ctx  @var int $version  @var array<string, string> $errors  @var list<array> $grid  @var ?array $input
 *  @var ?array $review  @var string $reason  @var string $revision  @var array $origin  @var string $effectiveFrom  @var string $earliestStart */
use Pfpms\Allotment\AllotmentRuleService as Rules;

$day = static fn(?string $date): string => $date === null ? '' : date('j M Y', (int) strtotime($date));
$formLabels = ['Any' => 'Dry or wet', 'Dry' => 'Dry', 'Wet' => 'Wet'];
?>
<h1>Allotment rules: draft version <?= (int) $version ?></h1>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>

<?php if ($review !== null): ?>
  <section>
    <h2>Check before publishing</h2>
    <p class="lead">
      <?php if ($review['previous'] === null): ?>
        This is the first version. It will be used from <strong><?= e($day($review['effective_from'])) ?></strong>, by each site's local date.
      <?php else: ?>
        Version <?= (int) $review['previous'] ?> stays in use up to and including <strong><?= e($day($review['previous_until'])) ?></strong>.
        Version <?= (int) $version ?> is used from <strong><?= e($day($review['effective_from'])) ?></strong>, by each site's local date.
      <?php endif; ?>
    </p>
    <?php if (!$review['changes']): ?>
      <p>No figures change<?= $review['previous'] !== null ? ' from version ' . (int) $review['previous'] : '' ?>.</p>
    <?php else: ?>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Pet</th><th scope="col">Food</th>
          <th scope="col"><?= $review['previous'] === null ? 'Now' : 'Now (version ' . (int) $review['previous'] . ')' ?></th>
          <th scope="col">From <?= e($day($review['effective_from'])) ?></th><th scope="col"><span class="visually-hidden">Check</span></th></tr></thead>
        <tbody>
        <?php foreach ($review['changes'] as $c): ?>
          <tr>
            <th scope="row">One <?= e($c['species_name']) ?>, <?= e($c['band_name']) ?> <span class="meta">(<?= e($c['range']) ?>)</span></th>
            <td><?= e($c['food']) ?></td>
            <td><?= $c['old'] !== null ? e($c['old']) : '<span class="meta">no figure</span>' ?></td>
            <td><strong><?= $c['new'] !== null ? e($c['new']) : 'no figure (no longer needed)' ?></strong></td>
            <td><?= $c['large'] ? '<span class="badge">Big change: please check</span>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
    <p class="hint"><a href="<?= e(url('admin_allotment_rules.php', ['version' => $version])) ?>">Try a household with these figures</a> before publishing.</p>
    <form method="post" action="<?= e(url('admin_allotment_rule_edit.php', ['version' => $version])) ?>" class="form form-narrow" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="version" value="<?= (int) $version ?>">
      <input type="hidden" name="effective_from" value="<?= e($review['effective_from']) ?>">
      <input type="hidden" name="revision" value="<?= e($review['revision']) ?>">
      <label for="reason">What changed and why (optional)</label>
      <input type="text" <?= field_attrs($errors, 'reason') ?> maxlength="255" value="<?= e($reason) ?>" placeholder="For example: larger bags from the new supplier">
      <?= field_error($errors, 'reason') ?>
      <p class="hint">Kept in the audit log with the published version. Once it starts, a version cannot be changed.</p>
      <div class="form-actions">
        <button type="submit" name="action" value="publish" class="button button-primary">Publish version <?= (int) $version ?></button>
        <a class="button" href="<?= e(url('admin_allotment_rule_edit.php', ['version' => $version])) ?>">Keep editing</a>
      </div>
    </form>
  </section>
<?php else: ?>
  <p class="lead">Give the pounds of food each pet gets at every distribution, by species and size band.
    <?php if (isset($origin['taken_back_from'])): ?>
      These are the figures of version <?= (int) $origin['taken_back_from'] ?>, which was taken back before it started.
    <?php elseif (!empty($origin['based_on'])): ?>
      The figures started as a copy of version <?= (int) $origin['based_on'] ?>.
    <?php endif; ?></p>
  <form method="post" action="<?= e(url('admin_allotment_rule_edit.php', ['version' => $version])) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="version" value="<?= (int) $version ?>">
    <input type="hidden" name="revision" value="<?= e($revision) ?>">
    <div class="form-narrow">
      <label for="effective_from">Start date</label>
      <input type="date" <?= field_attrs($errors, 'effective_from') ?> value="<?= e($effectiveFrom) ?>" required>
      <?= field_error($errors, 'effective_from') ?>
      <p class="hint">The earliest possible start is <?= e($day($earliestStart)) ?>. From its start date, each site uses the new
        figures on its own local date; distributions before that keep the old ones.</p>
    </div>

    <?php foreach ($grid as $species): ?>
      <?php
      $sid = $species['species_id'];
      // The choice just posted (kept when the save failed), else the draft's stored mode; the boxes follow the stored mode.
      $mode = in_array($input['mode'][$sid] ?? null, [Rules::MODE_ANY, Rules::MODE_SPLIT], true) ? $input['mode'][$sid] : $species['mode'];
      ?>
      <fieldset>
        <legend><?= e($species['species_name']) ?><?= $species['species_active'] ? '' : ($species['needed']
            ? ' <span class="meta">(no longer offered, but some pets still have it)</span>'
            : ' <span class="meta">(no longer offered: these figures are not needed and are dropped when you publish)</span>') ?></legend>
        <label for="mode-<?= (int) $sid ?>">Pounds for</label>
        <select id="mode-<?= (int) $sid ?>" name="mode[<?= (int) $sid ?>]">
          <option value="<?= e(Rules::MODE_ANY) ?>"<?= selected($mode === Rules::MODE_ANY) ?>>Dry or wet food: one figure</option>
          <option value="<?= e(Rules::MODE_SPLIT) ?>"<?= selected($mode === Rules::MODE_SPLIT) ?>>Dry and wet food separately</option>
        </select>
        <p class="hint">After changing this, save the draft to get the matching boxes.</p>
        <div class="table-wrap">
        <table class="table">
          <thead><tr><th scope="col">Size band</th><th scope="col">Weight</th>
            <?php foreach ($species['mode'] === Rules::MODE_SPLIT ? ['Dry', 'Wet'] : ['Any'] as $form): ?>
              <th scope="col"><?= e($formLabels[$form]) ?> (lb)</th>
            <?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($species['bands'] as $band): ?>
            <tr>
              <th scope="row"><?= e($band['band_name']) ?><?= $band['required'] ? '' : ' <span class="meta">(not needed)</span>' ?></th>
              <td><?= e($band['range']) ?></td>
              <?php foreach ($species['mode'] === Rules::MODE_SPLIT ? ['Dry', 'Wet'] : ['Any'] as $form): ?>
                <?php $key = $band['size_band_id'] . '_' . $form; $field = "lbs[$key]"; ?>
                <td>
                  <label class="visually-hidden" for="<?= e($field) ?>"><?= e($species['species_name'] . ' ' . $band['band_name'] . ', ' . $formLabels[$form]) ?> pounds</label>
                  <input type="text" inputmode="decimal" <?= field_attrs($errors, $field) ?> maxlength="6" size="6"
                         value="<?= e($input['lbs'][$key] ?? ($band['lbs'][$form] ?? '')) ?>">
                  <?= field_error($errors, $field) ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </fieldset>
    <?php endforeach; ?>
    <p class="hint">Leave a box blank if you have not decided yet: you can save the draft, but not publish it, until every size band
      has a figure. 0 means no food for pets of that size. At most <?= e(Rules::MAX_LBS) ?> lb, with up to 2 decimals.</p>
    <div class="form-actions">
      <button type="submit" name="action" value="save" class="button">Save draft</button>
      <button type="submit" name="action" value="review" class="button button-primary">Review and publish</button>
      <a class="button" href="<?= e(url('admin_allotment_rules.php')) ?>">Back to allotment rules</a>
    </div>
  </form>

  <form method="post" action="<?= e(url('admin_allotment_rule_edit.php', ['version' => $version])) ?>" class="form-actions">
    <?= csrf_field() ?>
    <input type="hidden" name="version" value="<?= (int) $version ?>">
    <button type="submit" name="action" value="discard" class="button">Discard this draft</button>
  </form>
<?php endif; ?>
