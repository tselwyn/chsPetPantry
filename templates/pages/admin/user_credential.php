<?php
/** @var \Pfpms\Http\Context $ctx  @var array $account  @var ?array $sheet  @var ?string $error */
$org = \Pfpms\Settings::string('organisation_name', 'CHS Pet Pantry');
$tz = $ctx->site()['time_zone'] ?? 'America/New_York';
$pending = $account['status'] === 'Pending'; // as it was before this sheet (a reset sheet leaves it Active)
?>
<?php if ($sheet === null): ?>
  <h1><?= $pending ? 'Activation sheet' : 'Password reset sheet' ?> for <?= e(trim($account['first_name'] . ' ' . $account['last_name'])) ?></h1>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <p class="lead">This creates a new link for <?= e($account['username']) ?> to choose a password, and cancels any earlier link or invitation.
    Print it and hand it to the person; the link is shown only once.</p>
  <?php if (!$pending): ?>
    <p class="flash flash-info">This is a password reset: their current password stops working at once, they are signed out everywhere, and they are told by email.</p>
  <?php endif; ?>
  <form method="post" action="<?= e(url('admin_user_credential.php', ['id' => $account['user_id']])) ?>" class="form-actions">
    <?= csrf_field() ?>
    <button type="submit" class="button button-primary">Create and show the sheet</button>
    <a class="button" href="<?= e(url('admin_user_edit.php', ['id' => $account['user_id']])) ?>">Cancel</a>
  </form>
<?php else: ?>
  <div class="no-print form-actions">
    <p class="flash flash-info">Print this page now. Once you leave it, the link cannot be shown again.</p>
    <a class="button" href="<?= e(url('admin_user_edit.php', ['id' => $account['user_id']])) ?>">Done</a>
  </div>
  <article class="print-sheet">
    <h1><?= e($org) ?>: <?= $pending ? 'set up your account' : 'choose a new password' ?></h1>
    <p>Hello <?= e($account['first_name']) ?>,</p>
    <p>Your username is <strong><?= e($account['username']) ?></strong>.</p>
    <p>Scan the code, or type the link into a browser, to choose your password. The link works once, until
      <strong><?= e(local_time(\Pfpms\Clock::db($sheet['expires']), $tz)) ?></strong>.</p>
    <img src="<?= e($sheet['qr']) ?>" alt="QR code for the account setup link" width="220" height="220" class="qr">
    <p class="sheet-link"><?= e($sheet['link']) ?></p>
    <p>Keep this sheet private: anyone with it can set the password for this account. Destroy it once you have signed in.</p>
  </article>
<?php endif; ?>
