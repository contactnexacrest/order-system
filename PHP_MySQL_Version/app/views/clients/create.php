<?php use App\Helpers\Csrf; $old = $old ?? []; $cooTypes = $cooTypes ?? []; ?>
<div class="card">
  <p class="muted small"><a href="/clients">&larr; Back to Clients</a></p>
  <h1>New Client</h1>
  <p class="muted">A Buyer Inquiry Ref is generated automatically and reused on every order and document for this client.</p>
  <div class="review-banner warn" style="margin-bottom:14px">
    ℹ <span>Once this client confirms their details (PI-details form) or an order's advance payment is recorded, whichever happens first, these details lock permanently — no further edits, by anyone but a Super Admin fixing a genuine mistake. Get everything right before that point.</span>
  </div>
  <form method="post" action="/clients">
    <?= Csrf::field() ?>
    <fieldset>
      <legend>Buyer Details</legend>
      <div class="field-grid">
        <label class="full">Company Legal Name *
          <input type="text" name="company_legal_name" value="<?= htmlspecialchars((string) ($old['company_legal_name'] ?? '')) ?>" placeholder="e.g., Test Company Ltd" required>
        </label>
        <label class="full">Billing Address *
          <textarea name="billing_address" rows="2" placeholder="e.g., 123 Example Street, Test City, Country" required><?= htmlspecialchars((string) ($old['billing_address'] ?? '')) ?></textarea>
        </label>
        <label>Billing Address Line 1
          <input type="text" name="billing_address_line1" value="<?= htmlspecialchars((string) ($old['billing_address_line1'] ?? '')) ?>" placeholder="Street number and street name">
        </label>
        <label>Billing Address Line 2
          <input type="text" name="billing_address_line2" value="<?= htmlspecialchars((string) ($old['billing_address_line2'] ?? '')) ?>" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town
          <input type="text" name="billing_city" value="<?= htmlspecialchars((string) ($old['billing_city'] ?? '')) ?>" placeholder="e.g., Birmingham">
        </label>
        <label>Postcode
          <input type="text" name="billing_postcode" value="<?= htmlspecialchars((string) ($old['billing_postcode'] ?? '')) ?>" placeholder="e.g., B1 1AA">
        </label>
        <label>VAT / EORI / Tax Reg. No.
          <input type="text" name="vat_eori_tax_no" value="<?= htmlspecialchars((string) ($old['vat_eori_tax_no'] ?? '')) ?>" placeholder="UK: EORI No. | Others: Tax Reg. No.">
        </label>
        <label>Country of Destination
          <input type="text" name="country_of_destination" value="<?= htmlspecialchars((string) ($old['country_of_destination'] ?? '')) ?>" placeholder="e.g., Hungary / UK / France">
        </label>
        <label>Certificate of Origin Type
          <select name="coo_type">
            <option value="">— To Be Confirmed —</option>
            <?php foreach ($cooTypes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= (($old['coo_type'] ?? '') === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Consignee Details</legend>
      <label><input type="checkbox" id="consignee_same_as_buyer" name="consignee_same_as_buyer" value="1" checked> Same as Buyer</label>
      <div id="consignee_fields" class="field-grid" style="display:none">
        <label class="full">Consignee Company Name
          <input type="text" name="consignee_name" disabled value="<?= htmlspecialchars((string) ($old['consignee_name'] ?? '')) ?>" placeholder="e.g., ABC Memorial Stones Ltd">
        </label>
        <label class="full">Address Line 1
          <input type="text" name="consignee_address_line1" disabled value="<?= htmlspecialchars((string) ($old['consignee_address_line1'] ?? '')) ?>" placeholder="Street number and street name">
        </label>
        <label>Address Line 2
          <input type="text" name="consignee_address_line2" disabled value="<?= htmlspecialchars((string) ($old['consignee_address_line2'] ?? '')) ?>" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town
          <input type="text" name="consignee_city" disabled value="<?= htmlspecialchars((string) ($old['consignee_city'] ?? '')) ?>" placeholder="e.g., Rotterdam">
        </label>
        <label>Postcode
          <input type="text" name="consignee_postcode" disabled value="<?= htmlspecialchars((string) ($old['consignee_postcode'] ?? '')) ?>" placeholder="e.g., 3011 AD">
        </label>
        <label>Country
          <input type="text" name="consignee_country" disabled value="<?= htmlspecialchars((string) ($old['consignee_country'] ?? '')) ?>" placeholder="e.g., Netherlands">
        </label>
        <label>VAT / EORI / Tax Reg. No.
          <input type="text" name="consignee_vat_eori_tax_no" disabled value="<?= htmlspecialchars((string) ($old['consignee_vat_eori_tax_no'] ?? '')) ?>" placeholder="UK: EORI No. | Others: Tax Reg. No.">
        </label>
        <label>Contact Person
          <input type="text" name="consignee_contact_person" disabled value="<?= htmlspecialchars((string) ($old['consignee_contact_person'] ?? '')) ?>" placeholder="e.g., Jane Smith">
        </label>
        <label>Phone
          <input type="text" name="consignee_phone" disabled value="<?= htmlspecialchars((string) ($old['consignee_phone'] ?? '')) ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>Email
          <input type="email" name="consignee_email" disabled value="<?= htmlspecialchars((string) ($old['consignee_email'] ?? '')) ?>" placeholder="e.g., name@example.com">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Notify Party</legend>
      <label><input type="checkbox" id="notify_party_same_as_consignee" name="notify_party_same_as_consignee" value="1" checked> Same as Consignee</label>
      <div id="notify_party_fields" class="field-grid" style="display:none">
        <label class="full">Notify Party Name
          <input type="text" name="notify_party" disabled value="<?= htmlspecialchars((string) ($old['notify_party'] ?? '')) ?>" placeholder="e.g., ABC Freight Forwarders Ltd">
        </label>
        <label class="full">Address Line 1
          <input type="text" name="notify_party_address_line1" disabled value="<?= htmlspecialchars((string) ($old['notify_party_address_line1'] ?? '')) ?>" placeholder="Street number and street name">
        </label>
        <label>Address Line 2
          <input type="text" name="notify_party_address_line2" disabled value="<?= htmlspecialchars((string) ($old['notify_party_address_line2'] ?? '')) ?>" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town
          <input type="text" name="notify_party_city" disabled value="<?= htmlspecialchars((string) ($old['notify_party_city'] ?? '')) ?>" placeholder="e.g., Rotterdam">
        </label>
        <label>Postcode
          <input type="text" name="notify_party_postcode" disabled value="<?= htmlspecialchars((string) ($old['notify_party_postcode'] ?? '')) ?>" placeholder="e.g., 3011 AD">
        </label>
        <label>Country
          <input type="text" name="notify_party_country" disabled value="<?= htmlspecialchars((string) ($old['notify_party_country'] ?? '')) ?>" placeholder="e.g., Netherlands">
        </label>
        <label>Contact Person
          <input type="text" name="notify_party_contact_person" disabled value="<?= htmlspecialchars((string) ($old['notify_party_contact_person'] ?? '')) ?>" placeholder="e.g., Jane Smith">
        </label>
        <label>Phone
          <input type="text" name="notify_party_phone" disabled value="<?= htmlspecialchars((string) ($old['notify_party_phone'] ?? '')) ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>Email
          <input type="email" name="notify_party_email" disabled value="<?= htmlspecialchars((string) ($old['notify_party_email'] ?? '')) ?>" placeholder="e.g., name@example.com">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Contact</legend>
      <div class="field-grid">
        <label>Contact Person
          <input type="text" name="contact_person" value="<?= htmlspecialchars((string) ($old['contact_person'] ?? '')) ?>" placeholder="e.g., John Doe">
        </label>
        <label>Email
          <input type="email" name="email" value="<?= htmlspecialchars((string) ($old['email'] ?? '')) ?>" placeholder="e.g., name@example.com">
        </label>
        <label>Phone
          <input type="text" name="phone" value="<?= htmlspecialchars((string) ($old['phone'] ?? '')) ?>" placeholder="e.g., +1 555 123 4567">
        </label>
      </div>
    </fieldset>
    <button type="submit">Create Client</button>
  </form>
</div>
<script>
(function () {
  function wireSameAs(checkboxId, fieldsId) {
    var checkbox = document.getElementById(checkboxId);
    var container = document.getElementById(fieldsId);
    if (!checkbox || !container) { return; }
    var inputs = container.querySelectorAll('input, textarea, select');
    function apply() {
      var same = checkbox.checked;
      container.style.display = same ? 'none' : '';
      inputs.forEach(function (el) { el.disabled = same; });
    }
    checkbox.addEventListener('change', apply);
    apply();
  }
  wireSameAs('consignee_same_as_buyer', 'consignee_fields');
  wireSameAs('notify_party_same_as_consignee', 'notify_party_fields');
})();
</script>
