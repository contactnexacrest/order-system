<?php use App\Helpers\Csrf; $locked = (int) $client['is_data_locked'] === 1; ?>
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
    <?php $ro = ($locked && !$isSuperAdmin) ? 'readonly disabled' : ''; ?>
    <fieldset <?= $locked && !$isSuperAdmin ? 'disabled' : '' ?>>
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
    <fieldset <?= $locked && !$isSuperAdmin ? 'disabled' : '' ?>>
      <legend>Contact</legend>
      <label>Contact Person<input type="text" name="contact_person" value="<?= htmlspecialchars($client['contact_person'] ?? '') ?>"></label>
      <label>Email<input type="email" name="email" value="<?= htmlspecialchars($client['email'] ?? '') ?>"></label>
      <label>Phone<input type="text" name="phone" value="<?= htmlspecialchars($client['phone'] ?? '') ?>"></label>
    </fieldset>

    <?php if ($locked && $isSuperAdmin): ?>
      <fieldset>
        <legend>Super Admin Override</legend>
        <label><input type="checkbox" name="override_lock" value="1"> Override the lock for this save (staff data-entry error only)</label>
        <label>Reason (required, min. 10 characters)<input type="text" name="override_reason" style="width:400px"></label>
      </fieldset>
    <?php endif; ?>

    <button type="submit" <?= $locked && !$isSuperAdmin ? 'disabled' : '' ?>>Save Changes</button>
  </form>
</div>
