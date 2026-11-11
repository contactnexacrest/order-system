<?php
/**
 * Owner decision D-303 (point 15): "+ Add a new supplier (quick)" — name, location, phone and
 * type. Creates the supplier in the Suppliers directory with status "Details pending"; GSTIN,
 * bank and contacts are completed later (details link or Procurement). Never typed twice.
 *
 * In: $supplierTypes, $__quickProductId (0 = back to Link suppliers).
 * Node twin: views/products/_supplier_quick_add.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
?>
<details class="quick-add" id="quick-add-supplier">
  <summary class="btn-sm">+ Add a new supplier (quick)…</summary>
  <form method="post" action="/suppliers/quick-add" class="field-grid card-nested">
    <?= Csrf::field() ?>
    <input type="hidden" name="product_id" value="<?= (int) ($__quickProductId ?? 0) ?>">
    <label>Supplier name *<input type="text" name="quick_name" required minlength="2" maxlength="255" placeholder="e.g. Balaji Stone Exports"></label>
    <label>Location *<input type="text" name="quick_location" required minlength="2" maxlength="120" placeholder="e.g. Kota, Rajasthan"></label>
    <label>Phone *<input type="tel" name="quick_phone" required pattern="\+?[0-9][0-9 \-]{6,18}" data-pattern-message="Digits, spaces or dashes, with the country code, e.g. +91-0000000000." placeholder="e.g. +91-0000000000"></label>
    <label>Supplier type
      <select name="quick_type">
        <option value="">Choose…</option>
        <?php foreach ($supplierTypes as $__t): ?><option value="<?= View::e($__t) ?>"><?= View::e($__t) ?></option><?php endforeach; ?>
      </select></label>
    <p class="small muted full">The supplier is added to the Suppliers directory marked <b>Details pending</b>; GSTIN, bank and contacts are completed later by the supplier's details link or by Procurement.</p>
    <div><button type="submit" class="btn-sm btn-accent">Add supplier</button></div>
  </form>
</details>
