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
      <label>Default Factory Cost<input type="number" step="0.01" name="default_factory_cost" value="<?= View::e($product['default_factory_cost'] !== null && $product['default_factory_cost'] !== '' ? (string) $product['default_factory_cost'] : '') ?>" placeholder="e.g., 450.00"></label>
      <label>Default Transportation Cost<input type="number" step="0.01" name="default_transportation_cost" value="<?= View::e($product['default_transportation_cost'] !== null && $product['default_transportation_cost'] !== '' ? (string) $product['default_transportation_cost'] : '') ?>" placeholder="e.g., 60.00"></label>
      <label>Default Packing Cost<input type="number" step="0.01" name="default_packing_cost" value="<?= View::e($product['default_packing_cost'] !== null && $product['default_packing_cost'] !== '' ? (string) $product['default_packing_cost'] : '') ?>" placeholder="e.g., 25.00"></label>
      <label>Default Loading Cost<input type="number" step="0.01" name="default_loading_cost" value="<?= View::e($product['default_loading_cost'] !== null && $product['default_loading_cost'] !== '' ? (string) $product['default_loading_cost'] : '') ?>" placeholder="e.g., 15.00"></label>
      <label>Default CHA Cost<input type="number" step="0.01" name="default_cha_cost" value="<?= View::e($product['default_cha_cost'] !== null && $product['default_cha_cost'] !== '' ? (string) $product['default_cha_cost'] : '') ?>" placeholder="e.g., 20.00"></label>
    </div>
  </fieldset>

  <label><input type="checkbox" name="is_active" value="1" <?= (!$isEdit || !empty($product['is_active'])) ? 'checked' : '' ?>> Active</label>

  <button type="submit"><?= $isEdit ? 'Save Changes' : 'Add Product' ?></button>
</form>
