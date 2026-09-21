<?php
use App\Services\AuthService;
use App\Services\PermissionService;
$current = AuthService::currentUser();
$canEdit = $current && PermissionService::can((int) $current['id'], $current['role_id'] !== null ? (int) $current['role_id'] : null, 'manage_company_settings');
?>
<div class="card page-wide">
  <p><a href="/reference-docs">&larr; Reference Library</a></p>
  <h1><?= htmlspecialchars($doc['name']) ?></h1>
  <?php if ($canEdit): ?>
    <p><a href="/reference-docs/<?= htmlspecialchars($doc['code']) ?>/edit" class="btn-sm">Edit</a></p>
  <?php endif; ?>

  <?php if ($contentHtml === null): ?>
    <p class="muted">Content has not been entered yet.<?= $canEdit ? ' Use Edit above to add it.' : '' ?></p>
  <?php else: ?>
    <div class="reference-doc-content"><?= $contentHtml ?></div>
    <p class="muted small">Last updated: <?= htmlspecialchars($doc['updated_at'] ?? '—') ?></p>
  <?php endif; ?>
</div>
