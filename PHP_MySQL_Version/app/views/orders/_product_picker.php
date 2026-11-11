<?php
/**
 * "Pick from Products" on an order line (D-240). Owner decision D-245 P2: picking
 * a product fills the description, HS code, unit and — on a USD order, for people
 * who may see selling prices — the quote price of whoever sells the order (staff,
 * or the client's associate at their commission level). The price stays editable;
 * its floor (the lowest price this seller may agree) travels with it as data-floor.
 *
 * In: $pickerProducts (ProductRepository::forPicker), $pickerPrices
 *     (OrderLinePricing::pickerPrices — empty for people who may not see selling
 *     prices), $__pickerName (field name), $__pickerSelected (int|null), optional
 *     $__pickerCurrency (order currency code) and $__pickerKey (the order's seller
 *     key "staff" / "L<level id>"); on the New Order form the currency and client
 *     selects are read instead.
 * Node twin: views/orders/_product_picker.njk.
 */
$__pickerSelected = (int) ($__pickerSelected ?? 0);
// Owner decision D-255: the product's specification options (names only — never values or cost) and the chosen ones.
$__pickerSpecName = str_ends_with((string) $__pickerName, '[]') ? 'product_spec_json[]' : 'spec_json';
?>
<label class="full">Pick from Products <small class="muted"><?= !empty($pickRequiredWhen) ? '(needed for a goods line on a USD order — fills the line; you can still change anything)' : '(optional — fills the line; you can still change anything)' ?></small>
  <select name="<?= htmlspecialchars($__pickerName) ?>" class="product-picker"<?= !empty($__pickerOrderId) ? ' data-order-id="' . (int) $__pickerOrderId . '"' : '' ?><?= isset($__pickerCurrency) ? ' data-order-currency="' . htmlspecialchars((string) $__pickerCurrency) . '"' : '' ?><?= isset($__pickerKey) ? ' data-price-key="' . htmlspecialchars((string) $__pickerKey) . '"' : '' ?><?= !empty($sees['selling']) ? ' data-selling="1"' : '' ?><?= !empty($pickRequiredWhen) ? ' data-required-when="' . htmlspecialchars((string) $pickRequiredWhen) . '" data-required-when-message="Pick this goods line from Products — on a USD order goods lines cannot be typed by hand (service and charge lines can)."' : '' ?>>
    <option value="">— Type the line by hand —</option>
    <?php foreach ($pickerProducts ?? [] as $__pp): ?>
      <?php $__prices = $pickerPrices[(int) $__pp['id']] ?? null; ?>
      <option value="<?= (int) $__pp['id'] ?>"
        data-name="<?= htmlspecialchars((string) $__pp['name']) ?>"
        data-hs="<?= htmlspecialchars((string) $__pp['hs_code']) ?>"
        data-specs="<?= htmlspecialchars(mb_substr((string) $__pp['specifications'], 0, 200)) ?>"
        data-unit="<?= htmlspecialchars((string) ($__pp['reference_unit'] ?? '')) ?>"
        <?php if ($__prices !== null): ?>data-prices="<?= htmlspecialchars((string) json_encode($__prices)) ?>"<?php endif; ?>
        <?php if (!empty($pickerSpecs[(int) $__pp['id']])): ?>data-spec-options="<?= htmlspecialchars((string) json_encode($pickerSpecs[(int) $__pp['id']])) ?>"<?php endif; ?>
        <?= $__pickerSelected === (int) $__pp['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $__pp['name']) ?> (HS <?= htmlspecialchars((string) $__pp['hs_code']) ?>)</option>
    <?php endforeach; ?>
  </select>
  <span class="muted small product-picker-hint" aria-live="polite"></span>
</label>
<?php if (empty($__pickerNoSpecBox)): // D-273: the side panel shows the options in its own step 2 ?>
<div class="full spec-options" aria-live="polite"></div>
<?php endif; ?>
<input type="hidden" name="<?= $__pickerSpecName ?>" class="spec-json" value="<?= htmlspecialchars((string) ($__pickerSpecSel ?? '')) ?>">
