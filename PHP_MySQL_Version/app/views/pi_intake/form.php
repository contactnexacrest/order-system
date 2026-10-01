<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Proforma Invoice Details Form</h1>
  <p class="muted">Order <?= htmlspecialchars($submission['order_reference']) ?> — <?= htmlspecialchars($submission['client_company_legal_name']) ?></p>
  <p class="muted">Product details were already confirmed in your Quotation — this just locks in the exact wording for your shipping documents. We'll issue your PI within 24 hours.</p>
  <div class="intake-summary">
    <span>&#128203; 4 short sections</span>
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
        <label class="full">Billing Address *
          <textarea name="billing_address" placeholder="Full address including postcode — must match PI and BL exactly" required><?= htmlspecialchars($submission['billing_address'] ?? '') ?></textarea>
        </label>
        <label class="full">Consignee Name *
          <input type="text" name="consignee_name" value="<?= htmlspecialchars($submission['consignee_name'] ?? '') ?>" placeholder="Write SAME if same as Company Legal Name" required>
        </label>
        <label class="full">Consignee Address *
          <textarea name="consignee_address" placeholder="Write SAME if same as Billing Address" required><?= htmlspecialchars($submission['consignee_address'] ?? '') ?></textarea>
        </label>
        <label>Contact Person *
          <input type="text" name="contact_person" value="<?= htmlspecialchars($submission['contact_person'] ?? '') ?>" required>
        </label>
        <label>Email *
          <input type="email" name="email" value="<?= htmlspecialchars($submission['email'] ?? '') ?>" required>
        </label>
        <label>Phone *
          <input type="text" name="phone" value="<?= htmlspecialchars($submission['phone'] ?? '') ?>" required>
        </label>
        <label>VAT / EORI / Tax Reg. No. *
          <input type="text" name="vat_eori_tax_no" value="<?= htmlspecialchars($submission['vat_eori_tax_no'] ?? '') ?>" required>
        </label>
        <label class="full">Notify Party
          <input type="text" name="notify_party" value="<?= htmlspecialchars($submission['notify_party'] ?? '') ?>" placeholder="Your freight forwarder/customs agent — write SAME or NIL">
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">2</span> Shipping Details</legend>
      <div class="field-grid">
        <label>Port of Discharge *
          <input type="text" name="port_of_discharge_text" value="<?= htmlspecialchars($submission['port_of_discharge_text'] ?? '') ?>" required>
        </label>
        <label>Country of Final Destination *
          <input type="text" name="country_of_destination" value="<?= htmlspecialchars($submission['country_of_destination'] ?? '') ?>" required>
        </label>
        <label>Incoterm *
          <input type="text" name="incoterm_confirmed" value="<?= htmlspecialchars($submission['incoterm_confirmed'] ?? '') ?>" required>
        </label>
        <label>Container Type
          <input type="text" name="container_type_text" value="<?= htmlspecialchars($submission['container_type_text'] ?? '') ?>" placeholder="Blank = same as quotation">
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">3</span> Payment &amp; Order Confirmation</legend>
      <div class="field-grid">
        <label class="full">Payment Terms Confirmation *
          <textarea name="payment_terms_confirmation" placeholder="e.g. CONFIRMED — 30% advance T/T + 70% balance against scanned BL copy within 7 days" required><?= htmlspecialchars($submission['payment_terms_confirmation'] ?? '') ?></textarea>
        </label>
        <label class="full">Acceptance of Quotation No. *
          <textarea name="quotation_acceptance_reference" placeholder="e.g. We accept Quotation SC/QT/2026/MMNNN dated DD Month YYYY" required><?= htmlspecialchars($submission['quotation_acceptance_reference'] ?? '') ?></textarea>
        </label>
        <label>Certificate of Origin Type *
          <input type="text" name="coo_type" value="<?= htmlspecialchars($submission['coo_type'] ?? '') ?>" placeholder="GSP Form A / Non-preferential" required>
        </label>
        <label>Buyer PO / Reference No.
          <input type="text" name="buyer_po_ref" value="<?= htmlspecialchars($submission['buyer_po_ref'] ?? '') ?>" placeholder="Write NIL if none">
        </label>
        <label class="full">Any Changes from Quotation
          <textarea name="changes_from_quotation" placeholder="Write: No changes — or describe any changes to product / quantity / price"><?= htmlspecialchars($submission['changes_from_quotation'] ?? '') ?></textarea>
        </label>
        <label class="full">Special Document Requirements
          <textarea name="special_document_requirements" placeholder="e.g. No special requirements / Consular legalised CI required"><?= htmlspecialchars($submission['special_document_requirements'] ?? '') ?></textarea>
        </label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">4</span> Confirmation</legend>
      <div class="intake-note">
        <strong>Once submitted, these details are locked permanently</strong> — not by you, and not by NexaCrest, through the ordinary course of business. If something needs correcting after this point, a new client record has to be set up from scratch; there is no edit option once locked.
      </div>
      <label><input type="checkbox" name="confirm_lock" value="1" required> I confirm the above is correct and understand it will be locked.</label>
    </fieldset>

    <button type="submit">Submit PI Details</button>
  </form>
</div>
