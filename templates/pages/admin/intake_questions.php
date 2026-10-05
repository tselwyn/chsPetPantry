<?php
/**
 * @var \Pfpms\Http\Context $ctx  @var list<array> $active  @var list<array> $retired  @var ?array $editing
 * @var array $values  @var array<string,string> $errors  @var string $timeZone
 */
use Pfpms\Reference\IntakeQuestionService;

$typeNames = ['Text' => 'Short text', 'Number' => 'Number', 'Yes/No' => 'Yes or no', 'Choice' => 'Pick one from a list', 'Date' => 'Date'];
$describe = static function (array $q) use ($typeNames): string {
    $type = $typeNames[$q['answer_type']] ?? $q['answer_type'];
    $options = IntakeQuestionService::optionList($q['options']);
    return $options ? $type . ': ' . implode(', ', $options) : $type;
};
$locked = $editing !== null && (int) $editing['answer_count'] > 0;
?>
<div class="page-head">
  <h1>Intake questions</h1>
</div>
<p class="hint">These extra questions are asked when a participant registers, in this order. Changes take effect straight away.</p>

<h2>Questions in use</h2>
<?php if (!$active): ?>
  <p class="meta">No intake questions yet. Add one below.</p>
<?php else: ?>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">Question</th><th scope="col">Answer</th><th scope="col">Required</th><th scope="col">Answers given</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($active as $i => $q): $id = (int) $q['question_id']; ?>
      <tr>
        <td><?= e($q['prompt']) ?></td>
        <td><?= e($describe($q)) ?></td>
        <td><?= $q['is_required'] ? 'Required' : 'Optional' ?></td>
        <td><?= (int) $q['answer_count'] ?></td>
        <td>
          <form method="post" action="<?= e(url('admin_intake_questions.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="question_id" value="<?= $id ?>">
            <?php if ($i > 0): ?><button type="submit" name="action" value="up" class="button">Move up<span class="visually-hidden">: <?= e($q['prompt']) ?></span></button><?php endif; ?>
            <?php if ($i < count($active) - 1): ?><button type="submit" name="action" value="down" class="button">Move down<span class="visually-hidden">: <?= e($q['prompt']) ?></span></button><?php endif; ?>
            <button type="submit" name="action" value="retire" class="button">Retire<span class="visually-hidden">: <?= e($q['prompt']) ?></span></button>
          </form>
          <a class="button" href="<?= e(url('admin_intake_questions.php', ['edit' => $id])) ?>#question-form">Edit<span class="visually-hidden">: <?= e($q['prompt']) ?></span></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php if ($retired): ?>
  <h2>Retired questions</h2>
  <p class="hint">Retired questions are not asked any more. The answers already given are kept and still show on participant records.</p>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th scope="col">Question</th><th scope="col">Answer</th><th scope="col">Retired</th><th scope="col">Answers given</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($retired as $q): ?>
      <tr class="status-inactive">
        <td><?= e($q['prompt']) ?></td>
        <td><?= e($describe($q)) ?></td>
        <td><?= e(local_time($q['retired_at'], $timeZone, 'M j, Y')) ?></td>
        <td><?= (int) $q['answer_count'] ?></td>
        <td>
          <form method="post" action="<?= e(url('admin_intake_questions.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="question_id" value="<?= (int) $q['question_id'] ?>">
            <button type="submit" name="action" value="restore" class="button">Restore<span class="visually-hidden">: <?= e($q['prompt']) ?></span></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<h2 id="question-form"><?= $editing ? 'Edit a question' : 'Add a question' ?></h2>
<?php if (isset($errors['_form'])): ?><div class="flash flash-error" role="alert"><?= e($errors['_form']) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('admin_intake_questions.php')) ?>" class="form form-narrow" novalidate>
  <?= csrf_field() ?>
  <?php if ($editing): ?><input type="hidden" name="question_id" value="<?= (int) $editing['question_id'] ?>"><?php endif; ?>

  <?php if ($locked): ?>
    <p class="hint">This question has been answered <?= (int) $editing['answer_count'] ?> time(s), so its wording, answer type and choices can no longer change
      (the answers already given must keep their meaning). You can still make it required or optional. To ask something different,
      retire this question and add a new one.</p>
    <dl class="readonly-list">
      <dt>Question</dt><dd><?= e($editing['prompt']) ?></dd>
      <dt>Answer</dt><dd><?= e($describe($editing)) ?></dd>
    </dl>
  <?php else: ?>
    <label for="prompt">Question</label>
    <input type="text" <?= field_attrs($errors, 'prompt') ?> maxlength="255" value="<?= e($values['prompt']) ?>" required>
    <?= field_error($errors, 'prompt') ?>

    <label for="answer_type">Kind of answer</label>
    <select <?= field_attrs($errors, 'answer_type') ?>>
      <?php foreach (IntakeQuestionService::TYPES as $type): ?>
        <option value="<?= e($type) ?>"<?= selected($type === $values['answer_type']) ?>><?= e($typeNames[$type]) ?></option>
      <?php endforeach; ?>
    </select>
    <?= field_error($errors, 'answer_type') ?>

    <label for="options">Choices</label>
    <textarea <?= field_attrs($errors, 'options') ?> rows="5"><?= e($values['options']) ?></textarea>
    <?= field_error($errors, 'options') ?>
    <p class="hint">Only for "Pick one from a list": type one choice per line, at least two, each up to 100 characters.</p>
  <?php endif; ?>

  <label class="checkbox"><input type="checkbox" name="is_required" value="1"<?= selected(!empty($values['is_required']), 'checked') ?>> Required (registration cannot be finished without an answer)</label>

  <div class="form-actions">
    <button type="submit" name="action" value="<?= $editing ? 'save' : 'add' ?>" class="button button-primary"><?= $editing ? 'Save question' : 'Add question' ?></button>
    <?php if ($editing): ?><a class="button" href="<?= e(url('admin_intake_questions.php')) ?>">Cancel</a><?php endif; ?>
  </div>
</form>
