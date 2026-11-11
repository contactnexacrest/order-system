<div class="card">
  <h1>Order <?= htmlspecialchars((string) $o['order_reference']) ?></h1>
  <table class="kv">
    <tr><th>Client</th><td><?= htmlspecialchars((string) $o['company_legal_name']) ?></td></tr>
    <tr><th>Ordered on</th><td><?= htmlspecialchars(\App\Helpers\Dates::short(substr((string) $o['created_at'], 0, 10))) ?><?= $o['buyer_po_received_on'] ? ' · purchase order received ' . htmlspecialchars(\App\Helpers\Dates::short((string) $o['buyer_po_received_on'])) : '' ?></td></tr>
    <tr><th>Products</th><td><?= htmlspecialchars((string) $o['products']) ?></td></tr>
    <?php if (!empty($access['fob_value'])): // D-261 ?><tr><th>Value of the goods (FOB, without freight)</th><td><?= htmlspecialchars((string) $o['currency_code']) ?> <?= number_format((float) $o['fob_value'], 2) ?></td></tr><?php endif; ?>
    <tr><th>Stage</th><td><?= htmlspecialchars((string) $o['stage']) ?></td></tr>
    <tr><th>Buyer paid in full</th><td><?= $o['buyer_paid_in_full'] ? 'Yes' : 'Not yet' ?></td></tr>
    <?php if (!empty($access['commission'])): ?><tr><th>Commission</th><td><?php require __DIR__ . '/_commission_cell.php'; ?></td></tr><?php endif; ?>
  </table>
  <?php if (!empty($access['commission']) && !empty($o['commission']['detail'])): $c = $o['commission']['detail']; ?>
  <h2>Commission statement</h2>
  <?php require __DIR__ . '/_statement.php'; ?>
  <?php endif; ?>
  <h2>What happened</h2>
  <table class="list">
    <?php foreach ($events as $e): ?><tr><td><?= htmlspecialchars(\App\Helpers\Dates::short(substr((string) $e['created_at'], 0, 10))) ?></td><td><?= htmlspecialchars((string) $e['message']) ?></td></tr><?php endforeach; ?>
    <?php if ($events === []): ?><tr><td class="muted">Nothing yet.</td></tr><?php endif; ?>
  </table>
  <p><a href="/associate/orders">All orders</a></p>
</div>
