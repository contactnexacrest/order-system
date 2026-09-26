<div class="card page-wide">
  <div class="sop-layout">
    <nav class="sop-nav">
      <p class="muted small" style="margin-top:0;">Chapters</p>
      <ul class="sop-chapter-list">
        <?php foreach ($chapters as $ch): ?>
          <li><a href="/sop/<?= htmlspecialchars($ch['slug']) ?>"><?= htmlspecialchars($ch['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <div class="sop-content">
      <?php if ($contentHtml !== null): ?>
        <?= $contentHtml ?>
      <?php else: ?>
        <h1>Standard Operating Procedure</h1>
        <p class="muted">No README.md found in docs/SOP.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
