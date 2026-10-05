<?php
/** @var \Pfpms\Http\Context $ctx  @var array $device  @var ?array $sheet  @var ?string $error  @var string $tz  @var string $revision  @var ?array $liveCode
 *  @var int $minutes  @var bool $redemptionAvailable */
use Pfpms\Clock;
use Pfpms\Device\DeviceStatus;
use Pfpms\Device\RegistrationSheet;

$org = \Pfpms\Settings::string('organisation_name', 'CHS Pet Pantry');
$id = (int) $device['device_id'];
$code = DeviceStatus::code($device);
$back = url('admin_device_edit.php', ['id' => $id]);
?>
<?php if ($sheet === null): ?>
  <p><a href="<?= e($back) ?>"><?= e($device['label']) ?></a></p>
  <h1>Registration sheet for <?= e($device['label']) ?></h1>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$redemptionAvailable): ?>
    <div class="flash flash-info" role="status">Tablets cannot be registered on this server yet: the Station app is not installed here. A sheet printed now will expire unused.</div>
  <?php endif; ?>
  <?php if (in_array($code, [DeviceStatus::AWAITING, DeviceStatus::NO_CODE], true) && (int) $device['site_active'] === 1): ?>
    <p class="lead">This creates a registration code for <strong><?= e($device['label']) ?></strong> at <strong><?= e($device['site_name']) ?></strong>.
      It works once, until about <?= e(DeviceStatus::at(Clock::db(Clock::now()->modify("+$minutes minutes")), $tz)) ?>, and is shown only once, so have the
      printer or the tablet ready.</p>
    <?php if ($liveCode !== null): ?>
      <p class="flash flash-info">It cancels the code <?= e($liveCode['issued_by_name']) ?> created, which works until <?= e(DeviceStatus::at($liveCode['expires_at'], $tz)) ?>.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('admin_device_credential.php', ['id' => $id])) ?>" class="form-actions">
      <?= csrf_field() ?>
      <input type="hidden" name="revision" value="<?= e($revision) ?>">
      <button type="submit" class="button button-primary">Create and show the sheet</button>
      <a class="button" href="<?= e($back) ?>">Cancel</a>
    </form>
  <?php else: ?>
    <p class="lead"><?= match (true) {
        (int) $device['has_credential'] === 1 && $device['revoked_at'] === null => 'This tablet is already registered. To register another tablet, add it as a new tablet.',
        $device['revoked_at'] !== null => 'This tablet was taken out of service, so it cannot be registered again. Add it as a new tablet.',
        default => "This tablet's site is not active. Reactivate the site first, or cancel this registration.",
    } ?></p>
    <p><a class="button" href="<?= e($back) ?>">Back to the tablet</a></p>
  <?php endif; ?>
<?php else: ?>
  <div class="no-print">
    <p class="flash flash-info">Print this page now, or let the tablet scan the code from this screen. Once you leave this page the code cannot be shown again.</p>
    <div class="form-actions">
      <a class="button" href="<?= e($back) ?>">Done</a>
      <a class="button" href="<?= e(url('admin_device_edit.php', ['site' => $device['site_id']])) ?>">Add another tablet</a>
    </div>
  </div>
  <article class="print-sheet">
    <h1><?= e($org) ?>: register a tablet</h1>
    <p class="sheet-site">For <?= e($device['site_name']) ?></p>
    <p>Tablet name: <strong><?= e($device['label']) ?></strong> · Tablet no. <?= $id ?></p>
    <ol>
      <li>On the tablet, connect to the internet and open <strong class="sheet-url"><?= e($sheet['station_url']) ?></strong> in Chrome (Android tablet or Chromebook) or Safari (iPad).</li>
      <li>Install it: in Chrome choose <em>Install app</em> or <em>Add to Home screen</em>; on an iPad tap <em>Share</em>, then <em>Add to Home Screen</em>.</li>
      <li>Close the browser and open <strong><?= e(RegistrationSheet::APP_NAME) ?></strong> from the home screen.</li>
      <li>Choose <strong>Register this tablet</strong>, then scan the square code below or type the code.</li>
      <li>Check that the tablet says <em>Registered to <?= e($device['site_name']) ?> as <?= e($device['label']) ?></em>.</li>
    </ol>
    <img src="<?= e($sheet['qr']) ?>" alt="Registration code for this tablet" width="220" height="220" class="qr">
    <p class="sheet-code"><?= e($sheet['formatted']) ?></p>
    <p>Scan it from inside the app, not with the camera app.</p>
    <p>This code works once, until <strong><?= e(DeviceStatus::at($sheet['expires_at'], $tz)) ?></strong>.</p>
    <p>Created by <?= e($sheet['created_by']) ?> on <?= e(DeviceStatus::at(Clock::db(), $tz)) ?>.</p>
    <p>Anyone with this sheet can register a tablet for <?= e($device['site_name']) ?> until then. Keep it with you and destroy it once the tablet is registered.</p>
  </article>
<?php endif; ?>
