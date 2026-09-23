<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p><a href="/reference-docs">&larr; Reference Library</a></p>
  <h1>Add Reference Document</h1>
  <form method="post" action="/reference-docs/custom" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <label>Title * <input type="text" name="title" required style="width:100%"></label>
    <p><label>Content (optional)<br>
      <textarea name="content" rows="16" style="width:100%;font-family:monospace" placeholder="A line starting with &quot;# &quot; is a heading, &quot;- &quot; a bullet, &quot;1. &quot; a numbered step, and a blank line starts a new paragraph."></textarea>
    </label></p>
    <p><label>Attach a file (optional — PDF, Word, or Excel, max 15MB)<br>
      <input type="file" name="file">
    </label></p>
    <button type="submit" class="btn-sm btn-success">Add Document</button>
  </form>
</div>
