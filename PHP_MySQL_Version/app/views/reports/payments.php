<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Payments Report</h1>
  <p class="muted">Collected vs. outstanding, broken down by currency — filtered by order created date, same as the Aggregate Report.</p>

  <form method="get" action="/reports/payments" class="section">
    <div class="kv-grid">
      <label>Date From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
      <label>Date To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/payments?<?= htmlspecialchars(http_build_query(array_filter(['date_from' => $filters['dateFrom'] ?? null, 'date_to' => $filters['dateTo'] ?? null]))) ?>&format=csv">Export CSV</a>
    </div>
  </form>

  <div class="section">
    <h2>Summary by Currency</h2>
    <p class="muted small">Computed from exactly the <?= count($rows) ?> order(s) listed below — never a separate query, so this can never disagree with the table.</p>
    <?php if (empty($byCurrency)): ?>
      <p class="muted">No payment records match these filters.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Currency</th><th>Advance Invoiced</th><th>Advance Cleared</th><th>Advance Outstanding</th><th>Balance Invoiced</th><th>Balance Cleared</th><th>Balance Outstanding</th><th>Freight Invoiced</th><th>Freight Cleared</th><th>Freight Outstanding</th><th>Total Outstanding</th></tr>
        <?php foreach ($byCurrency as $cc => $b): ?>
        <tr>
          <td><strong><?= htmlspecialchars($cc) ?></strong></td>
          <td><?= number_format($b['advance_invoiced'], 2) ?></td>
          <td><?= number_format($b['advance_cleared'], 2) ?></td>
          <td><?= number_format($b['advance_outstanding'], 2) ?></td>
          <td><?= number_format($b['balance_invoiced'], 2) ?></td>
          <td><?= number_format($b['balance_cleared'], 2) ?></td>
          <td><?= number_format($b['balance_outstanding'], 2) ?></td>
          <td><?= number_format($b['freight_invoiced'], 2) ?></td>
          <td><?= number_format($b['freight_cleared'], 2) ?></td>
          <td><?= number_format($b['freight_outstanding'], 2) ?></td>
          <td><strong><?= number_format($b['total_outstanding'], 2) ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Order Detail</h2>
    <table class="list">
      <tr><th>Order Ref</th><th>Client</th><th>Currency</th><th>Status</th><th>Advance</th><th>Balance</th><th>Freight</th><th>Created</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/orders/<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
        <td><?= htmlspecialchars($r['currency_code']) ?></td>
        <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
        <td>
          <?php if ($r['advance_amount'] === null): ?>—<?php else: ?>
            <?= number_format((float) $r['advance_amount'], 2) ?>
            <?= $r['advance_cleared_at'] ? '<span class="badge badge-success">Cleared</span>' : '<span class="badge badge-warning">Outstanding ' . number_format($r['advance_outstanding'], 2) . '</span>' ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($r['balance_amount'] === null): ?>—<?php else: ?>
            <?= number_format((float) $r['balance_amount'], 2) ?>
            <?= $r['balance_cleared_at'] ? '<span class="badge badge-success">Cleared</span>' : '<span class="badge badge-warning">Outstanding ' . number_format($r['balance_outstanding'], 2) . '</span>' ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($r['freight_amount'] === null): ?>—<?php else: ?>
            <?= number_format((float) $r['freight_amount'], 2) ?>
            <?= $r['freight_cleared_at'] ? '<span class="badge badge-success">Cleared</span>' : '<span class="badge badge-warning">Outstanding ' . number_format($r['freight_outstanding'], 2) . '</span>' ?>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="8" class="muted">No orders match these filters.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
