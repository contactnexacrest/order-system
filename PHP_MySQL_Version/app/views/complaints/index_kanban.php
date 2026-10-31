<?php /* Owner hard rule 2026-11-01: the same filters and the same cards as the Standard list (complaints/_filters.php, complaints/_card.php). */ ?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Complaints — Kanban Board</h1>
      <p class="muted" style="margin:0;">A process issue or flaw the client flagged for us to fix next time — not a Dispute. <?= (int) array_sum(array_map('count', $columns)) ?> shown.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn-sm btn-secondary" href="/complaints?view=list<?= $statusFilter !== null ? '&amp;status=' . urlencode($statusFilter) : '' ?>">Switch to list view</a>
    </div>
  </div>

  <?php $listOfBoard = false; include __DIR__ . '/_filters.php'; ?>

  <div class="kanban-board">
    <?php foreach ($columns as $statusValue => $complaints): ?>
      <div class="kanban-column">
        <div class="kanban-column-header">
          <span class="kanban-column-title"><?= htmlspecialchars(['open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved'][$statusValue] ?? (string) $statusValue) ?></span>
          <span class="kanban-column-count"><?= count($complaints) ?></span>
        </div>
        <?php if (empty($complaints)): ?>
          <p class="kanban-column-empty">No complaints here</p>
        <?php else: ?>
          <?php foreach ($complaints as $c): $kanbanCard = true; include __DIR__ . '/_card.php'; endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
