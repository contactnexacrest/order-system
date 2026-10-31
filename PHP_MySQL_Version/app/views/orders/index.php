<?php
use App\Services\AuthService;
use App\Services\PermissionService;
$currentUser = AuthService::currentUser();
$canViewArchivedOrders = $currentUser && PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'view_archived_orders');

?>
<div class="card page-wide">
  <div class="page-header-row">
    <div>
      <h1>Orders</h1>
      <p class="muted" style="margin:0;"><?= (int) $counts['all'] ?> total &middot; a card for every order, not a spreadsheet row.</p>
    </div>
    <div class="btn-row" style="margin-top:0;">
      <a class="btn btn-accent" href="/clients">Go to Clients to start a new order</a>
      <?php if (!empty($kanbanUser)): ?><a class="btn-sm btn-secondary" href="/orders">Switch to board view</a><?php endif; ?>
      <?php if ($canViewArchivedOrders): ?><a class="btn-sm btn-secondary" href="/orders/archived">View Archived Orders</a><?php endif; ?>
    </div>
  </div>

  <?php $listOfBoard = !empty($kanbanUser); include __DIR__ . '/_filters.php'; ?>

  <?php if (empty($orders)): ?>
    <p class="muted">No orders match this filter.</p>
  <?php else: ?>
  <div class="card-grid">
    <?php foreach ($orders as $o): $cardHref = '/orders/' . (int) $o['id']; $kanbanCard = false; include __DIR__ . '/_card.php'; endforeach; ?>
  </div>
  <?php if ($totalPages > 1): ?>
  <div class="btn-row" style="justify-content:center; margin-top:16px;">
    <?php if ($page > 1): ?><a class="btn-sm btn-secondary" href="/orders?status=<?= htmlspecialchars($statusFilter) ?>&amp;page=<?= $page - 1 ?><?= !empty($kanbanUser) ? '&amp;view=list' : '' ?>">&larr; Previous</a><?php endif; ?>
    <span class="muted small">Page <?= $page ?> of <?= $totalPages ?> (<?= (int) $totalFiltered ?> orders)</span>
    <?php if ($page < $totalPages): ?><a class="btn-sm btn-secondary" href="/orders?status=<?= htmlspecialchars($statusFilter) ?>&amp;page=<?= $page + 1 ?><?= !empty($kanbanUser) ? '&amp;view=list' : '' ?>">Next &rarr;</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
