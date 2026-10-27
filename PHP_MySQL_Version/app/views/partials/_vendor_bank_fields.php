<?php
// Owner request (2026-10-27): bank details to pay a supplier / logistics
// partner — filled by staff (e.g. from the vendor's email) or by the vendor
// through the Vendor Details Link. Expects $bank (VendorDetailsRepository::bankDetails()).
use App\Helpers\BankDetailsInput;
use App\Repositories\VendorDetailsRepository;

$__b = $bank ?? VendorDetailsRepository::bankDetails(null);
$__bv = static fn(string $k): string => htmlspecialchars((string) ($__b[$k] ?? ''));
?>
    <fieldset id="bank-details">
      <legend>Bank Details <small class="muted">(to pay them)</small></legend>
      <?php if ($__b['has_bank']): ?>
        <p class="small">
          On file: <strong>A/c <?= htmlspecialchars(VendorDetailsRepository::maskAccount($__b['bank_account_number'])) ?></strong>
          — entered by <?= $__b['bank_updated_source'] === 'vendor' ? 'them through the details link' : 'staff' ?><?= $__b['bank_updated_at'] ? ' on ' . htmlspecialchars((string) $__b['bank_updated_at']) : '' ?>.
          <?php if ($__b['bank_verified_at']): ?>
            <span class="badge good">&#10003; Verified <?= htmlspecialchars((string) $__b['bank_verified_at']) ?></span>
          <?php else: ?>
            <span class="badge warn">&#9888; Not verified — check against a cancelled cheque or bank letter before paying</span>
          <?php endif; ?>
        </p>
        <details class="small"><summary>Show full account number</summary><code><?= htmlspecialchars((string) $__b['bank_account_number']) ?></code></details>
      <?php else: ?>
        <p class="muted small">No bank details yet — fill them in here, or send the Vendor Details Link so they fill them in themselves.</p>
      <?php endif; ?>
      <div class="field-grid">
        <label>Account Holder Name<input type="text" name="bank_account_name" value="<?= $__bv('bank_account_name') ?>" placeholder="As printed on the cheque"></label>
        <label>Bank Name<input type="text" name="bank_name" value="<?= $__bv('bank_name') ?>" placeholder="e.g., State Bank of India"></label>
        <label>Branch<input type="text" name="bank_branch" value="<?= $__bv('bank_branch') ?>" placeholder="e.g., Udaipur Main"></label>
        <label>IFSC Code<input type="text" name="bank_ifsc" value="<?= $__bv('bank_ifsc') ?>" placeholder="e.g., SBIN0001234" maxlength="11" style="text-transform:uppercase"></label>
        <label>Account Number<input type="text" name="bank_account_number" data-bank-on-file="<?= $__b['has_bank'] ? '1' : '0' ?>" inputmode="numeric" autocomplete="off" placeholder="<?= $__b['has_bank'] ? 'Leave blank to keep ' . htmlspecialchars(VendorDetailsRepository::maskAccount($__b['bank_account_number'])) : 'Digits only' ?>"></label>
        <label>Re-enter Account Number<input type="text" name="bank_account_number_confirm" inputmode="numeric" autocomplete="off" placeholder="Type it again to confirm"></label>
        <label>Account Type
          <select name="bank_account_type">
            <option value="">—</option>
            <?php foreach (BankDetailsInput::ACCOUNT_TYPES as $__t): ?>
              <option value="<?= htmlspecialchars($__t) ?>"<?= ($__b['bank_account_type'] ?? '') === $__t ? ' selected' : '' ?>><?= htmlspecialchars($__t) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>UPI ID <small class="muted">(optional)</small><input type="text" name="bank_upi_id" value="<?= $__bv('bank_upi_id') ?>" placeholder="e.g., name@sbi"></label>
        <label class="checkbox-row full" style="font-weight:normal;"><input type="checkbox" name="bank_verified" value="1"> I have verified these bank details (cancelled cheque / bank letter)</label>
      </div>
    </fieldset>
