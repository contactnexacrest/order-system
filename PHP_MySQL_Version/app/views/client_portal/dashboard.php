<?php use App\Helpers\Dates; ?>
<div class="card page-wide">
  <h1>My Orders</h1>
  <p class="muted">Welcome, <?= htmlspecialchars($client['company_legal_name']) ?>. Below are all your orders with NexaCrest.</p>
  <table class="list">
    <tr><th>Order Ref</th><th>Stage</th><th>Status</th><th>Created</th><th></th></tr>
    <?php foreach ($orders as $o): ?>
    <tr>
      <td><?= htmlspecialchars($o['order_reference'] ?? ('#' . $o['id'])) ?></td>
      <td><?= htmlspecialchars($o['current_stage_name'] ?? '—') ?></td>
      <td><?= htmlspecialchars(ucfirst($o['status'])) ?></td>
      <td><?= htmlspecialchars(Dates::human($o['created_at'])) ?></td>
      <td><a href="/client/orders/<?= (int) $o['id'] ?>">View documents</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?>
      <tr><td colspan="5" class="muted">No orders yet.</td></tr>
    <?php endif; ?>
  </table>
</div>
