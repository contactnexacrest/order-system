<?php
use App\Services\AuthService;
use App\Services\PermissionService;
$currentUser = AuthService::currentUser();
$canViewArchivedOrders = $currentUser && PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'view_archived_orders');
?>
<div class="card page-wide">
  <h1>Orders</h1>
  <div class="btn-row">
    <a class="btn" href="/clients">Go to Clients to start a new order</a>
    <?php if ($canViewArchivedOrders): ?><a class="btn-sm btn-secondary" href="/orders/archived">View Archived Orders</a><?php endif; ?>
  </div>

  <?php if (empty($orders)): ?>
    <p class="muted">No orders yet.</p>
  <?php else: ?>
  <table class="list">
    <tr><th>Order Ref</th><th>Client</th><th>Stage</th><th>Status</th><th></th></tr>
    <?php foreach ($orders as $o): ?>
    <tr>
      <td><?= htmlspecialchars($o['order_reference']) ?></td>
      <td><?= htmlspecialchars($o['company_legal_name']) ?></td>
      <td><?= htmlspecialchars($o['current_stage_name'] ?? 'Quotation') ?></td>
      <td><?= htmlspecialchars(ucfirst($o['status'])) ?></td>
      <td><a href="/orders/<?= (int) $o['id'] ?>">View</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
