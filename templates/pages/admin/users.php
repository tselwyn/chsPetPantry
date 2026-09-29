<?php
/** @var \Pfpms\Http\Context $ctx  @var array $filters  @var list<array> $users */
$tz = $ctx->site()['time_zone'] ?? 'America/New_York';
?>
<div class="page-head">
  <h1>User accounts</h1>
  <a class="button button-primary" href="<?= e(url('admin_user_create.php')) ?>">Invite a person</a>
</div>
<form method="get" action="<?= e(url('admin_users.php')) ?>" class="filters">
  <div class="form-grid">
    <div>
      <label for="q">Name, username or email</label>
      <input type="text" id="q" name="q" value="<?= e($filters['q']) ?>">
    </div>
    <div>
      <label for="role">Role</label>
      <select id="role" name="role">
        <option value="">Any role</option>
        <?php foreach (\Pfpms\Auth\Rbac::ROLES as $role): ?>
          <option value="<?= e($role) ?>"<?= selected($filters['role'] === $role) ?>><?= e($role) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Any status</option>
        <?php foreach (['Pending' => 'Invited, not yet activated', 'Active' => 'Active', 'Locked' => 'Locked', 'Inactive' => 'Deactivated'] as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= selected($filters['status'] === $value) ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="form-actions"><button type="submit" class="button">Show</button> <a class="button" href="<?= e(url('admin_users.php')) ?>">Clear</a></div>
</form>

<?php if (!$users): ?>
  <p class="meta">No accounts match.</p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Sites</th><th scope="col">Status</th><th scope="col">Last sign-in</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <?php $status = \Pfpms\Account\AccountStatus::label($u); ?>
      <tr<?= $u['status'] === 'Inactive' ? ' class="status-inactive"' : '' ?>>
        <td>
          <a href="<?= e(url('admin_user_edit.php', ['id' => $u['user_id']])) ?>"><?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></a>
          <div class="meta"><?= e($u['username']) ?> &middot; <?= e($u['email']) ?></div>
        </td>
        <td><?= e($u['role']) ?></td>
        <td><?= e(\Pfpms\Auth\Rbac::can($u['role'], 'site.all') ? 'All sites' : ($u['site_names'] ?? 'None')) ?></td>
        <td><?= $status === 'Active' ? e($status) : '<span class="badge">' . e($status) . '</span>' ?></td>
        <td><?= $u['last_login_at'] ? e(local_time($u['last_login_at'], $tz)) : '<span class="meta">Never</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
