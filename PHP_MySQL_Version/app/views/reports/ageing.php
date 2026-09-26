<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Debtors / Receivables Ageing Report</h1>
  <p class="muted">Every outstanding (not yet cleared) advance, balance, or freight leg, bucketed by how overdue it is. Advance and freight are counted overdue from the order's own creation date (due promptly once invoiced); balance uses its own computed due date when one is set.</p>

  <div class="section">
    <h2>Summary by Bucket</h2>
    <table class="list">
      <tr><th>Bucket</th><th>Outstanding (by currency)</th></tr>
      <?php foreach ($buckets as $bucket): ?>
        <tr>
          <td><?= htmlspecialchars($bucket) ?></td>
          <td>
            <?php if (empty($by_bucket[$bucket])): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <?php foreach ($by_bucket[$bucket] as $cc => $total): ?>
                <?= htmlspecialchars($cc) ?> <?= number_format($total, 2) ?><br>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="section">
    <h2>Outstanding Legs (oldest first)</h2>
    <table class="list">
      <tr><th>Order Ref</th><th>Client</th><th>Leg</th><th>Currency</th><th>Amount</th><th>Due Date</th><th>Days Overdue</th><th>Bucket</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
        <td><?= htmlspecialchars($r['company_legal_name']) ?></td>
        <td><?= htmlspecialchars($r['leg']) ?></td>
        <td><?= htmlspecialchars($r['currency_code']) ?></td>
        <td><?= number_format($r['amount'], 2) ?></td>
        <td><?= htmlspecialchars($r['due_date']) ?></td>
        <td><?= (int) $r['days_overdue'] ?></td>
        <td><?= htmlspecialchars($r['bucket']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="8" class="muted">Nothing outstanding — every invoiced leg has been cleared.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="btn-row">
    <a class="btn-sm btn-secondary js-slow-download" data-loading-text="Exporting…" href="/reports/ageing?format=csv">Export CSV</a>
  </div>
</div>
