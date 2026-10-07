<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p><a href="/reference-docs">&larr; Reference Library</a></p>
  <h1>Add Reference Document</h1>
  <form method="post" action="/reference-docs/custom" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Title * <input type="text" name="title" required style="width:100%"></label>
    <p><label>Category <small class="muted">(optional — <a href="/reference-docs/categories">manage categories</a>. Leave blank to keep this visible to every staff member, as before.)</small><br>
      <select name="category_id" style="width:100%">
        <option value="">— None (visible to everyone) —</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?><?= $c['required_permission'] ? ' (restricted)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label></p>
    <p><label>Content (optional)<br>
      <textarea name="content" rows="16" style="width:100%;font-family:monospace" placeholder="A line starting with &quot;# &quot; is a heading, &quot;- &quot; a bullet, &quot;1. &quot; a numbered step, and a blank line starts a new paragraph."></textarea>
    </label></p>
    <p><label>Attach a file (optional — PDF, Word, or Excel, max 15MB)<br>
      <input type="file" name="file">
    </label></p>
    <button type="submit" class="btn-sm btn-accent">Add Document</button>
  </form>
</div>
