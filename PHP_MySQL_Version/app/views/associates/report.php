<?php $__m = static fn ($v): string => number_format((float) $v, 2); ?>
<div class="card page-wide">
  <h1>Associates report — FY <?= htmlspecialchars($year) ?>–<?= substr((string) ((int) $year + 1), 2) ?></h1>
  <form method="get" action="/reports/associates" class="inline-form"><label>Financial year from April <input type="number" name="fy" min="2020" max="2100" required value="<?= htmlspecialchars($year) ?>" placeholder="e.g. 2026"></label><button type="submit" class="btn-sm">Show</button></form>

  <div class="section"><h2>Performance <a class="btn-sm btn-secondary" href="/reports/associates?fy=<?= urlencode($year) ?>&amp;format=performance">CSV</a></h2>
    <table class="list"><tr><th>Associate</th><th>Status</th><th>Clients</th><th>Orders in year</th><th>FOB value of orders</th><th>Paid INR</th><th>TDS INR</th><th>Waiting INR</th></tr>
      <?php foreach ($perf as $r): ?><tr><td><a href="/associates/<?= (int) $r['id'] ?>"><?= htmlspecialchars((string) $r['name']) ?></a></td><td><?= htmlspecialchars((string) $r['status']) ?></td><td><?= (int) $r['clients'] ?></td><td><?= (int) $r['orders'] ?></td><td><?= $__m($r['fob']) ?></td><td><?= $__m($r['paid']) ?></td><td><?= $__m($r['tds']) ?></td><td><?= $__m($r['waiting']) ?></td></tr><?php endforeach; ?>
      <?php if ($perf === []): ?><tr><td colspan="8" class="muted">No associates.</td></tr><?php endif; ?>
    </table></div>

  <div class="section"><h2>Commission waiting (ageing) <a class="btn-sm btn-secondary" href="/reports/associates?fy=<?= urlencode($year) ?>&amp;format=ageing">CSV</a></h2>
    <table class="list"><tr><th>Associate</th><th>Order</th><th>Month</th><th>Amount INR</th><th>Status</th><th>Days</th></tr>
      <?php foreach ($ageing as $r): ?><tr><td><?= htmlspecialchars((string) $r['name']) ?></td><td><?= htmlspecialchars((string) $r['order_reference']) ?></td><td><?= htmlspecialchars((string) $r['incentive_month']) ?></td><td><?= $__m($r['amount_inr']) ?></td><td><?= htmlspecialchars((string) $r['status']) ?><?= $r['hold_reason'] ? ' — ' . htmlspecialchars((string) $r['hold_reason']) : '' ?></td><td><?= (int) $r['age_days'] ?></td></tr><?php endforeach; ?>
      <?php if ($ageing === []): ?><tr><td colspan="6" class="muted">Nothing waiting.</td></tr><?php endif; ?>
    </table></div>

  <div class="section"><h2>TDS register <a class="btn-sm btn-secondary" href="/reports/associates?fy=<?= urlencode($year) ?>&amp;format=tds">CSV</a></h2>
    <table class="list"><tr><th>Paid on</th><th>Associate</th><th>PAN</th><th>Order</th><th>Paid INR</th><th>TDS INR</th><th>Bank reference</th></tr>
      <?php foreach ($tds as $r): ?><tr><td><?= htmlspecialchars((string) $r['paid_on']) ?></td><td><?= htmlspecialchars((string) $r['name']) ?></td><td><?= htmlspecialchars((string) ($r['pan'] ?? '')) ?></td><td><?= htmlspecialchars((string) $r['order_reference']) ?></td><td><?= $__m($r['paid_inr']) ?></td><td><?= $__m($r['tds_inr']) ?></td><td><?= htmlspecialchars((string) ($r['paid_reference'] ?? '')) ?></td></tr><?php endforeach; ?>
      <?php if ($tds === []): ?><tr><td colspan="7" class="muted">No payments in this year.</td></tr><?php endif; ?>
    </table></div>
</div>
