<?php
use App\Services\AuthService;
use App\Services\PermissionService;
$currentUser = AuthService::currentUser();
$canViewArchivedOrders = $currentUser && PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'view_archived_orders');
// Owner hard rule 2026-11-01: the same filters and the same cards as the Standard list (orders/_filters.php, orders/_card.php).
?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Orders — Kanban Board</h1>
      <p class="muted" style="margin:0;"><?= (int) $counts['all'] ?> total &middot; <?= (int) $totalActive ?> active &middot; every order where it actually sits in the pipeline.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn btn-accent" href="/clients">Go to Clients to start a new order</a>
      <a class="btn-sm btn-secondary" href="/orders?view=list&amp;status=<?= htmlspecialchars($statusFilter !== '' ? $statusFilter : 'all') ?>">Switch to list view</a>
      <?php if ($canViewArchivedOrders): ?><a class="btn-sm btn-secondary" href="/orders/archived">View Archived Orders</a><?php endif; ?>
    </div>
  </div>

  <?php $listOfBoard = false; include __DIR__ . '/_filters.php'; ?>

  <div class="kanban-board">
    <?php foreach ($columns as $key => $col): ?>
      <div class="kanban-column" data-column="<?= htmlspecialchars((string) $key) ?>">
        <div class="kanban-column-header">
          <span class="kanban-column-title"><?= isset($col['stage']) ? 'Stage ' . (int) $col['stage'] . ' &middot; ' : '' ?><?= htmlspecialchars($col['title']) ?></span>
          <span class="kanban-column-count"><?= count($col['orders']) ?></span>
        </div>
        <?php if (empty($col['orders'])): ?>
          <p class="kanban-column-empty">No orders here</p>
        <?php else: ?>
          <?php foreach ($col['orders'] as $o): $cardHref = '/orders/' . (int) $o['id'] . '?from=kanban'; $kanbanCard = true; include __DIR__ . '/_card.php'; endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
