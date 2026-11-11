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

  <fieldset>
    <legend>Default Cost Components <small class="muted">(catalog-wide fallbacks used by computed-FOB suppliers)</small></legend>
    <div class="field-grid">
      <label>Default Factory Cost<input type="number" step="0.01" min="0" name="default_factory_cost" value="<?= View::e(($product['default_factory_cost'] ?? '') !== '' ? (string) $product['default_factory_cost'] : '') ?>" placeholder="e.g., 450.00"></label>
      <label>Default Transportation Cost<input type="number" step="0.01" min="0" name="default_transportation_cost" value="<?= View::e(($product['default_transportation_cost'] ?? '') !== '' ? (string) $product['default_transportation_cost'] : '') ?>" placeholder="e.g., 60.00"></label>
      <label>Default Packing Cost<input type="number" step="0.01" min="0" name="default_packing_cost" value="<?= View::e(($product['default_packing_cost'] ?? '') !== '' ? (string) $product['default_packing_cost'] : '') ?>" placeholder="e.g., 25.00"></label>
      <label>Default Loading Cost<input type="number" step="0.01" min="0" name="default_loading_cost" value="<?= View::e(($product['default_loading_cost'] ?? '') !== '' ? (string) $product['default_loading_cost'] : '') ?>" placeholder="e.g., 15.00"></label>
      <label>Default CHA Cost<input type="number" step="0.01" min="0" name="default_cha_cost" value="<?= View::e(($product['default_cha_cost'] ?? '') !== '' ? (string) $product['default_cha_cost'] : '') ?>" placeholder="e.g., 20.00"></label>
    </div>
  </fieldset>

  <?php // Owner decision D-300 (point 12): CHA + transport per container (default for this product's supplier prices) and the product's own price rules. ?>
  <fieldset>
    <legend>CHA + transport and price rules <small class="muted">(empty = the category's rules<?= ($categoryName ?? '') !== '' ? ' — ' . View::e((string) $categoryName) : '' ?>)</small></legend>
    <div class="field-grid">
      <label>CHA + transport per container (₹) <small class="muted">(default for this product's supplier prices)</small><input type="text" inputmode="decimal" name="cha_transport_per_container" data-max-decimals="2" data-max-value="9999999999.99" value="<?= View::e(($product['cha_transport_per_container'] ?? null) !== null ? (string) $product['cha_transport_per_container'] : '') ?>" placeholder="e.g. 60000.00"></label>
      <?php $__cr = $categoryRules ?? []; $__rro = !empty($canManagePricing) ? '' : ' disabled'; ?>
      <label>50 mm and above: 20 mm price per m² × <small class="muted">(own rule; MD only)</small><input type="text" inputmode="decimal" name="thick_k_factor" data-max-decimals="3" data-max-value="1000" value="<?= View::e(($product['thick_k_factor'] ?? null) !== null ? rtrim(rtrim((string) $product['thick_k_factor'], '0'), '.') : '') ?>" placeholder="category <?= View::e(($__cr['thick_k_factor'] ?? null) !== null ? rtrim(rtrim((string) $__cr['thick_k_factor'], '0'), '.') : '—') ?>"<?= $__rro ?>></label>
      <label>Container holds (CBM) <small class="muted">(own rule; MD only)</small><input type="text" inputmode="decimal" name="container_cbm" data-max-decimals="2" data-max-value="100" value="<?= View::e(($product['container_cbm'] ?? null) !== null ? rtrim(rtrim((string) $product['container_cbm'], '0'), '.') : '') ?>" placeholder="category <?= View::e(($__cr['container_cbm'] ?? null) !== null ? rtrim(rtrim((string) $__cr['container_cbm'], '0'), '.') : '8') ?>"<?= $__rro ?>></label>
      <label>Container maximum (tonnes) <small class="muted">(own rule; MD only)</small><input type="text" inputmode="decimal" name="container_max_t" data-max-decimals="2" data-max-value="100" value="<?= View::e(($product['container_max_t'] ?? null) !== null ? rtrim(rtrim((string) $product['container_max_t'], '0'), '.') : '') ?>" placeholder="category <?= View::e(($__cr['container_max_t'] ?? null) !== null ? rtrim(rtrim((string) $__cr['container_max_t'], '0'), '.') : '27') ?>"<?= $__rro ?>></label>
    </div>
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
