<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p><a href="/reference-docs/<?= htmlspecialchars($doc['code']) ?>">&larr; <?= htmlspecialchars($doc['name']) ?></a></p>
  <h1>Edit: <?= htmlspecialchars($doc['name']) ?></h1>
  <p class="muted small">Format: a line starting with "# " is a heading, "## " a sub-heading, "- " a bullet, "1. " a numbered step, and a blank line starts a new paragraph. <?= $doc['code'] === 'WALLREF' ? 'Placeholders like {bl_type_instruction} or {dispute_response_days_n} are substituted from live Company Settings whenever this page is viewed.' : '' ?></p>

  <form method="post" action="/reference-docs/<?= htmlspecialchars($doc['code']) ?>">
    <?= Csrf::field() ?>
    <textarea name="content" rows="24" style="width:100%;font-family:monospace"><?= htmlspecialchars($doc['content'] ?? '') ?></textarea>
    <p><button type="submit" class="btn-sm btn-success">Save</button></p>
  </form>
</div>
