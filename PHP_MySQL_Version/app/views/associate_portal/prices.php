<?php
/**
 * Owner decision D-245 P3: the associate's price range. Per product: the price to
 * quote and the lowest price they may agree. Never cost, margin or formula.
 */
use App\Helpers\Csrf;
$__d = static fn ($v): string => $v ? \App\Helpers\Dates::short(substr((string) $v, 0, 10)) : '—';
?>
<div class="card page-wide">
  <h1>Your price range</h1>
  <p class="muted">Price list dated <b><?= $__d($list['changed_at']) ?></b><?= $list['acknowledged'] ? ' · acknowledged by you on ' . $__d($list['ack_at']) . ' ✓' : '' ?></p>
  <?php if (!$list['acknowledged'] && $list['rows'] !== []): ?>
    <div class="alert alert-warning">These prices are new or have changed. Please read them and acknowledge them.
      <form method="post" action="/associate/prices/acknowledge" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="hash" value="<?= htmlspecialchars($list['hash']) ?>"><button type="submit" class="btn-sm">I have read this price list</button></form>
    </div>
  <?php endif; ?>
  <table class="list">
    <tr><th>Product</th><th>Unit</th><th class="r">Quote price (USD)</th><th class="r">Lowest you may agree (USD)</th></tr>
    <?php foreach ($list['rows'] as $r): ?>
      <tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['unit']) ?></td><td class="r"><?= number_format($r['quote'], 2) ?></td><td class="r"><?= number_format($r['lowest'], 2) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($list['rows'] === []): ?><tr><td colspan="4" class="muted">No priced products yet.</td></tr><?php endif; ?>
  </table>
  <p class="small">Quote at the first price. You may agree any price down to the second one; your commission (<?= htmlspecialchars(\App\Services\AssociateCommission::levelLabel($list['level'])) ?>) is the same anywhere in between. Below it, NexaCrest must approve in writing and <b>no commission is paid</b> on that line. Prices are FOB, per unit.</p>
</div>
