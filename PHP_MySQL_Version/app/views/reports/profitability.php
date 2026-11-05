<?php $__state = static fn (?string $s): string => \App\Services\OrderProfitabilityService::stateLabel($s); ?>
<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Order Profitability Report</h1>
  <p class="muted">Revenue, cost, the associate commission and team incentive, and the profit left per order — filtered by order creation date. Commission and incentive are <strong>estimated</strong> until the MD locks the order, then the locked figure replaces the estimate (never both). See each order's own page for the full cost breakdown behind these figures.</p>

  <form method="get" action="/reports/order-profitability" class="section">
    <div class="kv-grid">
      <label>Created From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
      <label>Created To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
      <label>Orders
        <select name="scope">
          <option value="all"<?= ($filters['scope'] ?? 'all') === 'all' ? ' selected' : '' ?>>Include open orders (estimated)</option>
          <option value="locked"<?= ($filters['scope'] ?? '') === 'locked' ? ' selected' : '' ?>>Locked orders only</option>
        </select>
      </label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/order-profitability?<?= htmlspecialchars(http_build_query(array_filter(['date_from' => $filters['dateFrom'] ?? null, 'date_to' => $filters['dateTo'] ?? null, 'scope' => $filters['scope'] ?? 'all']))) ?>&format=csv">Export CSV</a>
    </div>
  </form>

  <div class="section">
    <h2>Totals for This Period <span class="muted small">(<?= ($filters['scope'] ?? 'all') === 'locked' ? 'locked orders only' : 'open orders included — their commission and incentive are estimates' ?>)</span></h2>
    <div class="kv-grid">
      <div><span class="k">Total Revenue (INR)</span><span class="v"><?= number_format($totals['revenue_inr'], 2) ?></span></div>
      <div><span class="k">Cost before commission &amp; incentive (INR)</span><span class="v"><?= number_format($totals['cost_before_incentives_inr'], 2) ?></span></div>
      <div><span class="k">Profit before commission &amp; incentive (INR)</span><span class="v"><?= number_format($totals['profit_before_commission_inr'], 2) ?></span></div>
      <?php if (!empty($seeIncentives)): ?>
      <div><span class="k">Associate commission (INR)</span><span class="v"><?= number_format($totals['commission_inr'], 2) ?></span></div>
      <div><span class="k">Team incentive (INR)</span><span class="v"><?= number_format($totals['incentive_inr'], 2) ?></span></div>
      <div><span class="k">Profit after commission &amp; incentive (INR)</span><span class="v"><?= number_format($totals['profit_after_incentive_inr'], 2) ?></span></div>
      <div><span class="k">Overall margin after %</span><span class="v"><?= $totals['margin_after_pct'] !== null ? number_format($totals['margin_after_pct'], 2) . '%' : '—' ?></span></div>
      <?php else: ?>
      <div><span class="k">Associate commission &amp; team incentive</span><span class="v muted small">Shown to whoever manages or pays incentives.</span></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($divisionProfit)): ?>
  <div class="section" data-testid="division-profit">
    <h2>Profit by division</h2>
    <p class="muted">Company costs not linked to an order or a division (₹<?= htmlspecialchars(\App\Services\GstCalculator::inr($divisionProfit['overhead_inr'])) ?> in this period) are shared out by revenue<?= $divisionProfit['overhead_unshared'] ? ' — not shared: no revenue in this period' : '' ?>. A division's own costs are charged to it alone.</p>
    <table class="list">
      <tr><th>Division</th><th>Orders</th><th>Revenue (INR)</th><th>Share of revenue</th><th><?= htmlspecialchars($divisionProfit['profit_label']) ?> (INR)</th><th>Division's own costs (INR)</th><th>Share of company overhead (INR)</th><th>Profit after overhead (INR)</th></tr>
      <?php foreach ($divisionProfit['rows'] as $d): ?>
      <tr data-division="<?= htmlspecialchars($d['code']) ?>"><td><?= htmlspecialchars($d['name']) ?></td><td><?= (int) $d['orders'] ?></td><td><?= htmlspecialchars(\App\Services\GstCalculator::inr($d['revenue_inr'])) ?></td><td><?= htmlspecialchars($d['revenue_share_pct']) ?>%</td>
        <td><?= htmlspecialchars(\App\Services\GstCalculator::inr($d['profit_inr'])) ?></td><td><?= htmlspecialchars(\App\Services\GstCalculator::inr($d['own_costs_inr'])) ?></td><td><?= htmlspecialchars(\App\Services\GstCalculator::inr($d['overhead_share_inr'])) ?></td><td><?= htmlspecialchars(\App\Services\GstCalculator::inr($d['profit_after_overhead_inr'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
  <div class="section">
    <table class="list">
      <tr><th>Order</th><th>Client</th><th>Created</th><th>Revenue (INR)</th><th>Cost before commission &amp; incentive (INR)</th><th>Profit before (INR)</th><?php if (!empty($seeIncentives)): ?><th>Associate commission (INR)</th><th>Team incentive (INR)</th><th>Profit after (INR)</th><th>Margin after %</th><?php endif; ?></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
        <td><?= htmlspecialchars((string) $r['created_at']) ?></td>
        <td><?= number_format($r['revenue_inr'], 2) ?><?= $r['revenue_is_estimated'] ? ' <span class="muted small">(est.)</span>' : '' ?></td>
        <td><?= number_format($r['cost_before_incentives_inr'], 2) ?></td>
        <td><?= number_format($r['profit_before_commission_inr'], 2) ?></td>
        <?php if (!empty($seeIncentives)): ?>
        <td><?= $r['commission_state'] !== null ? number_format($r['commission_inr'], 2) . ' <span class="muted small">(' . $__state($r['commission_state']) . ')</span>' : '<span class="muted">—</span>' ?></td>
        <td><?= number_format($r['incentive_inr'], 2) ?> <span class="muted small">(<?= $__state($r['incentive_state']) ?>)</span></td>
        <td><?= number_format($r['profit_after_incentive_inr'], 2) ?></td>
        <td><?= $r['margin_after_pct'] !== null ? number_format($r['margin_after_pct'], 2) . '%' : '—' ?></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="<?= !empty($seeIncentives) ? 10 : 6 ?>" class="muted">No orders in this period.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
