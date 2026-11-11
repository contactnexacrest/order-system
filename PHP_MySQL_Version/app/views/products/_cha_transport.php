<?php
/**
 * Owner decision D-300 (point 12, 11 Nov 2026): "Price includes packing and loading"
 * (ticked by default) and CHA + transport — one price per container, turned into a
 * price per unit from what one container really carries (the category's 8 CBM and
 * 27 t, or the product's own; a heavy stone reaches the weight first).
 *
 * In: $rules, $product, $__row (the supplier-price row, or null for a new one).
 * Node twin: views/products/_cha_transport.njk.
 */
use App\Helpers\View;
use App\Services\ThicknessPrices;

$__chaRow = $__row ?? [];
$__chaVal = $__chaRow['cha_transport_per_container'] ?? null;
$__chaDefault = $product['cha_transport_per_container'] ?? null;
$__chaUse = $__chaVal !== null ? (float) $__chaVal : ($__chaDefault !== null ? (float) $__chaDefault : null);
$__fit = ThicknessPrices::chaPerCbm($__chaUse, (float) $rules['container_cbm'], $rules['container_max_t'], $rules['t_per_cbm']);
$__incl = !isset($__chaRow['includes_packing_loading']) || (int) $__chaRow['includes_packing_loading'] === 1;
?>
<input type="hidden" name="includes_packing_loading" value="0">
<label class="inline full"><input type="checkbox" name="includes_packing_loading" value="1"<?= $__incl ? ' checked' : '' ?>> Price includes packing and loading <small class="muted">(usual — then packing and loading are not added again; untick to add the product's packing and loading costs)</small></label>
<fieldset class="full cha-transport" data-cha-transport data-container-cbm="<?= View::e(ThicknessPrices::fmt((float) $rules['container_cbm'])) ?>" data-fits-cbm="<?= View::e(number_format($__fit['fits_cbm'], 4, '.', '')) ?>">
  <legend>CHA + transport (one price per container)</legend>
  <div class="field-grid">
    <label>CHA + transport per container (₹)
      <input type="text" inputmode="decimal" name="cha_transport_per_container" data-max-decimals="2" data-max-value="9999999999.99" value="<?= $__chaVal !== null ? View::e(number_format((float) $__chaVal, 2, '.', '')) : '' ?>"
        placeholder="<?= $__chaDefault !== null ? 'product default ' . View::e(number_format((float) $__chaDefault, 2, '.', '')) : 'e.g. 60000.00' ?>"></label>
    <div><span class="muted small">Container holds</span><br><b><?= View::e(ThicknessPrices::fmt((float) $rules['container_cbm'])) ?> CBM · max <?= View::e(ThicknessPrices::fmt((float) $rules['container_max_t'])) ?> t</b>
      <br><small class="muted"><?= $rules['own'] !== [] && in_array('container', $rules['own'], true) ? 'this product\'s own figures' : 'from the category; a product can override' ?></small></div>
    <div><span class="muted small">Per unit</span><br><b data-cha-per-cbm><?= $__fit['per_cbm'] !== null ? '₹' . number_format($__fit['per_cbm'], 2) . ' / CBM' : '—' ?></b></div>
  </div>
  <?php if ($rules['t_per_cbm'] !== null): ?>
    <p class="small muted">Weight check: <?= View::e(ThicknessPrices::fmt((float) $rules['container_cbm'])) ?> CBM × <?= View::e(ThicknessPrices::fmt((float) $rules['t_per_cbm'])) ?> t = <?= View::e(ThicknessPrices::fmt(round((float) $__fit['weight_t'], 2))) ?> t
      <?= $__fit['by_weight'] ? '— over ' . View::e(ThicknessPrices::fmt((float) $rules['container_max_t'])) . ' t, so only ' . number_format($__fit['fits_cbm'], 2) . ' CBM fit and the price per CBM is worked out from that.' : '≤ ' . View::e(ThicknessPrices::fmt((float) $rules['container_max_t'])) . ' t ✓' ?></p>
  <?php endif; ?>
  <?php if ($rules['grid']): ?>
    <table class="list">
      <tr><th>Thickness</th><th>m² per container</th><th class="num">CHA + transport per m² (₹)</th><th class="num">per sq ft (₹)</th></tr>
      <?php foreach ($rules['rows'] as $__r): $__m2 = $__fit['fits_cbm'] / ($__r['mm'] / 1000); ?>
        <tr data-cha-row data-mm="<?= (int) $__r['mm'] ?>"><td><?= (int) $__r['mm'] ?> mm</td><td><?= number_format($__m2, 1) ?> m² (<?= number_format($__m2 * \App\Services\LineUnits::SQFT_PER_M2, 0) ?> sq ft)</td>
          <td class="num" data-cha-m2><?= $__fit['per_cbm'] !== null ? number_format($__fit['per_cbm'] * $__r['mm'] / 1000, 2) : '—' ?></td>
          <td class="num" data-cha-sqft><?= $__fit['per_cbm'] !== null ? number_format($__fit['per_cbm'] * $__r['mm'] / 1000 / \App\Services\LineUnits::SQFT_PER_M2, 2) : '—' ?></td></tr>
      <?php endforeach; ?>
      <tr><td><?= (int) $rules['cbm_from'] ?> mm+</td><td colspan="3"><span data-cha-cbm-line><?= $__fit['per_cbm'] !== null ? '₹' . number_format($__fit['per_cbm'], 2) . ' per CBM' : '—' ?></span></td></tr>
    </table>
  <?php endif; ?>
  <p class="small muted">Empty = the product's default<?= $__chaDefault !== null ? ' (₹' . number_format((float) $__chaDefault, 2) . ')' : ' (none set yet — set it on the product)' ?>. FOB cost = supplier price + CHA and transport per unit; the selling price is then worked out from it as usual.</p>
</fieldset>
