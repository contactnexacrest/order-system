<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Edit Client</h1>
  <p class="muted small"><a href="/clients/<?= (int) $client['id'] ?>">&larr; Back to Client</a></p>
  <p class="muted">Buyer Inquiry Ref: <strong><?= htmlspecialchars($client['client_unique_number']) ?></strong> — changed only via the Admin Override on the client's page, not here.</p>
  <form method="post" action="/clients/<?= (int) $client['id'] ?>/update">
    <?= Csrf::field() ?>
    <fieldset>
      <legend>Buyer / Consignee</legend>
      <label>Company Legal Name *<input type="text" name="company_legal_name" value="<?= htmlspecialchars($client['company_legal_name']) ?>" required></label>
      <label>Billing Address *<textarea name="billing_address" rows="2" required><?= htmlspecialchars($client['billing_address']) ?></textarea></label>
      <label>Consignee Name <small class="muted">(leave blank if same as buyer)</small><input type="text" name="consignee_name" value="<?= htmlspecialchars($client['consignee_name'] ?? '') ?>"></label>
      <label>Consignee Address <small class="muted">(leave blank if same as buyer)</small><textarea name="consignee_address" rows="2"><?= htmlspecialchars($client['consignee_address'] ?? '') ?></textarea></label>
      <label>VAT / EORI / Tax Reg. No.<input type="text" name="vat_eori_tax_no" value="<?= htmlspecialchars($client['vat_eori_tax_no'] ?? '') ?>"></label>
      <label>Country of Destination<input type="text" name="country_of_destination" value="<?= htmlspecialchars($client['country_of_destination'] ?? '') ?>"></label>
      <label>Certificate of Origin Type<input type="text" name="coo_type" value="<?= htmlspecialchars($client['coo_type'] ?? '') ?>" placeholder="e.g. Non-Preferential"></label>
      <label>Notify Party <small class="muted">(leave blank for "SAME as buyer")</small><input type="text" name="notify_party" value="<?= htmlspecialchars($client['notify_party'] ?? '') ?>"></label>
    </fieldset>
    <fieldset>
      <legend>Contact</legend>
      <label>Contact Person<input type="text" name="contact_person" value="<?= htmlspecialchars($client['contact_person'] ?? '') ?>"></label>
      <label>Email<input type="email" name="email" value="<?= htmlspecialchars($client['email'] ?? '') ?>"></label>
      <label>Phone<input type="text" name="phone" value="<?= htmlspecialchars($client['phone'] ?? '') ?>"></label>
    </fieldset>
    <button type="submit">Save Changes</button>
  </form>
</div>
