<?php /* Owner hard rule 2026-11-01: the same filters and the same cards as the Standard list (disputes/_filters.php, disputes/_card.php). */ ?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Disputes — Kanban Board</h1>
      <p class="muted" style="margin:0;"><?= (int) $totalCount ?> total &middot; every dispute by its own status.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn-sm btn-secondary" href="/disputes?view=list<?= $statusFilter !== null ? '&amp;status=' . urlencode($statusFilter) : '' ?>">Switch to list view</a>
    </div>
  </div>

  <?php $listOfBoard = false; include __DIR__ . '/_filters.php'; ?>

  <div class="kanban-board">
    <?php foreach ($columns as $statusValue => $col): ?>
      <div class="kanban-column">
        <div class="kanban-column-header">
          <span class="kanban-column-title"><?= htmlspecialchars((string) $statusValue) ?></span>
          <span class="kanban-column-count"><?= count($col['disputes']) ?></span>
        </div>
        <?php if (empty($col['disputes'])): ?>
          <p class="kanban-column-empty">No disputes here</p>
        <?php else: ?>
          <?php foreach ($col['disputes'] as $d): $kanbanCard = true; include __DIR__ . '/_card.php'; endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
