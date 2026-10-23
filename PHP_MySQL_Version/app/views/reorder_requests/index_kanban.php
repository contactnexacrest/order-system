<?php
/** A closure, not a top-level function — see orders/index_kanban.php's own note on repeated includes. */
$renderKanbanCard = static function (array $r): string {
    ob_start();
    ?>
    <a class="entity-card kanban-card" href="/reorder-requests/<?= (int) $r['id'] ?>">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($r['company_legal_name']) ?></span>
      </div>
      <div class="entity-card-sub">From <?= htmlspecialchars($r['source_order_reference']) ?></div>
      <?php if (!empty($r['notes'])): ?>
        <div class="muted small"><?= htmlspecialchars(mb_strimwidth($r['notes'], 0, 90, '…')) ?></div>
      <?php endif; ?>
      <div class="entity-card-footer">
        <?php if (($r['status'] ?? 'pending') === 'approved' && !empty($r['new_order_reference'])): ?>
          <span class="muted small">&rarr; <?= htmlspecialchars($r['new_order_reference']) ?></span>
        <?php elseif (($r['status'] ?? 'pending') === 'rejected'): ?>
          <span class="muted small"><?= htmlspecialchars(mb_strimwidth((string) ($r['rejection_reason'] ?? ''), 0, 40, '…')) ?></span>
        <?php else: ?>
          <span class="muted small">Submitted <?= htmlspecialchars((string) $r['submitted_at']) ?></span>
        <?php endif; ?>
      </div>
    </a>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Reorder Requests — Kanban Board</h1>
      <p class="muted" style="margin:0;">Repeat-order requests submitted by clients from an order already on file.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn-sm btn-secondary" href="/reorder-requests?view=list">Switch to list view</a>
    </div>
  </div>

  <div class="kanban-board">
    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Pending</span>
        <span class="kanban-column-count"><?= count($pending) ?></span>
      </div>
      <?php if (empty($pending)): ?>
        <p class="kanban-column-empty">Nothing pending</p>
      <?php else: ?>
        <?php foreach ($pending as $r): ?><?= $renderKanbanCard($r) ?><?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Approved</span>
        <span class="kanban-column-count"><?= count($approved) ?></span>
      </div>
      <?php if (empty($approved)): ?>
        <p class="kanban-column-empty">Nothing here</p>
      <?php else: ?>
        <?php foreach ($approved as $r): ?><?= $renderKanbanCard($r) ?><?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Rejected</span>
        <span class="kanban-column-count"><?= count($rejected) ?></span>
      </div>
      <?php if (empty($rejected)): ?>
        <p class="kanban-column-empty">Nothing here</p>
      <?php else: ?>
        <?php foreach ($rejected as $r): ?><?= $renderKanbanCard($r) ?><?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
