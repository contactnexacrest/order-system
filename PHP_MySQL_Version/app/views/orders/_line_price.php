<?php
/**
 * Owner decision D-245 P2: one order line's price check — "Quote · lowest" and a
 * badge; MD/ED (view_price_buildup) also see the minimum price. Selling-visible
 * people only; never on a document or in the client portal.
 *
 * In: $__lc (OrderLinePricing::checkLine result, or null), $seesPriceBuildup.
 * Node twin: views/orders/_line_price.njk.
 */
if ($__lc === null) {
    return;
}
$__f = $__lc['figures'];
?>
<span class="price-check-line small">
  <?php if ($__f !== null): ?>
    <span class="price-range muted">Quote <?= number_format((float) $__f['quote'], 2) ?> · lowest <?= number_format((float) $__f['floor'], 2) ?><?= $seesPriceBuildup ? ' · minimum ' . number_format((float) $__f['minimum'], 2) : '' ?><?= $__lc['fixed'] ? ' <span title="Fixed when the Proforma Invoice was issued">(fixed at PI)</span>' : '' ?></span>
  <?php endif; ?>
  <?php if ($__lc['status'] === \App\Services\OrderLinePricing::OK): ?>
    <span class="price-badge price-badge-ok">✓ OK</span>
  <?php elseif ($__lc['status'] === \App\Services\OrderLinePricing::BELOW): ?>
    <span class="price-badge price-badge-bad">✗ Below your lowest price</span>
  <?php elseif ($__lc['status'] === \App\Services\OrderLinePricing::BELOW_MINIMUM): ?>
    <span class="price-badge price-badge-bad">✗ Below what NexaCrest can accept</span>
  <?php elseif ($__lc['status'] === \App\Services\OrderLinePricing::NO_PRICE): ?>
    <span class="price-badge price-badge-bad">No price set — needs MD approval</span>
  <?php elseif ($__lc['status'] === \App\Services\OrderLinePricing::APPROVED): ?>
    <span class="price-badge price-badge-approved">Approved by the MD</span>
  <?php elseif ($__lc['note'] !== null): ?>
    <span class="price-badge price-badge-none"><?= htmlspecialchars((string) $__lc['note']) ?></span>
  <?php endif; ?>
</span>
