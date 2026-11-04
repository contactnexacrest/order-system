<?php
use App\Helpers\Csrf;
use App\Helpers\Dates;
?>
<div class="card">
  <h1>Ready to lock</h1>
  <p class="muted">Owner decision D-245: nothing locks by itself. These orders are fully paid by the buyer with no claim open. Locking fixes the team incentive and the associate's commission; the team is paid in the next payout run (<?= htmlspecialchars(Dates::short($nextRun)) ?> if locked today), the associate on their due date.</p>
  <table class="list">
    <tr><th>Order</th><th>Client</th><th>Fully paid</th><th>Profit for the incentive (&#8377;)</th><th>Team incentive (&#8377;)</th><th>Associate (&#8377;)</th><th></th></tr>
    <?php foreach ($rows as $r): $o = $r['order']; $c = $r['calc']; ?>
      <tr>
        <td><a href="/orders/<?= (int) $o['id'] ?>#financials-12d"><?= htmlspecialchars((string) ($o['buyer_inquiry_ref'] ?: $o['order_reference'])) ?></a></td>
        <td><?= htmlspecialchars((string) $o['company_legal_name']) ?></td>
        <td><?= htmlspecialchars(Dates::short((string) $r['paid_on'])) ?></td>
        <td><?= number_format($c['basis'], 2) ?></td>
        <td><?= number_format($c['team_total'], 2) ?><?= $c['over_profit'] ? ' <span class="badge badge-danger">more than the profit</span>' : ($c['margin_warning'] ? ' <span class="badge badge-warning">below minimum margin</span>' : '') ?></td>
        <td><?= $c['associate'] !== null ? number_format((float) $c['associate']['amount_inr'], 2) : '—' ?></td>
        <td><?php if ($canManagePricing): ?><form method="post" action="/orders/<?= (int) $o['id'] ?>/incentive/lock" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm">Lock</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="7" class="muted">Nothing is ready to lock.</td></tr><?php endif; ?>
  </table>
  <?php if ($canManagePricing && $rows !== []): ?>
    <form method="post" action="/incentives/ready/lock-all" data-confirm="Lock all <?= count($rows) ?> ready orders?" style="margin-top:10px">
      <?= Csrf::field() ?><button type="submit">Lock all ready (<?= count($rows) ?>)</button>
    </form>
  <?php endif; ?>
</div>
