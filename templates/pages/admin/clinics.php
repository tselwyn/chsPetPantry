<?php /** @var \Pfpms\Http\Context $ctx  @var list<array> $clinics */ ?>
<div class="page-head">
  <h1>Clinics</h1>
  <a class="button button-primary" href="<?= e(url('admin_clinic_edit.php')) ?>">Add a clinic</a>
</div>
<?php if (!$clinics): ?>
  <p class="meta">No clinics yet. Add the clinics that take spay/neuter vouchers or offer low-cost vaccinations.</p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">Name</th><th scope="col">Partner</th><th scope="col">City</th><th scope="col">Phone</th><th scope="col">Low-cost vaccination</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($clinics as $c): $suspended = $c['status'] === 'Suspended'; ?>
      <tr<?= $suspended ? ' class="status-inactive"' : '' ?>>
        <td><a href="<?= e(url('admin_clinic_edit.php', ['id' => $c['clinic_id']])) ?>"><?= e($c['name']) ?></a></td>
        <td><?= $c['is_partner'] ? 'Yes' : 'No' ?></td>
        <td><?= e($c['city']) ?></td>
        <td><?= e(\Pfpms\Reference\ClinicService::formatPhone($c['phone'])) ?></td>
        <td><?= $c['offers_low_cost_vaccination'] ? 'Yes' : 'No' ?></td>
        <td><?= $suspended ? '<span class="badge badge-inactive">Suspended</span>' : 'Active' ?></td>
        <td>
          <form method="post" action="<?= e(url('admin_clinics.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="clinic_id" value="<?= (int) $c['clinic_id'] ?>">
            <button type="submit" name="action" value="<?= $suspended ? 'reactivate' : 'suspend' ?>" class="button">
              <?= $suspended ? 'Reactivate' : 'Suspend' ?>
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
