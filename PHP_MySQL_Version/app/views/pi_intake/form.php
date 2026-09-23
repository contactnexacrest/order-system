<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Proforma Invoice (PI) Form</h1>
  <p class="muted">Order <?= htmlspecialchars($submission['order_reference']) ?> — <?= htmlspecialchars($submission['client_company_legal_name']) ?></p>
  <p class="muted">Complete after accepting the Quotation — we will issue your PI within 24 hours. Fields marked * are required. Confirm your details exactly as they appear on official documents.</p>

  <form method="post" action="/pi-details/<?= htmlspecialchars($token) ?>">
    <?= Csrf::field() ?>

    <fieldset>
      <legend>Confirm Your Details (must match exactly as they appear on official documents)</legend>
      <label>Company Legal Name *
        <input type="text" name="company_legal_name" value="<?= htmlspecialchars($submission['company_legal_name'] ?? '') ?>" placeholder="Exact legal name, no abbreviations" required>
      </label>
      <label>Billing Address *
        <textarea name="billing_address" placeholder="Full address including postcode — must match PI and BL exactly" required><?= htmlspecialchars($submission['billing_address'] ?? '') ?></textarea>
      </label>
      <label>Consignee Name *
        <input type="text" name="consignee_name" value="<?= htmlspecialchars($submission['consignee_name'] ?? '') ?>" placeholder="Write SAME if same as Company Legal Name — appears on Bill of Lading" required>
      </label>
      <label>Consignee Address *
        <textarea name="consignee_address" placeholder="Write SAME if same as Billing Address — appears on Bill of Lading" required><?= htmlspecialchars($submission['consignee_address'] ?? '') ?></textarea>
      </label>
      <label>VAT / EORI / Tax Reg. No. *
        <input type="text" name="vat_eori_tax_no" value="<?= htmlspecialchars($submission['vat_eori_tax_no'] ?? '') ?>" required>
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
      <label>Notify Party
        <input type="text" name="notify_party" value="<?= htmlspecialchars($submission['notify_party'] ?? '') ?>" placeholder="Your freight forwarder or customs agent — write SAME or NIL">
      </label>
    </fieldset>

    <fieldset>
      <legend>Shipping Details</legend>
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
        <input type="text" name="container_type_text" value="<?= htmlspecialchars($submission['container_type_text'] ?? '') ?>" placeholder="Leave blank if same as quotation">
      </label>
    </fieldset>

    <fieldset>
      <legend>Payment &amp; Order Confirmation</legend>
      <label>Payment Terms Confirmation *
        <textarea name="payment_terms_confirmation" placeholder="e.g. CONFIRMED — 30% advance T/T + 70% balance against scanned BL copy within 7 days" required><?= htmlspecialchars($submission['payment_terms_confirmation'] ?? '') ?></textarea>
      </label>
      <label>Acceptance of Quotation No. *
        <textarea name="quotation_acceptance_reference" placeholder="e.g. We accept Quotation SC/QT/2026/MMNNN dated DD Month YYYY" required><?= htmlspecialchars($submission['quotation_acceptance_reference'] ?? '') ?></textarea>
      </label>
      <label>Certificate of Origin Type *
        <input type="text" name="coo_type" value="<?= htmlspecialchars($submission['coo_type'] ?? '') ?>" placeholder="GSP Form A (preferential) / Non-preferential" required>
      </label>
      <label>Buyer PO / Reference No.
        <input type="text" name="buyer_po_ref" value="<?= htmlspecialchars($submission['buyer_po_ref'] ?? '') ?>" placeholder="Write NIL if none">
      </label>
      <label>Any Changes from Quotation
        <textarea name="changes_from_quotation" placeholder="Write: No changes — or describe any changes to product / quantity / price"><?= htmlspecialchars($submission['changes_from_quotation'] ?? '') ?></textarea>
      </label>
      <label>Special Document Requirements
        <textarea name="special_document_requirements" placeholder="e.g. No special requirements / Consular legalised CI required"><?= htmlspecialchars($submission['special_document_requirements'] ?? '') ?></textarea>
      </label>
    </fieldset>

    <button type="submit">Submit PI Details</button>
  </form>
</div>
