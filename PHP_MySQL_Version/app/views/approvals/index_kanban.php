<?php use App\Services\ApprovalsInbox; ?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Approvals waiting for you (<?= (int) $counts['all'] ?>) — Kanban Board</h1>
      <p class="muted" style="margin:0;">One column per kind of approval. Each card opens the place where it is approved.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn-sm btn-secondary" href="/approvals?tab=<?= htmlspecialchars($tab) ?>&amp;view=list">Switch to list view</a>
    </div>
  </div>
  <?php $listView = false; include __DIR__ . '/_tabs.php'; ?>
  <div class="kanban-board">
    <?php foreach ($columns as $__col): ?>
    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title"><?= htmlspecialchars($__col['label']) ?></span>
        <span class="kanban-column-count"><?= count($__col['items']) ?></span>
      </div>
      <?php if ($__col['items'] === []): ?>
        <p class="kanban-column-empty">Nothing waiting</p>
      <?php endif; ?>
      <?php foreach ($__col['items'] as $__i): include __DIR__ . '/_cell.php'; ?>
      <div class="entity-card kanban-card" id="approval-<?= htmlspecialchars(str_replace(':', '-', (string) $__i['key'])) ?>">
        <div class="entity-card-header"><?= $__whatPart ?></div>
        <div class="entity-card-sub"><?= $__whoPart !== '' ? $__whoPart : '—' ?></div>
        <div class="muted small"><?= htmlspecialchars((string) $__i['details']) ?></div>
        <div class="muted small">Waiting since <?= htmlspecialchars(ApprovalsInbox::sinceLabel((string) $__i['since'], $today)) ?></div>
        <div class="entity-card-footer"><?= $__button ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php include __DIR__ . '/_footer.php'; ?>
</div>
