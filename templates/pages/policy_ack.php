<?php /** @var array $doc  @var ?string $next */ ?>
<h1><?= e($doc['doc_type']) ?></h1>
<p class="lead">Please read this agreement. You need to accept it before you can use the system.</p>
<article class="policy-text">
  <?php foreach (preg_split('/\R{2,}/', trim($doc['body'])) as $paragraph): ?>
    <p><?= nl2br(e($paragraph)) ?></p>
  <?php endforeach; ?>
</article>
<p class="meta">Version <?= e($doc['version']) ?>, effective <?= e($doc['effective_from']) ?></p>
<form method="post" action="<?= e(url('policy_ack.php')) ?>" class="form-actions">
  <?= csrf_field() ?>
  <input type="hidden" name="document_id" value="<?= (int) $doc['document_id'] ?>">
  <input type="hidden" name="fingerprint" value="<?= e(\Pfpms\Auth\Policy::fingerprint($doc)) ?>">
  <?php if ($next): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
  <button type="submit" name="decision" value="accept" class="button button-primary">I have read and accept this agreement</button>
  <button type="submit" name="decision" value="decline" class="button">I do not accept (sign out)</button>
</form>
