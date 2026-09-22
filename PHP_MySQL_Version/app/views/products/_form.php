<?php
use App\Helpers\Csrf;
use App\Helpers\View;
/** @var array<string,mixed>|null $product Shared by create.php and edit.php. */
$isEdit = $product !== null;
$action = $isEdit ? '/products/' . (int) $product['id'] . '/update' : '/products/create';
?>
<form method="post" action="<?= $action ?>">
  <?= Csrf::field() ?>
  <label>Name *<input type="text" name="name" value="<?= View::e($product['name'] ?? '') ?>" required></label>
  <label>Specifications *<textarea name="specifications" rows="4" required><?= View::e($product['specifications'] ?? '') ?></textarea></label>
  <label>HS Code * <small class="muted">(mandatory)</small><input type="text" name="hs_code" value="<?= View::e($product['hs_code'] ?? '') ?>" required></label>
  <label>Origin<input type="text" name="origin" value="<?= View::e($product['origin'] ?? '') ?>"></label>

  <fieldset>
    <legend>Default Cost Components <small class="muted">(catalog-wide fallbacks used by computed-FOB suppliers)</small></legend>
    <label>Default Factory Cost<input type="number" step="0.01" name="default_factory_cost" value="<?= View::e($product['default_factory_cost'] !== null && $product['default_factory_cost'] !== '' ? (string) $product['default_factory_cost'] : '') ?>"></label>
    <label>Default Transportation Cost<input type="number" step="0.01" name="default_transportation_cost" value="<?= View::e($product['default_transportation_cost'] !== null && $product['default_transportation_cost'] !== '' ? (string) $product['default_transportation_cost'] : '') ?>"></label>
    <label>Default Packing Cost<input type="number" step="0.01" name="default_packing_cost" value="<?= View::e($product['default_packing_cost'] !== null && $product['default_packing_cost'] !== '' ? (string) $product['default_packing_cost'] : '') ?>"></label>
    <label>Default Loading Cost<input type="number" step="0.01" name="default_loading_cost" value="<?= View::e($product['default_loading_cost'] !== null && $product['default_loading_cost'] !== '' ? (string) $product['default_loading_cost'] : '') ?>"></label>
    <label>Default CHA Cost<input type="number" step="0.01" name="default_cha_cost" value="<?= View::e($product['default_cha_cost'] !== null && $product['default_cha_cost'] !== '' ? (string) $product['default_cha_cost'] : '') ?>"></label>
  </fieldset>

  <label><input type="checkbox" name="is_active" value="1" <?= (!$isEdit || !empty($product['is_active'])) ? 'checked' : '' ?>> Active</label>

  <button type="submit"><?= $isEdit ? 'Save Changes' : 'Add Product' ?></button>
</form>
