<?php
/** A closure, not a top-level function — see orders/index_kanban.php's own note on repeated includes. */
$renderKanbanCard = static function (array $s): string {
    $company = $s['company_legal_name'] ?? $s['client_company_legal_name'] ?? '';
    ob_start();
    ?>
    <a class="entity-card kanban-card" href="/orders/<?= (int) $s['order_id'] ?>">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($s['order_reference']) ?></span>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars((string) $company) ?></div>
      <div class="entity-card-footer">
        <?php if (($s['status'] ?? 'pending_review') === 'applied'): ?>
          <span class="muted small">Applied</span>
        <?php elseif (($s['status'] ?? 'pending_review') === 'rejected'): ?>
          <span class="muted small"><?= htmlspecialchars(mb_strimwidth((string) ($s['rejection_reason'] ?? ''), 0, 40, '…')) ?></span>
        <?php else: ?>
          <span class="muted small">Submitted <?= htmlspecialchars((string) $s['submitted_at']) ?></span>
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
      <h1>PI Intake Review — Kanban Board</h1>
      <p class="muted" style="margin:0;">Submissions from the per-order PI-stage form.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn-sm btn-secondary" href="/pi-intake-review?view=list">Switch to list view</a>
    </div>
  </div>

  <div class="kanban-board">
    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Pending Review</span>
        <span class="kanban-column-count"><?= count($pending) ?></span>
      </div>
      <?php if (empty($pending)): ?>
        <p class="kanban-column-empty">Nothing pending</p>
      <?php else: ?>
        <?php foreach ($pending as $s): ?><?= $renderKanbanCard($s) ?><?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Applied</span>
        <span class="kanban-column-count"><?= count($applied) ?></span>
      </div>
      <?php if (empty($applied)): ?>
        <p class="kanban-column-empty">Nothing here</p>
      <?php else: ?>
        <?php foreach ($applied as $s): ?><?= $renderKanbanCard($s) ?><?php endforeach; ?>
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
        <?php foreach ($rejected as $s): ?><?= $renderKanbanCard($s) ?><?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
