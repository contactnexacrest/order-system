<?php
/**
 * Owner decisions 11 Nov 2026, point 18 (D-294): a supplier's / logistics partner's bank details changes —
 * waiting for their confirmation (link to the registered e-mail; "Send the link again"), then verified by
 * one person other than the one who typed them (at least 2 checks; MD/ED may verify their own entry with
 * a reason), then in use after the waiting days. Outside the edit form (its own forms).
 * Expects $vendorKind ('supplier' / 'logistics_partner'), $vendorId. Node twin: _vendor_bank_changes.njk.
 */
use App\Helpers\Csrf;
use App\Repositories\VendorBankChangeRepository;
use App\Services\BankDetailsCheck;
use App\Services\VendorBankChangeService;

VendorBankChangeService::applyDue($vendorKind, (int) $vendorId);
$__changes = VendorBankChangeRepository::forVendor($vendorKind, (int) $vendorId);
$__base = $vendorKind === 'supplier' ? '/suppliers/' : '/logistics-partners/';
$__two = BankDetailsCheck::people() === 'two';
$__u = (int) \App\Services\AuthService::currentUserId();
$__maySelf = BankDetailsCheck::maySelfVerify($__u);
?>
<?php if ($__changes !== []): ?>
<div class="section" id="bank-changes">
  <h2>Bank details changes</h2>
  <table class="list">
    <tr><th>Entered</th><th>New account</th><th>Status</th><th>Checks</th><th>Action</th></tr>
    <?php foreach ($__changes as $__c): $__own = (int) ($__c['entered_by'] ?? 0) === $__u && $__u > 0; $__act = $__base . (int) $vendorId . '/bank-changes/' . (int) $__c['id']; ?>
      <tr>
        <td><?= htmlspecialchars(\App\Helpers\Dates::short(substr((string) $__c['entered_at'], 0, 10))) ?><br><small class="muted"><?= $__c['entered_source'] === 'vendor' ? 'by them (details link)' : 'by ' . htmlspecialchars((string) ($__c['entered_by_name'] ?? 'staff')) ?></small></td>
        <td><?= htmlspecialchars(VendorBankChangeService::name($__c)) ?><br><small class="muted"><?= htmlspecialchars(trim((string) $__c['bank_account_name'] . ' ' . (string) $__c['bank_ifsc'])) ?></small></td>
        <td data-bank-status><?= htmlspecialchars(VendorBankChangeService::statusText($__c)) ?></td>
        <td><small><?= htmlspecialchars(BankDetailsCheck::checksText($__c)) ?><?= $__c['first_checked_by_name'] ? '<br>1: ' . htmlspecialchars((string) $__c['first_checked_by_name']) : '' ?><?= $__c['verified_by_name'] ? '<br>Verified by ' . htmlspecialchars((string) $__c['verified_by_name']) : '' ?><?= !empty($__c['verify_note']) ? '<br>' . htmlspecialchars((string) $__c['verify_note']) : '' ?><?= !empty($__c['self_verify_reason']) ? '<br>Own entry — reason: ' . htmlspecialchars((string) $__c['self_verify_reason']) : '' ?></small></td>
        <td>
        <?php if ($__c['status'] === 'awaiting_confirmation'): ?>
          <small class="muted">Verify is available once they confirm.</small>
          <form method="post" action="<?= $__act ?>/resend" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Send the link again</button></form>
        <?php elseif ($__c['status'] === 'pending'): ?>
          <?php if ($__own && !$__maySelf): ?>
            <small class="muted">Someone else must verify details you entered.</small>
          <?php elseif ($__two && $__c['first_checked_by'] && (int) $__c['first_checked_by'] === $__u): ?>
            <small class="muted">A different person verifies — you did the first check.</small>
          <?php elseif ($__two && $__c['first_checked_by']): ?>
            <form method="post" action="<?= $__act ?>/verify" class="inline-form"><?= Csrf::field() ?>
              <?php if ($__own): ?><input type="text" name="self_reason" required minlength="10" maxlength="300" placeholder="e.g. Only finance person is on leave until 20 Nov" aria-label="Why you verify details you entered yourself"><?php endif; ?>
              <button type="submit" class="btn-sm">Verify (second person)</button></form>
          <?php else: ?>
            <form method="post" action="<?= $__act ?>/<?= $__two ? 'check' : 'verify' ?>">
              <?= Csrf::field() ?>
              <fieldset><legend>How did you check it? <small class="muted">(tick at least two)</small></legend>
                <label class="checkbox-label"><input type="checkbox" name="check_bank_proof" value="1" data-min-ticked="check_bank_proof,check_phone,check_test_payment:2" data-min-ticked-message="Tick at least two of the ways you checked the bank details."> Matches the bank proof (cancelled cheque / bank letter)</label>
                <label class="checkbox-label"><input type="checkbox" name="check_phone" value="1"> Called them on their registered phone number and they confirmed the last 4 digits</label>
                <label class="checkbox-label"><input type="checkbox" name="check_test_payment" value="1"> Test payment of ₹1 received in the right name</label>
                <label>Bank reference of the test payment (UTR)<input type="text" name="test_payment_ref" minlength="3" maxlength="60" placeholder="e.g. UTR 412345678901" data-required-when="check_test_payment=1" data-required-when-message="Enter the bank reference (UTR) of the test payment."></label>
                <label>Note (optional)<input type="text" name="verify_note" maxlength="300" placeholder="e.g. Spoke to their accounts on 11 Nov at 3 pm"></label>
                <?php if ($__own): ?><label>You entered these details yourself — give the reason you verify them *<input type="text" name="self_reason" required minlength="10" maxlength="300" placeholder="e.g. Only finance person is on leave until 20 Nov"></label><?php endif; ?>
              </fieldset>
              <button type="submit" class="btn-sm"><?= $__two ? 'First check' : 'Verify' ?></button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (in_array($__c['status'], ['awaiting_confirmation', 'pending'], true)): ?>
          <form method="post" action="<?= $__act ?>/reject" class="inline-form"><?= Csrf::field() ?><input type="text" name="reason" required minlength="10" maxlength="300" placeholder="Reason for rejecting" aria-label="Reason for rejecting"><button type="submit" class="btn-sm btn-secondary">Reject</button></form>
        <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
