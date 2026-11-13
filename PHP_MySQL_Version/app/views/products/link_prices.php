<?php
/**
 * Owner decision D-361 (13 Nov 2026; decision 3): Products › "Link these prices" — supplier prices
 * typed before the Suppliers directory (D-303) that migration 13b could not link by name. A
 * manager picks the supplier per row (or adds one); the price itself does not change.
 * Node twin: views/products/link_prices.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\View;
?>
<div class="card page-wide">
  <p class="muted small"><a href="/products?status=all">← Products</a></p>
  <h1>Link these prices (<?= count($rows) ?>)</h1>
  <p class="muted">These supplier prices were typed before the Suppliers directory and are not linked to a supplier record yet. Pick the supplier for each — the price does not change. Once a product has a linked supplier, its red "No supplier yet" badge goes.</p>
  <?php if ($rows === []): ?>
    <p class="muted">Every supplier price is linked to a supplier. <a href="/products?status=all">Back to Products</a></p>
  <?php else: ?>
    <table class="list">
      <tr><th>Product</th><th>Supplier as typed</th><th>Price</th><th>Quoted on · valid until</th><th>Link to supplier *</th></tr>
      <?php foreach ($rows as $__r): ?>
        <tr data-unlinked-row="<?= (int) $__r['id'] ?>">
          <td><a href="/products/<?= (int) $__r['product_id'] ?>"><?= View::e($__r['product_name']) ?></a><?= $__r['used_for_pricing'] ? ' <small class="muted">(used for pricing)</small>' : '' ?></td>
          <td><?= View::e($__r['typed_name']) ?></td>
          <td><?= View::e($__r['price']) ?></td>
          <td><?= View::e($__r['dates']) ?></td>
          <td><form method="post" action="/products/link-prices" class="inline-form" style="display:flex;gap:6px;align-items:center">
              <?= Csrf::field() ?>
              <input type="hidden" name="offer_id" value="<?= (int) $__r['id'] ?>">
              <select name="supplier_id" required aria-label="Supplier for <?= View::e($__r['typed_name']) ?>" data-required-message="Choose the supplier.">
                <option value="">Choose…</option>
                <?php foreach ($pickList as $__sp): ?><option value="<?= (int) $__sp['id'] ?>"<?= $pickedId === (int) $__sp['id'] || mb_strtolower(trim($__sp['name'])) === mb_strtolower(trim($__r['typed_name'])) ? ' selected' : '' ?>><?= View::e($__sp['name']) ?></option><?php endforeach; ?>
              </select>
              <button type="submit" class="btn-sm">Link</button>
            </form></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php $__quickProductId = 0; ?>
    <details class="quick-add" id="quick-add-supplier">
      <summary class="btn-sm">+ Add a new supplier (quick)…</summary>
      <form method="post" action="/suppliers/quick-add" class="field-grid card-nested">
        <?= Csrf::field() ?>
        <input type="hidden" name="product_id" value="0">
        <input type="hidden" name="return_to" value="/products/link-prices">
        <label>Supplier name *<input type="text" name="quick_name" required minlength="2" maxlength="255" placeholder="e.g. Balaji Stone Exports"></label>
        <label>Location *<input type="text" name="quick_location" required minlength="2" maxlength="120" placeholder="e.g. Kota, Rajasthan"></label>
        <label>Phone *<input type="tel" name="quick_phone" required pattern="\+?[0-9][0-9 \-]{6,18}" data-pattern-message="Digits, spaces or dashes, with the country code, e.g. +91-0000000000." placeholder="e.g. +91-0000000000"></label>
        <label>Supplier type
          <select name="quick_type">
            <option value="">Choose…</option>
            <?php foreach ($supplierTypes as $__t): ?><option value="<?= View::e($__t) ?>"><?= View::e($__t) ?></option><?php endforeach; ?>
          </select></label>
        <p class="small muted full">The supplier is added to the Suppliers directory marked <b>Details pending</b>; then pick it in the row above.</p>
        <div><button type="submit" class="btn-sm btn-accent">Add supplier</button></div>
      </form>
    </details>
  <?php endif; ?>
</div>
