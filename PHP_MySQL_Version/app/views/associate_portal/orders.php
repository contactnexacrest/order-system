<div class="card page-wide">
  <h1>Orders of my clients</h1>
  <p class="muted" data-orders-intro>You earn commission on every order of the clients you introduced, while your agreement is active, when the value of the goods (FOB) is ₹<?= \App\Helpers\MoneyFormat::indian(\App\Services\AssociateRules::minOrderInr(), 0) ?> or more — a client's first order always earns, whatever its size. You earn it when the buyer has paid the order in full, not when they pay only the advance. <span class="help" title="Not earned yet — buyer still paying: the buyer has not paid in full yet. Will be paid to you on <date>: the buyer has paid in full; this is the payout date. Paid to you on <date> · bank ref: we have paid it; the bank reference is on your bank statement.">ℹ</span></p>
  <p class="small" data-badge-legend><span class="badge badge-notyet">🟠 Not earned yet — buyer still paying</span> <span class="badge badge-tobepaid">🔵 To be paid</span> <span class="badge badge-active">🟢 Paid</span></p>
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
