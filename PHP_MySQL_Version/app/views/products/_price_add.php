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
 * D-363 (13 Nov 2026, mockup ui3_u3): one row per thickness — tick box · thickness · the worked-out
 * price (live as the base price is typed; until then "type the price to see it") · its % rule with
 * a badge saying where it comes from ("category" or "this product"). A thickness with a typed price
 * is highlighted ("you typed ₹X on <date> — kept unless you tick"). One link under the list,
 * "Change the % rules for this product", opens Configure › Thickness rules. Same fields and maths.
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
// D-363: where each rule comes from — the product's own value ("this product") or the category.
$__afOwnOpt = [];
foreach ($rules['rows'] as $__r) {
    $__afOwnOpt[(int) $__r['mm']] = in_array((string) $__r['option'], $rules['own'], true);
}
$__afOwnK = false;
foreach ($rules['own'] as $__o) {
    $__afOwnK = $__afOwnK || str_starts_with((string) $__o, '×');
}
$__afBaseMm = (int) $rules['base_mm'];
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
    <label>Price (₹ per SQM, or per CBM for <?= $__afCbmFrom ?> mm and above) *<input type="text" inputmode="decimal" name="thickness_price" required data-calc-price
      data-max-decimals="2" data-max-value="999999999999.99" placeholder="e.g. 4600.00"></label>
    <?php if ($__afDate !== ''): ?>
      <label>Quoted on *<input type="date" name="<?= View::e($__afDate) ?>" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
    <?php endif; ?>
  </div>
  <fieldset class="thickness-also">
    <legend class="legend-sentence">Also work out from this <span data-calc-from>…</span> price <small class="muted">(the price rules in effect for this product)</small></legend>
    <?php foreach ($__afMms as $__mm): $__v = $__afVals[$__mm] ?? null; $__typed = $__v !== null && $__v['how'] === 'typed' && empty($__v['auto']);
      $__isCbm = $__mm === ThicknessPrices::CBM; $__p = $__afPct[$__mm] ?? 0.0; $__own = $__isCbm ? $__afOwnK : ($__afOwnOpt[$__mm] ?? false); ?>
      <div class="also-row<?= $__typed ? ' also-typed' : '' ?>" data-also-mm="<?= $__mm ?>"<?= $__isCbm ? ' data-cbm="1"' : ' data-pct="' . View::e(ThicknessPrices::fmt($__p)) . '"' ?>>
        <label class="also-check"><input type="checkbox" name="also[]" value="<?= $__mm ?>"<?= $__typed ? '' : ' checked' ?>>
          <b><?= View::e(ThicknessPrices::label($__mm, $__afCbmFrom)) ?></b></label>
        <span class="also-amount"><span data-also-amount>₹<span data-also-price>…</span> <?= $__isCbm ? '/ CBM' : '/ SQM' ?></span>
          <small class="muted" data-also-how>type the price to see it</small></span>
        <span class="also-rule">
          <?php if ($__typed): ?><span class="also-typed-note">you typed ₹<?= number_format((float) $__v['price'], 2) ?><?= !empty($__v['quoted_on']) ? ' on ' . View::e(\App\Helpers\Dates::short((string) $__v['quoted_on'])) : '' ?> — kept unless you tick</span><br><?php endif; ?>
          <?php if ($__isCbm): ?>× <?= View::e(ThicknessPrices::fmt((float) $rules['k'])) ?> of <?= $__afBaseMm ?> mm per SQM
          <?php elseif ($__mm === $__afBaseMm): ?>base
          <?php else: ?><?= $__p < 0 ? '−' : '+' ?><?= View::e(ThicknessPrices::fmt(abs($__p))) ?> % of <?= $__afBaseMm ?> mm<?php endif; ?>
          <?php if ($__mm !== $__afBaseMm || $__isCbm): ?><span class="badge level-from level-from-<?= $__own ? 'product' : 'category' ?>"><?= $__own ? 'this product' : 'category' ?></span><?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>
    <p class="also-change"><a href="/products/<?= (int) $product['id'] ?>/configure#thickness-rules">Change the % rules for this product</a></p>
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
