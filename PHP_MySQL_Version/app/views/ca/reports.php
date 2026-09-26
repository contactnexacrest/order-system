<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>Revenue Report</h1>
  <p class="muted">Financial-year or calendar-year revenue, computed from the INR Settlement Register — independent of the order-pipeline Reports module (own tables, own totals, no shared queries between the two).</p>

  <form method="get" action="/ca/reports" class="section">
    <div class="kv-grid">
      <label>View By
        <select name="mode" onchange="this.form.submit()">
          <option value="fy" <?= $mode === 'fy' ? 'selected' : '' ?>>Financial Year (1 Apr – 31 Mar)</option>
          <option value="calendar" <?= $mode === 'calendar' ? 'selected' : '' ?>>Calendar Year</option>
        </select>
      </label>
      <label><?= $mode === 'fy' ? 'Financial Year' : 'Calendar Year' ?>
        <select name="period" onchange="this.form.submit()">
          <?php $periods = $mode === 'fy' ? $availableFy : $availableCalendar; ?>
          <?php if (empty($periods)): ?>
            <option value="<?= htmlspecialchars($period) ?>"><?= htmlspecialchars($period) ?> (no data yet)</option>
          <?php endif; ?>
          <?php foreach ($periods as $p): ?>
            <option value="<?= htmlspecialchars((string) $p) ?>" <?= (string) $p === $period ? 'selected' : '' ?>><?= $mode === 'fy' ? 'FY ' . htmlspecialchars((string) $p) : htmlspecialchars((string) $p) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <noscript><button type="submit" class="btn-sm">Run</button></noscript>
    </div>
  </form>

  <div class="section">
    <h2>GST / LUT Export Declaration</h2>
    <p class="muted small">This business exports under LUT (Letter of Undertaking) — every revenue entry below is a zero-rated export invoice, not a domestic taxable sale.</p>
    <div class="kv-grid">
      <div><span class="k">GSTIN</span><span class="v"><?= htmlspecialchars($gstin ?? '—') ?></span></div>
      <div><span class="k">LUT Number</span><span class="v"><?= htmlspecialchars($lutNumber ?? '—') ?></span></div>
      <div><span class="k">LUT Valid For</span><span class="v"><?= htmlspecialchars($lutValidFy ?? '—') ?></span></div>
    </div>
  </div>

  <div class="section">
    <h2>Summary — <?= $mode === 'fy' ? 'FY ' . htmlspecialchars($period) : htmlspecialchars($period) ?> (<?= htmlspecialchars($report['from']) ?> to <?= htmlspecialchars($report['to']) ?>)</h2>
    <div class="kv-grid">
      <div><span class="k">Settlement Legs</span><span class="v"><?= (int) $report['legCount'] ?></span></div>
      <div><span class="k">Total INR Realized</span><span class="v">&#8377;<?= number_format($report['totalInrActual'], 2) ?></span></div>
      <div><span class="k">Net Forex Gain/(Loss)</span><span class="v">&#8377;<?= number_format(abs($report['totalForexGainLoss']), 2) ?> <?= $report['totalForexGainLoss'] >= 0 ? 'gain' : 'loss' ?></span></div>
      <div><span class="k">Legs Missing INR Actual</span><span class="v"><?= (int) $report['legsMissingInr'] ?></span></div>
    </div>

    <?php if (!empty($report['byCurrency'])): ?>
      <table class="list" style="margin-top:12px;">
        <tr><th>Currency</th><th>Foreign Total</th><th>INR Realized</th><th>Legs</th></tr>
        <?php foreach ($report['byCurrency'] as $cc => $b): ?>
        <tr>
          <td><strong><?= htmlspecialchars($cc) ?></strong></td>
          <td><?= number_format($b['foreign_total'], 2) ?></td>
          <td>&#8377;<?= number_format($b['inr_actual_total'], 2) ?></td>
          <td><?= (int) $b['leg_count'] ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p class="muted">No settlement legs cleared in this period.</p>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Legs in This Period</h2>
    <?php if (empty($report['rows'])): ?>
      <p class="muted">Nothing to show.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Order Ref</th><th>Client</th><th>Leg</th><th>Cleared On</th><th>Foreign Amount</th><th>INR Actual</th><th>Forex Gain/(Loss)</th></tr>
        <?php foreach ($report['rows'] as $r): ?>
        <tr>
          <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['buyer_inquiry_ref']) ?></a></td>
          <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
          <td><?= htmlspecialchars(ucfirst($r['leg'])) ?></td>
          <td><?= htmlspecialchars((string) $r['cleared_at']) ?></td>
          <td><?= $r['foreign_amount'] !== null ? number_format((float) $r['foreign_amount'], 2) : '—' ?> <?= htmlspecialchars($r['currency_code']) ?></td>
          <td><?= $r['inr_actual'] !== null ? '&#8377;' . number_format((float) $r['inr_actual'], 2) : '<span class="muted">—</span>' ?></td>
          <td><?= $r['forex_gain_loss'] !== null ? '&#8377;' . number_format(abs($r['forex_gain_loss']), 2) . ' ' . ($r['forex_gain_loss'] >= 0 ? 'gain' : 'loss') : '<span class="muted">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
