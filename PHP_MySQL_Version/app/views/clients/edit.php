<?php use App\Helpers\Csrf; $locked = (int) $client['is_data_locked'] === 1; $cooTypes = $cooTypes ?? []; ?>
<div class="card">
  <h1>Edit Client</h1>
  <p class="muted small"><a href="/clients/<?= (int) $client['id'] ?>">&larr; Back to Client</a></p>
  <p class="muted">Buyer Inquiry Ref: <strong><?= htmlspecialchars($client['client_unique_number']) ?></strong> — changed only via the Admin Override on the client's page, not here.</p>

  <?php if ($locked): ?>
    <div class="review-banner bad" style="margin-bottom:14px">
      🔒 <span><strong>This client's details are locked, permanently.</strong> Locked <?= htmlspecialchars((string) $client['data_locked_at']) ?> — <?= htmlspecialchars($client['data_locked_reason'] ?? '') ?>. These fields can never be edited again through the normal form; if something genuinely needs to change, the correct path is a brand-new client record, not an edit here.</span>
    </div>
    <?php if (!$isSuperAdmin): ?>
      <p class="muted small">All fields below are read-only.</p>
    <?php else: ?>
      <div class="review-banner warn" style="margin-bottom:14px">
        ⚠ <span><strong>Super Admin override available</strong> — for a genuine staff data-entry mistake only, never a client-requested change. Check the box below and give a specific reason to make an edit anyway; it will be fully logged.</span>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="review-banner warn" style="margin-bottom:14px">
      ℹ <span>Once this client confirms their details (PI-details form) or an order's advance payment is recorded, whichever happens first, these details lock permanently — no further edits, by anyone but a Super Admin fixing a genuine mistake. Get everything right before that point.</span>
    </div>
  <?php endif; ?>

  <form method="post" action="/clients/<?= (int) $client['id'] ?>/update">
    <?= Csrf::field() ?>
    <?php $fieldsetDisabled = $locked && !$isSuperAdmin; ?>
    <fieldset <?= $fieldsetDisabled ? 'disabled' : '' ?>>
      <legend>Buyer Details</legend>
      <div class="field-grid">
        <label class="full">Company Legal Name *
          <input type="text" name="company_legal_name" value="<?= htmlspecialchars($client['company_legal_name']) ?>" placeholder="e.g., Test Company Ltd" required>
        </label>
        <label class="full">Billing Address *
          <textarea name="billing_address" rows="2" placeholder="e.g., 123 Example Street, Test City, Country" required><?= htmlspecialchars($client['billing_address']) ?></textarea>
        </label>
        <label>Billing Address Line 1
          <input type="text" name="billing_address_line1" value="<?= htmlspecialchars($client['billing_address_line1'] ?? '') ?>" placeholder="Street number and street name">
        </label>
        <label>Billing Address Line 2
          <input type="text" name="billing_address_line2" value="<?= htmlspecialchars($client['billing_address_line2'] ?? '') ?>" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town
          <input type="text" name="billing_city" value="<?= htmlspecialchars($client['billing_city'] ?? '') ?>" placeholder="e.g., Birmingham">
        </label>
        <label>Postcode
          <input type="text" name="billing_postcode" value="<?= htmlspecialchars($client['billing_postcode'] ?? '') ?>" placeholder="e.g., B1 1AA">
        </label>
        <label>Billing Country
          <input type="text" name="billing_country" value="<?= htmlspecialchars($client['billing_country'] ?? '') ?>" placeholder="e.g., United Kingdom">
        </label>
        <label>VAT / EORI / Tax Reg. No.
          <input type="text" name="vat_eori_tax_no" value="<?= htmlspecialchars($client['vat_eori_tax_no'] ?? '') ?>" placeholder="UK: EORI No. | Others: Tax Reg. No.">
        </label>
        <label>Country of Destination
          <input type="text" name="country_of_destination" value="<?= htmlspecialchars($client['country_of_destination'] ?? '') ?>" placeholder="e.g., Hungary / UK / France">
        </label>
        <label>Certificate of Origin Type
          <select name="coo_type">
            <option value="">— To Be Confirmed —</option>
            <?php foreach ($cooTypes as $o): ?>
              <option value="<?= htmlspecialchars($o['option_value']) ?>" <?= (($client['coo_type'] ?? '') === $o['option_value']) ? 'selected' : '' ?>><?= htmlspecialchars($o['option_value']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </fieldset>

    <fieldset <?= $fieldsetDisabled ? 'disabled' : '' ?>>
      <legend>Consignee Details</legend>
      <?php $consigneeSame = (int) ($client['consignee_same_as_buyer'] ?? 1) === 1; ?>
      <label><input type="checkbox" id="consignee_same_as_buyer" name="consignee_same_as_buyer" value="1" <?= $consigneeSame ? 'checked' : '' ?>> Same as Buyer</label>
      <div id="consignee_fields" class="field-grid" style="display:none">
        <label class="full">Consignee Company Name
          <input type="text" name="consignee_name" value="<?= htmlspecialchars($client['consignee_name'] ?? '') ?>" placeholder="e.g., ABC Memorial Stones Ltd">
        </label>
        <label class="full">Address Line 1
          <input type="text" name="consignee_address_line1" value="<?= htmlspecialchars($client['consignee_address_line1'] ?? '') ?>" placeholder="Street number and street name">
        </label>
        <label>Address Line 2
          <input type="text" name="consignee_address_line2" value="<?= htmlspecialchars($client['consignee_address_line2'] ?? '') ?>" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town
          <input type="text" name="consignee_city" value="<?= htmlspecialchars($client['consignee_city'] ?? '') ?>" placeholder="e.g., Rotterdam">
        </label>
        <label>Postcode
          <input type="text" name="consignee_postcode" value="<?= htmlspecialchars($client['consignee_postcode'] ?? '') ?>" placeholder="e.g., 3011 AD">
        </label>
        <label>Country
          <input type="text" name="consignee_country" value="<?= htmlspecialchars($client['consignee_country'] ?? '') ?>" placeholder="e.g., Netherlands">
        </label>
        <label>VAT / EORI / Tax Reg. No.
          <input type="text" name="consignee_vat_eori_tax_no" value="<?= htmlspecialchars($client['consignee_vat_eori_tax_no'] ?? '') ?>" placeholder="UK: EORI No. | Others: Tax Reg. No.">
        </label>
        <label>Contact Person
          <input type="text" name="consignee_contact_person" value="<?= htmlspecialchars($client['consignee_contact_person'] ?? '') ?>" placeholder="e.g., Jane Smith">
        </label>
        <label>Phone
          <input type="text" name="consignee_phone" value="<?= htmlspecialchars($client['consignee_phone'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>Email
          <input type="email" name="consignee_email" value="<?= htmlspecialchars($client['consignee_email'] ?? '') ?>" placeholder="e.g., name@example.com">
        </label>
      </div>
    </fieldset>

    <fieldset <?= $fieldsetDisabled ? 'disabled' : '' ?>>
      <legend>Notify Party</legend>
      <?php $notifySame = (int) ($client['notify_party_same_as_consignee'] ?? 1) === 1; ?>
      <label><input type="checkbox" id="notify_party_same_as_consignee" name="notify_party_same_as_consignee" value="1" <?= $notifySame ? 'checked' : '' ?>> Same as Consignee</label>
      <div id="notify_party_fields" class="field-grid" style="display:none">
        <label class="full">Notify Party Name
          <input type="text" name="notify_party" value="<?= htmlspecialchars($client['notify_party'] ?? '') ?>" placeholder="e.g., ABC Freight Forwarders Ltd">
        </label>
        <label class="full">Address Line 1
          <input type="text" name="notify_party_address_line1" value="<?= htmlspecialchars($client['notify_party_address_line1'] ?? '') ?>" placeholder="Street number and street name">
        </label>
        <label>Address Line 2
          <input type="text" name="notify_party_address_line2" value="<?= htmlspecialchars($client['notify_party_address_line2'] ?? '') ?>" placeholder="Area / district — if applicable">
        </label>
        <label>City / Town
          <input type="text" name="notify_party_city" value="<?= htmlspecialchars($client['notify_party_city'] ?? '') ?>" placeholder="e.g., Rotterdam">
        </label>
        <label>Postcode
          <input type="text" name="notify_party_postcode" value="<?= htmlspecialchars($client['notify_party_postcode'] ?? '') ?>" placeholder="e.g., 3011 AD">
        </label>
        <label>Country
          <input type="text" name="notify_party_country" value="<?= htmlspecialchars($client['notify_party_country'] ?? '') ?>" placeholder="e.g., Netherlands">
        </label>
        <label>Contact Person
          <input type="text" name="notify_party_contact_person" value="<?= htmlspecialchars($client['notify_party_contact_person'] ?? '') ?>" placeholder="e.g., Jane Smith">
        </label>
        <label>Phone
          <input type="text" name="notify_party_phone" value="<?= htmlspecialchars($client['notify_party_phone'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
        </label>
        <label>Email
          <input type="email" name="notify_party_email" value="<?= htmlspecialchars($client['notify_party_email'] ?? '') ?>" placeholder="e.g., name@example.com">
        </label>
      </div>
    </fieldset>

    <fieldset <?= $fieldsetDisabled ? 'disabled' : '' ?>>
      <legend>Contact</legend>
      <div class="field-grid">
        <label>Contact Person
          <input type="text" name="contact_person" value="<?= htmlspecialchars($client['contact_person'] ?? '') ?>" placeholder="e.g., John Doe">
        </label>
        <label>Email
          <input type="email" name="email" value="<?= htmlspecialchars($client['email'] ?? '') ?>" placeholder="e.g., name@example.com">
        </label>
        <label>Phone
          <input type="text" name="phone" value="<?= htmlspecialchars($client['phone'] ?? '') ?>" placeholder="e.g., +1 555 123 4567">
        </label>
      </div>
    </fieldset>

    <?php if ($locked && $isSuperAdmin): ?>
      <fieldset>
        <legend>Super Admin Override</legend>
        <label><input type="checkbox" name="override_lock" value="1"> Override the lock for this save (staff data-entry error only)</label>
        <label>Reason (required, min. 10 characters)
          <input type="text" name="override_reason" style="width:400px" placeholder="e.g., Staff mistyped postcode during intake — correcting to match buyer's PO">
        </label>
      </fieldset>
    <?php endif; ?>

    <button type="submit" <?= $locked && !$isSuperAdmin ? 'disabled' : '' ?>>Save Changes</button>
  </form>

  <!-- Batch 3 #12 — its own form/endpoint, deliberately separate from the form above:
       this is a staff annotation of an external agreement, not a client-submitted
       detail, so it stays editable even when the client's details are locked. -->
  <form method="post" action="/clients/<?= (int) $client['id'] ?>/agreement-footer">
    <?= Csrf::field() ?>
    <fieldset>
      <legend>Agreement</legend>
      <label>T&amp;C Footer <small class="muted">(a clause specific to this client's own commercial agreement — shown as an extra note on every document generated for them, in addition to the standard terms. Never locked by the data lock above, since it's a staff annotation of an external agreement, not a client-submitted detail.)</small>
        <textarea name="agreement_footer_text" rows="4" placeholder="e.g. Pre-shipment inspection by buyer's nominated agent is permitted at supplier's premises, by prior appointment."><?= htmlspecialchars($client['agreement_footer_text'] ?? '') ?></textarea>
      </label>
    </fieldset>
    <button type="submit">Save Agreement Footer</button>
  </form>

  <!-- Item 2 — the actual signed agreement file + expiry date, tracked separately
       from the footer text above (which still prints independently of this). -->
  <form method="post" action="/clients/<?= (int) $client['id'] ?>/agreement/upload" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <fieldset>
      <legend>Agreement File &amp; Expiry</legend>
      <?php if (!empty($client['agreement_file_path'])): ?>
        <p class="muted small">Current file: <a href="/clients/<?= (int) $client['id'] ?>/agreement/download"><?= htmlspecialchars($client['agreement_file_original_name'] ?? 'agreement file') ?></a> — uploaded <?= htmlspecialchars((string) ($client['agreement_uploaded_at'] ?? '')) ?>. Uploading a new file below replaces it and resets Force Expire.</p>
      <?php else: ?>
        <p class="muted small">No agreement file uploaded yet. Upload one below — the expiry date controls how long the T&amp;C footer above keeps printing on this client's documents.</p>
      <?php endif; ?>
      <div class="field-grid">
        <label>Agreement File (PDF/DOC/DOCX)
          <input type="file" name="agreement_file" accept=".pdf,.doc,.docx">
        </label>
        <label>Expiry Date <small class="muted">(leave blank for no automatic expiry — only Force Expire will end it then)</small>
          <input type="date" name="agreement_expiry_date" value="<?= htmlspecialchars((string) ($client['agreement_expiry_date'] ?? '')) ?>">
        </label>
      </div>
    </fieldset>
    <button type="submit">Upload Agreement File</button>
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
