<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>Reconciliation Summary</h1>
  <p class="muted">All-time totals — how much of the recorded revenue and imported expenses has actually been matched against the real bank statement. <a href="/ca/bank-statement">Bank statement &amp; matching &rarr;</a></p>

  <div class="section">
    <h2>Totals</h2>
    <table class="list">
      <tr><th></th><th>In This System</th><th>In Bank Statement</th><th>Matched</th><th>Difference</th></tr>
      <tr>
        <td><strong>Revenue (Credits)</strong></td>
        <td>&#8377;<?= number_format($totalRevenue, 2) ?></td>
        <td>&#8377;<?= number_format($bankTotals['totalCredit'], 2) ?></td>
        <td>&#8377;<?= number_format($bankTotals['matchedCredit'], 2) ?></td>
        <td><?php $diff = $totalRevenue - $bankTotals['matchedCredit']; ?>&#8377;<?= number_format(abs($diff), 2) ?> <?= $diff == 0 ? '' : ($diff > 0 ? '(revenue not yet matched)' : '(unexpected)') ?></td>
      </tr>
      <tr>
        <td><strong>Expenses (Debits)</strong></td>
        <td>&#8377;<?= number_format($totalExpenses, 2) ?></td>
        <td>&#8377;<?= number_format($bankTotals['totalDebit'], 2) ?></td>
        <td>&#8377;<?= number_format($bankTotals['matchedDebit'], 2) ?></td>
        <td><?php $ediff = $totalExpenses - $bankTotals['matchedDebit']; ?>&#8377;<?= number_format(abs($ediff), 2) ?> <?= $ediff == 0 ? '' : ($ediff > 0 ? '(expenses not yet matched)' : '(unexpected)') ?></td>
      </tr>
    </table>
    <p class="muted small" style="margin-top:8px;"><?= (int) $bankTotals['lineCount'] ?> bank statement line(s) imported in total.</p>
  </div>

  <div class="section">
    <h2>Revenue Not Yet Matched to the Bank Statement</h2>
    <?php if (empty($unmatchedRevenueLegs)): ?>
      <p class="muted">Every recorded INR actual has a matching bank statement line.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Order Ref</th><th>Leg</th><th>Cleared On</th><th>INR Actual</th></tr>
        <?php foreach ($unmatchedRevenueLegs as $leg): ?>
        <tr>
          <td><a href="/orders/<?= (int) $leg['order_id'] ?>"><?= htmlspecialchars($leg['buyer_inquiry_ref']) ?></a></td>
          <td><?= htmlspecialchars(ucfirst($leg['leg'])) ?></td>
          <td><?= htmlspecialchars((string) $leg['cleared_at']) ?></td>
          <td>&#8377;<?= number_format((float) $leg['inr_actual'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Expenses Not Yet Matched to the Bank Statement</h2>
    <?php if (empty($unmatchedExpenses)): ?>
      <p class="muted">Every imported expense has a matching bank statement line.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Date</th><th>Category</th><th>Vendor</th><th>Amount</th></tr>
        <?php foreach ($unmatchedExpenses as $exp): ?>
        <tr>
          <td><?= htmlspecialchars((string) $exp['expense_date']) ?></td>
          <td><?= htmlspecialchars($exp['category']) ?></td>
          <td><?= htmlspecialchars((string) ($exp['vendor_name'] ?? '—')) ?></td>
          <td><?= number_format((float) $exp['amount'], 2) ?> <?= htmlspecialchars($exp['currency_code']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="section">
    <h2>Bank Lines Not Yet Matched</h2>
    <?php if (empty($unmatchedBankLines)): ?>
      <p class="muted">Every imported bank statement line has been matched.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Date</th><th>Description</th><th>Credit</th><th>Debit</th></tr>
        <?php foreach ($unmatchedBankLines as $l): ?>
        <tr>
          <td><?= htmlspecialchars((string) $l['transaction_date']) ?></td>
          <td class="muted small"><?= htmlspecialchars((string) ($l['description'] ?? '')) ?></td>
          <td><?= $l['credit_amount'] !== null ? number_format((float) $l['credit_amount'], 2) : '—' ?></td>
          <td><?= $l['debit_amount'] !== null ? number_format((float) $l['debit_amount'], 2) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
