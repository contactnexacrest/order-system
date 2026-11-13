<?php
/**
 * Owner decision D-300 (point 12, 11 Nov 2026): the supplier price for every thickness —
 * 20/30/40 mm per m² and 50 mm and above per CBM. Type any one; the others are
 * CALCULATED from the category's price rules (grey). A calculated box can be typed
 * over (it turns TYPED); "↺ use calculated" goes back; app.js recalculates on screen
 * (initThicknessGrid). Saving a box at 0 or empty calculates it again; all empty is
 * refused ("Enter at least one price").
 *
 * In: $rules (ThicknessPrices::rules), $__grid (ThicknessPrices::complete, or null for a new price).
 * Node twin: views/products/_thickness_grid.njk.
 */
use App\Helpers\View;
use App\Services\ThicknessPrices;

$__rowsG = $rules['rows'];
$__rowsG[] = ['mm' => ThicknessPrices::CBM, 'pct' => null, 'option' => ''];
$__money = static fn (float $v): string => number_format($v, 2);
?>
<table class="list thickness-grid" data-thickness-grid data-k="<?= View::e(ThicknessPrices::fmt((float) $rules['k'])) ?>" data-base-mm="<?= (int) $rules['base_mm'] ?>">
  <tr><th>Thickness</th><th>Price (₹)</th><th>Unit</th><th>How it was set</th><th class="num">Same as per CBM</th></tr>
  <?php foreach ($__rowsG as $__r): $__mm = (int) $__r['mm']; $__isCbm = $__mm === ThicknessPrices::CBM;
      $__v = $__grid['values'][$__mm] ?? null; $__typed = $__v !== null && $__v['how'] === 'typed';
      $__rule = $__isCbm ? $rules['base_mm'] . ' mm price × ' . ThicknessPrices::fmt((float) $rules['k'])
          : ($__mm === (int) $rules['base_mm'] ? 'base' : $rules['base_mm'] . ' mm ' . ($__r['pct'] < 0 ? '− ' : '+ ') . ThicknessPrices::fmt(abs((float) $__r['pct'])) . '%');
      $__label = ThicknessPrices::label($__mm, (int) $rules['cbm_from']); ?>
    <tr data-mm="<?= $__mm ?>"<?= $__isCbm ? ' data-cbm="1"' : ' data-pct="' . View::e(ThicknessPrices::fmt((float) $__r['pct'])) . '"' ?> class="<?= $__typed ? 'grid-typed' : 'grid-calculated' ?>">
      <td><?= View::e($__label) ?></td>
      <td>
        <input type="text" inputmode="decimal" name="price[<?= $__mm ?>]" class="grid-price" data-max-decimals="2" data-max-value="999999999999.99" data-at-least-one="price["
          data-at-least-one-message="Enter at least one price."
          value="<?= $__v !== null ? View::e(number_format((float) $__v['price'], 2, '.', '')) : '' ?>"
          placeholder="<?= $__v !== null ? View::e(number_format((float) $__v['calc'], 2, '.', '')) : ($__isCbm ? 'e.g. 64000.00' : 'e.g. 1600.00') ?>"
          aria-label="Supplier price for <?= View::e($__label) ?> (₹ <?= $__isCbm ? 'per CBM' : 'per SQM' ?>)">
        <input type="hidden" name="how[<?= $__mm ?>]" value="<?= $__typed ? 'typed' : 'calculated' ?>">
      </td>
      <td><?= $__isCbm ? 'per CBM' : 'per SQM' ?></td>
      <td class="grid-how">
        <span class="badge <?= $__typed ? 'badge-typed' : 'badge-calculated' ?>" data-grid-badge><?= $__typed ? 'TYPED' : 'CALCULATED' ?></span>
        <small class="muted" data-grid-rule><?= View::e($__rule) ?></small>
        <small class="muted" data-grid-hint><?php if ($__typed && abs((float) $__v['calc'] - (float) $__v['price']) > 0.005): ?>calculated would be <?= $__money((float) $__v['calc']) ?><?php endif; ?></small>
        <button type="button" class="btn-link" data-grid-use-calc<?= $__typed ? '' : ' hidden' ?>>↺ use calculated</button>
      </td>
      <td class="num muted" data-grid-percbm><?php if ($__v !== null): ?>₹<?= $__money($__isCbm ? (float) $__v['price'] : ThicknessPrices::m2ToCbm((float) $__v['price'], (float) $__mm)) ?><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
</table>
<p class="small muted">Rules for <?= View::e((string) ($rules['category_name'] ?? 'this category')) ?><?= $rules['own'] !== [] ? ' <span class="badge badge-warning">own rule: ' . View::e(implode(', ', $rules['own'])) . '</span>' : '' ?>: <?= View::e(\App\Services\PriceRules::summary($rules)) ?>.
  Works in every direction: if only the <?= (int) $rules['cbm_from'] ?> mm+ price is typed, <?= (int) $rules['base_mm'] ?> mm = that ÷ <?= View::e(ThicknessPrices::fmt((float) $rules['k'])) ?>.
  A box saved at 0 or empty is calculated again; when a TYPED price changes, every CALCULATED one follows. Every change is kept in the price history.
  <?php if (!empty($canChangeRules) && $rules['category_id'] !== null): ?><a class="btn-sm" href="/products/categories/<?= (int) $rules['category_id'] ?>?tab=rules">Change these rules</a><?php endif; ?></p>
