<?php
/**
 * Owner decision D-361 (13 Nov 2026; decision 1, mockup decision_d1.png part A): "Link a supplier"
 * for a product from before migration 13b that has no supplier yet — the supplier (or "+ new
 * supplier"), their price, per (unit) or for (thickness), quoted on, valid until. The product's
 * configured base price stays as it is (owner decision 1). Saved with SupplierOffers::addOffer,
 * the same as the supplier page's "+ Add product". Node twin: views/products/link_supplier.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;

$__id = (int) $product['id'];
$__picked = (int) ($old['supplier_id'] ?? $pickedId);
?>
<div class="card">
  <p class="muted small"><a href="/products?status=no_supplier_yet">← Products</a></p>
  <h1>Link a supplier — <?= View::e((string) $product['name']) ?></h1>
  <p><span class="badge badge-danger">No supplier yet</span> <span class="muted small">This product existed before suppliers were linked. It stays active and quotable until a supplier is linked; then the normal rule applies.</span></p>
  <form method="post" action="/products/<?= $__id ?>/link-supplier" class="field-grid">
    <?= Csrf::field() ?>
    <label>Supplier *
      <select name="supplier_id" required data-required-message="Choose the supplier — or add a new one below.">
        <option value="">Choose…</option>
        <?php foreach ($pickList as $__sp): ?><option value="<?= (int) $__sp['id'] ?>"<?= $__picked === (int) $__sp['id'] ? ' selected' : '' ?>><?= View::e($__sp['name']) ?><?= $__sp['locations'] !== [] ? ' — ' . View::e($__sp['locations'][0]) : '' ?></option><?php endforeach; ?>
      </select></label>
    <label>Their price (₹) *
      <input type="text" name="price" inputmode="decimal" required pattern="[0-9,]{1,15}(\.[0-9]{1,2})?" data-pattern-message="The price in ₹, e.g. 158000 or 158000.50." value="<?= View::e((string) ($old['price'] ?? '')) ?>" placeholder="e.g. 158000"></label>
    <?php if ($shape['grid']): ?>
      <label>For *
        <select name="mm" required data-required-message="Choose the thickness the price is for.">
          <option value="">Choose…</option>
          <?php foreach ($shape['thicknesses'] as $__t): ?><option value="<?= (int) $__t['mm'] ?>"<?= (string) ($old['mm'] ?? (string) $shape['base_mm']) === (string) $__t['mm'] ? ' selected' : '' ?>><?= View::e($__t['label']) ?> (per <?= $__t['unit'] === 'CBM' ? 'CBM' : 'm²' ?>)</option><?php endforeach; ?>
        </select></label>
    <?php else: ?>
      <label>Per *
        <select name="unit" required data-required-message="Choose what the price is per.">
          <option value="">Choose…</option>
          <?php foreach ($shape['units'] as $__u): ?><option value="<?= View::e($__u['value']) ?>"<?= (string) ($old['unit'] ?? $shape['units'][0]['value']) === $__u['value'] ? ' selected' : '' ?>><?= View::e($__u['label']) ?></option><?php endforeach; ?>
        </select></label>
    <?php endif; ?>
    <label>Quoted on *<input type="date" name="quoted_on" required max="<?= View::e($today) ?>" value="<?= View::e((string) ($old['quoted_on'] ?? $today)) ?>"></label>
    <label>Valid until<input type="date" name="valid_until" value="<?= View::e((string) ($old['valid_until'] ?? '')) ?>" data-not-before="[name=quoted_on]" data-not-before-message="Valid until cannot be before the quoted date."></label>
    <p class="muted small full"><?= $configured ? 'The product\'s configured base price stays ' . View::e((string) $baseText) . '. If this supplier quotes a different price, the product shows "Supplier prices changed — Use ₹…" and you decide.' : 'The product has no base price yet — Configure it after linking to set its selling price.' ?></p>
    <div class="full"><button type="submit">Save</button> <a class="btn-sm btn-secondary" href="/products?status=no_supplier_yet">Cancel</a></div>
  </form>
  <?php $__quickProductId = 0; ?>
  <details class="quick-add" id="quick-add-supplier">
    <summary class="btn-sm">+ new supplier…</summary>
    <form method="post" action="/suppliers/quick-add" class="field-grid card-nested">
      <?= Csrf::field() ?>
      <input type="hidden" name="product_id" value="0">
      <input type="hidden" name="return_to" value="/products/<?= $__id ?>/link-supplier">
      <label>Supplier name *<input type="text" name="quick_name" required minlength="2" maxlength="255" placeholder="e.g. Balaji Stone Exports"></label>
      <label>Location *<input type="text" name="quick_location" required minlength="2" maxlength="120" placeholder="e.g. Kota, Rajasthan"></label>
      <label>Phone *<input type="tel" name="quick_phone" required pattern="\+?[0-9][0-9 \-]{6,18}" data-pattern-message="Digits, spaces or dashes, with the country code, e.g. +91-0000000000." placeholder="e.g. +91-0000000000"></label>
      <label>Supplier type
        <select name="quick_type">
          <option value="">Choose…</option>
          <?php foreach ($supplierTypes as $__t): ?><option value="<?= View::e($__t) ?>"><?= View::e($__t) ?></option><?php endforeach; ?>
        </select></label>
      <p class="small muted full">The supplier is added to the Suppliers directory marked <b>Details pending</b> and picked above.</p>
      <div><button type="submit" class="btn-sm btn-accent">Add supplier</button></div>
    </form>
  </details>
</div>
