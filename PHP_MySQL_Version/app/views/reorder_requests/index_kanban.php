<?php
/** A closure, not a top-level function — see orders/index_kanban.php's own note on repeated includes. */
$renderKanbanCard = static function (array $r): string {
    // Owner hard rule 2026-11-01: every field and action of the Standard list's row (reorder_requests/index.php).
    $status = (string) ($r['status'] ?? 'pending');
    ob_start();
    ?>
    <div class="entity-card kanban-card">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($r['company_legal_name']) ?></span>
        <?php if ($status !== 'pending'): ?><span class="badge <?= $status === 'approved' ? 'badge-active' : 'badge-lost' ?>"><?= htmlspecialchars(ucfirst($status)) ?></span><?php endif; ?>
      </div>
      <div class="entity-card-sub">From <a href="/orders/<?= (int) $r['source_order_id'] ?>"><?= htmlspecialchars($r['source_order_reference']) ?></a></div>
      <div class="muted small">Notes: <?= htmlspecialchars($r['notes'] ?? '—') ?></div>
      <?php if ($status === 'pending'): ?>
        <div class="muted small">Submitted <?= htmlspecialchars((string) $r['submitted_at']) ?></div>
        <div class="entity-card-footer"><a href="/reorder-requests/<?= (int) $r['id'] ?>" class="btn-sm">Review</a></div>
      <?php else: ?>
        <div class="muted small">Resolved by <?= htmlspecialchars($r['reviewed_by_name'] ?? '—') ?> · <?= htmlspecialchars((string) $r['reviewed_at']) ?></div>
        <div class="entity-card-footer">
          <?php if ($status === 'approved' && !empty($r['new_order_reference'])): ?>
            <a href="/orders/<?= (int) $r['new_order_id'] ?>">&rarr; <?= htmlspecialchars($r['new_order_reference']) ?></a>
          <?php elseif ($status === 'rejected'): ?>
            <span class="muted small"><?= htmlspecialchars($r['rejection_reason'] ?? '') ?></span>
          <?php else: ?>
            <span class="muted small">—</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Reorder Requests — Kanban Board</h1>
      <p class="muted" style="margin:0;">Repeat-order requests submitted by clients from an order already on file. Approving reviews and confirms each product line (HS code, unit price) before it becomes a real order — nothing here becomes live automatically.</p>
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
