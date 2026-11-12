<?php
/**
 * Owner decision D-245 P2: one order line's price check — "Quote · lowest" and a
 * badge; MD/ED (view_price_buildup) also see the minimum price. Selling-visible
 * people only; never on a document or in the client portal.
 *
 * Owner decision D-270 (point 25): a line in another unit than the product's
 * reference unit shows both — the figures per the line's unit, and the product's
 * figures per its reference unit with the line price converted ("= USD 2,644.23 / CBM").
 *
 * In: $__lc (OrderLinePricing::checkLine result, or null), $seesPriceBuildup.
 * Node twin: views/orders/_line_price.njk.
 */
if ($__lc === null) {
    return;
}
$__f = $__lc['figures'];
$__conv = $__lc['conversion'] ?? null;
$__converted = $__conv !== null && $__conv['factor'] !== null && abs((float) $__conv['factor'] - 1.0) > 1e-9;
$__ref = $__lc['reference'] ?? null;
?>
<span class="price-check-line small">
  <?php if ($__f !== null): ?>
    <?php // D-305 (point 19): on an associate's client order the figures are this order's, shown without the associate's name, level or %. ?>
    <span class="price-range muted">Quote <?= number_format((float) $__f['quote'], 2) ?> · lowest <?= number_format((float) $__f['floor'], 2) ?><?= ($__f['sold_by'] ?? 'staff') !== 'staff' ? ' for this order' : '' ?><?= $seesPriceBuildup ? ' · minimum ' . number_format((float) $__f['minimum'], 2) : '' ?><?= $__converted ? ' per ' . htmlspecialchars(\App\Services\LineUnits::label((string) $__conv['line_unit'])) : '' ?><?= $__lc['fixed'] ? ' <span title="Fixed when the Proforma Invoice was issued">(fixed at PI)</span>' : '' ?></span>
    <?php if ($__converted && $__ref !== null): ?>
      <span class="price-ref muted">Product reference per <?= htmlspecialchars(\App\Services\LineUnits::label((string) $__conv['ref_unit'])) ?>: quote <?= number_format((float) $__ref['quote'], 2) ?> · lowest <?= number_format((float) $__ref['floor'], 2) ?> · your price = USD <?= number_format((float) ($__conv['price_ref'] ?? 0), 2) ?> / <?= htmlspecialchars(\App\Services\LineUnits::label((string) $__conv['ref_unit'])) ?> (1 <?= htmlspecialchars(\App\Services\LineUnits::label((string) $__conv['line_unit'])) ?> = <?= rtrim(rtrim(number_format((float) $__conv['factor'], 4, '.', ''), '0'), '.') ?> <?= htmlspecialchars(\App\Services\LineUnits::label((string) $__conv['ref_unit'])) ?>)</span>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($__lc['status'] === \App\Services\OrderLinePricing::OK): ?>
    <span class="price-badge price-badge-ok">✓ OK</span>
  <?php elseif ($__lc['status'] === \App\Services\OrderLinePricing::BELOW): ?>
    <span class="price-badge price-badge-bad">✗ Below your lowest price<?= $__lc['requested'] ? ' — sent to the MD' : '' ?></span>
  <?php elseif ($__lc['status'] === \App\Services\OrderLinePricing::BELOW_MINIMUM): ?>
    <span class="price-badge price-badge-bad">✗ Below what NexaCrest can accept</span>
  <?php elseif ($__lc['status'] === \App\Services\OrderLinePricing::NO_PRICE): ?>
    <span class="price-badge price-badge-bad">No price set — needs MD approval</span><?php if ($__lc['note'] !== null && $__conv !== null && $__conv['unconvertible']): ?> <span class="muted"><?= htmlspecialchars((string) $__lc['note']) ?></span><?php endif; ?>
  <?php elseif ($__lc['status'] === \App\Services\OrderLinePricing::CBM_CALCULATED): ?>
    <?php // Owner decision D-338 (2b). ?>
    <span class="price-badge price-badge-bad">CBM price worked out — needs MD approval<?= $__lc['requested'] ? ' — sent to the MD' : '' ?></span> <span class="muted"><?= htmlspecialchars((string) $__lc['cbm_reason']) ?></span>
  <?php elseif ($__lc['status'] === \App\Services\OrderLinePricing::NEEDS_SIZE): ?>
    <span class="price-badge price-badge-size">Size needed</span> <span class="muted"><?= htmlspecialchars((string) $__lc['note']) ?></span>
  <?php elseif ($__lc['status'] === \App\Services\OrderLinePricing::APPROVED): ?>
    <span class="price-badge price-badge-approved">Approved by the MD</span>
  <?php elseif ($__lc['note'] !== null): ?>
    <span class="price-badge price-badge-none"><?= htmlspecialchars((string) $__lc['note']) ?></span>
  <?php endif; ?>
</span>
