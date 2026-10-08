<?php use App\Helpers\Csrf; $containerTypes = $containerTypes ?? []; ?>
<div class="card">
  <h1>Correct Your Quotation Details</h1>
  <p class="muted">We haven't reviewed your request yet, so you can still fix anything here. Fields marked * are required.</p>

  <form method="post" action="/quotation-details/edit/<?= htmlspecialchars($token) ?>">
    <?= Csrf::field() ?>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">1</span> Your Details</legend>
      <div class="field-grid">
        <label class="full">Company Legal Name *
          <input type="text" name="company_legal_name" value="<?= htmlspecialchars($submission['company_legal_name']) ?>" placeholder="e.g., Test Company Ltd" required>
        </label>
        <label class="full">Billing Address Line 1 *
          <input type="text" name="billing_address_line1" value="<?= htmlspecialchars($submission['billing_address_line1'] ?? '') ?>" placeholder="Street number and street name" required>
        </label>
        <label>Address Line 2
          <input type="text" name="billing_address_line2" value="<?= htmlspecialchars($submission['billing_address_line2'] ?? '') ?>" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town *
          <input type="text" name="billing_city" value="<?= htmlspecialchars($submission['billing_city'] ?? '') ?>" placeholder="e.g., Rotterdam" required>
        </label>
        <label>Postcode
          <input type="text" name="billing_postcode" value="<?= htmlspecialchars($submission['billing_postcode'] ?? '') ?>" placeholder="e.g., 3011 AD">
        </label>
        <label>Country *
          <input type="text" name="billing_country" value="<?= htmlspecialchars($submission['billing_country'] ?? '') ?>" placeholder="e.g., Netherlands" required>
        </label>
        <label>Contact Person *
          <input type="text" name="contact_person" value="<?= htmlspecialchars($submission['contact_person']) ?>" placeholder="e.g., John Doe" required>
        </label>
        <label>Email *
          <input type="email" name="email" value="<?= htmlspecialchars($submission['email']) ?>" placeholder="e.g., name@example.com" required>
        </label>
        <label>Phone
          <input type="text" name="phone" value="<?= htmlspecialchars($submission['phone'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>VAT / EORI / Tax Reg. No. *
          <input type="text" name="vat_eori_tax_no" value="<?= htmlspecialchars($submission['vat_eori_tax_no'] ?? '') ?>" placeholder="UK: EORI No. | Others: Tax Reg. No." required>
        </label>
        <label>Country of Destination *
          <input type="text" name="country_of_destination" value="<?= htmlspecialchars($submission['country_of_destination']) ?>" placeholder="e.g., Hungary / UK / France" required>
        </label>
        <label>Port of Discharge
          <input type="text" name="port_of_discharge_text" value="<?= htmlspecialchars($submission['port_of_discharge_text'] ?? '') ?>" placeholder="Optional — we'll advise">
        </label>
        <label class="full">Certificate of Origin Type
          <input type="text" name="coo_type" value="<?= htmlspecialchars($submission['coo_type'] ?? '') ?>" placeholder="GSP Form A (preferential) / Non-preferential — confirm with your customs broker if unsure">
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">2</span> Consignee Details</legend>
      <p class="muted small">The consignee is the company your goods actually ship to — often the same as you, but not always.</p>
      <?php $consigneeSame = (int) ($submission['consignee_same_as_buyer'] ?? 1) === 1; ?>
      <label><input type="checkbox" id="consignee_same_as_buyer" name="consignee_same_as_buyer" value="1" <?= $consigneeSame ? 'checked' : '' ?>> Same as Buyer (my own company above)</label>
      <div id="consignee_fields" class="field-grid" style="display:none">
        <label class="full">Consignee Company Legal Name
          <input type="text" name="consignee_name" value="<?= htmlspecialchars($submission['consignee_name'] ?? '') ?>" placeholder="e.g., ABC Memorial Stones Ltd">
        </label>
        <label class="full">Consignee Address Line 1
          <input type="text" name="consignee_address_line1" value="<?= htmlspecialchars($submission['consignee_address_line1'] ?? '') ?>" placeholder="Street number and street name">
        </label>
        <label>Address Line 2
          <input type="text" name="consignee_address_line2" value="<?= htmlspecialchars($submission['consignee_address_line2'] ?? '') ?>" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town
          <input type="text" name="consignee_city" value="<?= htmlspecialchars($submission['consignee_city'] ?? '') ?>" placeholder="e.g., Rotterdam">
        </label>
        <label>Postcode
          <input type="text" name="consignee_postcode" value="<?= htmlspecialchars($submission['consignee_postcode'] ?? '') ?>" placeholder="e.g., 3011 AD">
        </label>
        <label>Country
          <input type="text" name="consignee_country" value="<?= htmlspecialchars($submission['consignee_country'] ?? '') ?>" placeholder="e.g., Netherlands">
        </label>
        <label>VAT / EORI / Tax Reg. No.
          <input type="text" name="consignee_vat_eori_tax_no" value="<?= htmlspecialchars($submission['consignee_vat_eori_tax_no'] ?? '') ?>" placeholder="UK: EORI No. | Others: Tax Reg. No.">
        </label>
        <label>Contact Person
          <input type="text" name="consignee_contact_person" value="<?= htmlspecialchars($submission['consignee_contact_person'] ?? '') ?>" placeholder="e.g., Jane Smith">
        </label>
        <label>Phone
          <input type="text" name="consignee_phone" value="<?= htmlspecialchars($submission['consignee_phone'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>Email
          <input type="email" name="consignee_email" value="<?= htmlspecialchars($submission['consignee_email'] ?? '') ?>" placeholder="e.g., name@example.com">
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">3</span> Shipping Preference</legend>
      <div class="field-grid">
        <label>Incoterm *
          <input type="text" name="incoterm_preference" value="<?= htmlspecialchars($submission['incoterm_preference'] ?? '') ?>" placeholder="FOB / CFR / CIF — if unsure, write FOB" required>
        </label>
        <label>Container Type
          <select name="container_type_text">
            <option value="">— Not sure / To Be Confirmed —</option>
            <?php foreach ($containerTypes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= ($submission['container_type_text'] ?? '') === $o['option_value'] ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="full">Your Own Reference Number
          <input type="text" name="buyer_own_reference" value="<?= htmlspecialchars($submission['buyer_own_reference'] ?? '') ?>" placeholder="Your internal reference number, if any — write NIL if none">
        </label>
        <label class="full">Anything else we should know?
          <textarea name="notes" placeholder="Product details, quantities, timeline, etc."><?= htmlspecialchars($submission['notes'] ?? '') ?></textarea>
        </label>
      </div>
    </fieldset>

    <button type="submit">Save Correction</button>
  </form>
</div>
<script>
(function () {
  var checkbox = document.getElementById('consignee_same_as_buyer');
  var container = document.getElementById('consignee_fields');
  if (!checkbox || !container) { return; }
  var inputs = container.querySelectorAll('input, textarea, select');
  function apply() {
    var same = checkbox.checked;
    container.style.display = same ? 'none' : '';
    inputs.forEach(function (el) { el.disabled = same; });
  }
  checkbox.addEventListener('change', apply);
  apply();
})();
</script>
