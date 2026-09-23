<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <h1>Archived Orders</h1>
  <p class="muted">Archiving only removes an order from the main Orders list — nothing is ever deleted. Restore an order any time to bring it back.</p>
  <div class="btn-row"><a class="btn-sm btn-secondary" href="/orders">Back to Orders</a></div>

  <?php if (empty($orders)): ?>
    <p class="muted">No archived orders.</p>
  <?php else: ?>
  <table class="list">
    <tr><th>Order Ref</th><th>Client</th><th>Stage</th><th>Status</th><th>Archived</th><th></th></tr>
    <?php foreach ($orders as $o): ?>
    <tr>
      <td><?= htmlspecialchars($o['order_reference']) ?></td>
      <td><?= htmlspecialchars($o['company_legal_name']) ?></td>
      <td><?= htmlspecialchars($o['current_stage_name'] ?? 'Quotation') ?></td>
      <td><?= htmlspecialchars(ucfirst($o['status'])) ?></td>
      <td><?= htmlspecialchars($o['archived_at'] ?? '') ?><?= !empty($o['archived_by_name']) ? ' by ' . htmlspecialchars($o['archived_by_name']) : '' ?></td>
      <td>
        <a href="/orders/<?= (int) $o['id'] ?>">View</a>
        <form method="post" action="/orders/<?= (int) $o['id'] ?>/unarchive" style="display:inline" onsubmit="return confirm('Restore <?= htmlspecialchars(addslashes($o['order_reference'])) ?> to the main Orders list?');">
          <?= Csrf::field() ?>
          <button type="submit" class="btn-sm btn-success">Restore</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
