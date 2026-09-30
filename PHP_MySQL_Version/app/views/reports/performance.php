<?php
$r = $report['current'];
$prev = $report['previous'];
$cmp = $report['comparison'];

/** Renders a value plus, when a comparison exists, a ▲/▼ badge against the previous period. */
$deltaBadge = static function (?float $pct): string {
    if ($pct === null) {
        return '<span class="muted small">—</span>';
    }
    $cls = $pct > 0 ? 'badge-success' : ($pct < 0 ? 'badge-lost' : 'badge-warning');
    $arrow = $pct > 0 ? '&#9650;' : ($pct < 0 ? '&#9660;' : '&#8212;');
    return '<span class="badge ' . $cls . '">' . $arrow . ' ' . number_format(abs($pct), 1) . '%</span>';
};
$presets = [
    'this_month' => 'This Month', 'last_month' => 'Last Month',
    'this_quarter' => 'This Quarter', 'last_quarter' => 'Last Quarter',
    'this_half_year' => 'This Half-Year', 'last_half_year' => 'Last Half-Year',
    'this_year' => 'This Year', 'last_year' => 'Last Year',
];
?>
<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Sales Performance Report</h1>
  <p class="muted">How the business is doing over any period — quotations and PIs sent, wins, losses (with each one's own reason), win rate, and FOB value won — automatically compared against the immediately preceding period of equal length whenever both dates are set.</p>

  <div class="section">
    <div class="btn-row" style="flex-wrap:wrap;margin-bottom:10px;">
      <?php foreach ($presets as $key => $label): ?>
        <a href="/reports/performance?preset=<?= htmlspecialchars($key) ?>" class="btn-sm <?= $filters['preset'] === $key ? 'btn-accent' : 'btn-secondary' ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get" action="/reports/performance">
      <div class="kv-grid">
        <label>Date From<input type="date" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '') ?>"></label>
        <label>Date To<input type="date" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '') ?>"></label>
      </div>
      <div class="btn-row"><button type="submit" class="btn-sm">Run Custom Range</button></div>
    </form>
    <?php if ($prev !== null): ?>
      <p class="muted small">Compared against <?= htmlspecialchars($report['previous_range'][0]) ?> to <?= htmlspecialchars($report['previous_range'][1]) ?> (same number of days, immediately before).</p>
    <?php else: ?>
      <p class="muted small">Set both Date From and Date To (or pick a preset) to see a comparison against the previous period of equal length.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Key Metrics</h2>
    <table class="list">
      <tr><th>Metric</th><th>This Period</th><?php if ($prev !== null): ?><th>Previous Period</th><th>Change</th><?php endif; ?></tr>
      <tr>
        <td>Orders Created</td><td><?= (int) $r['orders_created'] ?></td>
        <?php if ($prev !== null): ?><td><?= (int) $prev['orders_created'] ?></td><td><?= $deltaBadge($cmp['orders_created']) ?></td><?php endif; ?>
      </tr>
      <tr>
        <td>Quotations Sent</td><td><?= (int) $r['quotations_sent'] ?></td>
        <?php if ($prev !== null): ?><td><?= (int) $prev['quotations_sent'] ?></td><td><?= $deltaBadge($cmp['quotations_sent']) ?></td><?php endif; ?>
      </tr>
      <tr>
        <td>PIs Sent</td><td><?= (int) $r['pi_sent'] ?></td>
        <?php if ($prev !== null): ?><td><?= (int) $prev['pi_sent'] ?></td><td><?= $deltaBadge($cmp['pi_sent']) ?></td><?php endif; ?>
      </tr>
      <tr>
        <td>Won (reached PI)</td><td><?= (int) $r['won'] ?></td>
        <?php if ($prev !== null): ?><td><?= (int) $prev['won'] ?></td><td><?= $deltaBadge($cmp['won']) ?></td><?php endif; ?>
      </tr>
      <tr>
        <td>Lost — total <span class="muted small">(<?= (int) $r['lost_before_pi'] ?> before PI, <?= (int) $r['lost_after_pi'] ?> after)</span></td>
        <td><?= (int) $r['lost_total'] ?></td>
        <?php if ($prev !== null): ?><td><?= (int) $prev['lost_total'] ?></td><td><?= $deltaBadge($cmp['lost_total']) ?></td><?php endif; ?>
      </tr>
      <tr>
        <td><strong>Win Rate</strong></td>
        <td><strong><?= $r['win_rate_pct'] !== null ? $r['win_rate_pct'] . '%' : '—' ?></strong></td>
        <?php if ($prev !== null): ?><td><?= $prev['win_rate_pct'] !== null ? $prev['win_rate_pct'] . '%' : '—' ?></td><td><?= $deltaBadge($cmp['win_rate_pct']) ?></td><?php endif; ?>
      </tr>
    </table>
  </div>

  <div class="section">
    <h2>FOB Value Won (by currency)</h2>
    <p class="muted small">Sum of the product FOB value for every order that reached PI in this period — never summed across currencies.</p>
    <?php if (empty($r['fob_won_by_currency'])): ?>
      <p class="muted">No orders reached PI in this period.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Currency</th><th>FOB Value Won</th><?php if ($prev !== null): ?><th>Previous Period</th><?php endif; ?></tr>
        <?php foreach ($r['fob_won_by_currency'] as $cc => $amount): ?>
        <tr>
          <td><?= htmlspecialchars($cc) ?></td>
          <td><?= number_format($amount, 2) ?></td>
          <?php if ($prev !== null): ?><td><?= number_format($prev['fob_won_by_currency'][$cc] ?? 0, 2) ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Lost Orders — Detail</h2>
    <p class="muted small">Every order marked lost in this period, with its own reason as recorded at the time.</p>
    <table class="list">
      <tr><th>Order</th><th>Client</th><th>Lost On</th><th>Stage Reached</th><th>Reason</th></tr>
      <?php if (empty($report['lost_orders'])): ?>
      <tr><td colspan="5" class="muted">No orders were lost in this period.</td></tr>
      <?php endif; ?>
      <?php foreach ($report['lost_orders'] as $lo): ?>
      <tr>
        <td><a href="/orders/<?= (int) $lo['id'] ?>"><?= htmlspecialchars($lo['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($lo['company_legal_name']) ?></td>
        <td><?= htmlspecialchars((string) $lo['lost_at']) ?></td>
        <td><?= $lo['reached_pi'] ? 'PI' : 'Quotation' ?></td>
        <td><?= $lo['lost_reason'] ? htmlspecialchars($lo['lost_reason']) : '<span class="muted">—</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
