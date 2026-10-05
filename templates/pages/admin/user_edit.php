<?php
/** @var \Pfpms\Http\Context $ctx  @var array $account  @var array $values  @var array<string,string> $errors  @var list<array> $sites
 *  @var list<int> $selectedSites  @var bool $selfEdit  @var array $deactivateValues  @var string $reactivateReason  @var list<array> $temporaryGrants */
$tz = $ctx->site()['time_zone'] ?? 'America/New_York';
$lockedNow = \Pfpms\Account\AccountStatus::isLocked($account);
$pending = $account['status'] === 'Pending';
$inactive = $account['status'] === 'Inactive';
$scheduled = !$inactive && $account['deactivation_effective_date'] !== null;
// Same rules as sign-in, so the page never says Active when the person cannot sign in.
$statusText = match (true) {
    $pending => 'Invited: the person has not chosen a password yet.',
    $inactive => 'Deactivated' . ($account['deactivated_reason'] ? ': ' . $account['deactivated_reason'] : '') . '.',
    $lockedNow => 'Locked after too many wrong passwords' . ($account['locked_until'] ? ' until ' . local_time($account['locked_until'], $tz) : '') . '.',
    default => match (\Pfpms\Auth\AccountRules::blockReason($account)) {
        'inactive' => 'Deactivated from ' . $account['deactivation_effective_date'] . ' (' . $account['deactivated_reason'] . '); the person can no longer sign in.',
        'expired' => 'Ended on ' . $account['expiry_date'] . '; the person can no longer sign in. Change the end date to give access again.',
        'not_started' => 'Starts on ' . $account['start_date'] . '; the person cannot sign in before then.',
        default => $scheduled
            ? 'Active; deactivated from ' . $account['deactivation_effective_date'] . ' (' . $account['deactivated_reason'] . ').'
            : ($account['expiry_date'] ? 'Active until the end of ' . $account['expiry_date'] . '.' : 'Active.'),
    },
};
?>
<h1><?= e(trim($account['first_name'] . ' ' . $account['last_name'])) ?> <span class="meta">(<?= e($account['username']) ?>)</span></h1>
<p class="lead">
  <?= e($statusText) ?>
  Last sign-in: <?= $account['last_login_at'] ? e(local_time($account['last_login_at'], $tz)) : 'never' ?>.
</p>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<?php if ($selfEdit): ?>
  <div class="flash flash-info" role="status">This is your own account. Your role, sites, dates, email, export permission and status can only be changed by another Administrator.</div>
<?php endif; ?>

<section>
  <h2>Details, role and sites</h2>
  <form method="post" action="<?= e(url('admin_user_edit.php', ['id' => $account['user_id']])) ?>" class="form form-narrow" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="row_version" value="<?= (int) $account['row_version'] ?>">
    <?php include APP_ROOT . '/templates/partials/user_fields.php'; ?>
    <?php if ($temporaryGrants): ?>
      <p class="meta">Temporary site access:
        <?php foreach ($temporaryGrants as $g): ?>
          <?= e($g['site_name']) ?> until <?= e(local_time($g['ends_at'], $tz)) ?><?= $g['grant_reason'] ? ' (' . e($g['grant_reason']) . ')' : '' ?>;
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <label for="reason">Reason for a change of role, sites, dates, email or export permission</label>
    <input type="text" <?= field_attrs($errors, 'reason') ?> maxlength="255" value="<?= e($values['reason'] ?? '') ?>">
    <?= field_error($errors, 'reason') ?>
    <p class="hint">Kept in the audit log. Such a change signs the person out of every device at once.</p>
    <div class="form-actions">
      <button type="submit" name="action" value="save" class="button button-primary">Save account</button>
      <a class="button" href="<?= e(url('admin_users.php')) ?>">Back to accounts</a>
    </div>
  </form>
</section>

<?php if (!$selfEdit): ?>
<section>
  <h2>Sign-in</h2>
  <div class="form-actions">
    <?php if ($pending): ?>
      <form method="post" action="<?= e(url('admin_user_edit.php', ['id' => $account['user_id']])) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" name="action" value="resend" class="button">Send a new invitation</button>
      </form>
    <?php elseif (!$inactive): ?>
      <form method="post" action="<?= e(url('admin_user_edit.php', ['id' => $account['user_id']])) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" name="action" value="reset" class="button">Send a password reset</button>
      </form>
      <?php if ($lockedNow): ?>
        <form method="post" action="<?= e(url('admin_user_edit.php', ['id' => $account['user_id']])) ?>" class="inline-form">
          <?= csrf_field() ?>
          <button type="submit" name="action" value="unlock" class="button">Unlock without a reset</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!$inactive): ?>
      <a class="button" href="<?= e(url('admin_user_credential.php', ['id' => $account['user_id']])) ?>"><?= $pending ? 'Print an activation sheet' : 'Print a password reset sheet' ?></a>
    <?php endif; ?>
  </div>
  <p class="hint">Use a printed sheet when email does not reach the person. Each new link or sheet cancels the earlier ones.
    <?= $pending ? '' : 'A reset, emailed or printed, stops the current password working at once, and the person is told by email.' ?></p>
</section>

<section>
  <h2><?= $inactive || $scheduled ? 'Reactivate' : 'Deactivate' ?></h2>
  <?php if ($inactive || $scheduled): ?>
    <form method="post" action="<?= e(url('admin_user_edit.php', ['id' => $account['user_id']])) ?>" class="form form-narrow" novalidate>
      <?= csrf_field() ?>
      <label for="reactivate_reason">Reason</label>
      <input type="text" <?= field_attrs($errors, 'reactivate_reason') ?> maxlength="255" value="<?= e($reactivateReason) ?>" placeholder="For example: back for the spring term">
      <?= field_error($errors, 'reactivate_reason') ?>
      <div class="form-actions">
        <button type="submit" name="action" value="reactivate" class="button"><?= $inactive ? 'Reactivate this account' : 'Cancel the scheduled deactivation' ?></button>
      </div>
    </form>
  <?php else: ?>
    <form method="post" action="<?= e(url('admin_user_edit.php', ['id' => $account['user_id']])) ?>" class="form form-narrow" novalidate>
      <?= csrf_field() ?>
      <label for="deactivate_reason">Reason</label>
      <input type="text" <?= field_attrs($errors, 'deactivate_reason') ?> maxlength="255" value="<?= e($deactivateValues['deactivate_reason']) ?>" placeholder="For example: left the programme">
      <?= field_error($errors, 'deactivate_reason') ?>
      <label for="effective_date">From (leave blank for now)</label>
      <input type="date" <?= field_attrs($errors, 'effective_date') ?> value="<?= e($deactivateValues['effective_date']) ?>">
      <?= field_error($errors, 'effective_date') ?>
      <p class="hint">Nothing is deleted: everything the person recorded stays in the history under their name.</p>
      <div class="form-actions">
        <button type="submit" name="action" value="deactivate" class="button">Deactivate</button>
      </div>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
