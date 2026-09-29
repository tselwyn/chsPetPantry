<?php
/** @var \Pfpms\Http\Context $ctx  @var array $overview  @var list<array> $visible  @var bool $canManage  @var ?int $shown  @var ?array $shownVersion
 *  @var list<array> $table  @var array<string, list<string>> $gaps  @var array<int, int> $counts  @var array<string, string> $rawCounts
 *  @var array<string, string> $countErrors  @var ?array $tried  @var ?string $tryError
 *  @var string $earliestStart */
use Pfpms\Allotment\AllotmentRuleService as Rules;

$statusLabels = ['draft' => 'Draft', 'scheduled' => 'Starts later', 'in_force' => 'In use now', 'replaced' => 'Replaced'];
$day = static fn(?string $date): string => $date === null ? '' : date('j M Y', (int) strtotime($date));
$speciesNames = array_column($table, 'species_name', 'species_id');
?>
<div class="page-head no-print">
  <h1>Allotment rules</h1>
  <?php if ($canManage): ?>
    <?php if ($overview['draft'] !== null): ?>
      <a class="button button-primary" href="<?= e(url('admin_allotment_rule_edit.php', ['version' => $overview['draft']])) ?>">Continue the draft (version <?= (int) $overview['draft'] ?>)</a>
    <?php elseif ($overview['scheduled'] !== null): ?>
      <form method="post" action="<?= e(url('admin_allotment_rule_edit.php')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="version" value="<?= (int) $overview['scheduled'] ?>">
        <button type="submit" name="action" value="withdraw" class="button">Take version <?= (int) $overview['scheduled'] ?> back to edit</button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= e(url('admin_allotment_rule_edit.php')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" name="action" value="start" class="button button-primary"><?= $overview['in_force'] === null ? 'Set up the first version' : 'Start a new version' ?></button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<p class="lead no-print">Each pet gets the pounds shown for its species and size band at every distribution; a household's
  allotment is the total for its active pets. A change is made as a new version with a start date, so the rules behind
  past distributions never change.</p>
<?php if ($canManage && $overview['draft'] === null && $overview['scheduled'] === null): ?>
  <p class="hint no-print">A new version can start on <?= e($day($earliestStart)) ?> at the earliest.</p>
<?php elseif ($canManage && $overview['scheduled'] !== null): ?>
  <p class="hint no-print">You can have one upcoming change at a time. Until it starts you can take it back and edit it.</p>
<?php endif; ?>

<?php foreach ($gaps as $which => $list): ?>
  <div class="flash flash-error no-print" role="alert">
    The <?= $which === 'in_force' ? 'version in use now' : 'upcoming version' ?> has no figures for:
    <?= e(implode(', ', $list)) ?>. Pets in these size bands cannot be served until a version that includes them starts.
  </div>
<?php endforeach; ?>

<?php if (!$visible): ?>
  <p class="meta">No allotment rules <?= $canManage ? 'yet. Set up the first version to give each size band its pounds.' : 'have been published yet. An Administrator sets them up.' ?></p>
<?php else: ?>
  <section class="no-print">
    <h2>Versions</h2>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Version</th><th scope="col">Status</th><th scope="col">Used from</th><th scope="col">Used until</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($visible as $v): ?>
        <tr<?= $v['status'] === 'replaced' ? ' class="status-inactive"' : '' ?>>
          <td><?= (int) $v['rule_version'] ?></td>
          <td><?= $v['status'] === 'in_force' ? e($statusLabels[$v['status']]) : '<span class="badge">' . e($statusLabels[$v['status']]) . '</span>' ?></td>
          <td><?= $v['status'] === 'draft' ? '<span class="meta">Proposed: ' . e($day($v['effective_from'])) . '</span>' : e($day($v['effective_from'])) ?></td>
          <td><?= $v['ends_on'] !== null ? e($day($v['ends_on'])) : '<span class="meta">—</span>' ?></td>
          <td>
            <?php if ((int) $v['rule_version'] !== $shown): ?>
              <a href="<?= e(url('admin_allotment_rules.php', ['version' => $v['rule_version']])) ?>">View</a>
            <?php else: ?><span class="meta">Shown below</span><?php endif; ?>
            <?php if ($v['status'] === 'draft'): ?> · <a href="<?= e(url('admin_allotment_rule_edit.php', ['version' => $v['rule_version']])) ?>">Edit</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
<?php endif; ?>

<?php if ($shownVersion !== null): ?>
  <section>
    <h2>Version <?= (int) $shown ?>: pounds per pet per distribution</h2>
    <p class="meta"><?= e($statusLabels[$shownVersion['status']]) ?>.
      <?php if ($shownVersion['status'] === 'draft'): ?>Not published: nothing uses these figures yet.
      <?php else: ?>Used from <?= e($day($shownVersion['effective_from'])) ?><?= $shownVersion['ends_on'] !== null ? ' until ' . e($day($shownVersion['ends_on'])) : '' ?>, by each site's local date.<?php endif; ?>
      <span class="no-print">To print this table, use your browser's Print command.</span></p>
    <?php foreach ($table as $species): ?>
      <h3><?= e($species['species_name']) ?></h3>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Size band</th><th scope="col">Weight</th>
          <?php if ($species['mode'] === Rules::MODE_SPLIT): ?><th scope="col">Dry food (lb)</th><th scope="col">Wet food (lb)</th>
          <?php else: ?><th scope="col">Dry or wet food (lb)</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($species['bands'] as $band): ?>
          <tr>
            <th scope="row"><?= e($band['band_name']) ?></th>
            <td><?= e($band['range']) ?></td>
            <?php foreach ($species['mode'] === Rules::MODE_SPLIT ? ['Dry', 'Wet'] : ['Any'] as $form): ?>
              <td><?= isset($band['lbs'][$form]) ? e($band['lbs'][$form]) : '<span class="meta">not filled in</span>' ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="no-print">
    <h2>Try it: work out a household's allotment</h2>
    <form method="get" action="<?= e(url('admin_allotment_rules.php')) ?>" class="form">
      <input type="hidden" name="version" value="<?= (int) $shown ?>">
      <p class="hint">Enter how many pets of each size the household has, then work it out with version <?= (int) $shown ?>.</p>
      <div class="form-grid">
        <?php foreach ($table as $species): ?>
          <?php foreach ($species['bands'] as $band): ?>
            <?php $bid = (int) $band['size_band_id']; $field = "pets[$bid]"; ?>
            <div>
              <label for="<?= e($field) ?>"><?= e($species['species_name'] . ' – ' . $band['band_name']) ?></label>
              <input type="text" inputmode="numeric" <?= field_attrs($countErrors, $field) ?> maxlength="2" value="<?= e($rawCounts[(string) $bid] ?? '') ?>">
              <?= field_error($countErrors, $field) ?>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
      <div class="form-actions"><button type="submit" class="button">Work it out</button></div>
    </form>
    <?php $bandNames = [];
    foreach ($table as $species) { foreach ($species['bands'] as $band) { $bandNames[(int) $band['size_band_id']] = $species['species_name'] . ' – ' . $band['band_name']; } } ?>
    <?php if ($countErrors): ?>
      <div class="flash flash-error" role="alert">Some numbers could not be read. Correct them and work it out again.</div>
    <?php elseif ($tryError !== null): ?>
      <div class="flash flash-error" role="alert"><?= e($tryError) ?></div>
    <?php elseif ($rawCounts && !$counts): ?>
      <p class="hint" role="status">Enter at least one pet to work out an allotment.</p>
    <?php elseif ($tried !== null): ?>
      <div class="flash flash-info" role="status">
        <p><strong>Allotment: <?= e($tried['total']) ?> lb per distribution</strong> for
          <?= e(implode(', ', array_map(fn($bid, $n) => "$n × " . ($bandNames[$bid] ?? "band $bid"), array_keys($counts), $counts))) ?>.</p>
        <ul>
          <?php foreach ($tried['buckets'] as $bucket): ?>
            <li><?= e(($speciesNames[$bucket['species_id']] ?? 'Species ' . $bucket['species_id']) . ', ' . Rules::FORM_LABELS[$bucket['food_form']] . ' food: ' . $bucket['lbs'] . ' lb') ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
