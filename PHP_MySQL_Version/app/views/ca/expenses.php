<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>Expenses</h1>
  <p class="muted">Imported one-way from Zoho Books — salary, supplier payments, travel, and anything else entered as an expense there. This is a read-only mirror for CA/reconciliation reporting; expenses are still entered in Zoho Books itself, never here. <a href="/ca/zoho-sync">Zoho Books sync &rarr;</a></p>

  <div class="section">
    <h2>Imported Expenses</h2>
    <p class="muted small">The TDS column is a local-only annotation — setting it never changes anything in Zoho Books.</p>
    <?php if (empty($expenses)): ?>
      <p class="muted">No expenses imported yet — run a Zoho Books sync once it's configured.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Date</th><th>Category</th><th>Vendor</th><th>Description</th><th>Amount</th><th>TDS</th></tr>
        <?php foreach ($expenses as $e): ?>
        <tr>
          <td><?= htmlspecialchars((string) $e['expense_date']) ?></td>
          <td><?= htmlspecialchars($e['category']) ?></td>
          <td><?= htmlspecialchars((string) ($e['vendor_name'] ?? '—')) ?></td>
          <td class="muted small"><?= htmlspecialchars((string) ($e['description'] ?? '')) ?></td>
          <td><?= number_format((float) $e['amount'], 2) ?> <?= htmlspecialchars($e['currency_code']) ?></td>
          <td>
            <?php $expenseLockMessage = \App\Repositories\CaFyLockRepository::lockMessageForDate($e['expense_date']); ?>
            <?php if ($canEditTds && $expenseLockMessage === null): ?>
              <form method="post" action="/ca/expenses/<?= (int) $e['id'] ?>/tds" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                <?= \App\Helpers\Csrf::field() ?>
                <label style="display:flex; gap:4px; align-items:center;">
                  <input type="checkbox" name="is_tds_applicable" value="1" <?= $e['is_tds_applicable'] ? 'checked' : '' ?>> TDS
                </label>
                <input type="text" name="tds_amount" placeholder="Amount" value="<?= htmlspecialchars((string) ($e['tds_amount'] ?? '')) ?>" style="width:90px;">
                <button type="submit" class="btn-sm">Save</button>
              </form>
            <?php else: ?>
              <?php if ($e['is_tds_applicable']): ?>
                Yes<?= $e['tds_amount'] !== null ? ' (' . number_format((float) $e['tds_amount'], 2) . ')' : '' ?>
              <?php else: ?>
                <span class="muted">No</span>
              <?php endif; ?>
              <?php if ($expenseLockMessage !== null): ?>
                <br><span class="muted small">&#128274; <?= htmlspecialchars($expenseLockMessage) ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
