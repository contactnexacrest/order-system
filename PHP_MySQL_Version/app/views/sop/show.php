<div class="card page-wide">
  <div class="sop-layout">
    <nav class="sop-nav">
      <p class="muted small" style="margin-top:0;"><a href="/sop">&larr; SOP Home</a></p>
      <ul class="sop-chapter-list">
        <?php foreach ($chapters as $ch): if ($ch['category'] !== 'main') continue; ?>
          <li><a href="/sop/<?= htmlspecialchars($ch['slug']) ?>" class="<?= $ch['slug'] === $currentSlug ? 'active' : '' ?>"><?= htmlspecialchars($ch['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php if (array_filter($chapters, static fn(array $c): bool => $c['category'] === 'ca')): ?>
        <p class="muted small">CA / Accounting</p>
        <ul class="sop-chapter-list">
          <?php foreach ($chapters as $ch): if ($ch['category'] !== 'ca') continue; ?>
            <li><a href="/sop/<?= htmlspecialchars($ch['slug']) ?>" class="<?= $ch['slug'] === $currentSlug ? 'active' : '' ?>"><?= htmlspecialchars($ch['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </nav>
    <div class="sop-content">
      <?= $contentHtml ?>
    </div>
  </div>
</div>
