<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Quotation Details Form</h1>
  <p class="muted">Just your company and shipping details — no product information needed here. We'll review and send your Quotation within 24 hours.</p>
  <div class="intake-summary">
    <span>&#128203; 3 short sections</span>
    <span>&#9201;&#65039; About 2 minutes</span>
    <span>&#9989; Fields marked * are required</span>
  </div>

  <form method="post" action="/quotation-details/submit">
    <?= Csrf::field() ?>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">1</span> Your Details</legend>
      <div class="field-grid">
        <label class="full">Company Legal Name *
          <input type="text" name="company_legal_name" placeholder="e.g., Test Company Ltd" required>
        </label>
        <label class="full">Billing Address *
          <textarea name="billing_address" placeholder="e.g., 123 Example Street, Test City, Country" required></textarea>
        </label>
        <label>Contact Person *
          <input type="text" name="contact_person" placeholder="e.g., John Doe" required>
        </label>
        <label>Email *
          <input type="email" name="email" placeholder="e.g., name@example.com" required>
        </label>
        <label>Phone
          <input type="text" name="phone" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>VAT / EORI / Tax Reg. No. *
          <input type="text" name="vat_eori_tax_no" placeholder="UK: EORI No. | Others: Tax Reg. No." required>
        </label>
        <label>Country of Destination *
          <input type="text" name="country_of_destination" placeholder="e.g., Hungary / UK / France" required>
        </label>
        <label>Port of Discharge
          <input type="text" name="port_of_discharge_text" placeholder="Optional — we'll advise">
        </label>
        <label class="full">Certificate of Origin Type
          <input type="text" name="coo_type" placeholder="GSP Form A (preferential) / Non-preferential — confirm with your customs broker if unsure">
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">2</span> Consignee Details</legend>
      <p class="muted small">The consignee is the company your goods actually ship to — often the same as you, but not always (e.g. if you're a trading company and the goods go straight to your end customer).</p>
      <label><input type="checkbox" id="consignee_same_as_buyer" name="consignee_same_as_buyer" value="1" checked> Same as Buyer (my own company above)</label>
      <div id="consignee_fields" class="field-grid" style="display:none">
        <label class="full">Consignee Company Legal Name
          <input type="text" name="consignee_name" placeholder="e.g., ABC Memorial Stones Ltd">
        </label>
        <label class="full">Consignee Address Line 1
          <input type="text" name="consignee_address_line1" placeholder="Street number and street name">
        </label>
        <label>Address Line 2
          <input type="text" name="consignee_address_line2" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town
          <input type="text" name="consignee_city">
        </label>
        <label>Postcode
          <input type="text" name="consignee_postcode">
        </label>
        <label>Country
          <input type="text" name="consignee_country">
        </label>
        <label>VAT / EORI / Tax Reg. No.
          <input type="text" name="consignee_vat_eori_tax_no">
        </label>
        <label>Contact Person
          <input type="text" name="consignee_contact_person">
        </label>
        <label>Phone
          <input type="text" name="consignee_phone">
        </label>
        <label>Email
          <input type="email" name="consignee_email">
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">3</span> Shipping Preference</legend>
      <div class="field-grid">
        <label>Incoterm *
          <input type="text" name="incoterm_preference" placeholder="FOB / CFR / CIF — if unsure, write FOB" required>
        </label>
        <label>Container Type
          <input type="text" name="container_type_text" placeholder="e.g., 1 × 20ft FCL (optional)">
        </label>
        <label class="full">Your Own Reference Number
          <input type="text" name="buyer_own_reference" placeholder="Your internal reference number, if any — write NIL if none">
        </label>
        <label class="full">Anything else we should know?
          <textarea name="notes" placeholder="Product details, quantities, timeline, etc."></textarea>
        </label>
      </div>
    </fieldset>

    <button type="submit">Submit Request</button>
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
