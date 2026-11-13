<?php
use App\Helpers\Csrf;
use App\Helpers\View;

/**
 * Owner decision D-360 (13 Nov 2026, "go with recommendation", mockups p2 and decision_d1
 * part B): "+ Add product" for one supplier — pick an existing product (those with no
 * supplier yet listed first, marked) or create a new one (name * unique, category *, HS
 * code; it starts Inactive and not configured), then the supplier's price: base thickness *,
 * price ₹ *, per (unit) *, quoted on *, valid until. The thicknesses and units follow the
 * product's (or the category's) price rules — app.js fills them from data-choices.
 * D-364: a price per SQM may be typed per SQFT — shown converted live under the fields
 * ("₹185 / SQFT = ₹1,991.32 / SQM (× 10.7639)") and saved as the SQM price.
 * Used on the Edit supplier page (a panel) and on its own page from the Suppliers list.
 * Expects $addProduct = [supplier, from, old, today, choices]. Node twin: _add_product.njk.
 */
$__ap = $addProduct;
$__s = $__ap['supplier'];
$__o = $__ap['old'];
$__mode = (string) ($__o['product_mode'] ?? 'existing') === 'new' ? 'new' : 'existing';
$__map = ['products' => [], 'categories' => []];
foreach ($__ap['choices']['products'] as $__p) {
    $__map['products'][(string) $__p['id']] = ['shape' => $__p['shape'], 'no_supplier' => $__p['no_supplier'], 'offer_id' => $__p['offer_id']];
}
foreach ($__ap['choices']['categories'] as $__c) {
    $__map['categories'][(string) $__c['id']] = ['shape' => $__c['shape']];
}
$__back = $__ap['from'] === 'supplier' ? '/suppliers/' . (int) $__s['id'] . '/edit#product-prices' : '/suppliers#supplier-' . (int) $__s['id'];
?>
<form method="post" action="/suppliers/<?= (int) $__s['id'] ?>/add-product" class="add-offer-form" data-add-offer
      data-choices="<?= View::e(json_encode($__map, JSON_UNESCAPED_UNICODE)) ?>"
      data-old-thickness="<?= View::e((string) ($__o['thickness_mm'] ?? '')) ?>" data-old-unit="<?= View::e((string) ($__o['unit'] ?? '')) ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="from" value="<?= View::e($__ap['from']) ?>">
  <input type="hidden" name="product_mode" value="<?= $__mode ?>" data-add-offer-mode-field>

  <div class="add-offer-row" data-add-offer-existing<?= $__mode === 'new' ? ' hidden' : '' ?>>
    <label>Product *
      <select name="product_id" required data-add-offer-product data-required-message="Pick the product, or create a new product."<?= $__mode === 'new' ? ' disabled' : '' ?>>
        <option value="">Choose…</option>
        <?php foreach ($__ap['choices']['products'] as $__p): ?>
          <option value="<?= (int) $__p['id'] ?>"<?= (string) ($__o['product_id'] ?? '') === (string) $__p['id'] ? ' selected' : '' ?>><?= View::e($__p['name']) ?><?= $__p['no_supplier'] ? ' — no supplier yet' : '' ?><?= !$__p['active'] ? ' (Inactive)' : '' ?><?= $__p['offer_id'] !== null ? ' (already offered: use Edit price)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <span class="badge badge-danger" data-add-offer-badge hidden>no supplier yet</span>
    <span class="muted small">or <button type="button" class="btn-link" data-add-offer-mode="new">create a new product</button> (name *, category *, HS code). Products with no supplier are listed first.</span>
    <p class="muted small add-offer-note" data-add-offer-dup hidden>This supplier already has a price for this product. Saving opens its "Edit price" form instead.</p>
  </div>

  <fieldset class="add-offer-new" data-add-offer-new<?= $__mode === 'new' ? '' : ' hidden' ?>>
    <legend>New product · <button type="button" class="btn-link" data-add-offer-mode="existing">pick an existing product instead</button></legend>
    <div class="add-offer-row">
      <label>Name *
        <input type="text" name="new_name"<?= $__mode === 'new' ? '' : ' disabled' ?> required maxlength="255" value="<?= View::e((string) ($__o['new_name'] ?? '')) ?>" placeholder="e.g. Kashmir White Granite">
      </label>
      <label>Category *
        <select name="new_category_id"<?= $__mode === 'new' ? '' : ' disabled' ?> required data-add-offer-category>
          <option value="">Choose…</option>
          <?php foreach ($__ap['choices']['categories'] as $__c): ?>
            <option value="<?= (int) $__c['id'] ?>"<?= (string) ($__o['new_category_id'] ?? '') === (string) $__c['id'] ? ' selected' : '' ?>><?= View::e($__c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>HS code
        <input type="text" name="new_hs_code"<?= $__mode === 'new' ? '' : ' disabled' ?> maxlength="20" value="<?= View::e((string) ($__o['new_hs_code'] ?? '')) ?>" placeholder="e.g. 68022390 (empty = the category's)">
      </label>
    </div>
    <p class="muted small">The new product starts <strong>Inactive</strong> and not configured; it is not offered on quotations until it is configured.</p>
  </fieldset>

  <div class="add-offer-row">
    <label>Base thickness *
      <select name="thickness_mm" required data-add-offer-thickness data-required-message="Choose the thickness the supplier quoted.">
        <option value="">Choose the product first</option>
      </select>
    </label>
    <label>Price ₹ *
      <input type="text" name="price" inputmode="decimal" required pattern="[0-9,]{1,15}(\.[0-9]{1,2})?" value="<?= View::e((string) ($__o['price'] ?? '')) ?>" placeholder="e.g. 180000">
    </label>
    <label>per *
      <select name="unit" required data-add-offer-unit data-sqft-unit data-required-message="Choose what the price is per.">
        <option value="">Choose…</option>
      </select>
    </label>
  </div>
  <p class="sqft-note" data-sqft-note hidden></p>
  <div class="add-offer-row">
    <label>Quoted on *
      <input type="date" name="quoted_on" required max="<?= View::e($__ap['today']) ?>" value="<?= View::e((string) ($__o['quoted_on'] ?? $__ap['today'])) ?>">
    </label>
    <label>Valid until
      <input type="date" name="valid_until" value="<?= View::e((string) ($__o['valid_until'] ?? '')) ?>">
    </label>
    <div class="add-offer-actions">
      <button type="submit" class="btn">Save</button>
      <a class="btn-secondary btn" href="<?= View::e($__back) ?>">Cancel</a>
    </div>
  </div>
  <p class="muted small">On save the offer is switched on. For a price for every thickness, the other thicknesses are worked out from the one you type by the Category price rules (as on Market prices).</p>
</form>
