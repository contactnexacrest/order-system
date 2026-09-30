<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Order Profitability Report</h1>
  <p class="muted">Revenue, total cost, profit, and margin % per order — filtered by order creation date. Pick a date range to view any period (a month, a quarter, six months, a financial year) with a totals row for that range. See each order's own page for the full cost breakdown behind these figures.</p>

  <form method="get" action="/reports/order-profitability" class="section">
    <div class="kv-grid">
      <label>Created From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
      <label>Created To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/order-profitability?<?= htmlspecialchars(http_build_query(array_filter(['date_from' => $filters['dateFrom'] ?? null, 'date_to' => $filters['dateTo'] ?? null]))) ?>&format=csv">Export CSV</a>
    </div>
  </form>

  <div class="section">
    <h2>Totals for This Period</h2>
    <div class="kv-grid">
      <div><span class="k">Total Revenue (INR)</span><span class="v"><?= number_format($totals['revenue_inr'], 2) ?></span></div>
      <div><span class="k">Total Cost (INR)</span><span class="v"><?= number_format($totals['total_cost_inr'], 2) ?></span></div>
      <div><span class="k">Total Profit (INR)</span><span class="v"><?= number_format($totals['profit_inr'], 2) ?></span></div>
      <div><span class="k">Overall Margin %</span><span class="v"><?= $totals['margin_pct'] !== null ? number_format($totals['margin_pct'], 2) . '%' : '—' ?></span></div>
    </div>
  </div>

  <div class="section">
    <table class="list">
      <tr><th>Order</th><th>Client</th><th>Created</th><th>Revenue (INR)</th><th>Total Cost (INR)</th><th>Profit (INR)</th><th>Margin %</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
        <td><?= htmlspecialchars((string) $r['created_at']) ?></td>
        <td><?= number_format($r['revenue_inr'], 2) ?><?= $r['revenue_is_estimated'] ? ' <span class="muted small">(est.)</span>' : '' ?></td>
        <td><?= number_format($r['total_cost_inr'], 2) ?></td>
        <td><?= number_format($r['profit_inr'], 2) ?></td>
        <td><?= $r['margin_pct'] !== null ? number_format($r['margin_pct'], 2) . '%' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="7" class="muted">No orders in this period.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
