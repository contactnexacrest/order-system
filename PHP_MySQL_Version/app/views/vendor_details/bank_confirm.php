<?php
/**
 * Owner decision 11 Nov 2026, point 18 (D-294): a supplier / logistics partner confirms new bank
 * details through the link e-mailed to their registered e-mail — current vs new, re-type the last
 * 4 digits, "These are our bank details"; or "This is not us — cancel it".
 * Node twin: views/vendor_details/bank_confirm.njk.
 */
use App\Helpers\Csrf;
use App\Helpers\Dates;
use App\Repositories\VendorDetailsRepository;

$__v = static fn (?string $x): string => htmlspecialchars($x !== null && $x !== '' ? $x : '—');
?>
<div class="card card-narrow">
  <h1>Please confirm your new bank details</h1>
  <p><?= htmlspecialchars((string) $vendor[$meta['name']]) ?> — new bank details were recorded on <b><?= htmlspecialchars(Dates::short(substr((string) $c['entered_at'], 0, 10))) ?></b>
    <?= $c['entered_source'] === 'vendor' ? 'through your details link' : 'by NexaCrest' ?>.</p>
  <table class="list" data-bank-compare>
    <tr><th></th><th>Current (paid to now)</th><th>New (waiting for you)</th></tr>
    <tr><th>Bank</th><td><?= $__v($current['bank_name'] ?? null) ?></td><td><b><?= $__v($c['bank_name']) ?></b></td></tr>
    <tr><th>Account holder</th><td><?= $__v($current['bank_account_name'] ?? null) ?></td><td><b><?= $__v($c['bank_account_name']) ?></b></td></tr>
    <tr><th>Account number</th><td><?= htmlspecialchars(VendorDetailsRepository::maskAccount($current['bank_account_number'] ?? null)) ?></td><td><b>••••<?= htmlspecialchars((string) $c['account_last4']) ?></b></td></tr>
    <tr><th>IFSC</th><td><?= $__v($current['bank_ifsc'] ?? null) ?></td><td><b><?= $__v($c['bank_ifsc']) ?></b></td></tr>
  </table>
  <form method="post" action="/vendor-bank-confirm/<?= htmlspecialchars($token) ?>">
    <?= Csrf::field() ?>
    <label>Type the last 4 digits of the NEW account number *<input type="text" name="last4" required minlength="4" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" autocomplete="off" placeholder="e.g. 6789" title="The last 4 digits of the new account number"></label>
    <label class="checkbox-label"><input type="checkbox" name="ours" value="1" required> These are our bank details and we want NexaCrest to pay us into them.</label>
    <div class="btn-row"><button type="submit">Confirm new bank details</button></div>
  </form>
  <form method="post" action="/vendor-bank-confirm/<?= htmlspecialchars($token) ?>/not-us" data-confirm="Cancel these new bank details? NexaCrest's Managing Director and Finance team will be told.">
    <?= Csrf::field() ?>
    <button type="submit" class="btn-danger">This is not us — cancel it</button>
  </form>
  <p class="muted small">Not confirmed by <b><?= htmlspecialchars(Dates::short(substr((string) $c['confirm_by'], 0, 10))) ?></b>? The change is cancelled automatically and the account we have stays in use.</p>
</div>
