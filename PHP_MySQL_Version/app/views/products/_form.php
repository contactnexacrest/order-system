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
    <label>HS Code * <small class="muted">(mandatory)</small><input type="text" name="hs_code" value="<?= View::e($product['hs_code'] ?? '') ?>" placeholder="e.g., 68022310" required></label>
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

  <?php // Owner decision 2 Nov 2026 (D-240): reference SELLING price — only for people who may see selling prices. ?>
  <?php if (!empty($sees['selling'])): ?>
  <fieldset>
    <legend>Reference Selling Price <small class="muted">(a starting point for order lines — each order's own price can differ; never printed on a document)</small></legend>
    <div class="field-grid">
      <label>Reference Price<input type="number" step="0.01" min="0.01" max="999999999999.99" name="reference_price" value="<?= View::e(($product['reference_price'] ?? '') !== null ? (string) ($product['reference_price'] ?? '') : '') ?>" placeholder="e.g., 45.00"></label>
      <label>Currency
        <select name="reference_currency">
          <option value="">Choose…</option>
          <?php foreach ($referenceCurrencies ?? [] as $__c): ?>
            <option value="<?= View::e($__c) ?>" <?= ($product['reference_currency'] ?? '') === $__c ? 'selected' : '' ?>><?= View::e($__c) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Per (unit)
        <select name="reference_unit">
          <option value="">—</option>
          <?php foreach ($referenceUnits ?? [] as $__u): ?>
            <option value="<?= View::e($__u['option_value']) ?>" <?= ($product['reference_unit'] ?? '') === $__u['option_value'] ? 'selected' : '' ?>><?= View::e($__u['option_value']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <p class="muted small">Only INR reference prices go to Zoho Books as the item's rate; any other currency is written into the Zoho item's description.</p>
  </fieldset>
  <?php endif; ?>

  <label><input type="checkbox" name="is_active" value="1" <?= (!$isEdit || !empty($product['is_active'])) ? 'checked' : '' ?>> Active</label>

  <button type="submit"><?= $isEdit ? 'Save Changes' : 'Add Product' ?></button>
</form>
