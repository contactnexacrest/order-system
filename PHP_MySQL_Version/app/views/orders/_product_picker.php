<?php
/**
 * Owner decision 2 Nov 2026 (D-240): "Pick from Products" on an order line.
 * Fills description, HS code, unit and — when the product's reference price is in
 * the order's currency — the unit price, which stays freely editable. The
 * reference price reaches the browser only for people who may see selling prices.
 *
 * In: $pickerProducts (ProductRepository::forPicker), $__pickerName (field name),
 *     $__pickerSelected (int|null), $sees, optional $__pickerCurrency (order currency code;
 *     on the New Order form the currency select is read instead).
 * Node twin: views/orders/_product_picker.njk.
 */
$__pickerSelected = (int) ($__pickerSelected ?? 0);
?>
<label class="full">Pick from Products <small class="muted">(optional — fills the line; you can still change anything)</small>
  <select name="<?= htmlspecialchars($__pickerName) ?>" class="product-picker"<?= isset($__pickerCurrency) ? ' data-order-currency="' . htmlspecialchars((string) $__pickerCurrency) . '"' : '' ?>>
    <option value="">— Type the line by hand —</option>
    <?php foreach ($pickerProducts ?? [] as $__pp): ?>
      <?php $__hasRef = !empty($sees['selling']) && $__pp['reference_price'] !== null; ?>
      <option value="<?= (int) $__pp['id'] ?>"
        data-name="<?= htmlspecialchars((string) $__pp['name']) ?>"
        data-hs="<?= htmlspecialchars((string) $__pp['hs_code']) ?>"
        data-specs="<?= htmlspecialchars(mb_substr((string) $__pp['specifications'], 0, 200)) ?>"
        data-unit="<?= htmlspecialchars((string) ($__pp['reference_unit'] ?? '')) ?>"
        <?php if ($__hasRef): ?>data-price="<?= htmlspecialchars(number_format((float) $__pp['reference_price'], 2, '.', '')) ?>" data-currency="<?= htmlspecialchars((string) $__pp['reference_currency']) ?>" data-label="<?= htmlspecialchars(\App\Services\ReferencePrice::label($__pp)) ?>"<?php endif; ?>
        <?= $__pickerSelected === (int) $__pp['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $__pp['name']) ?> (HS <?= htmlspecialchars((string) $__pp['hs_code']) ?>)</option>
    <?php endforeach; ?>
  </select>
  <span class="muted small product-picker-hint" aria-live="polite"></span>
</label>
