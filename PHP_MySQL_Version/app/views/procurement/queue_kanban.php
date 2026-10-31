<?php
/** The Kanban UI template's Procurement board (D-214 phase 3): one column per requirement status. */
$renderKanbanCard = static function (array $r): string {
    $late = !in_array($r['status'], ['received', 'cancelled'], true) && $r['needed_by'] < date('Y-m-d');
    ob_start();
    ?>
    <a class="entity-card kanban-card" href="/procurement/<?= (int) $r['id'] ?>">
      <div class="entity-card-header">
        <span class="entity-card-title">PR-<?= (int) $r['id'] ?> · <?= htmlspecialchars((string) $r['description']) ?></span>
        <span class="badge badge-protected"><?= htmlspecialchars(\App\Repositories\PurchasingRepository::STATUSES[$r['status']] ?? (string) $r['status']) ?></span>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars((string) $r['order_reference']) ?> · <?= htmlspecialchars(rtrim(rtrim((string) $r['quantity'], '0'), '.')) ?> <?= htmlspecialchars((string) ($r['unit'] ?? '')) ?></div>
      <?php if (!empty($r['awarded_supplier_name'])): ?><div class="muted small"><?= htmlspecialchars((string) $r['awarded_supplier_name']) ?></div><?php endif; ?>
      <div class="entity-card-footer">
        <span class="muted small">Needed by <?= htmlspecialchars((string) $r['needed_by']) ?></span>
        <?php if ($late): ?><span class="badge badge-inactive">late</span><?php endif; ?>
        <span class="muted small"><?= (int) $r['quote_count'] ?> quote<?= (int) $r['quote_count'] === 1 ? '' : 's' ?></span>
      </div>
    </a>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Procurement — Kanban Board</h1>
      <p class="muted" style="margin:0;">Purchase requirements raised by Sales, earliest need first. Open one to record supplier quotes, compare, award, approve, and record receipt and the quality check. Received shows the last 60 days unless chosen in Status.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <?php if ($cancelled > 0): ?><a class="tag" href="/procurement?view=list&amp;status=cancelled">Cancelled <?= (int) $cancelled ?></a><?php endif; ?>
      <a class="btn-sm btn-secondary" href="/procurement?view=list<?= $status !== '' ? '&amp;status=' . htmlspecialchars($status) : '' ?>">Switch to list view</a>
    </div>
  </div>

  <?php $listOfBoard = false; include __DIR__ . '/_filters.php'; ?>

  <div class="kanban-board">
    <?php foreach ($columns as $key => $col): ?>
    <div class="kanban-column" data-status="<?= htmlspecialchars($key) ?>">
      <div class="kanban-column-header">
        <span class="kanban-column-title"><?= htmlspecialchars($col['label']) ?></span>
        <span class="kanban-column-count"><?= count($col['rows']) ?></span>
      </div>
      <?php if ($col['rows'] === []): ?>
        <p class="kanban-column-empty">Nothing here</p>
      <?php else: ?>
        <?php foreach ($col['rows'] as $r): ?><?= $renderKanbanCard($r) ?><?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
