<?php
/**
 * Owner decision D-303 (point 15, 11 Nov 2026): the supplier of a product price is picked
 * from the Suppliers directory (search as you type); the location is filled from the
 * supplier's factory / quarry addresses (app.js initSupplierPick). A name that is not in
 * the directory is refused beside the field (data-in-list).
 *
 * In: $pickList (SupplierRepository::pickList), $__pickName, $__pickId, $__pickLocation.
 * Node twin: views/products/_supplier_pick.njk.
 */
use App\Helpers\View;
?>
<label>Supplier * <small class="muted">(from the Suppliers directory)</small>
  <input type="text" name="supplier_name" list="supplier-pick-list" required autocomplete="off" maxlength="255"
    data-pick-id="supplier_id" data-pick-locations="location" data-in-list="supplier-pick-list"
    data-in-list-message="Pick the supplier from the Suppliers directory — or add it with &quot;+ Add a new supplier (quick)&quot;."
    value="<?= View::e((string) ($__pickName ?? '')) ?>" placeholder="Type the name, e.g. Sri Balaji Granites">
  <input type="hidden" name="supplier_id" value="<?= (int) ($__pickId ?? 0) ?: '' ?>">
</label>
<label>Location <small class="muted">(from the supplier's factory / quarry addresses — choose if several)</small>
  <input type="text" name="location" list="supplier-location-list" maxlength="150" value="<?= View::e((string) ($__pickLocation ?? '')) ?>" placeholder="e.g. Ongole, Andhra Pradesh">
</label>
<datalist id="supplier-pick-list">
  <?php foreach ($pickList as $__sp): ?>
    <option value="<?= View::e($__sp['name']) ?>" data-id="<?= (int) $__sp['id'] ?>" data-locations="<?= View::e(implode('|', $__sp['locations'])) ?>"><?= View::e(implode(' · ', array_filter([implode(', ', array_slice($__sp['locations'], 0, 1)), $__sp['pending'] ? 'Details pending' : '', $__sp['verified'] ? 'Verified bank' : '']))) ?></option>
  <?php endforeach; ?>
</datalist>
<datalist id="supplier-location-list"></datalist>
