<div class="card page-wide">
  <p class="muted small"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>TDS Payable Summary</h1>
  <p class="muted">Every month with at least one TDS-applicable expense (annotated on the Expenses page), grouped by month and by the Indian FY quarter it falls in — for reconciling against Form 26Q filings.</p>

  <div class="section">
    <table class="list">
      <tr><th>Month</th><th>Quarter</th><th>TDS-Applicable Expenses</th><th>Total Expense Amount</th><th>Total TDS Amount</th></tr>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['month']) ?></td>
        <td><?= htmlspecialchars($r['quarter']) ?></td>
        <td><?= (int) $r['expense_count'] ?></td>
        <td><?= number_format($r['total_expense_amount'], 2) ?></td>
        <td><?= number_format($r['total_tds_amount'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><tr><td colspan="5" class="muted">No expense has been marked TDS-applicable yet.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
