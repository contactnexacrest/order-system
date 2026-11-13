<?php
use App\Helpers\Csrf;
use App\Helpers\View;
/** @var array<string,mixed>|null $product Shared by create.php and edit.php. */
$isEdit = $product !== null;
$action = $isEdit ? '/products/' . (int) $product['id'] . '/update' : '/products/create';
?>
<form method="post" action="<?= $action ?>">
  <?= Csrf::field() ?>
  <div class="field-grid">
    <label>Name *<input type="text" name="name" value="<?= View::e($product['name'] ?? '') ?>" placeholder="e.g., Granite Tiles (Polished)" required></label>
    <label class="full">Specifications *<textarea name="specifications" rows="4" placeholder="e.g., Material, size, finish, and packing details" required><?= View::e($product['specifications'] ?? '') ?></textarea></label>
    <?php // D-302 (point 11): picked from the HS Code Master List, pre-filled with the category's default. ?>
    <label>HS Code * <small class="muted">(from the HS Code Master List)</small><input type="text" name="hs_code" list="product_hs_codes" autocomplete="off" maxlength="8"
      data-in-list="product_hs_codes" data-in-list-message="Pick a code from the HS Code Master List<?= !empty($canManageHs) ? ' — or add it with &quot;+ Add a new HS code&quot;' : '' ?>."
      value="<?= View::e($product['hs_code'] ?? ($categoryDefaultHs ?? '')) ?>" placeholder="e.g. 68022310 — type the code or words" required>
      <?php if (!$isEdit && ($categoryDefaultHs ?? '') !== ''): ?><small class="muted">Pre-filled with the default of <?= View::e((string) $categoryName) ?>.</small><?php endif; ?></label>
    <datalist id="product_hs_codes"><?php foreach ($hsCodes ?? [] as $__h): ?><option value="<?= View::e((string) $__h['code']) ?>"><?= View::e((string) $__h['description']) ?><?= (string) $__h['code'] === (string) ($categoryDefaultHs ?? '') ? ' · category default' : '' ?></option><?php endforeach; ?></datalist>
    <label>Origin<input type="text" name="origin" value="<?= View::e($product['origin'] ?? '') ?>" placeholder="e.g., Rajasthan, India"></label>
  </div>

  <?php // Owner decision D-335 (12 Nov 2026): "Costs to FOB" — the only place for CHA + transport; packing + loading only when a supplier price does not include them.
  $__pr = $productRules ?? null;
  $__chaV = ($product['cha_transport_per_container'] ?? null) !== null ? (float) $product['cha_transport_per_container'] : null;
  $__fit = $__pr !== null ? \App\Services\ThicknessPrices::chaPerCbm($__chaV, (float) $__pr['container_cbm'], $__pr['container_max_t'], $__pr['t_per_cbm']) : null;
  $__packV = ($product['default_packing_cost'] ?? null) !== null || ($product['default_loading_cost'] ?? null) !== null
      ? number_format((float) ($product['default_packing_cost'] ?? 0) + (float) ($product['default_loading_cost'] ?? 0), 2, '.', '') : ''; ?>
  <fieldset id="costs-to-fob">
    <legend>Costs to FOB <small class="muted">(added to every supplier price of this product — the only place for CHA and transport)</small></legend>
    <div class="field-grid">
      <label>CHA + transport per container (₹)<input type="text" inputmode="decimal" name="cha_transport_per_container" data-max-decimals="2" data-max-value="9999999999.99" value="<?= View::e(($product['cha_transport_per_container'] ?? null) !== null ? (string) $product['cha_transport_per_container'] : '') ?>" placeholder="e.g. 80000.00">
        <?php if ($__fit !== null): ?><small class="muted">÷ <?= View::e(\App\Services\ThicknessPrices::fmt(round((float) $__fit['fits_cbm'], 2))) ?> CBM per container<?= $__fit['per_cbm'] !== null ? ' = ₹' . number_format((float) $__fit['per_cbm'], 2) . ' per CBM' : '' ?><?= $__fit['by_weight'] ? ' (the weight limit is reached first)' : '' ?></small><?php endif; ?></label>
      <label>Packing + loading per unit (₹)<input type="text" inputmode="decimal" name="packing_loading_cost" data-max-decimals="2" data-max-value="9999999999.99" value="<?= View::e($__packV) ?>" placeholder="e.g. 1500.00">
        <small class="muted">Added only when a supplier price does NOT include packing and loading.</small></label>
    </div>
  </fieldset>

  <?php // D-335: the price rules in effect for this product — the category's, or its own (MD only). ?>
  <fieldset id="price-rules">
    <legend>Price rules in effect for this product</legend>
    <p class="small muted">Used only as a <strong>calculator</strong> — when you tick a thickness to work it out while adding a price. They never change a price you typed or the base price.</p>
    <?php if ($__pr !== null): $__own = $__pr['own']; $__tag = static fn (bool $own): string => $own ? '<span class="badge badge-warning">own rule</span>' : '<span class="badge">category</span>'; ?>
      <table class="list">
        <tr><th>Rule</th><th>Value</th><th>From</th></tr>
        <?php foreach ($__pr['rows'] as $__r): if ((int) $__r['mm'] === (int) $__pr['base_mm']) { continue; } ?>
          <tr><td><?= (int) $__r['mm'] ?> mm</td><td><?= (int) $__pr['base_mm'] ?> mm <?= $__r['pct'] < 0 ? '−' : '+' ?> <?= View::e(\App\Services\ThicknessPrices::fmt(abs((float) $__r['pct']))) ?> %</td><td><?= $__tag(in_array($__r['option'], $__own, true)) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($__pr['k'] !== null): ?><tr><td><?= (int) $__pr['cbm_from'] ?> mm and above, per CBM</td><td><?= (int) $__pr['base_mm'] ?> mm price per m² × <?= View::e(\App\Services\ThicknessPrices::fmt((float) $__pr['k'])) ?></td><td><?= $__tag(($product['thick_k_factor'] ?? null) !== null) ?></td></tr><?php endif; ?>
        <tr><td>Container</td><td><?= View::e(\App\Services\ThicknessPrices::fmt((float) $__pr['container_cbm'])) ?> CBM · max <?= View::e(\App\Services\ThicknessPrices::fmt((float) $__pr['container_max_t'])) ?> t</td><td><?= $__tag(($product['container_cbm'] ?? null) !== null || ($product['container_max_t'] ?? null) !== null) ?></td></tr>
      </table>
    <?php endif; ?>
    <?php $__cr = $categoryRules ?? []; $__hasOwn = ($product['thick_k_factor'] ?? null) !== null || ($product['container_cbm'] ?? null) !== null || ($product['container_max_t'] ?? null) !== null; ?>
    <?php if (!empty($canManagePricing)): ?>
      <input type="hidden" name="own_rules_shown" value="1">
      <label class="inline switch"><input type="checkbox" name="own_rules" value="1"<?= $__hasOwn ? ' checked' : '' ?>> Use own rules for this product <small class="muted">(off = the category's rules<?= ($categoryName ?? '') !== '' ? ', ' . View::e((string) $categoryName) : '' ?> · MD only)</small></label>
      <div class="field-grid" data-show-when="own_rules=1">
        <label><?= (int) ($__pr['cbm_from'] ?? 50) ?> mm and above, when worked out from <?= (int) ($__pr['base_mm'] ?? 20) ?> mm: <?= (int) ($__pr['base_mm'] ?? 20) ?> mm price per m² ×<input type="text" inputmode="decimal" name="thick_k_factor" data-max-decimals="3" data-max-value="1000" value="<?= View::e(($product['thick_k_factor'] ?? null) !== null ? rtrim(rtrim((string) $product['thick_k_factor'], '0'), '.') : '') ?>" placeholder="category <?= View::e(($__cr['thick_k_factor'] ?? null) !== null ? rtrim(rtrim((string) $__cr['thick_k_factor'], '0'), '.') : '—') ?>"></label>
        <label>Container holds (CBM)<input type="text" inputmode="decimal" name="container_cbm" data-max-decimals="2" data-max-value="100" value="<?= View::e(($product['container_cbm'] ?? null) !== null ? rtrim(rtrim((string) $product['container_cbm'], '0'), '.') : '') ?>" placeholder="category <?= View::e(($__cr['container_cbm'] ?? null) !== null ? rtrim(rtrim((string) $__cr['container_cbm'], '0'), '.') : '8') ?>"></label>
        <label>Container maximum (tonnes)<input type="text" inputmode="decimal" name="container_max_t" data-max-decimals="2" data-max-value="100" value="<?= View::e(($product['container_max_t'] ?? null) !== null ? rtrim(rtrim((string) $product['container_max_t'], '0'), '.') : '') ?>" placeholder="category <?= View::e(($__cr['container_max_t'] ?? null) !== null ? rtrim(rtrim((string) $__cr['container_max_t'], '0'), '.') : '27') ?>"></label>
      </div>
    <?php else: ?>
      <p class="small muted"><?= $__hasOwn ? 'This product uses its own rules (marked "own rule").' : 'This product uses the category\'s rules.' ?> Only the MD can switch on own rules for a product.</p>
    <?php endif; ?>
    <p class="small muted">Switch on to edit the rules for this product only; the thickness % are changed on the Category price rules (or this product's Price options). The Category price rules keep the standard for every product in the category.</p>
  </fieldset>

  <?php // Owner decision D-245 P2: the product's selling unit (filled into an order line). Prices come from Products → pricing. ?>
  <fieldset>
    <legend>Selling Unit <small class="muted">(filled into an order line when this product is picked; the price comes from the product's price build-up)</small></legend>
    <div class="field-grid">
      <label>Per (unit)
        <select name="reference_unit">
          <option value="">—</option>
          <?php foreach ($referenceUnits ?? [] as $__u): ?>
            <option value="<?= View::e($__u['option_value']) ?>" <?= ($product['reference_unit'] ?? '') === $__u['option_value'] ? 'selected' : '' ?>><?= View::e($__u['option_value']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
  </fieldset>

  <label><input type="checkbox" name="is_active" value="1" <?= (!$isEdit || !empty($product['is_active'])) ? 'checked' : '' ?>> Active</label>

  <button type="submit"><?= $isEdit ? 'Save Changes' : 'Add Product' ?></button>
</form>
<?php if (!empty($canManageHs)): // D-302: "+ Add a new HS code" — straight into the master list, back to this form. ?>
<details class="quick-add">
  <summary class="btn-sm">+ Add a new HS code to the master list…</summary>
  <form method="post" action="/hs-codes" class="field-grid card-nested">
    <?= Csrf::field() ?>
    <input type="hidden" name="return_to" value="<?= $isEdit ? '/products/' . (int) $product['id'] . '/edit' : '/products/create' ?>">
    <label>HS code *<input type="text" name="code" required pattern="\d{6}|\d{8}" maxlength="8" data-pattern-message="6 or 8 digits, no dots." placeholder="e.g. 68029300"></label>
    <label>Description *<input type="text" name="description" required maxlength="255" placeholder="e.g. Granite, other (polished, worked)"></label>
    <div><button type="submit" class="btn-sm btn-accent">Add HS code</button></div>
  </form>
</details>
<?php endif; ?>
