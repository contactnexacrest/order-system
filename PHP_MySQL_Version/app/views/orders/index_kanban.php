<?php
use App\Services\AuthService;
use App\Services\PermissionService;
$currentUser = AuthService::currentUser();
$canViewArchivedOrders = $currentUser && PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'view_archived_orders');

/**
 * Shared with orders/index.php's own card-grid — same card content, just
 * laid out in board columns instead of a flat grid. A closure, not a
 * top-level function: this template can render more than once per PHP
 * process (every test in the suite shares one process), and a second
 * `include` would fatal on "Cannot redeclare function" otherwise.
 */
$renderKanbanCard = static function (array $o) use ($seesAllOrders): string {
    $currency = $o['currency_code'] ?? '';
    $amount = trim($currency . ' ' . number_format((float) $o['total_fob_value'], 2));
    ob_start();
    ?>
    <a class="entity-card kanban-card<?= !empty($o['is_overdue']) ? ' entity-card-overdue' : '' ?>" href="/orders/<?= (int) $o['id'] ?>?from=kanban">
      <div class="entity-card-header">
        <span class="entity-card-title"><?= htmlspecialchars($o['order_reference']) ?></span>
        <?php if (!empty($o['incoterm_code'])): ?><span class="tag tag-gold"><?= htmlspecialchars($o['incoterm_code']) ?></span><?php endif; ?>
        <?php if (!empty($seesAllOrders) && !empty($o['assigned_to_name'])): ?><span class="tag tag-navy"><?= htmlspecialchars($o['assigned_to_name']) ?></span><?php endif; ?>
      </div>
      <div class="entity-card-sub"><?= htmlspecialchars((string) ($o['company_legal_name'] ?? '')) ?></div>
      <div class="entity-card-footer">
        <?php if (!empty($o['is_overdue'])): ?>
          <span class="badge badge-lost">Overdue</span>
        <?php else: ?>
          <span></span>
        <?php endif; ?>
        <span class="entity-card-amount"><?= htmlspecialchars($amount) ?></span>
      </div>
    </a>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Orders — Kanban Board</h1>
      <p class="muted" style="margin:0;"><?= (int) $totalActive ?> active &middot; every order where it actually sits in the pipeline.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn btn-accent" href="/clients">Go to Clients to start a new order</a>
      <a class="btn-sm btn-secondary" href="/orders?status=all">Switch to list view</a>
      <?php if ($canViewArchivedOrders): ?><a class="btn-sm btn-secondary" href="/orders/archived">View Archived Orders</a><?php endif; ?>
    </div>
  </div>

  <div class="kanban-summary-row">
    <a class="kanban-summary-chip" href="/orders?status=shipped_docs_sent">Docs Sent (<?= count($shippedDocsSent) ?>)</a>
    <a class="kanban-summary-chip" href="/orders?status=complete">Complete (<?= count($complete) ?>)</a>
    <a class="kanban-summary-chip" href="/orders?status=lost">Lost (<?= (int) $lostCount ?>)</a>
    <a class="kanban-summary-chip" href="/orders?status=cancelled">Cancelled (<?= (int) $cancelledCount ?>)</a>
  </div>

  <div class="kanban-board">
    <?php foreach ($columns as $stageNumber => $col): ?>
      <div class="kanban-column">
        <div class="kanban-column-header">
          <span class="kanban-column-title">Stage <?= (int) $stageNumber ?> &middot; <?= htmlspecialchars($col['name']) ?></span>
          <span class="kanban-column-count"><?= count($col['orders']) ?></span>
        </div>
        <?php if (empty($col['orders'])): ?>
          <p class="kanban-column-empty">No orders here</p>
        <?php else: ?>
          <?php foreach ($col['orders'] as $o): ?>
            <?= $renderKanbanCard($o) ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Docs Sent — Awaiting Benefits</span>
        <span class="kanban-column-count"><?= count($shippedDocsSent) ?></span>
      </div>
      <?php if (empty($shippedDocsSent)): ?>
        <p class="kanban-column-empty">No orders here</p>
      <?php else: ?>
        <?php foreach ($shippedDocsSent as $o): ?>
          <?= $renderKanbanCard($o) ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="kanban-column">
      <div class="kanban-column-header">
        <span class="kanban-column-title">Complete</span>
        <span class="kanban-column-count"><?= count($complete) ?></span>
      </div>
      <?php if (empty($complete)): ?>
        <p class="kanban-column-empty">No orders here</p>
      <?php else: ?>
        <?php foreach ($complete as $o): ?>
          <?= $renderKanbanCard($o) ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
