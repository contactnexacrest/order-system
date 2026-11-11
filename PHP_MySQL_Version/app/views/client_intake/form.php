<?php
use App\Helpers\Csrf;
$containerTypes = $containerTypes ?? [];
$incoterms = $incoterms ?? [];
$countries = $countries ?? [];

// External QA report U-06: Country was free text, producing inconsistent
// data ("UK", "United Kingdom", "uk"). Same select-plus-hidden-fallback
// pattern already used above for Incoterm — a short id prefix is all that
// differs between the three country fields on this form, so one closure
// renders all three rather than repeating the markup with copy-paste risk.
$countryField = function (string $idPrefix, string $name, bool $required, array $countries): void {
    $req = $required ? 'required' : '';
    ?>
    <select id="<?= $idPrefix ?>_select">
      <option value="">— Select —</option>
      <option value="Not sure">Not sure</option>
      <?php foreach ($countries as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
      <?php endforeach; ?>
      <option value="__other__">Other (not listed) — specify</option>
    </select>
    <input type="text" id="<?= $idPrefix ?>_input" name="<?= $name ?>" placeholder="Type the country" style="display:none" readonly <?= $req ?>>
    <?php
};
?>
<div class="card">
  <h1>Ask us for a quotation</h1>
  <p class="muted">Just your company and shipping details — no product information needed here. We'll review and send your Quotation within 24 hours.</p>
  <div class="intake-summary">
    <span>&#128203; 3 short sections</span>
    <span>&#9201;&#65039; About 2 minutes</span>
    <span>&#9989; Fields marked * are required</span>
  </div>

  <form method="post" action="/quotation-details/submit">
    <?= Csrf::field() ?>
    <?php // Owner decision 1 Nov 2026 (AS-5, revised): the associate's invitation link or code travels only as a hidden field — nothing for the buyer to tick or type. ?>
    <?php if (!empty($introducer)): ?>
      <?php if ($introducer['inv'] !== ''): ?><input type="hidden" name="associate_inv" value="<?= htmlspecialchars($introducer['inv']) ?>"><?php else: ?><input type="hidden" name="associate_code" value="<?= htmlspecialchars($introducer['code']) ?>"><?php endif; ?>
    <?php elseif (!empty($introGiven)): // A6 (4 Nov 2026): a code/link that cannot be applied still travels, so staff see what was given — the buyer sees nothing ?>
      <?php if ($introGiven['inv'] !== ''): ?><input type="hidden" name="associate_inv" value="<?= htmlspecialchars($introGiven['inv']) ?>"><?php else: ?><input type="hidden" name="associate_code" value="<?= htmlspecialchars($introGiven['code']) ?>"><?php endif; ?>
    <?php endif; ?>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">1</span> Your Details</legend>
      <div class="field-grid">
        <label class="full">Company name, as registered *
          <input type="text" name="company_legal_name" placeholder="e.g., Test Company Ltd" required>
        </label>
        <label class="full">Billing Address Line 1 *
          <input type="text" name="billing_address_line1" placeholder="Street number and street name" required>
        </label>
        <label>Address Line 2
          <input type="text" name="billing_address_line2" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town *
          <input type="text" name="billing_city" placeholder="e.g., Rotterdam" required>
        </label>
        <label>Postcode
          <input type="text" name="billing_postcode" placeholder="e.g., 3011 AD">
        </label>
        <label>Country *
          <?php $countryField('billing_country', 'billing_country', true, $countries); ?>
        </label>
        <label>Contact Person *
          <input type="text" name="contact_person" placeholder="e.g., Purchasing Manager" required>
        </label>
        <label>Email *
          <input type="email" name="email" placeholder="e.g., name@example.com" required>
        </label>
        <label>Phone
          <input type="text" name="phone" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>Company tax number (VAT, EORI or tax registration) *
          <input type="text" name="vat_eori_tax_no" placeholder="e.g., GB123456789" required>
          <small class="muted">In the UK, give your EORI number. In other countries, give your company tax or VAT number.</small>
        </label>
        <label>Country the goods go to *
          <?php $countryField('country_of_destination', 'country_of_destination', true, $countries); ?>
        </label>
        <label>Port where the goods arrive
          <input type="text" name="port_of_discharge_text" placeholder="Optional — we can suggest one">
        </label>
        <label class="full">Certificate of origin you need
          <?php // Owner report (2026-10-26): a dropdown of the real options, so what the client picks is kept exactly. ?>
          <?php $__coo = \App\Repositories\LookupRepository::matchOption(array_column($cooTypes ?? [], 'option_value'), (string) ('')); ?>
          <select name="coo_type">
            <option value="">Not sure — please confirm with me</option>
            <?php foreach (($cooTypes ?? []) as $__o): ?>
              <option value="<?= htmlspecialchars($__o['option_value']) ?>" <?= $__coo === $__o['option_value'] ? 'selected' : '' ?>><?= htmlspecialchars($__o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="muted">A preferential certificate (GSP Form A) can lower import duty in some countries. If you are not sure, ask your customs agent or choose 'Not sure'.</small>
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">2</span> Who receives the goods (consignee)</legend>
      <p class="muted small">The consignee is the company the goods are shipped to. It is often your own company. It can be different, for example when you are a trader and we ship straight to your customer.</p>
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
          <input type="text" name="consignee_city" placeholder="e.g., Rotterdam">
        </label>
        <label>Postcode
          <input type="text" name="consignee_postcode" placeholder="e.g., 3011 AD">
        </label>
        <label>Country
          <?php $countryField('consignee_country', 'consignee_country', false, $countries); ?>
        </label>
        <label>Company tax number (VAT, EORI or tax registration)
          <input type="text" name="consignee_vat_eori_tax_no" placeholder="e.g., GB123456789">
          <small class="muted">In the UK, give your EORI number. In other countries, give your company tax or VAT number.</small>
        </label>
        <label>Contact Person
          <input type="text" name="consignee_contact_person" placeholder="e.g., Logistics Manager">
        </label>
        <label>Phone
          <input type="text" name="consignee_phone" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>Email
          <input type="email" name="consignee_email" placeholder="e.g., name@example.com">
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">3</span> How you want the goods shipped</legend>
      <div class="field-grid">
        <label>Delivery terms (Incoterm) *
          <select id="incoterm_select" required>
            <option value="">— Select —</option>
            <?php foreach ($incoterms as $i): ?>
              <option value="<?= htmlspecialchars($i['code']) ?>"><?= htmlspecialchars($i['code']) ?></option>
            <?php endforeach; ?>
            <option value="__other__">Other (not listed) — specify</option>
          </select>
          <input type="text" id="incoterm_preference_input" name="incoterm_preference" placeholder="Type the delivery terms, e.g. DAP" style="display:none" readonly>
          <small class="muted">For example FOB: you book the sea freight. CIF: we book freight and insurance to your port.</small>
        </label>
        <label>Container Type
          <select name="container_type_text">
            <option value="">Not sure yet</option>
            <?php foreach ($containerTypes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>"><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Company website
          <input type="text" name="website" maxlength="255" placeholder="e.g., www.example.com">
        </label>
        <label class="full">Your reference number
          <input type="text" name="buyer_own_reference" placeholder="Your own reference for this request. If you have none, write NIL.">
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
    container.style.display = same ? 'none' : '';
    inputs.forEach(function (el) { el.disabled = same; });
  }
  checkbox.addEventListener('change', apply);
  apply();
})();
(function () {
  var select = document.getElementById('incoterm_select');
  var input = document.getElementById('incoterm_preference_input');
  if (!select || !input) { return; }
  function apply() {
    var isOther = select.value === '__other__';
    input.style.display = isOther ? '' : 'none';
    input.readOnly = !isOther;
    if (!isOther) { input.value = select.value; }
  }
  select.addEventListener('change', apply);
  apply();
})();
function wireCountryField(idPrefix) {
  var select = document.getElementById(idPrefix + '_select');
  var input = document.getElementById(idPrefix + '_input');
  if (!select || !input) { return; }
  function apply() {
    var isOther = select.value === '__other__';
    input.style.display = isOther ? '' : 'none';
    input.readOnly = !isOther;
    if (!isOther) { input.value = select.value; }
  }
  select.addEventListener('change', apply);
  apply();
}
wireCountryField('billing_country');
wireCountryField('country_of_destination');
wireCountryField('consignee_country');
</script>
