<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Correct Your Quotation Intake</h1>
  <p class="muted">We haven't reviewed your request yet, so you can still fix anything here. Fields marked * are required.</p>

  <form method="post" action="/quotation-details/edit/<?= htmlspecialchars($token) ?>">
    <?= Csrf::field() ?>

    <fieldset>
      <legend>Your Details</legend>
      <label>Company Legal Name *
        <input type="text" name="company_legal_name" value="<?= htmlspecialchars($submission['company_legal_name']) ?>" required>
      </label>
      <label>Billing Address *
        <textarea name="billing_address" required><?= htmlspecialchars($submission['billing_address']) ?></textarea>
      </label>
      <label>VAT / EORI / Tax Reg. No. *
        <input type="text" name="vat_eori_tax_no" value="<?= htmlspecialchars($submission['vat_eori_tax_no'] ?? '') ?>" required>
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
      <label>Country of Destination *
        <input type="text" name="country_of_destination" value="<?= htmlspecialchars($submission['country_of_destination']) ?>" required>
      </label>
      <label>Port of Discharge
        <input type="text" name="port_of_discharge_text" value="<?= htmlspecialchars($submission['port_of_discharge_text'] ?? '') ?>">
      </label>
      <label>Certificate of Origin Type
        <input type="text" name="coo_type" value="<?= htmlspecialchars($submission['coo_type'] ?? '') ?>">
      </label>
    </fieldset>

    <fieldset>
      <legend>Shipping Preference</legend>
      <label>Incoterm *
        <input type="text" name="incoterm_preference" value="<?= htmlspecialchars($submission['incoterm_preference'] ?? '') ?>" required>
      </label>
      <label>Container Type
        <input type="text" name="container_type_text" value="<?= htmlspecialchars($submission['container_type_text'] ?? '') ?>">
      </label>
      <label>Your Own Reference Number
        <input type="text" name="buyer_own_reference" value="<?= htmlspecialchars($submission['buyer_own_reference'] ?? '') ?>">
      </label>
      <label>Anything else we should know?
        <textarea name="notes"><?= htmlspecialchars($submission['notes'] ?? '') ?></textarea>
      </label>
    </fieldset>

    <button type="submit">Save Correction</button>
  </form>
</div>
