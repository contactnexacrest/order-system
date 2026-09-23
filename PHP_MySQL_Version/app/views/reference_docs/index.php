<?php
use App\Helpers\Csrf;
use App\Services\AuthService;
use App\Services\PermissionService;
$currentUser = AuthService::currentUser();
$canManageSettings = $currentUser && PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'manage_company_settings');
?>
<div class="card page-wide">
  <h1>Reference Library</h1>
  <p class="muted">Stage Gate conditions, hard-rules quick reference, the cross-verification checklist, and the sales-process SOPs — kept here so any staff member can look them up without asking around.</p>

  <table class="list">
    <tr><th>Document</th><th>Last Updated</th><th>Action</th></tr>
    <?php foreach ($docs as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['name']) ?></td>
        <td><?= $d['updated_at'] ? htmlspecialchars($d['updated_at']) : '<span class="muted">not yet entered</span>' ?></td>
        <td><a href="/reference-docs/<?= htmlspecialchars($d['code']) ?>" class="btn-sm">View</a></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="section">
    <h2>Other Reference Documents</h2>
    <p class="muted small">Freely add/edit/delete — for anything that isn't one of the fixed documents above. Each can carry typed content, an uploaded file (PDF/Word/Excel), or both.</p>
    <table class="list">
      <tr><th>Title</th><th>File</th><th>Last Updated</th><th>Action</th></tr>
      <?php foreach ($customDocs as $d): ?>
        <tr>
          <td><?= htmlspecialchars($d['title']) ?></td>
          <td>
            <?php if (!empty($d['file_path'])): ?>
              <a href="/reference-docs/custom/<?= (int) $d['id'] ?>/download"><?= htmlspecialchars($d['file_original_name']) ?></a>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?= htmlspecialchars((string) $d['updated_at']) ?>
            <?php if (!empty($d['updated_by_name'])): ?> <span class="muted small">by <?= htmlspecialchars($d['updated_by_name']) ?></span><?php endif; ?>
          </td>
          <td>
            <a href="/reference-docs/custom/<?= (int) $d['id'] ?>" class="btn-sm">View</a>
            <?php if ($canManageSettings): ?>
            <a href="/reference-docs/custom/<?= (int) $d['id'] ?>/edit" class="btn-sm btn-secondary">Edit</a>
            <form method="post" action="/reference-docs/custom/<?= (int) $d['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete &quot;<?= htmlspecialchars(addslashes($d['title'])) ?>&quot; from the Reference Library? Any uploaded file stays on disk, but this entry and its typed content are removed.');">
              <?= Csrf::field() ?>
              <button type="submit" class="btn-sm btn-danger">Delete</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($customDocs)): ?>
        <tr><td colspan="4" class="muted">No other reference documents added yet.</td></tr>
      <?php endif; ?>
    </table>
    <?php if ($canManageSettings): ?>
    <p><a href="/reference-docs/custom/create" class="btn-sm btn-success">+ Add Reference Document</a></p>
    <?php endif; ?>
  </div>
</div>
