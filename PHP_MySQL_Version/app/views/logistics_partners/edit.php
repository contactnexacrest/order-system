<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/logistics-partners">&larr; Back to Logistics Partners</a></p>
  <h1>Edit Logistics Partner — <?= htmlspecialchars($partner['partner_name']) ?></h1>
  <form method="post" action="/logistics-partners/<?= (int) $partner['id'] ?>/update">
    <?= Csrf::field() ?>
    <div class="kv-grid">
      <label>Partner / Company Name * <input type="text" name="partner_name" value="<?= htmlspecialchars($partner['partner_name']) ?>" required></label>
      <label>Service Type *
        <select name="service_type" required>
          <?php foreach ($serviceTypes as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>" <?= $partner['service_type'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Address <input type="text" name="address" value="<?= htmlspecialchars($partner['address'] ?? '') ?>"></label>
      <label>City <input type="text" name="city" value="<?= htmlspecialchars($partner['city'] ?? '') ?>"></label>
      <label>State <input type="text" name="state" value="<?= htmlspecialchars($partner['state'] ?? '') ?>"></label>
      <label>Phone <input type="text" name="phone" value="<?= htmlspecialchars($partner['phone'] ?? '') ?>"></label>
      <label>WhatsApp Number <input type="text" name="whatsapp_number" value="<?= htmlspecialchars($partner['whatsapp_number'] ?? '') ?>"></label>
      <label>Email <input type="email" name="email" value="<?= htmlspecialchars($partner['email'] ?? '') ?>"></label>
      <label>Contact Person — Name <input type="text" name="contact_person_name" value="<?= htmlspecialchars($partner['contact_person_name'] ?? '') ?>"></label>
      <label>Contact Person — Phone <input type="text" name="contact_person_phone" value="<?= htmlspecialchars($partner['contact_person_phone'] ?? '') ?>"></label>
      <label>Contact Person — WhatsApp <input type="text" name="contact_person_whatsapp" value="<?= htmlspecialchars($partner['contact_person_whatsapp'] ?? '') ?>"></label>
      <label>GSTIN <input type="text" name="gstin" value="<?= htmlspecialchars($partner['gstin'] ?? '') ?>"></label>
      <label>PAN <input type="text" name="pan" value="<?= htmlspecialchars($partner['pan'] ?? '') ?>"></label>
    </div>
    <label>Notes <textarea name="notes" rows="3"><?= htmlspecialchars($partner['notes'] ?? '') ?></textarea></label>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Save Changes</button>
      <a href="/logistics-partners" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
