<?php
use App\Helpers\Csrf;
$incoterms = $incoterms ?? [];
$incotermValue = $submission['incoterm_confirmed'] ?? '';
$incotermIsOther = $incotermValue !== '' && !in_array($incotermValue, array_column($incoterms, 'code'), true);
?>
<div class="card">
  <h1>Proforma Invoice Details Form</h1>
  <p class="muted">Order <?= htmlspecialchars($submission['order_reference']) ?> — <?= htmlspecialchars($submission['client_company_legal_name']) ?></p>
  <p class="muted">Product details were already confirmed in your Quotation — this just locks in the exact wording for your shipping documents. We'll issue your PI within 24 hours.</p>
  <div class="intake-summary">
    <span>&#128203; 6 short sections</span>
    <span>&#9989; Fields marked * are required</span>
    <span>&#9999; Your Details are pre-filled from your Quotation — please review and correct if needed</span>
    <span>&#128274; Confirm exactly as it should appear on official documents</span>
  </div>

  <form method="post" action="/pi-details/<?= htmlspecialchars($token) ?>">
    <?= Csrf::field() ?>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">1</span> Your Details</legend>
      <div class="field-grid">
        <label class="full">Company Legal Name *
          <input type="text" name="company_legal_name" value="<?= htmlspecialchars($submission['company_legal_name'] ?? '') ?>" placeholder="Exact legal name, no abbreviations" required>
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
          <input type="text" name="contact_person" value="<?= htmlspecialchars($submission['contact_person'] ?? '') ?>" placeholder="e.g., John Doe" required>
        </label>
        <label>Email *
          <input type="email" name="email" value="<?= htmlspecialchars($submission['email'] ?? '') ?>" placeholder="e.g., name@example.com" required>
        </label>
        <label>Phone *
          <input type="text" name="phone" value="<?= htmlspecialchars($submission['phone'] ?? '') ?>" placeholder="e.g., +1 555 123 4567" required>
        </label>
        <label>VAT / EORI / Tax Reg. No. *
          <input type="text" name="vat_eori_tax_no" value="<?= htmlspecialchars($submission['vat_eori_tax_no'] ?? '') ?>" placeholder="UK: EORI No. | Others: Tax Reg. No." required>
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">2</span> Consignee Details</legend>
      <p class="muted small">The consignee is the company your goods actually ship to and that will appear on the Bill of Lading — often the same as you, but not always.</p>
      <?php $consigneeSame = (int) ($submission['consignee_same_as_buyer'] ?? 1) === 1; ?>
      <label><input type="checkbox" id="consignee_same_as_buyer" name="consignee_same_as_buyer" value="1" <?= $consigneeSame ? 'checked' : '' ?>> Same as Buyer (my own company above)</label>
      <div id="consignee_fields" class="field-grid" style="display:none">
        <label class="full">Consignee Company Legal Name
          <input type="text" name="consignee_name" value="<?= htmlspecialchars($submission['consignee_name'] ?? '') ?>" placeholder="e.g., ABC Memorial Stones Ltd">
        </label>
        <label class="full">Address Line 1
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
      <legend><span class="intake-step-num">3</span> Notify Party</legend>
      <p class="muted small">The freight forwarder/customs agent to notify when the shipment arrives — not necessarily the consignee itself.</p>
      <?php $notifySame = (int) ($submission['notify_party_same_as_consignee'] ?? 1) === 1; ?>
      <label><input type="checkbox" id="notify_party_same_as_consignee" name="notify_party_same_as_consignee" value="1" <?= $notifySame ? 'checked' : '' ?>> Same as Consignee</label>
      <div id="notify_party_fields" class="field-grid" style="display:none">
        <label class="full">Notify Party Name
          <input type="text" name="notify_party" value="<?= htmlspecialchars($submission['notify_party'] ?? '') ?>" placeholder="e.g., ABC Freight Forwarders Ltd">
        </label>
        <label class="full">Address Line 1
          <input type="text" name="notify_party_address_line1" value="<?= htmlspecialchars($submission['notify_party_address_line1'] ?? '') ?>" placeholder="Street number and street name">
        </label>
        <label>Address Line 2
          <input type="text" name="notify_party_address_line2" value="<?= htmlspecialchars($submission['notify_party_address_line2'] ?? '') ?>" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town
          <input type="text" name="notify_party_city" value="<?= htmlspecialchars($submission['notify_party_city'] ?? '') ?>" placeholder="e.g., Rotterdam">
        </label>
        <label>Postcode
          <input type="text" name="notify_party_postcode" value="<?= htmlspecialchars($submission['notify_party_postcode'] ?? '') ?>" placeholder="e.g., 3011 AD">
        </label>
        <label>Country
          <input type="text" name="notify_party_country" value="<?= htmlspecialchars($submission['notify_party_country'] ?? '') ?>" placeholder="e.g., Netherlands">
        </label>
        <label>Contact Person
          <input type="text" name="notify_party_contact_person" value="<?= htmlspecialchars($submission['notify_party_contact_person'] ?? '') ?>" placeholder="e.g., Jane Smith">
        </label>
        <label>Phone
          <input type="text" name="notify_party_phone" value="<?= htmlspecialchars($submission['notify_party_phone'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>Email
          <input type="email" name="notify_party_email" value="<?= htmlspecialchars($submission['notify_party_email'] ?? '') ?>" placeholder="e.g., name@example.com">
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">4</span> Shipping Details</legend>
      <div class="field-grid">
        <label>Port of Discharge *
          <input type="text" name="port_of_discharge_text" value="<?= htmlspecialchars($submission['port_of_discharge_text'] ?? '') ?>" placeholder="Buyer's nominated discharge port" required>
        </label>
        <label>Country of Final Destination *
          <input type="text" name="country_of_destination" value="<?= htmlspecialchars($submission['country_of_destination'] ?? '') ?>" placeholder="e.g., Hungary / UK / France" required>
        </label>
        <label>Incoterm *
          <select id="incoterm_select">
            <option value="">— Select —</option>
            <?php foreach ($incoterms as $i): ?>
              <option value="<?= htmlspecialchars($i['code']) ?>" <?= $incotermValue === $i['code'] ? 'selected' : '' ?>><?= htmlspecialchars($i['code']) ?></option>
            <?php endforeach; ?>
            <option value="__other__" <?= $incotermIsOther ? 'selected' : '' ?>>Other (not listed) — specify</option>
          </select>
          <input type="text" id="incoterm_confirmed_input" name="incoterm_confirmed" value="<?= htmlspecialchars($incotermValue) ?>" placeholder="Type the Incoterm" style="<?= $incotermIsOther ? '' : 'display:none' ?>" <?= $incotermIsOther ? '' : 'readonly' ?>>
        </label>
        <label>Container Type
          <input type="text" name="container_type_text" value="<?= htmlspecialchars($submission['container_type_text'] ?? '') ?>" placeholder="Blank = same as quotation">
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">5</span> Payment Terms &amp; Order Confirmation</legend>
      <div class="field-grid">
        <label class="full">Payment Terms Confirmation as per quotation *
          <textarea name="payment_terms_confirmation" placeholder="<?= htmlspecialchars($paymentTermsPlaceholder) ?>" required><?= htmlspecialchars($submission['payment_terms_confirmation'] ?? $paymentTermsPlaceholder) ?></textarea>
        </label>
        <label class="full">Acceptance of Quotation No. *
          <textarea name="quotation_acceptance_reference" placeholder="<?= htmlspecialchars($quotationAcceptancePlaceholder) ?>" required><?= htmlspecialchars($submission['quotation_acceptance_reference'] ?? $quotationAcceptancePlaceholder) ?></textarea>
        </label>
        <label>Certificate of Origin Type <span class="info-bubble" tabindex="0" title="A certificate confirming the goods' country of manufacture, often required for customs/preferential duty. GSP Form A = preferential rate under a trade scheme (if your country qualifies); Non-preferential = standard certificate of origin. Leave blank if you're not sure — we'll confirm with you.">&#9432;</span>
          <?php // Owner report (2026-10-26): a dropdown of the real options, so what the client picks is kept exactly. ?>
          <?php $__coo = \App\Repositories\LookupRepository::matchOption(array_column($cooTypes ?? [], 'option_value'), (string) ($submission['coo_type'] ?? '')); ?>
          <select name="coo_type">
            <option value="">Not sure — please confirm with me</option>
            <?php foreach (($cooTypes ?? []) as $__o): ?>
              <option value="<?= htmlspecialchars($__o['option_value']) ?>" <?= $__coo === $__o['option_value'] ? 'selected' : '' ?>><?= htmlspecialchars($__o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Buyer PO / Reference No.
          <input type="text" name="buyer_po_ref" value="<?= htmlspecialchars($submission['buyer_po_ref'] ?? '') ?>" placeholder="Write NIL if none">
        </label>
        <label class="full">Special Document Requirements
          <textarea name="special_document_requirements" placeholder="e.g. No special requirements / Consular legalised CI required"><?= htmlspecialchars($submission['special_document_requirements'] ?? '') ?></textarea>
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">6</span> Confirmation</legend>
      <div class="intake-note">
        <strong>Once submitted, these details are locked permanently</strong> — not by you, and not by NexaCrest, through the ordinary course of business. If something needs correcting after this point, a new client record has to be set up from scratch; there is no edit option once locked.
      </div>
      <label><input type="checkbox" name="confirm_lock" value="1" required> I confirm the above is correct and understand it will be locked.</label>
    </fieldset>

    <button type="submit">Submit PI Details</button>
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

  var incotermSelect = document.getElementById('incoterm_select');
  var incotermInput = document.getElementById('incoterm_confirmed_input');
  if (incotermSelect && incotermInput) {
    var applyIncoterm = function () {
      var isOther = incotermSelect.value === '__other__';
      incotermInput.style.display = isOther ? '' : 'none';
      incotermInput.readOnly = !isOther;
      if (!isOther) { incotermInput.value = incotermSelect.value; }
    };
    incotermSelect.addEventListener('change', applyIncoterm);
    applyIncoterm();
  }
})();
</script>
