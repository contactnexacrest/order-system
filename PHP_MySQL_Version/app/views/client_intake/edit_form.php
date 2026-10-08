<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Correct Your Quotation Details</h1>
  <p class="muted">We haven't reviewed your request yet, so you can still fix anything here. Fields marked * are required.</p>

  <form method="post" action="/quotation-details/edit/<?= htmlspecialchars($token) ?>">
    <?= Csrf::field() ?>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">1</span> Your Details</legend>
      <div class="field-grid">
        <label class="full">Company Legal Name *
          <input type="text" name="company_legal_name" value="<?= htmlspecialchars($submission['company_legal_name']) ?>" required>
        </label>
        <label class="full">Billing Address *
          <textarea name="billing_address" required><?= htmlspecialchars($submission['billing_address']) ?></textarea>
        </label>
        <label>Contact Person *
          <input type="text" name="contact_person" value="<?= htmlspecialchars($submission['contact_person']) ?>" required>
        </label>
        <label>Email *
          <input type="email" name="email" value="<?= htmlspecialchars($submission['email']) ?>" required>
        </label>
        <label>Phone
          <input type="text" name="phone" value="<?= htmlspecialchars($submission['phone'] ?? '') ?>">
        </label>
        <label>VAT / EORI / Tax Reg. No. *
          <input type="text" name="vat_eori_tax_no" value="<?= htmlspecialchars($submission['vat_eori_tax_no'] ?? '') ?>" required>
        </label>
        <label>Country of Destination *
          <input type="text" name="country_of_destination" value="<?= htmlspecialchars($submission['country_of_destination']) ?>" required>
        </label>
        <label>Port of Discharge
          <input type="text" name="port_of_discharge_text" value="<?= htmlspecialchars($submission['port_of_discharge_text'] ?? '') ?>">
        </label>
        <label class="full">Certificate of Origin Type
          <input type="text" name="coo_type" value="<?= htmlspecialchars($submission['coo_type'] ?? '') ?>">
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
          <input type="text" name="consignee_name" value="<?= htmlspecialchars($submission['consignee_name'] ?? '') ?>">
        </label>
        <label class="full">Consignee Address Line 1
          <input type="text" name="consignee_address_line1" value="<?= htmlspecialchars($submission['consignee_address_line1'] ?? '') ?>">
        </label>
        <label>Address Line 2
          <input type="text" name="consignee_address_line2" value="<?= htmlspecialchars($submission['consignee_address_line2'] ?? '') ?>">
        </label>
        <label>City / Town
          <input type="text" name="consignee_city" value="<?= htmlspecialchars($submission['consignee_city'] ?? '') ?>">
        </label>
        <label>Postcode
          <input type="text" name="consignee_postcode" value="<?= htmlspecialchars($submission['consignee_postcode'] ?? '') ?>">
        </label>
        <label>Country
          <input type="text" name="consignee_country" value="<?= htmlspecialchars($submission['consignee_country'] ?? '') ?>">
        </label>
        <label>VAT / EORI / Tax Reg. No.
          <input type="text" name="consignee_vat_eori_tax_no" value="<?= htmlspecialchars($submission['consignee_vat_eori_tax_no'] ?? '') ?>">
        </label>
        <label>Contact Person
          <input type="text" name="consignee_contact_person" value="<?= htmlspecialchars($submission['consignee_contact_person'] ?? '') ?>">
        </label>
        <label>Phone
          <input type="text" name="consignee_phone" value="<?= htmlspecialchars($submission['consignee_phone'] ?? '') ?>">
        </label>
        <label>Email
          <input type="email" name="consignee_email" value="<?= htmlspecialchars($submission['consignee_email'] ?? '') ?>">
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">3</span> Shipping Preference</legend>
      <div class="field-grid">
        <label>Incoterm *
          <input type="text" name="incoterm_preference" value="<?= htmlspecialchars($submission['incoterm_preference'] ?? '') ?>" required>
        </label>
        <label>Container Type
          <input type="text" name="container_type_text" value="<?= htmlspecialchars($submission['container_type_text'] ?? '') ?>">
        </label>
        <label class="full">Your Own Reference Number
          <input type="text" name="buyer_own_reference" value="<?= htmlspecialchars($submission['buyer_own_reference'] ?? '') ?>">
        </label>
        <label class="full">Anything else we should know?
          <textarea name="notes"><?= htmlspecialchars($submission['notes'] ?? '') ?></textarea>
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
    container.style.display = same ? 'none' : 'block';
    inputs.forEach(function (el) { el.disabled = same; });
  }
  checkbox.addEventListener('change', apply);
  apply();
})();
</script>
