<?php
use App\Helpers\Csrf;

$isEdit = $supplier !== null;
$v = static fn(string $key): string => htmlspecialchars((string) ($supplier[$key] ?? ''));
$selectedTypes = $supplier['types'] ?? [];
?>
<div class="card page-wide">
  <p class="muted small"><a href="/suppliers">&larr; Back to Suppliers</a></p>
  <h1><?= $isEdit ? 'Edit Supplier — ' . htmlspecialchars($supplier['supplier_legal_name']) : 'Add Supplier' ?></h1>
  <?php if ($isEdit && !empty($usedOnAnOrder)): ?>
    <p class="muted small">This supplier has been used on an order. Changes here apply to new Supplier POs only — orders that already saved their Supplier PO terms keep the details they recorded.</p>
  <?php endif; ?>
  <form method="post" action="<?= $isEdit ? '/suppliers/' . (int) $supplier['id'] . '/update' : '/suppliers' ?>">
    <?= Csrf::field() ?>
    <?= $isEdit && isset($editFingerprint) ? \App\Helpers\EditFingerprint::input($editFingerprint) : '' ?>
    <fieldset>
      <legend>Supplier Details</legend>
      <div class="field-grid">
        <label class="full">Supplier Legal Name *
          <input type="text" name="supplier_legal_name" value="<?= $v('supplier_legal_name') ?>" placeholder="e.g., Shree Granite Quarries Pvt Ltd" required>
        </label>
        <div class="full">
          <span class="field-label">Supplier Type(s) * <small class="muted">— tick every type that applies</small></span>
          <div style="display:flex;flex-wrap:wrap;gap:6px 18px;margin-top:4px;">
            <?php foreach ($supplierTypes as $type): ?>
              <label class="checkbox-row" style="display:inline-flex;align-items:center;gap:6px;width:auto;margin:0;font-weight:normal;">
                <input type="checkbox" name="types[]" value="<?= htmlspecialchars($type) ?>" <?= in_array($type, $selectedTypes, true) ? 'checked' : '' ?>> <?= htmlspecialchars($type) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <small class="muted">The list is managed under Dropdown Options (Supplier Type).</small>
        </div>
        <label class="full">Address
          <input type="text" name="address" value="<?= $v('address') ?>" placeholder="e.g., Survey No. 45, Quarry Road">
        </label>
        <label>City
          <input type="text" name="city" value="<?= $v('city') ?>" placeholder="e.g., Udaipur">
        </label>
        <label>State
          <input type="text" name="state" value="<?= $v('state') ?>" placeholder="e.g., Rajasthan">
        </label>
        <label>Phone
          <input type="text" name="phone" value="<?= $v('phone') ?>" placeholder="e.g., +91-0000000000">
        </label>
        <label>WhatsApp Number
          <input type="text" name="whatsapp_number" value="<?= $v('whatsapp_number') ?>" placeholder="e.g., +91-0000000000">
        </label>
        <label>Email
          <input type="email" name="email" value="<?= $v('email') ?>" placeholder="e.g., name@example.com">
        </label>
        <label>Contact Person — Name
          <input type="text" name="contact_person" value="<?= $v('contact_person') ?>" placeholder="e.g., Sales Manager">
        </label>
        <label>Contact Person — Phone
          <input type="text" name="contact_person_phone" value="<?= $v('contact_person_phone') ?>" placeholder="e.g., +91-0000000000">
        </label>
        <label>Contact Person — WhatsApp
          <input type="text" name="contact_person_whatsapp" value="<?= $v('contact_person_whatsapp') ?>" placeholder="e.g., +91-0000000000">
        </label>
        <label>GSTIN
          <input type="text" name="gstin" value="<?= $v('gstin') ?>" placeholder="e.g., 08AAAAA0000A1Z5">
        </label>
        <label>PAN
          <input type="text" name="pan" value="<?= $v('pan') ?>" placeholder="e.g., AAAAA0000A">
        </label>
      </div>
    </fieldset>
    <?php include __DIR__ . '/../partials/_vendor_bank_fields.php'; ?>
    <label>Notes <textarea name="notes" rows="3" placeholder="Anything else worth recording — material specialities, capacity, payment terms, etc."><?= $v('notes') ?></textarea></label>
    <?php // D-303 (point 15): a supplier added quickly from a product price is "Details pending" until completed here or by its details link. ?>
    <?php if ($isEdit): ?>
      <input type="hidden" name="details_pending" value="0">
      <label class="checkbox-row"><input type="checkbox" name="details_pending" value="1"<?= !empty($supplier['details_pending']) ? ' checked' : '' ?>> Details pending <small class="muted">(GSTIN, bank or contacts still to be completed — untick once they are)</small></label>
    <?php endif; ?>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success"><?= $isEdit ? 'Save Changes' : 'Add Supplier' ?></button>
      <a href="/suppliers" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>

  <?php if ($isEdit): ?>
  <?php $vendorKind = 'supplier'; $vendorId = (int) $supplier['id']; $vendor = $supplier; include __DIR__ . '/../partials/_vendor_details_link.php'; ?>
  <?php include __DIR__ . '/../partials/_vendor_bank_changes.php'; // D-294 ?>
  <div class="section" id="factory-addresses">
    <h2 style="font-size:1rem;">Factory Addresses</h2>
    <p class="muted small">Offered as the Delivery Location when saving Supplier PO terms for this supplier. Removing one never changes a Supplier PO that already recorded it.</p>
    <?php if (!empty($factoryAddresses)): ?>
      <table class="list">
        <tr><th>Label</th><th>Address</th><th></th></tr>
        <?php foreach ($factoryAddresses as $fa): ?>
          <tr>
            <td><?= htmlspecialchars($fa['label'] ?? '—') ?></td>
            <td><?= htmlspecialchars($fa['address']) ?></td>
            <td>
              <form method="post" action="/suppliers/<?= (int) $supplier['id'] ?>/factory-addresses/<?= (int) $fa['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this factory address?');">
                <?= Csrf::field() ?>
                <button type="submit" class="btn-sm btn-danger">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p class="muted small">No factory addresses added yet.</p>
    <?php endif; ?>
    <form method="post" action="/suppliers/<?= (int) $supplier['id'] ?>/factory-addresses" class="field-grid">
      <?= Csrf::field() ?>
      <label>Label <small class="muted">(optional)</small><input type="text" name="label" placeholder="e.g. Udaipur Factory"></label>
      <label>Address *<input type="text" name="address" required placeholder="e.g. Plot 12, Industrial Area, Udaipur, Rajasthan"></label>
      <button type="submit" class="btn-sm">Add Factory Address</button>
    </form>
  </div>
  <?php endif; ?>

  <?php // D-303 (point 15): the prices this supplier has given for products (purchase-price viewers only).
        // D-360 (mockup p2): editable here — "+ Add product", "Edit price", the offer switch and "Open product";
        // the "Used for pricing" column is gone from this table (the product keeps its own pricing supplier). ?>
  <?php if ($isEdit && isset($productPrices) && $productPrices !== null): ?>
    <div class="section-head-row">
      <h2 style="font-size:1rem;" id="product-prices">Prices given for products</h2>
      <?php if (!empty($addProduct) && $supplier['is_active']): ?><a href="#add-product" class="btn-sm" data-open-details="add-product">+ Add product</a><?php endif; ?>
    </div>
    <?php if (!$supplier['is_active'] && $productPrices !== []): ?><p class="muted small">Inactive: none of their prices are used anywhere.</p><?php endif; ?>
    <table class="list<?= !$supplier['is_active'] ? ' offer-table-off' : '' ?>">
      <tr><th>Product</th><th>Base thickness</th><th>Price</th><th>Quoted on</th><th>Valid until</th><th>Offer active</th><th>Actions</th></tr>
      <?php foreach ($productPrices as $__pp): $__off = $offerRows[(int) $__pp['id']] ?? null; ?>
        <tr class="offer-row<?= $__off !== null && !$__off['offer_active'] ? ' offer-row-off' : '' ?>"><td><?= htmlspecialchars((string) $__pp['product_name']) ?></td>
          <td><?= htmlspecialchars($__off['thickness'] ?? '—') ?></td>
          <td><?php if (($__pp['price_basis'] ?? '') === 'grid'): $__g = \App\Services\ThicknessPrices::typedFor((int) $__pp['id']); ?>
              <?= htmlspecialchars(implode(' · ', array_map(static fn ($mm, $v) => ($mm === 0 ? '50 mm+' : $mm . ' mm') . ' ₹' . number_format((float) $v, 2) . ($mm === 0 ? ' / CBM' : ' / SQM'), array_keys($__g), $__g))) ?> <small class="muted">(typed; others calculated)</small>
            <?php else: ?>₹<?= $__pp['fob_value'] !== null ? number_format((float) $__pp['fob_value'], 2) : '—' ?> <small class="muted">/ <?= htmlspecialchars(($__pp['price_basis'] ?? '') === 'cbm' ? 'CBM' : ((string) ($__pp['reference_unit'] ?? '') ?: 'unit')) ?></small><?php endif; ?></td>
          <td><?= htmlspecialchars((string) ($__pp['quoted_on'] ?? '—')) ?></td><td><?= htmlspecialchars((string) ($__pp['valid_until'] ?? '—')) ?></td>
          <td><?php if ($__off !== null): $__from = 'supplier'; $__canSwitch = !empty($canEditPrice); require __DIR__ . '/_offer_switch.php'; endif; ?></td>
          <td><div class="row-actions"><?php if (!empty($canEditPrice) && ($__off['editable'] ?? false)): ?><a class="btn-sm" href="/products/suppliers/<?= (int) $__pp['id'] ?>/edit-price?from=supplier">Edit price</a><?php endif; ?>
            <a class="btn-sm btn-secondary" href="/products/<?= (int) $__pp['product_id'] ?>">Open product</a></div></td></tr>
      <?php endforeach; ?>
      <?php if ($productPrices === []): ?><tr><td colspan="7" class="muted">No product prices from this supplier yet<?= !empty($addProduct) && $supplier['is_active'] ? ' — add one with "+ Add product".' : '.' ?></td></tr><?php endif; ?>
    </table>
    <?php if (!empty($addProduct) && $supplier['is_active']): ?>
    <details class="add-offer-panel" id="add-product"<?= isset($_GET['add']) || $addProduct['old'] !== [] ? ' open' : '' ?>>
      <summary>+ Add product — <?= htmlspecialchars((string) $supplier['supplier_legal_name']) ?></summary>
      <?php require __DIR__ . '/_add_product.php'; ?>
    </details>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (!empty($isEdit)) { $__partyType = "supplier"; $__partyId = (int) $supplier["id"]; require __DIR__ . "/../agreements/_party_card.php"; } ?>
