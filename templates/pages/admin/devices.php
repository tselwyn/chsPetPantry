<?php
/** @var \Pfpms\Http\Context $ctx  @var list<array> $devices  @var list<array{site_id: int, name: string}> $sites  @var ?int $siteFilter  @var bool $showAll
 *  @var bool $redemptionAvailable  @var bool $offlineAllowed  @var string $orgZone  @var bool $hasHidden */
use Pfpms\Clock;
use Pfpms\Device\DeviceStatus;

$now = Clock::now();
$build = DeviceStatus::currentBuild();
?>
<div class="page-head">
  <h1>Devices</h1>
  <?php if ($ctx->sites): ?><a class="button button-primary" href="<?= e(url('admin_device_edit.php', $siteFilter !== null ? ['site' => $siteFilter] : [])) ?>">Register a tablet</a><?php endif; ?>
</div>
<p class="lead">Tablets and Chromebooks that run the Station at your sites.</p>

<?php if (!$redemptionAvailable): ?>
  <div class="flash flash-info" role="status">Tablets cannot be registered on this server yet: the Station app is not installed here. Registration sheets
    can be printed, but they will expire unused, and tablets have nothing to report.</div>
<?php endif; ?>

<form method="get" action="<?= e(url('admin_devices.php')) ?>" class="filters">
  <div class="form-grid">
    <?php if (count($sites) > 1): ?>
      <div>
        <label for="site">Site</label>
        <select id="site" name="site">
          <option value="">Every site</option>
          <?php foreach ($sites as $s): ?>
            <option value="<?= (int) $s['site_id'] ?>"<?= selected($siteFilter === $s['site_id']) ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div>
      <label for="show">Show</label>
      <select id="show" name="show">
        <option value="">Tablets waiting, in service, retiring or being erased</option>
        <option value="all"<?= selected($showAll) ?>>Also cancelled and erased tablets</option>
      </select>
    </div>
  </div>
  <div class="form-actions"><button type="submit" class="button">Show</button></div>
</form>

<?php if (!$devices): ?>
  <p class="meta"><?= match (true) {
      !$ctx->can('site.all') && !$ctx->sites => 'You have no sites at the moment, so there are no tablets to show.',
      $hasHidden => 'No tablets match these choices. Choose Every site, or show cancelled and erased tablets too, to see more.',
      default => 'No tablets yet. Register the first tablet for a site.',
  } ?></p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">Tablet</th><th scope="col">Site</th><th scope="col">Status</th><th scope="col">Last heard from</th>
      <th scope="col">Unsynced (reported)</th><th scope="col">App build</th><th scope="col">Storage kept</th></tr></thead>
    <tbody>
    <?php foreach ($devices as $d):
        $status = DeviceStatus::describe($d, $now, $d['time_zone'] ?? $orgZone, $offlineAllowed, $build);
        $closed = in_array($status['code'], [DeviceStatus::ERASED, DeviceStatus::CANCELLED], true);
        $heard = $d['last_seen_at'] !== null; ?>
      <tr<?= $closed ? ' class="status-inactive"' : '' ?>>
        <th scope="row"><a href="<?= e(url('admin_device_edit.php', ['id' => $d['device_id']])) ?>"><?= e($d['label']) ?></a>
          <br><span class="meta">Tablet no. <?= (int) $d['device_id'] ?></span></th>
        <td><?= $d['site_id'] !== null ? e($d['site_name']) : '<span class="meta">No site</span>' ?>
          <?= $d['site_id'] !== null && !(int) $d['site_active'] ? ' <span class="badge badge-inactive">site inactive</span>' : '' ?></td>
        <td><span class="<?= e($status['badge']) ?>"><?= e($status['label']) ?></span>
          <?php if ($status['detail'] !== null || $status['warnings']): ?><br><span class="meta"><?= e($status['warnings'][0] ?? $status['detail']) ?></span><?php endif; ?></td>
        <td><?= $heard ? e(DeviceStatus::ago($d['last_seen_at'], $now)) : '<span class="meta">Never</span>' ?></td>
        <td><?= $heard ? e(number_format((int) $d['pending_count']) . ', as of ' . DeviceStatus::ago($d['last_seen_at'], $now)) : '<span class="meta">Not reported</span>' ?></td>
        <td><?= $d['app_build'] !== null ? e($d['app_build']) . ($d['app_build'] !== $build ? ' <span class="badge">older</span>' : '') : '<span class="meta">—</span>' ?></td>
        <td><?= $heard ? ((int) $d['storage_persisted'] ? 'Yes' : 'No') : '<span class="meta">Not reported</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
