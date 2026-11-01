<div class="card page-wide">
  <h1>Orders of my clients</h1>
  <p class="muted">Commission is earned on a client's first orders (everything ordered in the 10 days from their first purchase order), once the buyer has paid the order in full — never on the advance alone. Repeat orders after that earn no commission.</p>
  <table class="list">
    <tr><th>Order</th><th>Client</th><th>Products</th><th>FOB value</th><th>Stage</th><th>Buyer paid in full</th><th>Commission</th></tr>
    <?php foreach ($orders as $o): ?>
      <tr><td><a href="/associate/orders/<?= (int) $o['id'] ?>"><?= htmlspecialchars((string) $o['order_reference']) ?></a><br><small class="muted"><?= htmlspecialchars(\App\Helpers\Dates::short(substr((string) $o['created_at'], 0, 10))) ?></small></td>
        <td><?= htmlspecialchars((string) $o['company_legal_name']) ?></td><td><?= htmlspecialchars((string) $o['products']) ?></td>
        <td><?= htmlspecialchars((string) $o['currency_code']) ?> <?= number_format((float) $o['fob_value'], 2) ?></td><td><?= htmlspecialchars((string) $o['stage']) ?></td>
        <td><?= $o['buyer_paid_in_full'] ? 'Yes' : 'Not yet' ?></td><td><?php require __DIR__ . '/_commission_cell.php'; ?></td></tr>
    <?php endforeach; ?>
    <?php if ($orders === []): ?><tr><td colspan="7" class="muted">No orders yet.</td></tr><?php endif; ?>
  </table>
</div>
