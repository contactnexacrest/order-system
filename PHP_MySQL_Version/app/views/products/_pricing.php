<?php
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\PricingService;

/**
 * Owner decision D-245 (4 Nov 2026), P1: the product's price.
 *  - $pricingFull (view_price_buildup): the whole build-up, every associate level, and
 *    (manage_pricing) this product's own overrides;
 *  - $pricingStaff (may see selling prices only): the quote price and the lowest price;
 *  - nothing for anyone else (Procurement sees supplier prices on Market prices).
 */
$__unit = !empty($product['reference_unit']) ? (string) $product['reference_unit'] : 'unit';
$__usd = static fn (float $v): string => 'USD ' . number_format($v, 2);
?>
<?php if ($pricingFull !== null): $__p = $pricingFull; $__b = $__p['build']; $__e = $__p['effective']; ?>
<div class="section" id="pricing">
  <h2>Price build-up <small class="muted">(per <?= View::e($__unit) ?>, FOB, USD · MD / ED only)</small></h2>
  <?php if ($canMarketPrices): ?><p><a class="btn-sm" href="/products/<?= (int) $product['id'] ?>/market-prices">Market prices</a></p><?php endif; ?>
  <?php if ($__b === null): ?>
    <p class="muted"><?= View::e($__p['missing']) ?> Procurement records supplier prices on the Market prices page.</p>
  <?php else: ?>
    <table class="list" style="max-width:720px">
      <tr><td>Supplier price (<?= View::e($__p['supplier']['supplier_name']) ?>, used for pricing<?= $__p['price_date'] !== null ? ', quoted ' . View::e($__p['price_date']) : '' ?>)
        <?php if ($__p['staleness'] !== null): ?> <span class="badge <?= $__p['staleness'] === 'expired' ? 'badge-danger' : 'badge-warning' ?>"><?= $__p['staleness'] ?></span><?php endif; ?></td>
        <td class="num">&#8377;<?= number_format((float) $__p['supplier_inr'], 2) ?></td></tr>
      <tr><td>÷ pricing exchange rate (&#8377;<?= number_format($__p['settings']['pricing_fx_rate'], 4) ?>)</td><td class="num"><?= $__usd($__b['supplier_usd']) ?></td></tr>
      <tr><td>+ other costs to FOB <span class="muted small">(<?= View::e(PricingService::modeLabel($__e['other_mode'], $__e['other_value'])) ?><?= $product['pricing_other_cost_mode'] === null ? ', global' : ', this product' ?>)</span></td><td class="num"><?= $__usd($__b['other_costs']) ?></td></tr>
      <tr><td><strong>Cost (FOB)</strong></td><td class="num"><strong><?= $__usd($__b['cost']) ?></strong></td></tr>
      <tr><td>Minimum price = cost ÷ (1 − <?= View::e(PricingService::pct($__e['margin_pct'])) ?> margin) <span class="muted small">— nobody goes below this</span></td><td class="num"><strong><?= $__usd($__b['minimum']) ?></strong></td></tr>
      <tr><td>+ team incentive reserve <span class="muted small">(<?= View::e(PricingService::modeLabel($__e['incentive_mode'], $__e['incentive_value'])) ?><?= $__e['incentive_mode'] === 'pct' ? ' of profit' : '' ?>)</span>
        <span class="badge <?= $__p['program_on'] ? 'badge-success' : 'badge-inactive' ?>">program <?= $__p['program_on'] ? 'on' : 'off' ?></span></td><td class="num"><?= $__usd($__b['reserve']) ?></td></tr>
      <tr><td><strong>Staff floor</strong> (lowest price your sales staff may agree)</td><td class="num"><strong><?= $__usd($__b['staff_floor']) ?></strong></td></tr>
      <tr><td>Staff quote price (+ <?= View::e(PricingService::pct($__e['buffer_pct'])) ?> buffer)</td><td class="num"><strong><?= $__usd($__b['staff_quote']) ?></strong></td></tr>
    </table>
    <?php if ($__p['levels'] !== []): ?>
      <h3>Associates <small class="muted">(floor = staff floor ÷ (1 − commission %), or + a fixed amount)</small></h3>
      <table class="list" style="max-width:720px">
        <tr><th>Commission level</th><th>Commission</th><th class="num">Floor</th><th class="num">Quote price</th></tr>
        <?php foreach ($__p['levels'] as $l): ?>
          <tr><td><?= View::e($l['name']) ?></td><td><?= View::e($l['label']) ?></td><td class="num"><?= number_format($l['floor'], 2) ?></td><td class="num"><?= number_format($l['quote'], 2) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  <?php endif; ?>

  <h3>This product's own settings <?= $canManagePricing ? '' : '<small class="muted">(only the MD can change them)</small>' ?></h3>
  <form method="post" action="/products/<?= (int) $product['id'] ?>/pricing" class="field-grid">
    <?= Csrf::field() ?>
    <?php $__ro = $canManagePricing ? '' : ' disabled'; ?>
    <label>Margin %<input type="number" name="pricing_margin_pct" min="0" max="89.99" step="0.01" value="<?= View::e($product['pricing_margin_pct'] === null ? '' : rtrim(rtrim((string) $product['pricing_margin_pct'], '0'), '.')) ?>" placeholder="global <?= View::e(PricingService::pct($__p['settings']['margin_pct'])) ?>"<?= $__ro ?>></label>
    <label>Buffer %<input type="number" name="pricing_buffer_pct" min="0" max="100" step="0.01" value="<?= View::e($product['pricing_buffer_pct'] === null ? '' : rtrim(rtrim((string) $product['pricing_buffer_pct'], '0'), '.')) ?>" placeholder="global <?= View::e(PricingService::pct($__p['settings']['buffer_pct'])) ?>"<?= $__ro ?>></label>
    <label>Other costs to FOB<span class="row" style="display:flex;gap:6px">
      <select name="pricing_other_cost_mode" aria-label="Other costs to FOB: type"<?= $__ro ?>>
        <option value="pct"<?= ($product['pricing_other_cost_mode'] ?? 'pct') === 'pct' ? ' selected' : '' ?>>%</option>
        <option value="usd"<?= ($product['pricing_other_cost_mode'] ?? '') === 'usd' ? ' selected' : '' ?>>USD per unit</option>
      </select>
      <input type="number" name="pricing_other_cost_value" min="0" step="0.0001" aria-label="Other costs to FOB: value" value="<?= View::e($product['pricing_other_cost_value'] === null ? '' : rtrim(rtrim((string) $product['pricing_other_cost_value'], '0'), '.')) ?>" placeholder="global <?= View::e(PricingService::pct($__p['settings']['other_cost_pct'])) ?>"<?= $__ro ?>></span></label>
    <label>Team incentive reserve<span class="row" style="display:flex;gap:6px">
      <select name="pricing_incentive_mode" aria-label="Team incentive reserve: type"<?= $__ro ?>>
        <option value="pct"<?= ($product['pricing_incentive_mode'] ?? 'pct') === 'pct' ? ' selected' : '' ?>>% of profit</option>
        <option value="usd"<?= ($product['pricing_incentive_mode'] ?? '') === 'usd' ? ' selected' : '' ?>>USD per unit</option>
      </select>
      <input type="number" name="pricing_incentive_value" min="0" step="0.0001" aria-label="Team incentive reserve: value" value="<?= View::e($product['pricing_incentive_value'] === null ? '' : rtrim(rtrim((string) $product['pricing_incentive_value'], '0'), '.')) ?>" placeholder="global <?= View::e(PricingService::modeLabel($__p['settings']['incentive_mode'], $__p['settings']['incentive_value'])) ?>"<?= $__ro ?>></span></label>
    <p class="muted small full">Empty = uses the global value (Admin → Pricing). Fill in to override for this product only.<?= $__p['overrides'] !== [] ? ' Overridden now: ' . View::e(implode(', ', $__p['overrides'])) . '.' : '' ?></p>
    <?php if ($canManagePricing): ?><div><button type="submit" class="btn-sm">Save this product's settings</button></div><?php endif; ?>
  </form>
</div>
<?php elseif ($pricingStaff !== null): ?>
<div class="section" id="pricing">
  <h2>Price <small class="muted">(per <?= View::e($__unit) ?>, FOB, USD)</small></h2>
  <?php if ($pricingStaff['build'] === null): ?>
    <p class="muted">No price yet — ask Procurement to record the supplier price.</p>
  <?php else: ?>
    <table class="list" style="max-width:480px">
      <tr><th class="num">Quote price</th><th class="num">Your lowest price</th></tr>
      <tr><td class="num"><?= $__usd($pricingStaff['build']['staff_quote']) ?></td><td class="num"><?= $__usd($pricingStaff['build']['staff_floor']) ?></td></tr>
    </table>
    <p class="muted small">Quote at the first price; you may agree any price down to the second. Below it, the order line needs the MD's approval.</p>
  <?php endif; ?>
</div>
<?php elseif ($canMarketPrices): ?>
<div class="section">
  <h2>Market prices</h2>
  <p><a class="btn-sm" href="/products/<?= (int) $product['id'] ?>/market-prices">Supplier and market prices</a></p>
</div>
<?php endif; ?>
