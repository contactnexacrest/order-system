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
          <input type="text" name="phone" value="<?= $v('phone') ?>" placeholder="e.g., +91 98765 43210">
        </label>
        <label>WhatsApp Number
          <input type="text" name="whatsapp_number" value="<?= $v('whatsapp_number') ?>" placeholder="e.g., +91 98765 43210">
        </label>
        <label>Email
          <input type="email" name="email" value="<?= $v('email') ?>" placeholder="e.g., name@example.com">
        </label>
        <label>Contact Person — Name
          <input type="text" name="contact_person" value="<?= $v('contact_person') ?>" placeholder="e.g., Ramesh Kumar">
        </label>
        <label>Contact Person — Phone
          <input type="text" name="contact_person_phone" value="<?= $v('contact_person_phone') ?>" placeholder="e.g., +91 98765 43210">
        </label>
        <label>Contact Person — WhatsApp
          <input type="text" name="contact_person_whatsapp" value="<?= $v('contact_person_whatsapp') ?>" placeholder="e.g., +91 98765 43210">
        </label>
        <label>GSTIN
          <input type="text" name="gstin" value="<?= $v('gstin') ?>" placeholder="e.g., 08AAAAA0000A1Z5">
        </label>
        <label>PAN
          <input type="text" name="pan" value="<?= $v('pan') ?>" placeholder="e.g., AAAAA0000A">
        </label>
      </div>
    </fieldset>
    <label>Notes <textarea name="notes" rows="3" placeholder="Anything else worth recording — material specialities, capacity, payment terms, etc."><?= $v('notes') ?></textarea></label>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success"><?= $isEdit ? 'Save Changes' : 'Add Supplier' ?></button>
      <a href="/suppliers" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>

  <?php if ($isEdit): ?>
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
</div>
