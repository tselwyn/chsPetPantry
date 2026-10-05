<?php
/** @var \Pfpms\Http\Context $ctx  @var array $filters  @var bool $canIncludeDeleted  @var int $limit  @var bool $tooLong  @var ?array{rows: list<array>, truncated: bool} $result */
use Pfpms\Participant\ParticipantName;
use Pfpms\Participant\ParticipantRepository;

$site = $ctx->site();
$day = static fn(?string $date): string => $date === null ? '' : date('M j, Y', (int) strtotime($date));
?>
<div class="page-head">
  <h1>Find a participant at <?= e($site['name'] ?? 'this site') ?></h1>
</div>

<form method="get" action="<?= e(url('participant_search.php')) ?>" class="filters" role="search">
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
  <?php if ($result['truncated']): ?>
    <div class="flash flash-info banner-warning" role="status">Showing the first <?= (int) $limit ?> matches only. Type more of the name, or the phone
      number or participant code, to narrow the search.</div>
  <?php endif; ?>
  <?php if (!$result['rows']): ?>
    <p class="meta">No participant at this site matches “<?= e($filters['q']) ?>”.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr><th scope="col">Name</th><th scope="col">Participant code</th><th scope="col">Status</th><th scope="col" class="num">Pets</th>
        <th scope="col">Last distribution</th></tr></thead>
      <tbody>
      <?php foreach ($result['rows'] as $p): ?>
        <tr<?= $p['status'] === 'Active' ? '' : ' class="status-inactive"' ?>>
          <th scope="row"><a href="<?= e(url('participant_view.php', ['id' => $p['participant_id']])) ?>"><?= e(ParticipantName::display($p)) ?></a>
            <?php if (($legal = ParticipantName::legalIfDifferent($p)) !== null): ?><br><span class="meta">Legal name: <?= e($legal) ?></span><?php endif; ?></th>
          <td><?= e($p['participant_code']) ?></td>
          <td><?= $p['status'] === 'Active' ? 'Active' : '<span class="badge badge-inactive">' . e($p['status']) . '</span>' ?></td>
          <td class="num"><?= (int) $p['pet_count'] ?></td>
          <td><?= $p['last_distribution_date'] !== null ? e($day($p['last_distribution_date'])) : '<span class="meta">never</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>
