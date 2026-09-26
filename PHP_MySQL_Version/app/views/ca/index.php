<div class="card page-wide">
  <h1>CA / Accounting</h1>
  <p class="muted">Independent of the order-pipeline system — nothing here affects order stages, and nothing in the order reports feeds this. <a href="/ca/reports">FY / calendar-year revenue reports &rarr;</a></p>

  <div class="section">
    <h2>INR Settlement Register</h2>
    <p class="muted small">Every advance/balance/freight leg that has been marked cleared in the order pipeline: the actual INR amount credited, the forex gain/(loss) against the order's assumed exchange rate, and its FIRC/eBRC realization proof. Recorded from the order's own Payment Status section by whoever holds the "Add/edit INR actual" permission.</p>
    <?php if (empty($settlements)): ?>
      <p class="muted">No settlement legs have been cleared yet.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Order Ref</th><th>Client</th><th>Leg</th><th>Foreign Amount</th><th>Currency</th><th>Cleared On</th><th>INR Actual</th><th>Forex Gain/(Loss)</th><th>FIRC/eBRC</th></tr>
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
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
