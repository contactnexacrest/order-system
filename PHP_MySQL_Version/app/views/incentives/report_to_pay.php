<div class="card page-wide">
  <?php require __DIR__ . '/_report_nav.php'; ?>
  <h1>To Pay</h1>
  <p class="muted">What falls in this month's payout run (including anything locked for an earlier run and still unpaid), what falls in next month's, and what is not locked yet (estimated — the MD locks it first).</p>
  <form method="get" action="/reports/incentives/to-pay" class="section">
    <div class="kv-grid">
      <label>Payee type
        <select name="payee">
          <option value="">Staff and associates</option>
          <option value="staff"<?= $payee === 'staff' ? ' selected' : '' ?>>Staff only</option>
          <option value="associate"<?= $payee === 'associate' ? ' selected' : '' ?>>Associates only</option>
        </select></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/incentives/to-pay?<?= htmlspecialchars(http_build_query(array_filter(['payee' => $payee]))) ?>&format=csv">Export CSV</a>
    </div>
  </form>
  <?php foreach ($groups as $key => $g): ?>
  <div class="section" id="to-pay-<?= htmlspecialchars($key) ?>">
    <h2><?= htmlspecialchars($g['label']) ?> — &#8377;<?= number_format($g['totals']['amount_inr'], 2) ?></h2>
    <?php $rows = $g['rows']; $totals = $g['totals']; require __DIR__ . '/_report_table.php'; ?>
  </div>
  <?php endforeach; ?>
</div>
