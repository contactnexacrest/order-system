<?php
use App\Helpers\BankDetailsInput;
use App\Helpers\Csrf;
use App\Repositories\VendorDetailsRepository;

// Owner request (2026-10-27): the vendor fills in their own company and
// bank details. Laid out like the QT / PI details forms.
$v = static fn(string $k): string => htmlspecialchars((string) ($vendor[$k] ?? ''));
$bv = static fn(string $k): string => htmlspecialchars((string) ($bank[$k] ?? ''));
?>
<div class="card">
  <h1>Your Company &amp; Bank Details</h1>
  <p class="muted">For <strong><?= htmlspecialchars((string) $vendor[$meta['name']]) ?></strong> — no login needed. NexaCrest uses these details to pay you. Please check every field and correct anything that is out of date.</p>

  <form method="post" action="/vendor-details/<?= htmlspecialchars($token) ?>">
    <?= Csrf::field() ?>
    <fieldset class="intake-step">
      <legend><span class="intake-step-num">1</span> Company Details</legend>
      <div class="field-grid">
        <label class="full">Address<input type="text" name="address" value="<?= $v('address') ?>"></label>
        <label>City<input type="text" name="city" value="<?= $v('city') ?>"></label>
        <label>State<input type="text" name="state" value="<?= $v('state') ?>"></label>
        <label>Phone<input type="text" name="phone" value="<?= $v('phone') ?>"></label>
        <label>WhatsApp Number<input type="text" name="whatsapp_number" value="<?= $v('whatsapp_number') ?>"></label>
        <label>Email<input type="email" name="email" value="<?= $v('email') ?>"></label>
        <label>Contact Person — Name<input type="text" name="<?= htmlspecialchars($meta['contact']) ?>" value="<?= $v($meta['contact']) ?>"></label>
        <label>Contact Person — Phone<input type="text" name="contact_person_phone" value="<?= $v('contact_person_phone') ?>"></label>
        <label>Contact Person — WhatsApp<input type="text" name="contact_person_whatsapp" value="<?= $v('contact_person_whatsapp') ?>"></label>
        <label>GSTIN<input type="text" name="gstin" value="<?= $v('gstin') ?>"></label>
        <label>PAN<input type="text" name="pan" value="<?= $v('pan') ?>"></label>
      </div>
    </fieldset>

    <fieldset class="intake-step">
      <legend><span class="intake-step-num">2</span> Bank Details <small class="muted">(where we pay you)</small></legend>
      <?php if ($bank['has_bank']): ?>
        <p class="small">We have an account ending <strong><?= htmlspecialchars(substr((string) $bank['bank_account_number'], -4)) ?></strong> on file. Leave the account number empty to keep it, or type a new one twice to change it.</p>
      <?php endif; ?>
      <div class="field-grid">
        <label>Account Holder Name *<input type="text" name="bank_account_name" required value="<?= $bv('bank_account_name') ?>" placeholder="As printed on your cheque"></label>
        <label>Bank Name *<input type="text" name="bank_name" required value="<?= $bv('bank_name') ?>"></label>
        <label>Branch<input type="text" name="bank_branch" value="<?= $bv('bank_branch') ?>"></label>
        <label>IFSC Code *<input type="text" name="bank_ifsc" required value="<?= $bv('bank_ifsc') ?>" maxlength="11" placeholder="e.g., SBIN0001234" style="text-transform:uppercase"></label>
        <label>Account Number<?= $bank['has_bank'] ? '' : ' *' ?><input type="text" name="bank_account_number" data-bank-on-file="<?= $bank['has_bank'] ? '1' : '0' ?>" inputmode="numeric" autocomplete="off"<?= $bank['has_bank'] ? '' : ' required' ?>></label>
        <label>Re-enter Account Number<?= $bank['has_bank'] ? '' : ' *' ?><input type="text" name="bank_account_number_confirm" inputmode="numeric" autocomplete="off"<?= $bank['has_bank'] ? '' : ' required' ?>></label>
        <label>Account Type
          <select name="bank_account_type">
            <option value="">—</option>
            <?php foreach (BankDetailsInput::ACCOUNT_TYPES as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"<?= ($bank['bank_account_type'] ?? '') === $t ? ' selected' : '' ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>UPI ID <small class="muted">(optional)</small><input type="text" name="bank_upi_id" value="<?= $bv('bank_upi_id') ?>"></label>
      </div>
      <p class="muted small">Our accounts team verifies the account before the first payment and may ask for a cancelled cheque or a bank letter.</p>
    </fieldset>
    <button type="submit">Submit My Details</button>
  </form>
  <p class="muted small">This link works once. Questions? Reply to the email this link came with.</p>
</div>
