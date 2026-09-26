<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>Bank Statement</h1>
  <p class="muted">Upload a CSV export of the bank statement, then match each line to a settlement leg (revenue) or an imported expense. <a href="/ca/reconciliation">Reconciliation summary &rarr;</a></p>

  <div class="section">
    <h2>Upload Statement</h2>
    <p class="muted small">CSV only, with a header row. Most bank export formats work — the importer looks for Date, Description/Narration, Reference, and either separate Credit/Debit columns or a single Amount + Type column. Uploading the same date range twice never creates duplicate lines.</p>
    <form method="post" action="/ca/bank-statement/upload" enctype="multipart/form-data">
      <?= \App\Helpers\Csrf::field() ?>
      <input type="file" name="statement" accept=".csv" required>
      <button type="submit" class="btn-sm">Upload</button>
    </form>
  </div>

  <div class="section">
    <h2>Statement Lines</h2>
    <?php if (empty($lines)): ?>
      <p class="muted">No statement lines imported yet.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Date</th><th>Description</th><th>Reference</th><th>Credit</th><th>Debit</th><th>Match</th></tr>
        <?php foreach ($lines as $l): ?>
        <tr>
          <td><?= htmlspecialchars((string) $l['transaction_date']) ?></td>
          <td class="muted small"><?= htmlspecialchars((string) ($l['description'] ?? '')) ?></td>
          <td class="muted small"><?= htmlspecialchars((string) ($l['reference'] ?? '')) ?></td>
          <td><?= $l['credit_amount'] !== null ? number_format((float) $l['credit_amount'], 2) : '—' ?></td>
          <td><?= $l['debit_amount'] !== null ? number_format((float) $l['debit_amount'], 2) : '—' ?></td>
          <td>
            <?php if ($l['matched_order_id'] !== null): ?>
              Revenue: <a href="/orders/<?= (int) $l['matched_order_id'] ?>"><?= htmlspecialchars($l['matched_order_ref']) ?></a> — <?= htmlspecialchars(ucfirst($l['matched_leg'])) ?>
              <form method="post" action="/ca/bank-statement/<?= (int) $l['id'] ?>/unmatch" style="display:inline;">
                <?= \App\Helpers\Csrf::field() ?>
                <button type="submit" class="btn-sm btn-secondary">Unmatch</button>
              </form>
            <?php elseif ($l['matched_expense_id'] !== null): ?>
              Expense: <?= htmlspecialchars($l['matched_expense_category']) ?><?= $l['matched_expense_vendor'] ? ' (' . htmlspecialchars($l['matched_expense_vendor']) . ')' : '' ?>
              <form method="post" action="/ca/bank-statement/<?= (int) $l['id'] ?>/unmatch" style="display:inline;">
                <?= \App\Helpers\Csrf::field() ?>
                <button type="submit" class="btn-sm btn-secondary">Unmatch</button>
              </form>
            <?php else: ?>
              <?php if ($l['credit_amount'] !== null): ?>
                <form method="post" action="/ca/bank-statement/<?= (int) $l['id'] ?>/match-revenue" style="display:flex; gap:4px;">
                  <?= \App\Helpers\Csrf::field() ?>
                  <select name="order_leg" onchange="this.form.order_id.value=this.value.split('|')[0]; this.form.leg.value=this.value.split('|')[1];" style="max-width:220px;">
                    <option value="">Match to revenue leg&hellip;</option>
                    <?php foreach ($unmatchedRevenueLegs as $leg): ?>
                      <option value="<?= (int) $leg['order_id'] ?>|<?= htmlspecialchars($leg['leg']) ?>">
                        <?= htmlspecialchars($leg['buyer_inquiry_ref']) ?> — <?= htmlspecialchars(ucfirst($leg['leg'])) ?> — &#8377;<?= number_format((float) $leg['inr_actual'], 2) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <input type="hidden" name="order_id">
                  <input type="hidden" name="leg">
                  <button type="submit" class="btn-sm">Match</button>
                </form>
              <?php endif; ?>
              <?php if ($l['debit_amount'] !== null): ?>
                <form method="post" action="/ca/bank-statement/<?= (int) $l['id'] ?>/match-expense" style="display:flex; gap:4px; margin-top:4px;">
                  <?= \App\Helpers\Csrf::field() ?>
                  <select name="expense_id" style="max-width:220px;">
                    <option value="">Match to expense&hellip;</option>
                    <?php foreach ($unmatchedExpenses as $exp): ?>
                      <option value="<?= (int) $exp['id'] ?>"><?= htmlspecialchars($exp['category']) ?><?= $exp['vendor_name'] ? ' (' . htmlspecialchars($exp['vendor_name']) . ')' : '' ?> — <?= number_format((float) $exp['amount'], 2) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn-sm">Match</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
