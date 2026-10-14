<?php
/**
 * @var \Pfpms\Http\Context $ctx  @var array $filters  @var bool $canIncludeDeleted  @var int $limit  @var bool $tooLong  @var string $timeZone
 * @var ?array{rows: list<array>, truncated: bool} $result  search results, when something was typed
 * @var ?array $event  the site's Open event today  @var ?array{rows: list<array>, truncated: bool} $checkIns  its check-ins (US-04)
 * @var ?array{rows: list<array>, truncated: bool} $recent  households served here lately  @var int $recentDays  @var int $pollSeconds
 */
use Pfpms\Participant\ParticipantRepository;
use Pfpms\Participant\SearchRow;

$site = $ctx->site();
$present = static fn(array $p): array => SearchRow::present($p, $timeZone);
$nameCell = static function (array $r): string {
    return '<a href="' . e($r['url']) . '">' . e($r['name']) . '</a>'
        . ($r['legal'] !== null ? '<br><span class="meta">Legal name: ' . e($r['legal']) . '</span>' : '');
};
$statusCell = static fn(array $r): string => $r['status'] === 'Active' ? 'Active' : '<span class="badge badge-inactive">' . e($r['status']) . '</span>';
$capNote = static fn(int $limit): string => '<div class="flash flash-info banner-warning" role="status">Showing the first ' . $limit
    . ' only. Type more of the name, or the phone number or participant code, to narrow the search.</div>';
?>
<div class="page-head">
  <h1>Find a participant at <?= e($site['name'] ?? 'this site') ?></h1>
</div>

<form method="get" action="<?= e(url('participant_search.php')) ?>" class="filters" role="search" id="participant-search">
  <div class="form-grid">
    <div>
      <label for="q">Name, phone or participant code</label>
      <input type="search" id="q" name="q" value="<?= e($filters['q'] ?? '') ?>" maxlength="<?= ParticipantRepository::MAX_TERM ?>"
             autocomplete="off" autofocus<?= $tooLong ? ' aria-invalid="true" aria-describedby="q-error"' : '' ?>>
      <?php if ($tooLong): ?><p class="field-error" id="q-error">Use at most <?= ParticipantRepository::MAX_TERM ?> characters.</p><?php endif; ?>
    </div>
  </div>
  <?php if ($canIncludeDeleted): ?>
    <label class="checkbox"><input type="checkbox" name="include_deleted" value="1"<?= selected($filters['include_deleted'], 'checked') ?>> Include deleted records</label>
  <?php endif; ?>
  <div class="form-actions"><button type="submit" class="button button-primary">Search</button> <a class="button" href="<?= e(url('participant_search.php')) ?>">Clear</a></div>
</form>

<?php if ($result !== null): ?>
<section aria-live="polite">
  <h2>Results</h2>
  <?php if ($result['truncated']): ?><?= $capNote($limit) ?><?php endif; ?>
  <?php if (!$result['rows']): ?>
    <p class="meta">No participant at this site matches “<?= e($filters['q']) ?>”.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Name</th><th scope="col">Participant code</th><th scope="col">Status</th><th scope="col" class="num">Pets</th>
        <th scope="col">Last distribution</th></tr></thead>
      <tbody>
      <?php foreach (array_map($present, $result['rows']) as $r): ?>
        <tr<?= $r['status'] === 'Active' ? '' : ' class="status-inactive"' ?>>
          <th scope="row"><?= $nameCell($r) ?></th>
          <td><?= e($r['code']) ?></td>
          <td><?= $statusCell($r) ?></td>
          <td class="num"><?= $r['pets'] ?></td>
          <td><?= $r['last_distribution'] !== null ? e($r['last_distribution']) : '<span class="meta">never</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>
<?php elseif ($recent !== null): ?>
<div id="empty-search">
  <?php if ($checkIns !== null): ?>
  <section id="check-ins" data-poll-url="<?= e(url('api/participant/checkins.php')) ?>" data-poll-seconds="<?= $pollSeconds ?>">
    <h2>Checked in today</h2>
    <p class="meta">Households checked in to today's event, in the order they arrived. This list updates by itself.</p>
    <div data-cap<?= $checkIns['truncated'] ? '' : ' hidden' ?>><?= $capNote($limit) ?></div>
    <p class="meta" data-empty<?= $checkIns['rows'] ? ' hidden' : '' ?>>No one has checked in yet.</p>
    <div class="table-wrap" data-table<?= $checkIns['rows'] ? '' : ' hidden' ?>>
    <table class="table">
      <thead><tr><th scope="col">Checked in</th><th scope="col">Name</th><th scope="col">Participant code</th><th scope="col">Queue</th>
        <th scope="col" class="num">Pets</th><th scope="col">Last distribution</th></tr></thead>
      <tbody aria-live="polite">
      <?php foreach (array_map($present, $checkIns['rows']) as $r): ?>
        <tr>
          <td><?= e($r['checked_in']) ?></td>
          <th scope="row"><?= $nameCell($r) ?></th>
          <td><?= e($r['code']) ?></td>
          <td><?= e($r['outcome']) ?></td>
          <td class="num"><?= $r['pets'] ?></td>
          <td><?= $r['last_distribution'] !== null ? e($r['last_distribution']) : '<span class="meta">never</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
  <?php endif; ?>

  <section>
    <h2>Served here in the last <?= $recentDays ?> days</h2>
    <?php if ($recent['truncated']): ?><?= $capNote($limit) ?><?php endif; ?>
    <?php if (!$recent['rows']): ?>
      <p class="meta">No household has been served at this site in the last <?= $recentDays ?> days<?= $checkIns !== null ? ' apart from those checked in today' : '' ?>.
        Search by name, phone or participant code above.</p>
    <?php else: ?>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Name</th><th scope="col">Participant code</th><th scope="col">Status</th><th scope="col" class="num">Pets</th>
          <th scope="col">Last served here</th></tr></thead>
        <tbody>
        <?php foreach (array_map($present, $recent['rows']) as $r): ?>
          <tr<?= $r['status'] === 'Active' ? '' : ' class="status-inactive"' ?>>
            <th scope="row"><?= $nameCell($r) ?></th>
            <td><?= e($r['code']) ?></td>
            <td><?= $statusCell($r) ?></td>
            <td class="num"><?= $r['pets'] ?></td>
            <td><?= e((string) $r['served_here']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>
<script src="<?= e(asset('js/participant_search.js')) ?>" defer></script>
