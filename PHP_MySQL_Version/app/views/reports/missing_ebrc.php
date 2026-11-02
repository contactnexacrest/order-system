<?php
// Owner decision (2 Nov 2026, gap-list Q-6a): settle without waiting for eBRC — list what is missing instead.
// Node twin: views/reports/missing_ebrc.njk (same text).
?>
<div class="card page-wide">
  <p class="muted small"><a href="/reports">&larr; Back to Reports</a></p>
  <h1>Missing eBRC / FIRC</h1>
  <p class="muted">Every buyer payment that has reached the bank but has no FIRC / eBRC reference recorded yet. Marking an order fully settled does not wait for these — this list is how nothing is forgotten. Rows older than <?= (int) $alertDays ?> days are past the FEMA realisation reminder.</p>
  <p><a class="btn-sm" href="/reports/missing-ebrc?format=csv">Download CSV</a></p>
  <?php if ($rows === []): ?>
    <p class="muted">Nothing missing — every received payment has its FIRC / eBRC reference.</p>
  <?php else: ?>
  <table class="list">
    <tr><th>Order</th><th>Buyer</th><th>Payment</th><?php if ($showAmounts): ?><th>Amount</th><?php endif; ?><th>Received on</th><th>Days since receipt</th><th>Owner</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr<?= $r['days'] > $alertDays ? ' class="row-warn"' : '' ?>>
      <td><a href="/orders/<?= (int) $r['order_id'] ?>"><?= htmlspecialchars($r['order_reference']) ?></a></td>
      <td><?= htmlspecialchars($r['client']) ?></td>
      <td><?= htmlspecialchars($r['label']) ?></td>
      <?php if ($showAmounts): ?><td><?= htmlspecialchars($r['currency']) ?> <?= number_format($r['amount'], 2) ?></td><?php endif; ?>
      <td><?= htmlspecialchars($r['received_at']) ?></td>
      <td><?= (int) $r['days'] ?><?= $r['days'] > $alertDays ? ' &#9888;' : '' ?></td>
      <td><?= htmlspecialchars($r['owners'] !== '' ? $r['owners'] : '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="muted small"><?= count($rows) ?> payment(s) on <?= count(array_unique(array_column($rows, 'order_id'))) ?> order(s).</p>
  <?php endif; ?>
</div>
