<?php
/**
 * Owner decisions 11 Nov 2026, points 10 and 18 (D-293): the associate's bank details on their staff page.
 * New details: entered → the associate confirms (portal, e-mailed code, 7 days) → one person other than
 * the enterer verifies ("how did you check it?", at least 2 ticks; MD/ED may verify their own entry with a
 * reason — Admin › Self-approval rules › Bank details) → used after the waiting days.
 * Expects $a, $id, $banks, $usableBank, $canBank, $me, $__d. Node twin: views/associates/_bank_section.njk.
 */
use App\Helpers\Csrf;
use App\Repositories\AssociateBankRepository;
use App\Services\AssociateBankConfirmation;
use App\Services\BankDetailsCheck;

$__two = BankDetailsCheck::people() === 'two';
$__uid = (int) ($me['id'] ?? 0);
$__maySelf = BankDetailsCheck::maySelfVerify($__uid);
?>
  <div class="section" id="bank">
    <h2>Bank details</h2>
    <p>Payments go to: <b><?= $usableBank ? htmlspecialchars(AssociateBankRepository::masked($usableBank)) . ' (verified)' : 'no verified account yet' ?></b></p>
    <table class="list">
      <tr><th>Requested</th><th>Account</th><th>Holder</th><th>IFSC / SWIFT</th><th>Status</th><th>Checks</th><th>Action</th></tr>
      <?php foreach ($banks as $b): $__own = (int) ($b['requested_by_user'] ?? 0) === $__uid && $__uid > 0; ?>
        <tr><td><?= $__d($b['requested_at']) ?><br><small class="muted"><?= (int) $b['requested_by_associate'] === 1 ? 'by the associate' : 'by ' . htmlspecialchars((string) ($b['requested_by_name'] ?? 'NexaCrest')) ?></small></td>
          <td><?= htmlspecialchars(AssociateBankRepository::masked($b)) ?> <small class="muted"><?= htmlspecialchars((string) $b['account_currency']) ?></small><?php if ($canBank): ?><br><a class="small" href="/associates/<?= $id ?>/bank/<?= (int) $b['id'] ?>/number">Show full number</a><?php endif; ?></td>
          <td><?= htmlspecialchars((string) $b['holder_name']) ?></td><td><?= htmlspecialchars(trim((string) $b['ifsc'] . ' ' . (string) $b['swift'])) ?></td>
          <td data-bank-status><?= htmlspecialchars(AssociateBankConfirmation::staffStatus($b)) ?><?= $b['rejected_reason'] ? '<br><small>' . htmlspecialchars((string) $b['rejected_reason']) . '</small>' : '' ?></td>
          <td><small><?= $b['first_checked_by_name'] ? ($__two ? '1: ' : '') . htmlspecialchars((string) $b['first_checked_by_name']) . ' — ' . htmlspecialchars((string) $b['check_method']) : '' ?>
            <?= $b['verified_by_name'] ? '<br>' . ($__two ? '2: ' : 'Verified by ') . htmlspecialchars((string) $b['verified_by_name']) . (!$__two && BankDetailsCheck::checksText($b) !== '' ? ' — ' . htmlspecialchars(BankDetailsCheck::checksText($b)) : '') : '' ?>
            <?= !empty($b['verify_note']) ? '<br>' . htmlspecialchars((string) $b['verify_note']) : '' ?><?= !empty($b['self_verify_reason']) ? '<br>Own entry — reason: ' . htmlspecialchars((string) $b['self_verify_reason']) : '' ?></small></td>
          <td>
          <?php if ($canBank && $b['status'] === 'awaiting_confirmation'): ?>
            <small class="muted">Verify is available once the associate confirms.</small>
            <form method="post" action="/associates/<?= $id ?>/bank/<?= (int) $b['id'] ?>/remind" style="display:inline"><?= Csrf::field() ?><button type="submit" class="btn-sm btn-secondary">Remind associate</button></form>
          <?php elseif ($canBank && $b['status'] === 'pending'): ?>
            <?php if ($__own && !$__maySelf): ?>
              <small class="muted">Someone else must verify details you entered.</small>
            <?php elseif ($__two && $b['first_checked_by'] && (int) $b['first_checked_by'] === $__uid): ?>
              <small class="muted">A different person verifies — you did the first check.</small>
            <?php elseif ($__two && $b['first_checked_by']): ?>
              <form method="post" action="/associates/<?= $id ?>/bank/<?= (int) $b['id'] ?>/verify" class="inline-form"><?= Csrf::field() ?>
                <?php if ($__own): ?><input type="text" name="self_reason" required minlength="10" maxlength="300" placeholder="e.g. Only finance person is on leave until 20 Nov" aria-label="Why you verify details you entered yourself"><?php endif; ?>
                <button type="submit" class="btn-sm">Verify (second person)</button></form>
            <?php else: ?>
              <form method="post" action="/associates/<?= $id ?>/bank/<?= (int) $b['id'] ?>/<?= $__two ? 'check' : 'verify' ?>" data-bank-verify-form>
                <?= Csrf::field() ?>
                <fieldset><legend>How did you check it? <small class="muted">(tick at least two)</small></legend>
                  <label class="checkbox-label"><input type="checkbox" name="check_bank_proof" value="1" data-min-ticked="check_bank_proof,check_phone,check_test_payment:2" data-min-ticked-message="Tick at least two of the ways you checked the bank details."> Matches the bank proof on file (cancelled cheque / bank statement)</label>
                  <label class="checkbox-label"><input type="checkbox" name="check_phone" value="1"> Called the associate on their registered phone and they confirmed the last 4 digits</label>
                  <label class="checkbox-label"><input type="checkbox" name="check_test_payment" value="1"> Test payment of ₹1 / USD 1 received in the right name</label>
                  <label>Bank reference of the test payment (UTR / SWIFT)<input type="text" name="test_payment_ref" minlength="3" maxlength="60" placeholder="e.g. UTR 412345678901" data-required-when="check_test_payment=1" data-required-when-message="Enter the bank reference (UTR / SWIFT) of the test payment."></label>
                  <label>Note (optional)<input type="text" name="verify_note" maxlength="300" placeholder="e.g. Spoke to them on 11 Nov at 3 pm"></label>
                  <?php if ($__own): ?><label>You entered these details yourself — give the reason you verify them *<input type="text" name="self_reason" required minlength="10" maxlength="300" placeholder="e.g. Only finance person is on leave until 20 Nov"></label><?php endif; ?>
                </fieldset>
                <button type="submit" class="btn-sm"><?= $__two ? 'First check' : 'Verify' ?></button>
              </form>
            <?php endif; ?>
            <form method="post" action="/associates/<?= $id ?>/bank/<?= (int) $b['id'] ?>/reject" class="inline-form"><?= Csrf::field() ?><input type="text" name="reason" required minlength="10" maxlength="300" placeholder="Reason (shown to the associate)" aria-label="Reason for rejecting"><button type="submit" class="btn-sm btn-secondary">Reject</button></form>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if ($banks === []): ?><tr><td colspan="7" class="muted">No bank details yet — the associate adds them in their portal, or Accounts enter them below.</td></tr><?php endif; ?>
    </table>
    <p class="muted small">New bank details are used only after the associate confirms them in the portal (code to their registered e-mail, within <?= BankDetailsCheck::CONFIRM_DAYS ?> days — otherwise cancelled; "This is not me" cancels and alerts the MD and Finance) and
      <?= $__two ? 'two people' : 'one person' ?> other than the one who entered them verified them. Then a first account is used after <?= BankDetailsCheck::waitDays(true) ?> days, a changed account after <?= BankDetailsCheck::waitDays(false) ?> days (Associates › Commission settings).</p>
    <?php if ($canBank): ?>
    <details><summary>Enter bank details from the associate's signed form</summary>
      <form method="post" action="/associates/<?= $id ?>/bank" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <?php $__india = $a['based_in'] === 'india'; require dirname(__DIR__) . '/associate_portal/_bank_fields.php'; ?>
        <button type="submit" class="btn-sm">Record — the associate then confirms them</button>
      </form>
    </details>
    <?php endif; ?>
  </div>
