<div class="card page-wide">
  <h1>CA / Accounting</h1>
  <p class="muted">Independent of the order-pipeline system — nothing here affects order stages, and nothing in the order reports feeds this. Phase 1: the INR settlement register. Zoho sync, expense import, and reconciliation/FY reports land here in later phases.</p>

  <div class="section">
    <h2>INR Settlement Register</h2>
    <p class="muted small">Every advance/balance/freight leg that has been marked cleared in the order pipeline, with the actual INR amount credited to the bank (if recorded yet). Recorded from the order's own Payment Status section by whoever holds the "Add/edit INR actual" permission.</p>
    <?php if (empty($settlements)): ?>
      <p class="muted">No settlement legs have been cleared yet.</p>
    <?php else: ?>
      <table class="list">
        <tr><th>Order Ref</th><th>Client</th><th>Leg</th><th>Foreign Amount</th><th>Currency</th><th>Cleared On</th><th>INR Actual</th><th>Recorded</th></tr>
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
            <?php else: ?>
              <span class="muted">Not yet recorded</span>
            <?php endif; ?>
          </td>
          <td class="muted small">
            <?php if ($s['inr_actual_recorded_at'] !== null): ?>
              <?= htmlspecialchars((string) $s['inr_actual_recorded_at']) ?><?php if ($s['inr_actual_recorded_by'] !== null): ?> by <?= htmlspecialchars($usersById[$s['inr_actual_recorded_by']] ?? ('User #' . $s['inr_actual_recorded_by'])) ?><?php endif; ?>
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
