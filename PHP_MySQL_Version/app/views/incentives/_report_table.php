<?php
/** D-245 P5: one row per order × payee. Expects $rows, $totals. */
use App\Services\IncentiveReportService;
$__d = static fn (?string $d): string => $d ? \App\Helpers\Dates::short($d) : '—';
?>
<table class="list">
  <tr><th>Order</th><th>Client</th><th>Order date</th><th>Payee</th><th>Payee type</th><th>Role</th><th>Amount (INR)</th><th>GST (INR)</th><th>TDS (INR)</th><th>State</th><th>Due on</th><th>Payout run</th><th>Paid on</th><th>Kept — not eligible (INR)</th><th>Kept — capped (INR)</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
    <td><?= htmlspecialchars($r['client']) ?></td>
    <td><?= $__d($r['order_date']) ?></td>
    <td><?= htmlspecialchars($r['payee']) ?></td>
    <td><?= $r['payee_kind'] === 'associate' ? 'Associate' : 'Staff' ?></td>
    <td><?= htmlspecialchars($r['role']) ?></td>
    <td><?= number_format((float) $r['amount_inr'], 2) ?></td>
    <td><?= number_format((float) $r['gst_inr'], 2) ?></td>
    <td><?= number_format((float) $r['tds_inr'], 2) ?></td>
    <td><?= htmlspecialchars(IncentiveReportService::STATES[$r['state']] ?? $r['state']) ?></td>
    <td><?= $__d($r['due_on']) ?></td>
    <td><?= $__d($r['payout_run_on']) ?></td>
    <td><?= $__d($r['paid_on']) ?></td>
    <td><?= number_format((float) ($r['kept_not_eligible_inr'] ?? 0), 2) ?></td>
    <td><?= number_format((float) ($r['kept_capped_inr'] ?? 0), 2) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (empty($rows)): ?><tr><td colspan="15" class="muted">Nothing to show.</td></tr><?php endif; ?>
  <tr class="totals-row"><td colspan="6"><strong>Total (<?= (int) $totals['count'] ?> row<?= $totals['count'] === 1 ? '' : 's' ?>)</strong></td>
    <td><strong><?= number_format($totals['amount_inr'], 2) ?></strong></td><td><strong><?= number_format($totals['gst_inr'], 2) ?></strong></td><td><strong><?= number_format($totals['tds_inr'], 2) ?></strong></td><td colspan="4"></td>
    <td><strong><?= number_format((float) ($totals['kept_not_eligible_inr'] ?? 0), 2) ?></strong></td><td><strong><?= number_format((float) ($totals['kept_capped_inr'] ?? 0), 2) ?></strong></td></tr>
</table>
<p class="muted small">GST on an associate's commission is paid on top against their tax invoice and comes back as input credit — not a cost. TDS is deducted from the payout and owed to the government — a liability, not a cost. Estimated rows are worked out from the order as it stands and change until the MD locks the order. "Kept" is the part of a person's share the company keeps because they are not eligible for the team incentive or reached a cap; it is not paid to anyone else.</p>
