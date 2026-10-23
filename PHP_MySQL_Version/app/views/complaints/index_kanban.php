<?php
$statusLabel = static function (string $status): string {
    return match ($status) {
        'acknowledged' => 'Acknowledged',
        'resolved' => 'Resolved',
        default => 'Open',
    };
};
/** A closure, not a top-level function — see orders/index_kanban.php's own note on repeated includes. */
$renderKanbanCard = static function (array $c): string {
    ob_start();
    ?>
    <a class="entity-card kanban-card" href="/orders/<?= (int) $c['order_id'] ?>/complaints">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($c['order_reference']) ?></span>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars((string) ($c['company_legal_name'] ?? '')) ?></div>
      <div class="muted small"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 90, '…')) ?></div>
      <div class="entity-card-footer">
        <span class="muted small">Raised: <?= htmlspecialchars((string) $c['created_at']) ?></span>
      </div>
    </a>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Complaints — Kanban Board</h1>
      <p class="muted" style="margin:0;">A process issue or flaw the client flagged for us to fix next time — not a Dispute.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn-sm btn-secondary" href="/complaints?view=list">Switch to list view</a>
    </div>
  </div>

  <div class="kanban-board">
    <?php foreach ($columns as $statusValue => $complaints): ?>
      <div class="kanban-column">
        <div class="kanban-column-header">
          <span class="kanban-column-title"><?= htmlspecialchars($statusLabel((string) $statusValue)) ?></span>
          <span class="kanban-column-count"><?= count($complaints) ?></span>
        </div>
        <?php if (empty($complaints)): ?>
          <p class="kanban-column-empty">No complaints here</p>
        <?php else: ?>
          <?php foreach ($complaints as $c): ?><?= $renderKanbanCard($c) ?><?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
