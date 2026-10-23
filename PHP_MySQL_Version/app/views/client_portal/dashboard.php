<?php
use App\Helpers\Dates;
// docs/schema.sql Section BU — the client sees "Complete" the moment
// documents are sent (export process step 11b: "Mark the order almost
// close - client close"); whether government export benefits are still
// outstanding internally (status='shipped_docs_sent' vs 'complete') is
// staff-only information with no bearing on what the buyer needs to know.
$clientStatusLabels = ['shipped_docs_sent' => 'Complete'];
?>
<div class="card page-wide">
  <h1>My Orders</h1>
  <?php if ($historyHidden): ?>
    <p class="muted">Welcome, <?= htmlspecialchars($client['company_legal_name']) ?>. Below are your current orders with NexaCrest.</p>
  <?php else: ?>
    <p class="muted">Welcome, <?= htmlspecialchars($client['company_legal_name']) ?>. Below are all your orders with NexaCrest.</p>
  <?php endif; ?>
  <table class="list">
    <tr><th>Order Ref</th><th>Stage</th><th>Status</th><th>Created</th><th></th></tr>
    <?php foreach ($orders as $o): ?>
    <tr>
      <td><?= htmlspecialchars($o['order_reference'] ?? ('#' . $o['id'])) ?></td>
      <td><?= htmlspecialchars($o['current_stage_name'] ?? '—') ?></td>
      <td><?= htmlspecialchars($clientStatusLabels[$o['status']] ?? ucfirst($o['status'])) ?></td>
      <td><?= htmlspecialchars(Dates::human($o['created_at'])) ?></td>
      <td><a href="/client/orders/<?= (int) $o['id'] ?>">View documents</a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?>
      <tr><td colspan="5" class="muted">No orders yet.</td></tr>
    <?php endif; ?>
  </table>
</div>
