<?php
use App\Repositories\IncentiveRepository;
use App\Services\IncentiveService;

$__year = date('Y');
$__paidThisYear = 0.0;
$__due = [];
foreach ($payouts as $p) {
    if ($p['status'] === 'paid' && substr((string) $p['paid_on'], 0, 4) === $__year) {
        $__paidThisYear += (float) $p['paid_inr'];
    }
    if ($p['status'] === 'due') {
        $__due[(string) $p['incentive_month']] = ($__due[(string) $p['incentive_month']] ?? 0) + (float) $p['amount_inr'];
    }
}
?>
<div class="card">
  <h1>My Incentives</h1>
  <div class="kv-grid">
    <div><span class="k">Paid in <?= $__year ?></span><span class="v">&#8377;<?= number_format($__paidThisYear, 0) ?></span></div>
    <?php foreach ($__due as $month => $amount): ?>
      <div><span class="k">Your <?= htmlspecialchars(IncentiveService::monthLabel($month)) ?> incentive (paid with the <?= htmlspecialchars(date('F', strtotime($month . '-01'))) ?> salary)</span><span class="v">&#8377;<?= number_format($amount, 0) ?></span></div>
    <?php endforeach; ?>
  </div>
  <table class="list" style="margin-top:12px">
    <tr><th>Order</th><th>Client</th><th>Your role</th><th>Amount (&#8377;)</th><th>Status</th></tr>
    <?php foreach ($payouts as $p): ?>
      <tr>
        <td><?= htmlspecialchars((string) ($p['buyer_inquiry_ref'] ?: $p['order_reference'])) ?></td>
        <td><?= htmlspecialchars((string) $p['company_legal_name']) ?></td>
        <td><?= htmlspecialchars(IncentiveRepository::ROLES[$p['role']] ?? (string) $p['role']) ?></td>
        <td><?= number_format((float) ($p['status'] === 'paid' ? $p['paid_inr'] : $p['amount_inr']), 0) ?></td>
        <td><?= $p['status'] === 'paid'
            ? '<span class="badge badge-success">' . htmlspecialchars(IncentiveService::monthLabel((string) $p['incentive_month'])) . ' incentive — paid</span>'
            : '<span class="badge badge-warning">' . htmlspecialchars(IncentiveService::monthLabel((string) $p['incentive_month'])) . ' incentive</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($payouts === []): ?><tr><td colspan="5" class="muted">Nothing yet. Your incentives appear here once the buyer of an order you worked on has fully paid.</td></tr><?php endif; ?>
  </table>
  <p class="muted small">An order appears here once the buyer has fully paid. Incentive cycle: <?= $cutoff === 'last' ? 'the 1st to the last day of the month' : 'the ' . ((int) $cutoff + 1) . date('S', mktime(0, 0, 0, 1, (int) $cutoff + 1)) . ' of last month to the ' . (int) $cutoff . date('S', mktime(0, 0, 0, 1, (int) $cutoff)) . ' of this month' ?>. Your incentive for the cycle is paid together with your salary on the last day of the month.</p>
</div>
