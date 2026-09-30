<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/logistics-partners">&larr; Back to Logistics Partners</a></p>
  <h1>Add Logistics Partner</h1>
  <form method="post" action="/logistics-partners">
    <?= Csrf::field() ?>
    <div class="kv-grid">
      <label>Partner / Company Name * <input type="text" name="partner_name" required></label>
      <label>Service Type *
        <select name="service_type" required>
          <?php foreach ($serviceTypes as $key => $label): ?>
            <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Address <input type="text" name="address"></label>
      <label>City <input type="text" name="city"></label>
      <label>State <input type="text" name="state"></label>
      <label>Phone <input type="text" name="phone"></label>
      <label>WhatsApp Number <input type="text" name="whatsapp_number"></label>
      <label>Email <input type="email" name="email"></label>
      <label>Contact Person — Name <input type="text" name="contact_person_name"></label>
      <label>Contact Person — Phone <input type="text" name="contact_person_phone"></label>
      <label>Contact Person — WhatsApp <input type="text" name="contact_person_whatsapp"></label>
      <label>GSTIN <input type="text" name="gstin"></label>
      <label>PAN <input type="text" name="pan"></label>
    </div>
    <label>Notes <textarea name="notes" rows="3" placeholder="Anything else worth recording — service area, rate notes, preferred ports, etc."></textarea></label>
    <div class="btn-row">
      <button type="submit" class="btn-sm btn-success">Add Partner</button>
      <a href="/logistics-partners" class="btn-sm btn-secondary">Cancel</a>
    </div>
  </form>
</div>
