<div class="card page-wide">
  <h1>CA / Accounting</h1>
  <p class="muted">Independent of the order-pipeline system — nothing here affects order stages, and nothing in the order reports feeds this.</p>

  <div class="section">
    <h2>Revenue &amp; Benefits</h2>
    <p class="muted">FY/calendar-year revenue, and the government export-benefit claims (RODTEP etc.) linked to individual orders.</p>
    <div class="btn-row">
      <a class="btn-sm" href="/ca/reports">Open Revenue Reports</a>
      <a class="btn-sm" href="/ca/export-benefits">Open Export Benefits</a>
    </div>
  </div>

  <div class="section">
    <h2>Expenses &amp; TDS</h2>
    <p class="muted">Every recorded expense (ECGC, inspection, CHA, transport, ...), its TDS treatment, and a payable summary across all of them.</p>
    <div class="btn-row">
      <a class="btn-sm" href="/ca/expenses">Open Expenses</a>
      <a class="btn-sm" href="/ca/tds-summary">Open TDS Payable Summary</a>
    </div>
  </div>

  <div class="section">
    <h2>Bank &amp; Reconciliation</h2>
    <p class="muted">Upload a bank statement and match its lines against revenue settlements and expenses.</p>
    <div class="btn-row">
      <a class="btn-sm" href="/ca/bank-statement">Open Bank Statement</a>
      <a class="btn-sm" href="/ca/reconciliation">Open Reconciliation</a>
    </div>
  </div>

  <?php if ($canManageCa): ?>
  <div class="section">
    <h2>Zoho Books &amp; Financial Year Lock</h2>
    <p class="muted">Sync settlements/expenses to Zoho Books, and lock a financial year once it's fully reconciled. Both need the CA Manage permission — that's why they're only shown here, not above.</p>
    <div class="btn-row">
      <a class="btn-sm" href="/ca/zoho-sync">Open Zoho Books Sync</a>
      <a class="btn-sm" href="/ca/fy-locks">Open Financial Year Lock</a>
    </div>
  </div>
  <?php endif; ?>

  <div class="section">
    <h2>INR Settlement Register</h2>
    <p class="muted small">Every advance/balance/freight leg that has been marked cleared in the order pipeline: the actual INR amount credited, the forex gain/(loss) against the order's assumed exchange rate, and its FIRC/eBRC realization proof. Recorded from the order's own Payment Status section by whoever holds the "Add/edit INR actual" permission.</p>
    <?php if (empty($settlements)): ?>
      <p class="muted">No settlement legs have been cleared yet.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Order Ref</th><th>Client</th><th>Leg</th><th>Foreign Amount</th><th>Currency</th><th>Cleared On</th><th>INR Actual</th><th>Forex Gain/(Loss)</th><th>FIRC/eBRC</th><th>Zoho Books</th></tr>
        <?php foreach ($settlements as $s): ?>
        <tr>
          <td><a href="/orders/<?= (int) $s['order_id'] ?>"><?= htmlspecialchars($s['buyer_inquiry_ref']) ?></a></td>
          <td><?= htmlspecialchars($s['company_legal_name']) ?></td>
          <td><?= htmlspecialchars(ucfirst($s['leg'])) ?></td>
          <td><?= $s['foreign_amount'] !== null ? number_format((float) $s['foreign_amount'], 2) : '—' ?></td>
          <td><?= htmlspecialchars($s['currency_code']) ?></td>
          <td><?= htmlspecialchars((string) $s['cleared_at']) ?></td>
          <td>
            <?php if ($s['inr_actual'] !== null): ?>
              &#8377;<?= number_format((float) $s['inr_actual'], 2) ?>
              <div class="muted small">
                <?php if ($s['inr_actual_recorded_at'] !== null): ?>
                  <?= htmlspecialchars((string) $s['inr_actual_recorded_at']) ?><?php if ($s['inr_actual_recorded_by'] !== null): ?> by <?= htmlspecialchars($usersById[$s['inr_actual_recorded_by']] ?? ('User #' . $s['inr_actual_recorded_by'])) ?><?php endif; ?>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <span class="muted">Not yet recorded</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($s['forex_gain_loss'] !== null): ?>
              <span class="<?= $s['forex_gain_loss'] >= 0 ? '' : 'muted' ?>">&#8377;<?= number_format(abs($s['forex_gain_loss']), 2) ?> <?= $s['forex_gain_loss'] >= 0 ? 'gain' : 'loss' ?></span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($s['firc_reference'] !== null): ?>
              <?= htmlspecialchars($s['firc_reference']) ?><div class="muted small"><?= htmlspecialchars((string) $s['firc_received_at']) ?></div>
            <?php elseif ($s['firc_pending']): ?>
              <span class="review-banner bad" style="display:inline-flex; padding:3px 8px;">Pending — flag for follow-up</span>
            <?php else: ?>
              <span class="muted">Not yet recorded</span>
            <?php endif; ?>
          </td>
          <td class="muted small">
            <?php if ($s['zoho_synced_at'] !== null): ?>
              Synced <?= htmlspecialchars((string) $s['zoho_synced_at']) ?>
            <?php elseif ($s['inr_actual'] !== null): ?>
              Pending
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
