<div class="card page-wide">
  <p class="muted small" style="margin-top:0;"><a href="/ca">&larr; CA / Accounting</a></p>
  <h1>Government Export Benefits</h1>
  <p class="muted">RODTEP and any other export incentive scheme claimed against a shipping bill. Unlike Expenses, this is money owed <strong>to</strong> the company by the government — entered here directly, since there's no Zoho Books import for it.</p>

  <div class="section">
    <h2>Totals</h2>
    <p>Claimed: <strong><?= number_format((float) $totalClaimed, 2) ?></strong> &middot; Received: <strong><?= number_format((float) $totalReceived, 2) ?></strong> &middot; Outstanding: <strong><?= number_format((float) $totalClaimed - (float) $totalReceived, 2) ?></strong></p>
  </div>

  <div class="section">
    <h2>Record a Claim</h2>
    <form method="post" action="/ca/export-benefits">
      <?= \App\Helpers\Csrf::field() ?>
      <div class="field-grid">
        <label>Scheme
          <select name="scheme_name" required>
            <option value="">Select…</option>
            <?php foreach ($schemeOptions as $opt): ?>
              <option value="<?= htmlspecialchars($opt['option_value']) ?>" <?= $opt['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($opt['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Order Reference (optional)
          <input type="text" name="order_reference" placeholder="e.g. NC/2026/0042">
        </label>
        <label>Shipping Bill / Scroll No. (optional)
          <input type="text" name="reference_number" placeholder="e.g., SB-2026-00417">
        </label>
        <label>Claimed Amount
          <input type="number" step="0.01" min="0.01" name="claimed_amount" placeholder="e.g., 15000.00" required>
        </label>
        <label>Currency
          <input type="text" name="currency_code" value="INR" maxlength="10" placeholder="e.g., INR" style="width:70px;">
        </label>
        <label>Claim Date
          <input type="date" name="claimed_at" required>
        </label>
        <label class="full">Notes (optional)
          <input type="text" name="notes" placeholder="Any extra context for this claim" style="width:100%;">
        </label>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:8px;">Record Claim</button>
    </form>
  </div>

  <div class="section">
    <h2>Claims</h2>
    <?php if (empty($benefits)): ?>
      <p class="muted">No claims recorded yet.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Claimed</th><th>Scheme</th><th>Order</th><th>Reference</th><th>Claimed Amount</th><th>Received</th><th>Status</th></tr>
        <?php foreach ($benefits as $b): ?>
        <tr>
          <td><?= htmlspecialchars((string) $b['claimed_at']) ?></td>
          <td><?= htmlspecialchars($b['scheme_name']) ?></td>
          <td><?= $b['order_id'] !== null ? '<a href="/orders/' . (int) $b['order_id'] . '">' . htmlspecialchars($b['order_reference']) . '</a>' : '<span class="muted">—</span>' ?></td>
          <td class="muted small"><?= htmlspecialchars((string) ($b['reference_number'] ?? '—')) ?></td>
          <td><?= number_format((float) $b['claimed_amount'], 2) ?> <?= htmlspecialchars($b['currency_code']) ?></td>
          <td>
            <?php if ($b['received_amount'] !== null): ?>
              <?= number_format((float) $b['received_amount'], 2) ?> <?= htmlspecialchars($b['currency_code']) ?>
              <div class="muted small"><?= htmlspecialchars((string) $b['received_at']) ?></div>
            <?php else: ?>
              <?php $benefitLockMessage = \App\Repositories\CaFyLockRepository::lockMessageForDate($b['claimed_at']); ?>
              <?php if ($benefitLockMessage === null || $canOverrideFyLock): ?>
                <?php if ($benefitLockMessage !== null): ?>
                  <span class="muted small" style="display:block;">&#9888; <?= htmlspecialchars($benefitLockMessage) ?> Saving will log an override.</span>
                <?php endif; ?>
                <form method="post" action="/ca/export-benefits/<?= (int) $b['id'] ?>/mark-received" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                  <?= \App\Helpers\Csrf::field() ?>
                  <input type="number" step="0.01" min="0" name="received_amount" placeholder="Amount" required style="width:100px;">
                  <input type="date" name="received_at" required>
                  <button type="submit" class="btn btn-sm">Mark Received</button>
                </form>
              <?php else: ?>
                <span class="muted small">Pending — <?= htmlspecialchars($benefitLockMessage) ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?= $b['received_amount'] !== null ? '<span class="badge good">Received</span>' : '<span class="badge">Claimed</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
