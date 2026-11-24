<?php
/**
 * D-410 (M05, owner 24 Nov 2026): when the associate's commission % is above the % the client's price includes,
 * the MD/ED (view_price_buildup) see how much of the commission comes from NexaCrest's margin.
 * Expects $c = AssociateCommission::forOrder(). Node twin: views/orders/_associate_margin.njk.
 */
$__seesMargin = \App\Services\PermissionService::can((int) $currentUser['id'], $currentUser['role_id'] !== null ? (int) $currentUser['role_id'] : null, 'view_price_buildup');
$__pct = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . '%';
?>
<?php if ($__seesMargin && (float) ($c['margin_pct'] ?? 0) > 0): ?>
  <div class="notice notice-info" data-margin-box style="margin-top:8px">
    <b>Paid from our margin:</b> <?= htmlspecialchars((string) $c['currency']) ?> <?= number_format((float) $c['margin_fc'], 2) ?> · ₹<?= number_format((float) $c['margin_inr'], 2) ?>
    <br><small>The client's price includes <?= $__pct($c['included_pct']) ?> commission; the associate is at <?= $__pct($c['rate']) ?>, so the other <?= $__pct($c['margin_pct']) ?> of the value of the goods comes from our margin. <span class="help" title="Set in Admin → Pricing → Settings ('Commission included in the client's price') or on the associate's page. The order's prices never go below the minimum price.">ℹ</span></small>
  </div>
<?php endif; ?>
