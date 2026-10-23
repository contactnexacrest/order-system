<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Today's Payments</h1>
  <p class="muted">What actually cleared/was paid on this specific day, both directions — distinct from the Payments Report, which filters orders by created date and shows their current status regardless of when a leg cleared.</p>

  <form method="get" action="/reports/todays-payments" class="section">
    <div class="kv-grid">
      <label>Date<input type="date" name="date" value="<?= htmlspecialchars($date) ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/todays-payments?date=<?= htmlspecialchars($date) ?>&format=csv">Export CSV</a>
    </div>
  </form>

  <div class="section">
    <h2>Incoming (Buyer) Payments</h2>
    <table class="list">
      <tr><th>Order Ref</th><th>Client</th><th>Currency</th><th>Leg</th><th>Amount</th><th>Cleared</th></tr>
      <?php $anyIncoming = false; ?>
      <?php foreach ($incoming as $r): ?>
        <?php foreach (['advance' => 'Advance', 'balance' => 'Balance', 'freight' => 'Freight'] as $legKey => $legLabel): ?>
          <?php $clearedAt = $r["{$legKey}_cleared_at"] ?? null; ?>
          <?php if ($clearedAt !== null && substr((string) $clearedAt, 0, 10) === $date): ?>
            <?php $anyIncoming = true; ?>
            <tr>
              <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
              <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
              <td><?= htmlspecialchars($r['currency_code']) ?></td>
              <td><?= htmlspecialchars($legLabel) ?></td>
              <td><?= number_format((float) ($r["{$legKey}_amount"] ?? 0), 2) ?></td>
              <td><?= htmlspecialchars((string) $clearedAt) ?></td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$anyIncoming): ?><tr><td colspan="6" class="muted">No incoming payments cleared on this date.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Outgoing (Supplier) Payments</h2>
    <?php if (!$canManageSupplierPayments): ?>
      <p class="muted">Only Accounts/Admin/MD/ED can see supplier payment amounts — this is internal cost data.</p>
    <?php else: ?>
    <table class="list">
      <tr><th>Order Ref</th><th>Supplier</th><th>Leg</th><th>Amount (INR)</th><th>Paid</th></tr>
      <?php $anyOutgoing = false; ?>
      <?php foreach ($supplier as $r): ?>
        <?php if ($r['advance_paid_at'] === $date): ?>
          <?php $anyOutgoing = true; ?>
          <tr>
            <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
            <td><?= htmlspecialchars($r['supplier_legal_name']) ?></td>
            <td>Advance</td>
            <td><?= number_format((float) ($r['advance_amount_inr'] ?? 0), 2) ?></td>
            <td><?= htmlspecialchars((string) $r['advance_paid_at']) ?></td>
          </tr>
        <?php endif; ?>
        <?php if ($r['balance_paid_at'] === $date): ?>
          <?php $anyOutgoing = true; ?>
          <tr>
            <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
            <td><?= htmlspecialchars($r['supplier_legal_name']) ?></td>
            <td>Balance</td>
            <td><?= number_format((float) ($r['balance_amount_inr'] ?? 0), 2) ?></td>
            <td><?= htmlspecialchars((string) $r['balance_paid_at']) ?></td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (!$anyOutgoing): ?><tr><td colspan="5" class="muted">No supplier payments recorded on this date.</td></tr><?php endif; ?>
    </table>
    <?php endif; ?>
  </div>
</div>
