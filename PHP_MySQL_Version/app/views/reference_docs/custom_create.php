<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p><a href="/reference-docs">&larr; Reference Library</a></p>
  <h1>Add Reference Document</h1>
  <form method="post" action="/reference-docs/custom" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <div class="field-grid">
      <label>Title * <input type="text" name="title" placeholder="e.g., Incoterms 2020 Quick Reference" required></label>
      <label>Category <small class="muted">(optional — <a href="/reference-docs/categories">manage categories</a>. Leave blank to keep this visible to every staff member, as before.)</small>
        <select name="category_id">
          <option value="">— None (visible to everyone) —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?><?= $c['required_permission'] ? ' (restricted)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="full">Content (optional)
        <textarea name="content" rows="16" style="font-family:monospace" placeholder="A line starting with &quot;# &quot; is a heading, &quot;- &quot; a bullet, &quot;1. &quot; a numbered step, and a blank line starts a new paragraph."></textarea>
      </label>
    </div>
    <p><label>Attach a file (optional — PDF, Word, or Excel, max 15MB)<br>
      <input type="file" name="file">
    </label></p>
    <button type="submit" class="btn-sm btn-accent">Add Document</button>
  </form>
</div>
