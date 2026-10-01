<?php
/** @var \Pfpms\Http\Context $ctx  @var array $device  @var array<string, string> $errors  @var ?string $open  @var array $typed  @var string $revision
 *  @var ?array $liveCode  @var list<array> $recentUsers  @var bool $redemptionAvailable  @var bool $offlineAllowed  @var int $clockTolerance  @var int $graceHours  @var string $orgZone */
use Pfpms\Clock;
use Pfpms\Device\DeviceStatus;

$now = Clock::now();
$tz = $device['time_zone'] ?? $orgZone;
$build = DeviceStatus::currentBuild();
$status = DeviceStatus::describe($device, $now, $tz, $offlineAllowed, $build, $clockTolerance);
$code = $status['code'];
$id = (int) $device['device_id'];
$action = url('admin_device_edit.php', ['id' => $id]);
$heard = $device['last_seen_at'] !== null;
$pending = (int) $device['pending_count'];
$canErase = $ctx->can('device.erase');
$waiting = in_array($code, [DeviceStatus::AWAITING, DeviceStatus::NO_CODE], true);
$lost = (int) $device['revoked_lost'] === 1;
$offlineNote = 'If it is offline now: nothing changes on the tablet until it connects. It can still be used offline for up to ' . $graceHours
    . ' hours after someone last signed in on it, and its data is protected only by the passwords of the people who signed in on it.';
// Which action sections this state shows; a form's messages appear in its own section, or at the top when
// the section is gone (the tablet changed before the post).
$shown = [
    'rename' => $waiting || $code === DeviceStatus::IN_SERVICE,
    'retire' => $code === DeviceStatus::IN_SERVICE,
    'report_lost' => $code === DeviceStatus::RETIRING && !$lost,
    'erase' => $canErase && in_array($code, [DeviceStatus::IN_SERVICE, DeviceStatus::RETIRING], true),
];
$anchored = $open !== null && ($shown[$open] ?? false);
$fieldIf = fn(string $form) => $open === $form ? $errors : [];
$formError = fn(string $form) => $open === $form && isset($errors['_form']) ? '<div class="flash flash-error" role="alert">' . e($errors['_form']) . '</div>' : '';
?>
<p><a href="<?= e(url('admin_devices.php')) ?>">Devices</a></p>
<h1><?= e($device['label']) ?><?= $device['site_id'] !== null ? ' at ' . e($device['site_name']) : '' ?></h1>
<p>Tablet no. <?= $id ?> · <span class="<?= e($status['badge']) ?>"><?= e($status['label']) ?></span>
  <?= $status['detail'] !== null && $code !== DeviceStatus::IN_SERVICE ? '<span class="meta">' . e($status['detail']) . '</span>' : '' ?></p>
<?php foreach ($status['warnings'] as $w): ?><div class="flash flash-info" role="status"><?= e($w) ?></div><?php endforeach; ?>
<?php if (!$anchored): // the posted form's section is not on the page (or it has none): its messages go here ?>
  <?php foreach ($errors as $message): ?><div class="flash flash-error" role="alert"><?= e($message) ?></div><?php endforeach; ?>
<?php endif; ?>

<dl class="readonly-list">
  <dt>Site</dt><dd><?= $device['site_id'] !== null ? e($device['site_name']) . (!(int) $device['site_active'] ? ' (inactive)' : '') : 'No site' ?></dd>
  <?php if ($waiting || $code === DeviceStatus::CANCELLED): ?>
    <dt>Added</dt><dd>by <?= e($device['registered_by_name'] ?? 'someone') ?> on <?= e(DeviceStatus::at($device['registered_at'], $tz)) ?></dd>
  <?php else: ?>
    <dt>Registered</dt><dd><?= e(DeviceStatus::at($device['registered_at'], $tz)) ?><?= $device['registered_by_name'] !== null ? ' (' . e($device['registered_by_name']) . ')' : '' ?></dd>
  <?php endif; ?>
  <?php if ($liveCode !== null): ?>
    <dt>Registration code</dt><dd>works until <?= e(DeviceStatus::at($liveCode['expires_at'], $tz)) ?> (created by <?= e($liveCode['issued_by_name']) ?>)</dd>
  <?php endif; ?>
  <?php if ($device['revoked_at'] !== null && (int) $device['has_credential'] === 1): ?>
    <?php $escalated = $device['erase_requested_at'] !== null && $device['erase_requested_at'] !== $device['revoked_at']; ?>
    <dt>Out of service</dt><dd>since <?= e(DeviceStatus::at($device['revoked_at'], $tz)) ?>, by <?= e($device['revoked_by_name'] ?? 'someone') ?>:
      <?= $device['wipe_mode'] === 'Wipe Now' && !$escalated ? 'erase now' : 'retire (upload, then erase)' ?><?= $lost ? ', reported lost or stolen' : '' ?></dd>
    <?php if ($escalated): ?>
      <dt>Erase now</dt><dd>requested <?= e(DeviceStatus::at($device['erase_requested_at'], $tz)) ?>, by <?= e($device['erase_requested_by_name'] ?? 'someone') ?></dd>
    <?php endif; ?>
  <?php elseif ($code === DeviceStatus::CANCELLED): ?>
    <dt>Cancelled</dt><dd><?= e(DeviceStatus::at($device['revoked_at'], $tz)) ?>, by <?= e($device['revoked_by_name'] ?? 'someone') ?></dd>
  <?php endif; ?>
  <?php if ($device['wiped_at'] !== null): ?><dt>Erased</dt><dd><?= e(DeviceStatus::at($device['wiped_at'], $tz)) ?></dd><?php endif; ?>
</dl>

<?php if (!$waiting && $code !== DeviceStatus::CANCELLED): ?>
  <section>
    <h2>What the tablet last reported</h2>
    <?php if (!$heard): ?>
      <p class="meta">Not reported yet. A tablet reports these once the Station app is running on it.</p>
    <?php else: ?>
      <dl class="readonly-list">
        <dt>Last heard from</dt><dd><?= e(DeviceStatus::ago($device['last_seen_at'], $now)) ?> (<?= e(DeviceStatus::at($device['last_seen_at'], $tz)) ?>)</dd>
        <dt>Unsynced records</dt><dd><?= number_format($pending) ?>, reported by the tablet at <?= e(DeviceStatus::at($device['last_seen_at'], $tz)) ?></dd>
        <dt>Last upload</dt><dd><?= $device['last_sync_at'] !== null ? e(DeviceStatus::at($device['last_sync_at'], $tz)) : 'never' ?></dd>
        <dt>Station build</dt><dd><?= $device['app_build'] !== null ? e($device['app_build']) . ($device['app_build'] !== $build ? ' (current is ' . e($build) . ')' : '') : 'not reported' ?></dd>
        <dt>Storage kept</dt><dd><?= (int) $device['storage_persisted'] ? 'Yes' : 'No' ?></dd>
        <?php $mode = $device['display_mode'] ?? null; $skew = $device['clock_skew_seconds'] ?? null; ?>
        <dt>Running as</dt><dd><?= $mode === null ? 'not reported' : ($mode === 'standalone' ? 'the installed app' : 'a browser tab (' . e($mode) . ')') ?></dd>
        <?php if (($device['oldest_pending_at'] ?? null) !== null && $pending > 0): ?>
          <dt>Oldest unsynced record</dt><dd>from <?= e(DeviceStatus::at($device['oldest_pending_at'], $tz)) ?></dd>
        <?php endif; ?>
        <?php if (($device['attention_count'] ?? null) !== null): ?>
          <dt>Records needing attention</dt><dd><?= number_format((int) $device['attention_count']) ?></dd>
        <?php endif; ?>
        <dt>Storage used</dt><dd><?= ($device['storage_estimate_kb'] ?? null) === null ? 'not reported' : e(number_format((int) $device['storage_estimate_kb'] / 1024, 1)) . ' MB' ?></dd>
        <dt>Clock</dt><dd><?= $skew === null ? 'not reported' : (abs((int) $skew) < 60 ? 'right'
            : e((string) (int) round(abs((int) $skew) / 60)) . ' minutes ' . ((int) $skew > 0 ? 'slow' : 'fast')) ?></dd>
        <?php if (($device['pbkdf2_iterations'] ?? null) !== null): ?>
          <dt>Encryption strength</dt><dd><?= number_format((int) $device['pbkdf2_iterations']) ?> rounds</dd>
        <?php endif; ?>
        <?php if ($code === DeviceStatus::IN_SERVICE): ?>
          <?php $why = DeviceStatus::onlineOnlyReason($device, $offlineAllowed); ?>
          <dt>Offline</dt><dd><?= e(DeviceStatus::serviceLabel($device, $offlineAllowed)) ?><?= $why !== null ? ': ' . e(lcfirst(rtrim($why, '.'))) : '' ?></dd>
        <?php endif; ?>
      </dl>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($recentUsers): ?>
  <section>
    <h2>People who signed in on this tablet</h2>
    <ul>
      <?php foreach ($recentUsers as $u): ?>
        <li><?= $ctx->can('user.manage') ? '<a href="' . e(url('admin_user_edit.php', ['id' => $u['user_id']])) . '">' . e($u['name']) . '</a>' : e($u['name']) ?>,
          last on <?= e(DeviceStatus::at($u['last_signed_in'], $tz)) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($lost): ?><p class="hint">If it was lost or stolen, reset their passwords: data on the tablet is protected by them.</p><?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($shown['rename']): ?>
  <section id="name">
    <h2>Name</h2>
    <?= $formError('rename') ?>
    <form method="post" action="<?= e($action) ?>#name" class="form form-narrow" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="revision" value="<?= e($revision) ?>">
      <label for="label">Tablet name</label>
      <input type="text" <?= field_attrs($fieldIf('rename'), 'label') ?> maxlength="50" value="<?= e($open === 'rename' ? $typed['label'] : $device['label']) ?>">
      <?= field_error($fieldIf('rename'), 'label') ?>
      <div class="form-actions"><button type="submit" name="action" value="rename" class="button">Save name</button></div>
    </form>
  </section>
<?php endif; ?>

<?php if ($waiting && (int) $device['site_active'] === 1): // no sheet can be made at an inactive site ?>
  <section>
    <h2>Registration sheet</h2>
    <?php if (!$redemptionAvailable): ?><p class="hint">This server cannot register tablets yet (the Station app is not installed here), so a sheet printed now will expire unused.</p><?php endif; ?>
    <p><a class="button button-primary" href="<?= e(url('admin_device_credential.php', ['id' => $id])) ?>"><?= $liveCode !== null ? 'Create a new registration sheet' : 'Create a registration sheet' ?></a></p>
    <?php if ($liveCode !== null): ?><p class="hint">A new sheet cancels the code on the earlier one.</p><?php endif; ?>
  </section>
  <section>
    <h2>Cancel this registration</h2>
    <p>If this tablet will not be set up after all. Its code stops working.</p>
    <form method="post" action="<?= e($action) ?>" class="form-actions">
      <?= csrf_field() ?>
      <input type="hidden" name="revision" value="<?= e($revision) ?>">
      <button type="submit" name="action" value="cancel" class="button">Cancel this registration</button>
    </form>
  </section>
<?php endif; ?>

<?php if ($shown['retire']): ?>
  <section id="retire">
    <h2>Retire this tablet</h2>
    <?= $formError('retire') ?>
    <p><strong>At once:</strong> everyone signed in to the Station app on this tablet is signed out, and its registration, PIN switching and offline
      permissions stop working. Records made on it after now are held for review, not added automatically.</p>
    <p><strong>Next time it connects:</strong> it uploads any records it has not sent, then erases itself.</p>
    <p><?= e($offlineNote) ?></p>
    <p class="hint">If it is lost or stolen and someone used this system in a web browser on it, sign them out or reset their password as well:
      a browser is not part of the tablet's registration.</p>
    <form method="post" action="<?= e($action) ?>#retire" class="form form-narrow" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="revision" value="<?= e($revision) ?>">
      <label for="reason">Why is it being retired?</label>
      <input type="text" <?= field_attrs($fieldIf('retire'), 'reason') ?> maxlength="255" value="<?= $open === 'retire' ? e($typed['reason']) : '' ?>">
      <?= field_error($fieldIf('retire'), 'reason') ?>
      <label class="checkbox"><input type="checkbox" name="lost" value="1"<?= selected($open === 'retire' && $typed['lost'], 'checked') ?>> It is lost or stolen (Administrators are told at once)</label>
      <div class="form-actions"><button type="submit" name="action" value="retire" class="button">Retire this tablet</button></div>
    </form>
  </section>
<?php endif; ?>

<?php if ($shown['report_lost']): ?>
  <section id="report-lost">
    <h2>Report it lost or stolen</h2>
    <?= $formError('report_lost') ?>
    <p>If this retired tablet has gone missing: Administrators are told at once, and records it still uploads are held for review instead of being added.</p>
    <form method="post" action="<?= e($action) ?>#report-lost" class="form form-narrow" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="revision" value="<?= e($revision) ?>">
      <label for="lost_reason">What happened?</label>
      <input type="text" <?= field_attrs($fieldIf('report_lost'), 'lost_reason') ?> maxlength="255" value="<?= $open === 'report_lost' ? e($typed['lost_reason']) : '' ?>">
      <?= field_error($fieldIf('report_lost'), 'lost_reason') ?>
      <div class="form-actions"><button type="submit" name="action" value="report_lost" class="button">Report it lost or stolen</button></div>
    </form>
  </section>
<?php endif; ?>

<?php if ($shown['erase']): ?>
  <?php $recount = $open === 'erase' && isset($errors['seen_pending']); // the count went up since the page opened: posting again goes ahead ?>
  <section id="erase">
    <h2><?= $code === DeviceStatus::RETIRING ? 'Erase now instead' : 'Erase now' ?></h2>
    <?= $formError('erase') ?>
    <?php if ($recount): ?><div class="flash flash-error" role="alert" id="seen_pending-error"><?= e($errors['seen_pending']) ?></div><?php endif; ?>
    <p>Erase now is for a tablet that is lost, stolen or may have been tampered with. The next time it connects it erases everything, without uploading.
      <?= $heard ? 'It last reported ' . number_format($pending) . ' unsynced record' . ($pending === 1 ? '' : 's') . ' (' . e(DeviceStatus::ago($device['last_seen_at'], $now)) . ').'
        : 'It has not reported how many records it holds.' ?>
      Those are distributions that already happened: if they are lost, stock and eligibility will not include them.
      <?= $code === DeviceStatus::RETIRING ? 'If it only needs to be retired, leave it as it is: it uploads its records before it erases itself.'
        : 'If the tablet is simply no longer needed, use Retire instead.' ?></p>
    <p><?= e($offlineNote) ?></p>
    <form method="post" action="<?= e($action) ?>#erase" class="form form-narrow" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="revision" value="<?= e($revision) ?>">
      <input type="hidden" name="seen_pending" value="<?= $pending ?>">
      <?php if ($recount): ?><input type="hidden" name="pending_ack" value="1"><?php endif; ?>
      <label for="erase-reason">Why must it be erased at once?</label>
      <input type="text" id="erase-reason" name="reason" maxlength="255" value="<?= $open === 'erase' ? e($typed['reason']) : '' ?>"
        <?= $open === 'erase' && isset($errors['reason']) ? 'aria-invalid="true" aria-describedby="erase-reason-error"' : '' ?>>
      <?php if ($open === 'erase' && isset($errors['reason'])): ?><p class="field-error" id="erase-reason-error"><?= e($errors['reason']) ?></p><?php endif; ?>
      <label for="confirm_label">Type the tablet's name to confirm: <?= e($device['label']) ?></label>
      <input type="text" <?= field_attrs($fieldIf('erase'), 'confirm_label') ?> maxlength="50" autocomplete="off" value="<?= $open === 'erase' ? e($typed['confirm_label']) : '' ?>">
      <?= field_error($fieldIf('erase'), 'confirm_label') ?>
      <div class="form-actions"><button type="submit" name="action" value="erase" class="button button-danger">Erase this tablet</button></div>
    </form>
  </section>
<?php endif; ?>

<?php if ($code === DeviceStatus::IN_SERVICE): ?>
  <p class="hint">To move a tablet to another site, retire it here, then add it at the new site and register it again with a new sheet.</p>
<?php elseif ($waiting): ?>
  <p class="hint">To set it up at another site instead, cancel this registration, then add it at the new site.</p>
<?php endif; ?>
