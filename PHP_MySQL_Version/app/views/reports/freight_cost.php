<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Freight Cost Report</h1>
  <p class="muted">Confirmed freight/insurance terms and invoiced freight, rolled up by forwarder — filtered by order creation date.</p>

  <form method="get" action="/reports/freight-cost" class="section">
    <div class="kv-grid">
      <label>Created From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
      <label>Created To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/freight-cost?<?= htmlspecialchars(http_build_query(array_filter(['date_from' => $filters['dateFrom'] ?? null, 'date_to' => $filters['dateTo'] ?? null]))) ?>&format=csv">Export CSV</a>
    </div>
  </form>

  <div class="section">
    <h2>By Forwarder</h2>
    <table class="list">
      <tr><th>Forwarder</th><th>Currency</th><th>Orders</th><th>Total Confirmed Rate</th><th>Total Insurance</th><th>Total Cleared</th></tr>
      <?php foreach ($byForwarder as $forwarder => $byCurrency): ?>
        <?php foreach ($byCurrency as $cc => $b): ?>
        <tr>
          <td><?= htmlspecialchars($forwarder) ?></td>
          <td><?= htmlspecialchars($cc) ?></td>
          <td><?= (int) $b['order_count'] ?></td>
          <td><?= number_format($b['total_rate'], 2) ?></td>
          <td><?= number_format($b['total_insurance'], 2) ?></td>
          <td><?= number_format($b['total_cleared'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (empty($byForwarder)): ?><tr><td colspan="6" class="muted">No orders with freight terms recorded match these filters.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="section">
    <h2>Orders</h2>
    <table class="list">
      <tr><th>Order Ref</th><th>Client</th><th>Incoterm</th><th>Currency</th><th>Forwarder</th><th>Confirmed Rate</th><th>Insurance</th><th>Invoiced</th><th>Cleared</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/orders/<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
        <td><?= htmlspecialchars($r['incoterm_code']) ?></td>
        <td><?= htmlspecialchars($r['currency_code']) ?></td>
        <td><?= htmlspecialchars($r['freight_forwarder_name'] ?? '—') ?></td>
        <td><?= $r['confirmed_freight_rate'] !== null ? number_format((float) $r['confirmed_freight_rate'], 2) : '—' ?></td>
        <td><?= $r['insurance_amount'] !== null ? number_format((float) $r['insurance_amount'], 2) : '—' ?></td>
        <td><?= $r['freight_amount'] !== null ? number_format((float) $r['freight_amount'], 2) : '—' ?></td>
        <td><?= $r['payment_cleared_at'] ? 'Yes' : 'No' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="9" class="muted">No orders with freight terms recorded match these filters.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
