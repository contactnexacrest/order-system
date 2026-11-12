<?php
use App\Helpers\Csrf;
use App\Services\OrderLinePricing;

/**
 * Owner decision D-245 P2: the MD approves one order line's price below the floor
 * (never below the minimum price). In: $order, $line, $ctx, $check, $effect.
 * Node twin: views/orders/price_approval.njk.
 */
$__f = $check['figures'];
$__ref = (string) ($order['order_reference'] ?? ('#' . (int) $order['id']));
?>
<div class="container">
  <p><a href="/orders/<?= (int) $order['id'] ?>#price-check" data-focus-link>&larr; Back to order <?= htmlspecialchars($__ref) ?></a></p>
  <div class="section">
    <h1><?= $check['status'] === OrderLinePricing::CBM_CALCULATED ? 'Approve a price from a worked-out CBM price' : 'Approve a price below the floor' ?></h1>
    <p class="muted">Order <?= htmlspecialchars($__ref) ?> · line <?= (int) $line['line_no'] ?> · sold by <?= htmlspecialchars((string) $ctx['label']) ?></p>
    <table class="list">
      <tr><td>Product line</td><td><?= htmlspecialchars((string) $line['description']) ?><?= $line['quantity'] !== null ? ' × ' . htmlspecialchars(rtrim(rtrim((string) $line['quantity'], '0'), '.')) . ' ' . htmlspecialchars((string) ($line['unit'] ?? '')) : '' ?></td></tr>
      <tr><td>Asked price</td><td><strong>USD <?= number_format((float) $line['unit_price'], 2) ?></strong></td></tr>
      <?php if (!empty($check['cbm_reason'])): // Owner decision D-338 (2b) ?>
        <tr><td>Reason</td><td><strong><?= htmlspecialchars((string) $check['cbm_reason']) ?></strong></td></tr>
      <?php endif; ?>
      <?php if ($__f !== null): ?>
        <tr><td>Lowest price for this order (floor)</td><td>USD <?= number_format((float) $__f['floor'], 2) ?><?= $effect['short_per_unit'] > 0 ? ' <span class="muted">(short by USD ' . number_format((float) $effect['short_per_unit'], 2) . ' per unit)</span>' : '' ?></td></tr>
        <tr><td>Staff floor</td><td>USD <?= number_format((float) $__f['staff_floor'], 2) ?></td></tr>
        <tr><td>Minimum price (never below)</td><td>USD <?= number_format((float) $__f['minimum'], 2) ?></td></tr>
        <tr><td>Margin at the asked price</td><td><?= $effect['margin_pct'] !== null ? number_format((float) $effect['margin_pct'], 1) . '%' : '—' ?> <span class="muted">(minimum <?= htmlspecialchars(rtrim(rtrim(number_format((float) $__f['margin_pct'], 2), '0'), '.')) ?>%)</span></td></tr>
        <tr><td>Team incentive on this line</td><td>
          <?php if ($effect['reserve_line'] !== null): ?>
            USD <?= number_format((float) $effect['reserve_line'], 2) ?> → <strong>USD <?= number_format((float) $effect['after_line'], 2) ?></strong>
          <?php else: ?>
            USD <?= number_format((float) $effect['reserve_per_unit'], 2) ?> → <strong>USD <?= number_format((float) $effect['reserve_per_unit'] - (float) $effect['cut_per_unit'], 2) ?></strong> per unit
          <?php endif; ?>
        </td></tr>
        <?php if ($effect['commission_zero']): ?>
          <tr><td>Associate commission on this line</td><td><strong>0</strong> — <?= htmlspecialchars((string) $ctx['associate']['name']) ?> earns nothing on a line approved below their floor.</td></tr>
        <?php endif; ?>
      <?php else: ?>
        <tr><td>Price check</td><td><?= htmlspecialchars((string) $check['note']) ?></td></tr>
      <?php endif; ?>
    </table>

    <?php if ($check['status'] === OrderLinePricing::BELOW_MINIMUM): ?>
      <p class="notice notice-error">⛔ USD <?= number_format((float) $line['unit_price'], 2) ?> is below the minimum price of USD <?= number_format((float) $__f['minimum'], 2) ?>. Nobody can approve it, not even the MD. To sell lower, change the product's margin (Products → pricing) — that change is logged.</p>
    <?php elseif (in_array($check['status'], OrderLinePricing::APPROVABLE, true)): ?>
      <form method="post" action="/orders/<?= (int) $order['id'] ?>/products/<?= (int) $line['id'] ?>/price-approval">
        <?= Csrf::field() ?>
        <label>Reason *<textarea name="reason" rows="2" required minlength="10" maxlength="500" placeholder="e.g. Second container for a repeat buyer; matched a competitor offer"></textarea></label>
        <p class="notice notice-warning">
          <?php if ($check['status'] === OrderLinePricing::NO_PRICE): ?>
            This product has no price set, so there is no floor to check against. Approving lets the line go on the Proforma Invoice at USD <?= number_format((float) $line['unit_price'], 2) ?>.
          <?php elseif ($check['status'] === OrderLinePricing::CBM_CALCULATED): ?>
            The price is at or above the floor, but the supplier quoted no <?= (int) ($__f['cbm_from_mm'] ?? 50) ?> mm+ price — the per-CBM price was worked out from a thinner one. Approving lets the line go on the Quotation and Proforma Invoice at USD <?= number_format((float) $line['unit_price'], 2) ?>.
          <?php else: ?>
            The USD <?= number_format((float) $effect['short_per_unit'], 2) ?> per unit below the floor comes out of the team incentive first<?= $effect['commission_zero'] ? '; the associate\'s commission on this line becomes 0' : '' ?>.
          <?php endif; ?>
          The approval holds while the line keeps this price; it is logged with your name, the time and the reason.
        </p>
        <button type="submit" name="decision" value="approve">Approve</button>
        <button type="submit" name="decision" value="refuse" class="btn-secondary">Refuse</button>
      </form>
    <?php elseif ($check['status'] === OrderLinePricing::APPROVED): ?>
      <p class="notice notice-success">Already approved — <?= htmlspecialchars((string) $check['approval']['reason']) ?></p>
    <?php else: ?>
      <p class="muted">This line does not need an approval.</p>
    <?php endif; ?>
    <p class="muted small">Only the MD (permission "Manage pricing") sees this page.</p>
  </div>
</div>
