<?php /** @var \Pfpms\Http\Context $ctx  @var list<array{name:string,settings:array<string,array>}> $groups  @var array<string,string> $posted  @var array<string,string> $errors */ ?>
<?php
$labels = [];
foreach ($groups as $group) {
    foreach ($group['settings'] as $key => $s) {
        $labels[$key] = $s['label'];
    }
}
?>
<h1>Settings</h1>
<p class="lead">These settings apply to every site. Changes take effect straight away and are recorded in the audit log.</p>
<?php if ($errors): ?>
  <div class="flash flash-error" role="alert">
    <p>Nothing was saved. Please correct these settings:</p>
    <ul>
      <?php foreach ($errors as $key => $message): ?>
        <li><a href="#<?= e($key) ?>"><?= e($labels[$key] ?? $key) ?></a>: <?= e($message) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url('admin_settings.php')) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <?php foreach ($groups as $group): ?>
    <fieldset>
      <legend><?= e($group['name']) ?></legend>
      <?php foreach ($group['settings'] as $key => $s): ?>
        <?php
        $value = (string) ($posted[$key] ?? $s['value']);
        $label = $s['label'] . (isset($s['unit']) ? ' (' . $s['unit'] . ')' : '');
        ?>
        <input type="hidden" name="orig[<?= e($key) ?>]" value="<?= e($original[$key] ?? $s['value']) ?>">
        <?php if ($s['type'] === 'bool'): ?>
          <input type="hidden" name="<?= e($key) ?>" value="0">
          <label class="checkbox">
            <input type="checkbox" <?= field_attrs($errors, $key) ?> value="1"<?= selected(in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true), 'checked') ?>>
            <?= e($label) ?>
          </label>
        <?php else: ?>
          <label for="<?= e($key) ?>"><?= e($label) ?></label>
          <?php if ($s['type'] === 'enum'): ?>
            <select <?= field_attrs($errors, $key) ?>>
              <?php foreach ($s['choices'] as $option => $optionLabel): ?>
                <option value="<?= e($option) ?>"<?= selected((string) $option === $value) ?>><?= e($optionLabel) ?></option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($s['type'] === 'int' || $s['type'] === 'float'): ?>
            <input type="number" <?= field_attrs($errors, $key) ?> min="<?= e($s['min']) ?>" max="<?= e($s['max']) ?>"
                   step="<?= $s['type'] === 'int' ? '1' : 'any' ?>" inputmode="<?= $s['type'] === 'int' ? 'numeric' : 'decimal' ?>" value="<?= e($value) ?>"<?= empty($s['optional']) ? ' required' : '' ?>>
          <?php elseif ($s['type'] === 'mmdd'): ?>
            <input type="text" <?= field_attrs($errors, $key) ?> maxlength="5" inputmode="numeric" placeholder="MM-DD" value="<?= e($value) ?>" required>
          <?php else: ?>
            <input type="text" <?= field_attrs($errors, $key) ?> maxlength="<?= (int) $s['max'] ?>" value="<?= e($value) ?>"<?= empty($s['optional']) ? ' required' : '' ?>>
          <?php endif; ?>
        <?php endif; ?>
        <?= field_error($errors, $key) ?>
        <?php if ($s['hint'] !== ''): ?><p class="hint"><?= e($s['hint']) ?></p><?php endif; ?>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>

  <div class="form-actions">
    <button type="submit" class="button button-primary">Save settings</button>
    <a class="button" href="<?= e(url('admin_settings.php')) ?>">Discard changes</a>
  </div>
</form>
