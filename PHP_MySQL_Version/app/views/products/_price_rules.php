<?php
/**
 * Owner decision D-300 (point 12, 11 Nov 2026): every rule used for working out a
 * supplier's prices, on one page per category — the thickness %s, × K for 50 mm and
 * above per CBM, "+ Add a thickness", what one container holds and the stone weight,
 * with a live example column (app.js initRulesExample). MD only, with a reason; every
 * change is in the Audit Log. A product can have its own values ("own rule" badge).
 *
 * In: $rules (PriceRules::forCategory), $canManagePricing, $categories, $__id.
 * Node twin: views/products/_price_rules.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
use App\Services\ThicknessPrices;

$__r = $rules;
$__ro = $canManagePricing ? '' : ' disabled';
$__old = \App\Helpers\Flash::pullOld();
$__ex = 1600.0;
$__k = $__r['k'];
$__fmt = static fn (?float $v): string => $v === null ? '' : ThicknessPrices::fmt($v);
?>
<form method="get" action="/products/price-rules" class="field-grid">
  <label>Category
    <select name="category" onchange="this.form.submit()">
      <?php foreach ($categories as $__cat): ?><option value="<?= (int) $__cat['id'] ?>"<?= (int) $__cat['id'] === $__id ? ' selected' : '' ?>><?= View::e((string) $__cat['name']) ?></option><?php endforeach; ?>
    </select></label>
  <noscript><button type="submit" class="btn-sm">Open</button></noscript>
</form>

<form method="post" action="/products/categories/<?= $__id ?>/price-rules" data-rules-example data-example="<?= View::e(ThicknessPrices::fmt($__ex)) ?>">
  <?= Csrf::field() ?>
  <h2>Thickness</h2>
  <?php if ($__r['spec'] === null): ?><p class="muted">This category has no Thickness specification — add one on the "Options and their price effect" tab first.</p><?php endif; ?>
  <table class="list">
    <tr><th>Thickness</th><th>Price compared with <?= (int) $__r['base_mm'] ?> mm</th><th class="num">Example (<?= (int) $__r['base_mm'] ?> mm = ₹<?= number_format($__ex, 0) ?> / m²)</th></tr>
    <?php foreach ($__r['thickness'] as $__t): ?>
      <tr data-rule-row data-mm="<?= (int) $__t['mm'] ?>">
        <td><?= View::e($__t['name']) ?></td>
        <td><?php if ($__t['base']): ?>base<?php else: ?>+ <input type="text" inputmode="decimal" name="pct[<?= (int) $__t['id'] ?>]" data-rule-pct data-max-decimals="2" data-max-value="500" value="<?= View::e((string) ($__old['pct'][$__t['id']] ?? $__fmt($__t['pct']))) ?>" placeholder="e.g. 35" aria-label="<?= View::e($__t['name']) ?>: % compared with <?= (int) $__r['base_mm'] ?> mm" style="width:6em"<?= $__ro ?>> %<?php endif; ?></td>
        <td class="num" data-rule-example>₹<?= number_format($__ex * (1 + $__t['pct'] / 100), 0) ?> / m²</td>
      </tr>
    <?php endforeach; ?>
    <tr data-rule-cbm>
      <td><?= (int) $__r['cbm_from'] ?> mm and above (per CBM)</td>
      <td><?= (int) $__r['base_mm'] ?> mm price per m² × <input type="text" inputmode="decimal" name="thick_k_factor" data-rule-k data-max-decimals="3" data-max-value="1000" value="<?= View::e((string) ($__old['thick_k_factor'] ?? $__fmt($__k))) ?>" placeholder="e.g. 40" aria-label="<?= (int) $__r['cbm_from'] ?> mm and above: <?= (int) $__r['base_mm'] ?> mm price per m² times" style="width:6em"<?= $__ro ?>></td>
      <td class="num" data-rule-example><?= $__k !== null ? '₹' . number_format($__ex * $__k, 0) . ' / CBM' : '—' ?></td>
    </tr>
  </table>
  <?php if ($canManagePricing && $__r['spec'] !== null): ?>
  <details class="add-thickness"><summary class="btn-sm">+ Add a thickness</summary>
    <div class="field-grid">
      <label>Thickness (mm)<input type="number" name="new_mm" min="1" max="<?= (int) $__r['cbm_from'] - 1 ?>" step="1" value="<?= View::e((string) ($__old['new_mm'] ?? '')) ?>" placeholder="e.g. 18"></label>
      <label>% compared with <?= (int) $__r['base_mm'] ?> mm<input type="text" inputmode="decimal" name="new_change" data-max-decimals="2" data-required-if="new_mm" value="<?= View::e((string) ($__old['new_change'] ?? '')) ?>" placeholder="e.g. -5"></label>
    </div>
  </details>
  <?php endif; ?>

  <h2>Container and weight</h2>
  <div class="field-grid">
    <label>One container holds (CBM)<input type="text" inputmode="decimal" name="container_cbm" data-max-decimals="2" data-max-value="100" value="<?= View::e((string) ($__old['container_cbm'] ?? $__fmt($__r['container_cbm']))) ?>" placeholder="e.g. 8"<?= $__ro ?>></label>
    <label>Maximum (tonnes)<input type="text" inputmode="decimal" name="container_max_t" data-max-decimals="2" data-max-value="100" value="<?= View::e((string) ($__old['container_max_t'] ?? $__fmt($__r['container_max_t']))) ?>" placeholder="e.g. 27"<?= $__ro ?>></label>
    <label>Stone weight (tonnes per CBM) <small class="muted">(used for Ton prices and the weight limit)</small><input type="text" inputmode="decimal" name="weight_t_per_cbm" data-max-decimals="3" data-max-value="20" value="<?= View::e((string) ($__old['weight_t_per_cbm'] ?? $__fmt($__r['weight']))) ?>" placeholder="e.g. 2.7"<?= $__ro ?>></label>
  </div>
  <p class="small muted">The example column updates as you type. Changes apply to prices worked out from now on; quotes already sent keep their prices. A product can have its own values — shown on that product with a yellow "own rule" badge.</p>
  <?php if ($canManagePricing): ?>
    <label>Reason for the change *<textarea name="reason" rows="2" required maxlength="500" placeholder="e.g. Supplier rates for 30 mm went up in November"><?= View::e((string) ($__old['reason'] ?? '')) ?></textarea></label>
    <button type="submit">Save rules</button>
  <?php else: ?>
    <p class="muted small">Only the MD can change these rules.</p>
  <?php endif; ?>
</form>
