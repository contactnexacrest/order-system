<?php /** Shared by the associate's bank request and Accounts' entry. $__india: based in India. */ ?>
<div class="field-grid">
  <label>Account holder's name *<?= $__india ? ' <small class="muted">(as on the PAN)</small>' : '' ?><input type="text" name="holder_name" required minlength="2" maxlength="150" placeholder="e.g. Name exactly as on the bank account"></label>
  <label><?= $__india ? 'Account number' : 'IBAN / account number' ?> *<input type="text" name="account_number" required minlength="6" maxlength="40" autocomplete="off" placeholder="e.g. 50100234567890"></label>
  <label>Re-enter the <?= $__india ? 'account number' : 'IBAN / account number' ?> *<input type="text" name="account_number_confirm" required minlength="6" maxlength="40" autocomplete="off" data-match="account_number" placeholder="Type the account number again"></label>
  <?php if ($__india): ?>
    <label>IFSC *<input type="text" name="ifsc" required pattern="[A-Za-z]{4}0[A-Za-z0-9]{6}" maxlength="11" title="11 characters, e.g. HDFC0001234" placeholder="e.g. HDFC0001234"></label>
    <label>SWIFT / BIC<input type="text" name="swift" maxlength="11" pattern="[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?" placeholder="e.g. HDFCINBB"></label>
  <?php else: ?>
    <label>SWIFT / BIC *<input type="text" name="swift" required maxlength="11" pattern="[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?" title="8 or 11 characters" placeholder="e.g. HDFCINBB"></label>
  <?php endif; ?>
  <label>Bank name *<input type="text" name="bank_name" required minlength="2" maxlength="150" placeholder="e.g. HDFC Bank"></label>
  <label>Bank / branch address<?= $__india ? '' : ' *' ?><input type="text" name="bank_branch_address" maxlength="300"<?= $__india ? '' : ' required minlength="5"' ?> placeholder="e.g. 1 Fort Street, Mumbai 400001"></label>
  <?php if (!$__india): ?><label>Intermediary bank (if any)<input type="text" name="intermediary_bank" maxlength="300" placeholder="e.g. Citibank N.A., New York (CITIUS33)"></label><?php endif; ?>
  <label>Account currency *<input type="text" name="account_currency" required pattern="[A-Za-z]{3}" maxlength="3" value="<?= $__india ? 'INR' : '' ?>" placeholder="e.g. INR"></label>
  <label><?= $__india ? 'Cancelled cheque or bank letter' : 'Bank letter confirming the account' ?> (PDF or image) *<input type="file" name="proof_file" required accept=".pdf,.jpg,.jpeg,.png"></label>
</div>
