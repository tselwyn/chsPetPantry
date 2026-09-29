<?php
/** @var \Pfpms\Http\Context $ctx  @var list<array> $languages  @var string $defaultCode  @var array $values  @var array<string,string> $errors */
/** @var array{code: ?string, name: string, error: ?string} $rename */
?>
<div class="page-head">
  <h1>Languages</h1>
</div>
<p class="lead">The languages participants can choose, and that policy texts, receipts and vouchers can be written in.</p>
<p class="hint">A language cannot be deleted, because records refer to it. Deactivate it to stop offering it; records already in that language keep it.</p>

<?php if (!$languages): ?>
  <p class="meta">No languages yet. Add the first one below.</p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">Code</th><th scope="col">Name</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($languages as $l):
        $code = (string) $l['language_code'];
        $isDefault = strcasecmp($code, $defaultCode) === 0;
        $renameError = $rename['code'] !== null && strcasecmp($rename['code'], $code) === 0 ? $rename['error'] : null;
        $inputId = 'rename-' . $code; ?>
      <tr<?= $l['is_active'] ? '' : ' class="status-inactive"' ?>>
        <td><?= e($code) ?></td>
        <td>
          <form method="post" action="<?= e(url('admin_languages.php')) ?>" class="inline-form" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="language_code" value="<?= e($code) ?>">
            <label for="<?= e($inputId) ?>" class="visually-hidden">Name of the language with code <?= e($code) ?></label>
            <input type="text" id="<?= e($inputId) ?>" name="name" maxlength="50" required
                   value="<?= e($renameError !== null ? $rename['name'] : $l['name']) ?>"<?= $renameError !== null ? ' aria-invalid="true" aria-describedby="' . e($inputId) . '-error"' : '' ?>>
            <?php if ($renameError !== null): ?><p class="field-error" id="<?= e($inputId) ?>-error"><?= e($renameError) ?></p><?php endif; ?>
            <button type="submit" class="button">Save name</button>
          </form>
        </td>
        <td>
          <?= $l['is_active'] ? 'Active' : '<span class="badge badge-inactive">Inactive</span>' ?>
          <?php if ($isDefault): ?><span class="badge">Default</span><?php endif; ?>
        </td>
        <td>
          <?php if ($isDefault && $l['is_active']): ?>
            <span class="meta">The default language stays active.</span>
          <?php else: ?>
            <form method="post" action="<?= e(url('admin_languages.php')) ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="language_code" value="<?= e($code) ?>">
              <button type="submit" name="action" value="<?= $l['is_active'] ? 'deactivate' : 'activate' ?>" class="button">
                <?= $l['is_active'] ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<h2>Add a language</h2>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('admin_languages.php')) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <div class="form-grid">
    <div>
      <label for="language_code">Code</label>
      <input type="text" <?= field_attrs($errors, 'language_code') ?> maxlength="10" autocomplete="off" value="<?= e($values['language_code']) ?>" required>
      <?= field_error($errors, 'language_code') ?>
    </div>
    <div>
      <label for="name">Name</label>
      <input type="text" <?= field_attrs($errors, 'name') ?> maxlength="50" value="<?= e($values['name']) ?>" required>
      <?= field_error($errors, 'name') ?>
    </div>
  </div>
  <p class="hint">Use the standard short code: en for English, es for Spanish, vi for Vietnamese, or pt-BR for Brazilian Portuguese.</p>
  <div class="form-actions">
    <button type="submit" class="button button-primary">Add language</button>
  </div>
</form>
