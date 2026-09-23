<?php
use App\Services\AuthService;
use App\Services\PermissionService;
$currentUser = AuthService::currentUser();
$canManageSettings = $currentUser && PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_company_settings');
?>
<div class="card page-wide">
  <p><a href="/reference-docs">&larr; Reference Library</a></p>
  <h1><?= htmlspecialchars($doc['title']) ?></h1>
  <?php if ($canManageSettings): ?>
    <p><a href="/reference-docs/custom/<?= (int) $doc['id'] ?>/edit" class="btn-sm">Edit</a></p>
  <?php endif; ?>

  <?php if (!empty($doc['file_path'])): ?>
    <p><a href="/reference-docs/custom/<?= (int) $doc['id'] ?>/download" class="btn-sm btn-secondary">Download <?= htmlspecialchars($doc['file_original_name']) ?></a></p>
  <?php endif; ?>

  <?php if ($contentHtml === null): ?>
    <p class="muted">No typed content on this entry.<?= !empty($doc['file_path']) ? ' See the attached file above.' : '' ?></p>
  <?php else: ?>
    <div class="reference-doc-content"><?= $contentHtml ?></div>
  <?php endif; ?>
  <p class="muted small">Last updated: <?= htmlspecialchars((string) ($doc['updated_at'] ?? '—')) ?></p>
</div>
