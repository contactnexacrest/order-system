<?php
/**
 * Owner decision D-335 (12 Nov 2026): "Add / update a price" — the fields. One thickness is
 * typed (Thickness *, Price *, Quoted on *); "Also work out from this … price" has one tick
 * box per OTHER thickness with the result and how it is worked out ("= 4,600 − 20 %",
 * "change −20 %" opens the product's price rules). Ticked by default: only thicknesses with
 * no TYPED price. app.js (initThicknessCalc) shows the amounts while typing; the server
 * works them out again on save (ThicknessPrices::calcFrom). For an existing supplier price:
 * "Base for orders" — keep the current base or make this price the base.
 *
 * In: $rules, $product, $__af = ['grid' => ThicknessPrices::values()|null, 'card' => price card|null,
 *     'dateField' => name of the date field ('' = the surrounding form has "Quoted on" already)].
 * Node twin: views/products/_price_add.njk.
 */
use App\Helpers\View;
use App\Services\ThicknessPrices;

$__afMms = ThicknessPrices::thicknesses($rules);
$__afPct = [];
foreach ($rules['rows'] as $__r) {
    $__afPct[(int) $__r['mm']] = (float) $__r['pct'];
}
$__afVals = $__af['grid']['values'] ?? [];
$__afCard = $__af['card'] ?? null;
$__afDate = (string) ($__af['dateField'] ?? '');
$__afCbmFrom = (int) $rules['cbm_from'];
?>
<div class="full thickness-calc" data-thickness-calc data-k="<?= View::e(ThicknessPrices::fmt((float) $rules['k'])) ?>" data-base-mm="<?= (int) $rules['base_mm'] ?>">
  <div class="field-grid">
    <label>Thickness *
      <select name="thickness_mm" required data-calc-thickness>
        <option value="">Choose…</option>
        <?php foreach ($__afMms as $__mm): ?>
          <option value="<?= $__mm ?>"<?= $__mm === ThicknessPrices::CBM ? ' data-cbm="1"' : ' data-pct="' . View::e(ThicknessPrices::fmt($__afPct[$__mm] ?? 0.0)) . '"' ?>><?= View::e(ThicknessPrices::label($__mm, $__afCbmFrom)) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label>Price (₹ per m², or per CBM for <?= $__afCbmFrom ?> mm and above) *<input type="text" inputmode="decimal" name="thickness_price" required data-calc-price
      data-max-decimals="2" data-max-value="999999999999.99" placeholder="e.g. 4600.00"></label>
    <?php if ($__afDate !== ''): ?>
      <label>Quoted on *<input type="date" name="<?= View::e($__afDate) ?>" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
    <?php endif; ?>
  </div>
  <fieldset class="thickness-also">
    <legend class="legend-sentence">Also work out from this <span data-calc-from>…</span> price <small class="muted">(the price rules in effect for this product)</small></legend>
    <?php foreach ($__afMms as $__mm): $__v = $__afVals[$__mm] ?? null; $__typed = $__v !== null && $__v['how'] === 'typed' && empty($__v['auto']);
      $__isCbm = $__mm === ThicknessPrices::CBM; ?>
      <div class="also-row" data-also-mm="<?= $__mm ?>"<?= $__isCbm ? ' data-cbm="1"' : ' data-pct="' . View::e(ThicknessPrices::fmt($__afPct[$__mm] ?? 0.0)) . '"' ?>>
        <label class="inline"><input type="checkbox" name="also[]" value="<?= $__mm ?>"<?= $__typed ? '' : ' checked' ?>>
          <b><?= View::e(ThicknessPrices::label($__mm, $__afCbmFrom)) ?> → ₹<span data-also-price>…</span></b> <?= $__isCbm ? '/ CBM' : '/ m²' ?></label>
        <?php if ($__typed): ?><span class="badge badge-typed">you typed ₹<?= number_format((float) $__v['price'], 2) ?><?= !empty($__v['quoted_on']) ? ' on ' . View::e(\App\Helpers\Dates::short((string) $__v['quoted_on'])) : '' ?> — kept unless you tick this</span><?php endif; ?>
        <br><small class="muted"><span data-also-how></span> · <a href="/products/<?= (int) $product['id'] ?>/edit#price-rules">change <span data-also-change><?= $__isCbm ? '× ' . View::e(ThicknessPrices::fmt((float) $rules['k'])) : ($__mm === (int) $rules['base_mm'] ? 'the rules' : View::e((($__afPct[$__mm] ?? 0) < 0 ? '−' : '+') . ThicknessPrices::fmt(abs($__afPct[$__mm] ?? 0.0)) . ' %')) ?></span></a></small>
      </div>
    <?php endforeach; ?>
    <p class="small muted">Ticked by default: only thicknesses that have no typed price. A typed price is never ticked — tick it only if you really want to replace it. A thickness you leave unticked keeps the price it has (or stays "no price").</p>
  </fieldset>
  <?php if ($__afCard !== null): ?>
    <fieldset class="thickness-base">
      <legend class="legend-sentence">Base for orders</legend>
      <label class="inline"><input type="radio" name="base" value="keep" checked> keep <?= View::e($__afCard['base_label']) ?><?= $__afCard['base_price'] !== null ? ' ₹' . number_format((float) $__afCard['base_price'], 2) . ' / ' . View::e($__afCard['base_unit']) : ' (no price yet)' ?></label>
      <label class="inline"><input type="radio" name="base" value="this"> make this <span data-calc-from>…</span> price the base</label>
    </fieldset>
  <?php endif; ?>
</div>
