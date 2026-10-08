<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/logistics-partners">&larr; Back to Logistics Partners</a></p>
  <h1>Edit Logistics Partner — <?= htmlspecialchars($partner['partner_name']) ?></h1>
  <form method="post" action="/logistics-partners/<?= (int) $partner['id'] ?>/update">
    <?= Csrf::field() ?>
    <fieldset>
      <legend>Partner Details</legend>
      <div class="field-grid">
        <label class="full">Partner / Company Name *
          <input type="text" name="partner_name" value="<?= htmlspecialchars($partner['partner_name']) ?>" placeholder="e.g., Swift Cargo Logistics Pvt Ltd" required>
        </label>
        <label class="full">Address
          <input type="text" name="address" value="<?= htmlspecialchars($partner['address'] ?? '') ?>" placeholder="e.g., 123 Example Street, Test City, Country">
        </label>
        <label>Service Type *
          <select name="service_type" required>
            <?php foreach ($serviceTypes as $key => $label): ?>
              <option value="<?= htmlspecialchars($key) ?>" <?= $partner['service_type'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>City
          <input type="text" name="city" value="<?= htmlspecialchars($partner['city'] ?? '') ?>" placeholder="e.g., Mumbai">
        </label>
        <label>State
          <input type="text" name="state" value="<?= htmlspecialchars($partner['state'] ?? '') ?>" placeholder="e.g., Maharashtra">
        </label>
        <label>Phone
          <input type="text" name="phone" value="<?= htmlspecialchars($partner['phone'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>WhatsApp Number
          <input type="text" name="whatsapp_number" value="<?= htmlspecialchars($partner['whatsapp_number'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>Email
          <input type="email" name="email" value="<?= htmlspecialchars($partner['email'] ?? '') ?>" placeholder="e.g., name@example.com">
        </label>
        <label>Contact Person — Name
          <input type="text" name="contact_person_name" value="<?= htmlspecialchars($partner['contact_person_name'] ?? '') ?>" placeholder="e.g., John Doe">
        </label>
        <label>Contact Person — Phone
          <input type="text" name="contact_person_phone" value="<?= htmlspecialchars($partner['contact_person_phone'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>Contact Person — WhatsApp
          <input type="text" name="contact_person_whatsapp" value="<?= htmlspecialchars($partner['contact_person_whatsapp'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>GSTIN
          <input type="text" name="gstin" value="<?= htmlspecialchars($partner['gstin'] ?? '') ?>" placeholder="e.g., 22AAAAA0000A1Z5">
        </label>
        <label>PAN
          <input type="text" name="pan" value="<?= htmlspecialchars($partner['pan'] ?? '') ?>" placeholder="e.g., AAAAA0000A">
        </label>
      </div>
    </fieldset>
    <label>Notes <textarea name="notes" rows="3" placeholder="Anything else worth recording — service area, rate notes, preferred ports, etc."><?= htmlspecialchars($partner['notes'] ?? '') ?></textarea></label>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Save Changes</button>
      <a href="/logistics-partners" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
