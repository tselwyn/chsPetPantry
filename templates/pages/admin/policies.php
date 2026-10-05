<?php
/** @var \Pfpms\Http\Context $ctx  @var list<array{doc_type: string, documents: list<array>, shown: list<array>}> $types */
$about = [
    'Confidentiality Agreement' => 'Staff and volunteers accept this before they can use the system, and again when it is due.',
    'Programme Consent' => 'A participant agrees to this when they register. The version they agreed to is kept with their record.',
    'Retention Notice' => 'Tells participants how long their records are kept.',
    'SNV Explanation' => 'Explains the spay/neuter voucher programme to participants.',
];
$day = static fn(string $date): string => date('M j, Y', (int) strtotime($date));
?>
<div class="page-head">
  <h1>Policy texts</h1>
  <a class="button button-primary" href="<?= e(url('admin_policy_edit.php')) ?>">Add a policy text</a>
</div>
<p class="lead">Each text can have several versions, each in several languages. The version in force is the newest one whose start date has arrived.
  Where a language has no translation of it, readers see the default language instead.</p>
<p class="hint">Once someone has accepted a version, or a participant has agreed to it, it is locked so the record of what they agreed to never changes.
  To change the wording, create a new version.</p>

<?php foreach ($types as $type): ?>
  <section>
    <h2><?= e($type['doc_type']) ?></h2>
    <p class="meta"><?= e($about[$type['doc_type']] ?? '') ?></p>
    <?php if (!$type['documents']): ?>
      <p>No text yet. <a href="<?= e(url('admin_policy_edit.php', ['type' => $type['doc_type']])) ?>">Add the first version</a>.</p>
    <?php else: ?>
      <h3 class="meta">Shown today</h3>
      <ul>
        <?php foreach ($type['shown'] as $s): ?>
          <li>
            <strong><?= e($s['language_name']) ?>:</strong>
            <?php if ($s['document'] === null): ?>
              nothing in force yet.
            <?php elseif ($s['fallback']): ?>
              the <?= e($s['document_language_name']) ?> text, version <?= e($s['document']['version']) ?>
              (there is no <?= e($s['language_name']) ?> translation of the version in force).
            <?php else: ?>
              version <?= e($s['document']['version']) ?>, in force since <?= e($day($s['document']['effective_from'])) ?>.
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th scope="col">Version</th><th scope="col">Language</th><th scope="col">Start date</th><th scope="col">Status</th><th scope="col">Changes</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        <?php foreach ($type['documents'] as $d): ?>
          <tr<?= $d['status'] === 'replaced' ? ' class="status-inactive"' : '' ?>>
            <td><a href="<?= e(url('admin_policy_edit.php', ['id' => $d['document_id']])) ?>"><?= e($d['version']) ?></a></td>
            <td><?= e($d['language_name']) ?></td>
            <td><?= e($day($d['effective_from'])) ?></td>
            <td>
              <?php if ($d['status'] === 'in_force'): ?><span class="badge">In force</span>
              <?php elseif ($d['status'] === 'scheduled'): ?>Not yet in force
              <?php else: ?>Replaced<?php endif; ?>
            </td>
            <td><?= $d['is_used'] ? 'Locked (in use)' : 'Can still be edited' ?></td>
            <td>
              <a class="button" href="<?= e(url('admin_policy_edit.php', ['id' => $d['document_id']])) ?>"><?= $d['is_used'] ? 'View' : 'Edit' ?></a>
              <a class="button" href="<?= e(url('admin_policy_edit.php', ['from' => $d['document_id'], 'as' => 'version'])) ?>">New version</a>
              <a class="button" href="<?= e(url('admin_policy_edit.php', ['from' => $d['document_id'], 'as' => 'translation'])) ?>">Translate</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
