<?php
/**
 * Shared card content with disputes/index.php's own card-grid, laid out
 * in board columns instead. A closure, not a top-level function: this
 * template can render more than once per PHP process (every test in the
 * suite shares one process), and a second `include` would fatal on
 * "Cannot redeclare function" otherwise.
 */
$renderKanbanCard = static function (array $d): string {
    ob_start();
    ?>
    <a class="entity-card kanban-card" href="/orders/<?= (int) $d['order_id'] ?>/disputes">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($d['order_reference']) ?></span>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars((string) ($d['company_legal_name'] ?? '')) ?></div>
      <div class="muted small"><?= htmlspecialchars(mb_strimwidth($d['description'], 0, 90, '…')) ?></div>
      <div class="entity-card-footer">
        <span class="muted small">Notice: <?= htmlspecialchars($d['notice_date']) ?></span>
        <span class="muted small">Due: <?= htmlspecialchars($d['response_due_date'] ?? '—') ?></span>
      </div>
    </a>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Disputes — Kanban Board</h1>
      <p class="muted" style="margin:0;"><?= (int) $totalCount ?> total &middot; every dispute by its own status.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn-sm btn-secondary" href="/disputes?status=all">Switch to list view</a>
    </div>
  </div>

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
          <?php foreach ($col['disputes'] as $d): ?>
            <?= $renderKanbanCard($d) ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
