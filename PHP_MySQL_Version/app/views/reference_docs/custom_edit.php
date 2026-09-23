<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p><a href="/reference-docs/custom/<?= (int) $doc['id'] ?>">&larr; <?= htmlspecialchars($doc['title']) ?></a></p>
  <h1>Edit: <?= htmlspecialchars($doc['title']) ?></h1>

  <?php if (!empty($doc['file_path'])): ?>
    <p class="muted small">Current file: <a href="/reference-docs/custom/<?= (int) $doc['id'] ?>/download"><?= htmlspecialchars($doc['file_original_name']) ?></a> — uploading a new one below replaces it here, but the old file is never deleted from disk.</p>
  <?php endif; ?>

  <form method="post" action="/reference-docs/custom/<?= (int) $doc['id'] ?>/update" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Title * <input type="text" name="title" value="<?= htmlspecialchars($doc['title']) ?>" required style="width:100%"></label>
    <p><label>Content<br>
      <textarea name="content" rows="16" style="width:100%;font-family:monospace"><?= htmlspecialchars($doc['content'] ?? '') ?></textarea>
    </label></p>
    <p><label><?= !empty($doc['file_path']) ? 'Replace file' : 'Attach a file' ?> (optional — PDF, Word, or Excel, max 15MB)<br>
      <input type="file" name="file">
    </label></p>
    <button type="submit" class="btn-sm btn-success">Save</button>
  </form>
</div>
