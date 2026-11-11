<?php
/**
 * Owner decision D-304 (point 14, 11 Nov 2026): one self-explaining line wherever the
 * pricing rate is shown (the catalogue header and Pricing settings; price build-up viewers
 * only). Never on order lines, quotes or documents.
 *
 * In: $fx (FxRateService::current()). Node twin: views/pricing/_fx_rate_line.njk.
 */
use App\Helpers\View;

$__rate = (float) $fx['rate'];
?>
<p class="fx-rate-line small">
  <?php if ($fx['mode'] === 'auto' && $fx['market'] !== null): ?>
    <b>Pricing rate ₹<?= number_format($__rate, 2) ?> per USD</b> — set <?= View::e(rtrim(rtrim(number_format((float) $fx['cushion'], 1), '0'), '.')) ?>% below today's market rate (₹<?= number_format((float) $fx['market'], 2) ?>) on purpose: it keeps our prices safe if the rupee gets stronger before the buyer pays.
  <?php else: ?>
    <b>Pricing rate ₹<?= number_format($__rate, 2) ?> per USD</b> — <?= $fx['mode'] === 'manual' ? 'set by hand (Manual)' : 'the manual rate, until the first daily rate is fetched' ?>; keep it a little below the market rate on purpose: it keeps our prices safe if the rupee gets stronger before the buyer pays.
  <?php endif; ?>
  <details class="inline-help"><summary>Why?</summary>
    <span class="help-line">Example: we quote USD 1,000 when the market rate is ₹88.40. The buyer pays 60 days later and the rupee has got stronger: USD 1,000 now brings only ₹86,630 instead of ₹88,400. Our supplier still wants the same rupees. Because our prices were worked out at ₹86.63 (2% below ₹88.40), the cost was covered — the 2% cushion absorbed the move. Without it, the same move would come straight out of our margin.</span>
  </details>
  <?php foreach ($fx['warnings'] as $__w): ?><br><span class="badge badge-warning">⚠ <?= View::e($__w) ?></span><?php endforeach; ?>
</p>
