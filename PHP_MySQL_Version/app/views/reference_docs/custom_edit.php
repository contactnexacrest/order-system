<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p><a href="/reference-docs/custom/<?= (int) $doc['id'] ?>">&larr; <?= htmlspecialchars($doc['title']) ?></a></p>
  <h1>Edit: <?= htmlspecialchars($doc['title']) ?></h1>

  <?php if (!empty($doc['file_path'])): ?>
    <p class="muted small">Current file: <a href="/reference-docs/custom/<?= (int) $doc['id'] ?>/download"><?= htmlspecialchars($doc['file_original_name']) ?></a> — uploading a new one below replaces it here, but the old file is never deleted from disk.</p>
  <?php endif; ?>

  <form method="post" action="/reference-docs/custom/<?= (int) $doc['id'] ?>/update" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <div class="field-grid">
      <label>Title * <input type="text" name="title" value="<?= htmlspecialchars($doc['title']) ?>" placeholder="e.g., Incoterms 2020 Quick Reference" required></label>
      <label>Category <small class="muted">(optional — <a href="/reference-docs/categories">manage categories</a>. Leave blank to keep this visible to every staff member.)</small>
        <select name="category_id">
          <option value="">— None (visible to everyone) —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) ($doc['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?><?= $c['required_permission'] ? ' (restricted)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="full">Content
        <textarea name="content" rows="16" style="font-family:monospace" placeholder="A line starting with &quot;# &quot; is a heading, &quot;- &quot; a bullet, &quot;1. &quot; a numbered step, and a blank line starts a new paragraph."><?= htmlspecialchars($doc['content'] ?? '') ?></textarea>
      </label>
    </div>
    <p><label><?= !empty($doc['file_path']) ? 'Replace file' : 'Attach a file' ?> (optional — PDF, Word, or Excel, max 15MB)<br>
      <input type="file" name="file">
    </label></p>
    <button type="submit" class="btn-sm btn-success">Save</button>
  </form>
</div>
