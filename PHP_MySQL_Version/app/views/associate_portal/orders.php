<div class="card page-wide">
  <h1>Orders of my clients</h1>
  <p class="muted">You earn commission on a client's first orders: everything they order within 10 days of their first purchase order. You earn it when the buyer has paid the order in full, not when they pay only the advance. Later repeat orders do not earn commission.</p>
  <table class="list">
    <?php $__fob = !empty($access['fob_value']); $__com = !empty($access['commission']); // D-261 ?>
    <tr><th>Order</th><th>Client</th><th>Products</th><?php if ($__fob): ?><th>Value of the goods (FOB, without freight)</th><?php endif; ?><th>Stage</th><th>Buyer paid in full</th><?php if ($__com): ?><th>Commission</th><?php endif; ?></tr>
    <?php foreach ($orders as $o): ?>
      <tr><td><a href="/associate/orders/<?= (int) $o['id'] ?>"><?= htmlspecialchars((string) $o['order_reference']) ?></a><br><small class="muted"><?= htmlspecialchars(\App\Helpers\Dates::short(substr((string) $o['created_at'], 0, 10))) ?></small></td>
        <td><?= htmlspecialchars((string) $o['company_legal_name']) ?></td><td><?= htmlspecialchars((string) $o['products']) ?></td>
        <?php if ($__fob): ?><td><?= htmlspecialchars((string) $o['currency_code']) ?> <?= number_format((float) $o['fob_value'], 2) ?></td><?php endif; ?><td><?= htmlspecialchars((string) $o['stage']) ?></td>
        <td><?= $o['buyer_paid_in_full'] ? 'Yes' : 'Not yet' ?></td><?php if ($__com): ?><td><?php require __DIR__ . '/_commission_cell.php'; ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if ($orders === []): ?><tr><td colspan="7" class="muted">No orders yet.</td></tr><?php endif; ?>
  </table>
  <?php if (!$__fob): ?><p class="small muted">Order values are not shown for your account.</p><?php endif; ?>
</div>
