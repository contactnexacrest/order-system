<div class="card page-wide">
  <div class="sop-layout">
    <nav class="sop-nav">
      <p class="muted small" style="margin-top:0;"><a href="/sop">&larr; SOP Home</a></p>
      <ul class="sop-chapter-list">
        <?php foreach ($chapters as $ch): ?>
          <li><a href="/sop/<?= htmlspecialchars($ch['slug']) ?>" class="<?= $ch['slug'] === $currentSlug ? 'active' : '' ?>"><?= htmlspecialchars($ch['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <div class="sop-content">
      <?= $contentHtml ?>
    </div>
  </div>
</div>
