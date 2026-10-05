<?php
/** @var ?array $doc  @var ?array $source  @var string $as  @var ?array{acknowledgements: int, consents: int} $usage  @var bool $locked */
/** @var array $values  @var array<string,string> $errors  @var list<string> $types  @var list<array> $languages  @var string $title */
$day = static fn(string $date): string => date('M j, Y', (int) strtotime($date));
$times = static fn(int $n): string => $n === 1 ? 'once' : "$n times";
$action = $doc !== null ? url('admin_policy_edit.php')
    : ($source !== null ? url('admin_policy_edit.php', ['from' => $source['document_id'], 'as' => $as]) : url('admin_policy_edit.php'));
?>
<h1><?= e($title) ?></h1>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>

<?php if ($doc !== null): ?>
  <dl class="readonly-list">
    <dt>Type</dt><dd><?= e($doc['doc_type']) ?></dd>
    <dt>Version</dt><dd><?= e($doc['version']) ?></dd>
    <dt>Language</dt><dd><?= e($doc['language_name']) ?></dd>
    <?php if ($locked): ?><dt>Start date</dt><dd><?= e($day($doc['effective_from'])) ?></dd><?php endif; ?>
    <dt>Used</dt>
    <dd>
      <?php if (!$locked): ?>
        Not yet. It can be changed until someone accepts it or a participant agrees to it.
      <?php else: ?>
        <?php if ($usage['acknowledgements'] > 0): ?>Accepted by staff <?= e($times($usage['acknowledgements'])) ?>.<?php endif; ?>
        <?php if ($usage['consents'] > 0): ?>Agreed to by participants <?= e($times($usage['consents'])) ?>.<?php endif; ?>
      <?php endif; ?>
    </dd>
  </dl>
<?php endif; ?>

<?php if ($doc !== null && $locked): ?>
  <div class="flash flash-info">This version is locked because it is in use: it is the record of what people agreed to, so it cannot be changed.
    To change the wording, create a new version.</div>
  <article class="policy-text">
    <?php foreach (preg_split('/\R{2,}/', trim((string) $doc['body'])) ?: [] as $paragraph): ?>
      <p><?= nl2br(e($paragraph)) ?></p>
    <?php endforeach; ?>
  </article>
  <div class="form-actions">
    <a class="button button-primary" href="<?= e(url('admin_policy_edit.php', ['from' => $doc['document_id'], 'as' => 'version'])) ?>">Create a new version</a>
    <a class="button" href="<?= e(url('admin_policy_edit.php', ['from' => $doc['document_id'], 'as' => 'translation'])) ?>">Add a translation</a>
    <a class="button" href="<?= e(url('admin_policies.php')) ?>">Back to policy texts</a>
  </div>
<?php else: ?>
  <?php if ($source !== null): ?>
    <p class="lead">
      Starting from <?= e($source['doc_type']) ?> version <?= e($source['version']) ?> in <?= e($source['language_name']) ?>.
      <?= $as === 'translation' ? 'Choose the language, then replace the copied text with the translation.' : 'Give the new version a number and a start date, then change the text.' ?>
    </p>
  <?php endif; ?>
  <form method="post" action="<?= e($action) ?>" class="form" novalidate>
    <?= csrf_field() ?>
    <?php if ($doc !== null): ?>
      <input type="hidden" name="document_id" value="<?= (int) $doc['document_id'] ?>">
    <?php else: ?>
      <div class="form-grid">
        <div>
          <label for="doc_type">Type</label>
          <select <?= field_attrs($errors, 'doc_type') ?>>
            <?php foreach ($types as $type): ?>
              <option value="<?= e($type) ?>"<?= selected($type === $values['doc_type']) ?>><?= e($type) ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'doc_type') ?>
        </div>
        <div>
          <label for="version">Version</label>
          <input type="text" <?= field_attrs($errors, 'version') ?> maxlength="10" autocomplete="off" value="<?= e($values['version']) ?>" required>
          <?= field_error($errors, 'version') ?>
        </div>
        <div>
          <label for="language_code">Language</label>
          <select <?= field_attrs($errors, 'language_code') ?>>
            <option value="">Choose a language</option>
            <?php foreach ($languages as $l): ?>
              <option value="<?= e($l['language_code']) ?>"<?= selected($l['language_code'] === $values['language_code']) ?>><?= e($l['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?= field_error($errors, 'language_code') ?>
        </div>
      </div>
      <p class="hint">Translations of the same text share one version number, such as version 2 in English and version 2 in Spanish.</p>
    <?php endif; ?>

    <div class="form-grid">
      <div>
        <label for="effective_from">Start date</label>
        <input type="date" <?= field_attrs($errors, 'effective_from') ?> value="<?= e($values['effective_from']) ?>" required>
        <?= field_error($errors, 'effective_from') ?>
      </div>
    </div>
    <p class="hint">From this date the text replaces the earlier version. Use today's date to put it in force now.</p>

    <label for="body">Text</label>
    <textarea <?= field_attrs($errors, 'body') ?> rows="18" required><?= e($values['body']) ?></textarea>
    <?= field_error($errors, 'body') ?>
    <p class="hint">Leave a blank line between paragraphs. You can change the text until someone accepts it or a participant agrees to it.</p>

    <div class="form-actions">
      <button type="submit" class="button button-primary"><?= $doc !== null ? 'Save changes' : 'Add policy text' ?></button>
      <a class="button" href="<?= e(url('admin_policies.php')) ?>">Cancel</a>
    </div>
  </form>
<?php endif; ?>
