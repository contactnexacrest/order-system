<div class="card page-wide">
  <?php require __DIR__ . '/_report_nav.php'; ?>
  <h1>Owed at Month End</h1>
  <p class="muted">What NexaCrest owes staff and associates at the chosen date: everything locked on or before it and not paid by it. For today or a later date, orders ready to lock (buyer fully paid, no open claim) are added as estimates.</p>
  <form method="get" action="/reports/incentives/owed" class="section">
    <div class="kv-grid">
      <label>As at<input type="date" name="date" value="<?= htmlspecialchars($date) ?>" required></label>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn-sm">Run</button>
      <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/incentives/owed?date=<?= htmlspecialchars($date) ?>&format=csv">Export CSV</a>
    </div>
  </form>
  <div class="section"><h2>Owed at <?= htmlspecialchars(\App\Helpers\Dates::short($date)) ?> — &#8377;<?= number_format($totals['amount_inr'], 2) ?></h2><?php require __DIR__ . '/_report_table.php'; ?></div>
</div>
