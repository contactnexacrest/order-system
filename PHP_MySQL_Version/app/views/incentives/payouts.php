<?php
use App\Helpers\Csrf;
use App\Repositories\IncentiveRepository;
use App\Services\IncentiveService;

$__staff = 0.0;
$__assocInr = 0.0;
$__assocFc = [];
$__dueIds = [];
foreach ($rows as $r) {
    if ($r['payee_kind'] === 'staff') {
        $__staff += (float) $r['amount_inr'];
    } else {
        $__assocInr += (float) $r['amount_inr'];
        if ($r['currency_code'] !== null && $r['currency_code'] !== 'INR') {
            $__assocFc[$r['currency_code']] = ($__assocFc[$r['currency_code']] ?? 0) + (float) $r['amount_fc'];
        }
    }
    if ($r['status'] === 'due') {
        $__dueIds[] = (int) $r['id'];
    }
}
?>
<div class="card">
  <h1>Incentive Payouts</h1>
  <form method="get" action="/incentives/payouts">
    <label>Incentive month
      <select name="month" onchange="this.form.submit()">
        <?php foreach ($months as $m): ?>
          <option value="<?= htmlspecialchars($m) ?>"<?= $m === $month ? ' selected' : '' ?>><?= htmlspecialchars(IncentiveService::monthLabel($m)) ?> payout run — orders locked before it</option>
        <?php endforeach; ?>
      </select></label>
    <noscript><button type="submit" class="btn-sm">Show</button></noscript>
  </form>
  <p class="muted">Pay these in the <?= htmlspecialchars(IncentiveService::monthLabel($month)) ?> payout run (<?= htmlspecialchars(\App\Services\AssociateCommission::runLabel()) ?>; staff with the salary, associates against their invoice on or after their due date), then mark them paid. Each order's incentive then goes to Zoho Books as Commission Expense. Staff incentives go through payroll (salary TDS). For associates the app works out the TDS (Settings &rarr; Associate TDS: section <?= htmlspecialchars(\App\Services\AssociateTds::settings()['section']) ?> at <?= htmlspecialchars(\App\Services\AssociateTds::pct(\App\Services\AssociateTds::settings()['rate'])) ?> once the financial year passes &#8377;<?= number_format(\App\Services\AssociateTds::settings()['threshold'], 0) ?>) — pay the net amount and deposit the TDS by the 7th of next month.</p>

  <table class="list">
    <tr><th>Person</th><th>Order</th><th>Role</th><th>Amount</th><th>Status</th><th>Paid on · amount paid · TDS</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars((string) ($r['user_name'] ?? $r['associate_name'])) ?><?= $r['payee_kind'] === 'associate' ? ' <small class="muted">(associate, ' . htmlspecialchars($r['based_in'] === 'india' ? 'India' : ((string) ($r['country'] ?: 'abroad'))) . ')</small>' : '' ?></td>
        <td><?= htmlspecialchars((string) ($r['buyer_inquiry_ref'] ?: $r['order_reference'])) ?></td>
        <td><?= htmlspecialchars($r['payee_kind'] === 'associate' ? 'Associate (agreement)' : IncentiveRepository::roleLabel((string) $r['role'])) ?></td>
        <td>&#8377;<?= number_format((float) $r['amount_inr'], 2) ?><?= $r['payee_kind'] === 'associate' && $r['currency_code'] !== null && $r['currency_code'] !== 'INR' ? '<br><small class="muted">' . htmlspecialchars((string) $r['currency_code']) . ' ' . number_format((float) $r['amount_fc'], 2) . ' (order currency)</small>' : '' ?></td>
        <td><?= $r['status'] === 'paid' ? '<span class="badge badge-success">Paid ' . htmlspecialchars(\App\Helpers\Dates::short((string) $r['paid_on'])) . '</span><br><small class="muted">&#8377;' . number_format((float) $r['paid_inr'], 2) . ($r['tds_inr'] !== null ? ' · TDS &#8377;' . number_format((float) $r['tds_inr'], 2) : '') . '</small>' : '<span class="badge badge-warning">Due</span>' ?></td>
        <td>
          <?php $__t = $tdsCalc[(int) $r['id']] ?? null; ?>
          <?php if ($r['status'] === 'paid' && $r['payee_kind'] === 'associate' && !empty($r['tds_reason'])): ?>
            <small class="muted">TDS: <?= htmlspecialchars((string) $r['tds_reason']) ?></small>
          <?php endif; ?>
          <?php if ($r['status'] === 'due'): ?>
            <form method="post" action="/incentives/payouts/<?= (int) $r['id'] ?>/paid"<?= $__t !== null && $__t['abroad'] ? ' enctype="multipart/form-data"' : '' ?>>
              <?= Csrf::field() ?>
              <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
              <?php if ($__t !== null): ?>
                <p class="small" data-associate-tds style="margin:0 0 4px">Gross &#8377;<?= number_format($__t['gross'], 2) ?> &middot; TDS &#8377;<?= number_format($__t['tds'], 2) ?> <span class="muted">(<?= htmlspecialchars($__t['reason']) ?>)</span> &middot; <strong>Net to pay &#8377;<?= number_format($__t['net'], 2) ?></strong></p>
                <?php if ($__t['abroad'] && ($__t['missing_foreign'] !== [] || $__t['missing_payout'] !== [])): ?>
                  <p class="small" style="margin:0 0 4px;color:#9a3412">Before paying: <?= htmlspecialchars(implode(', ', array_merge($__t['missing_foreign'], $__t['missing_payout']))) ?> must be on file.<?= $__t['missing_foreign'] !== [] ? ' Accept the TRC / Form 10F / no-PE on the associate\'s Documents tab first.' : '' ?></p>
                <?php endif; ?>
              <?php endif; ?>
              <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:flex-start">
                <input type="date" name="paid_on" required min="<?= \App\Services\IncentiveService::earliestPaidOn((string) $r['incentive_month']) ?>" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" aria-label="Paid on" title="Paid on" style="width:10.5em">
                <input type="number" name="paid_inr" required min="<?= $__t !== null ? '0' : '0.01' ?>" max="<?= number_format((float) $r['amount_inr'], 2, '.', '') ?>" step="0.01" value="<?= number_format($__t !== null ? $__t['net'] : (float) $r['amount_inr'], 2, '.', '') ?>" aria-label="Amount paid (₹)" title="<?= $__t !== null ? 'Net amount paid (₹) — the commission less TDS' : 'Amount paid (₹)' ?>" style="width:8.5em" placeholder="e.g. 25000.00">
                <input type="number" name="tds_inr" min="0" step="0.01" placeholder="TDS ₹" aria-label="TDS (₹)" title="TDS (₹)<?= $__t !== null ? ' — worked out by the app; change it only with a reason' : ', if any' ?>" style="width:7em"<?= $__t !== null ? ' value="' . number_format($__t['tds'], 2, '.', '') . '"' : '' ?> data-not-more-than="[name=paid_inr]" data-not-more-than-message="TDS cannot be more than the amount paid.">
                <?php if ($__t !== null): ?>
                  <input type="text" name="tds_override_reason" minlength="10" maxlength="255" placeholder="Only if you change the TDS: why (e.g. CA advice)" aria-label="Reason for a different TDS" title="Needed only when the TDS differs from the amount worked out" style="width:16em">
                  <?php if ($__t['abroad']): ?>
                    <label class="small">Form 15CA <input type="file" name="form_15ca_file" accept=".pdf,.jpg,.jpeg,.png"></label>
                    <?php if (\App\Services\AssociateTds::settings()['cb_required']): ?><label class="small">Form 15CB <input type="file" name="form_15cb_file" accept=".pdf,.jpg,.jpeg,.png"></label><?php endif; ?>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($r['payee_kind'] === 'associate'): // D-264: what the payment cost — never deducted from the commission ?>
                  <?php if ($r['based_in'] !== 'india'): ?>
                    <input type="number" name="bank_debited_inr" required min="0.01" step="0.01" placeholder="Rupees debited, e.g. 105125.00" aria-label="Rupees debited by the bank (₹)" title="Rupees the bank debited for <?= htmlspecialchars((string) $r['currency_code']) ?> <?= number_format((float) $r['amount_fc'], 2) ?> (from the bank advice)" style="width:11em">
                  <?php endif; ?>
                  <input type="number" name="bank_charges_inr" min="0" step="0.01" placeholder="<?= $r['based_in'] !== 'india' ? 'Our + intermediary charges ₹' : 'Our bank charges ₹' ?>" aria-label="Bank charges (₹)" title="Paid by NexaCrest — not deducted from the commission" style="width:11em">
                <?php endif; ?>
                <select name="paid_via" required aria-label="Paid via" title="Paid via"><option value="">Paid via…</option><?php foreach (IncentiveRepository::PAID_VIA as $__k => $__v): ?><option value="<?= $__k ?>"><?= htmlspecialchars(ucfirst($__v)) ?></option><?php endforeach; ?></select>
                <input type="text" name="paid_reference" required minlength="4" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9 .\/_#:\(\)\-]*" placeholder="Payroll Nov-2026 / UTR / cheque no." aria-label="Payment reference" title="Payment reference: payroll month, UTR or cheque no. (rule D-212)" style="width:13em">
                <button type="submit" class="btn-sm">Mark paid</button>
              </div>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="6" class="muted">No incentives for this month.</td></tr><?php endif; ?>
  </table>

  <?php if (!empty($payoutCosts)): ?>
  <h2>Associate payout costs — <?= htmlspecialchars(IncentiveService::monthLabel($month)) ?></h2>
  <p class="small muted">D-264: what paying associates cost on top of their fixed commission (exchange difference and transfer charges). A company cost of the month — the orders keep the fixed commission.</p>
  <?php if (\App\Services\AssociateCommission::settings()['zoho_payout_costs_account_id'] === ''): ?><div class="alert alert-warning small" data-payout-costs-unmapped><?= htmlspecialchars(\App\Services\CaSyncService::PAYOUT_COSTS_UNMAPPED) ?></div><?php endif; ?>
  <table class="list" data-payout-costs>
    <tr><th>Payout</th><th class="r">Payout difference (₹)</th><th>Zoho Books</th></tr>
    <?php foreach ($payoutCosts as $__pc): ?><tr><td><?= htmlspecialchars((string) $__pc['description']) ?></td><td class="r"><?= number_format((float) $__pc['payout_difference_inr'], 2) ?></td><td class="small"><?= (float) $__pc['payout_difference_inr'] == 0.0 ? '—' : (!empty($__pc['zoho_cost_journal_id']) ? 'Journal ' . htmlspecialchars((string) $__pc['zoho_cost_journal_id']) : 'Goes at the next sync') ?></td></tr><?php endforeach; ?>
    <tr><td><b>Total</b></td><td class="r"><b><?= number_format(array_sum(array_map(static fn (array $x): float => (float) $x['payout_difference_inr'], $payoutCosts)), 2) ?></b></td><td></td></tr>
  </table>
  <?php endif; ?>
  <p><strong>Total <?= htmlspecialchars(IncentiveService::monthLabel($month)) ?> incentive:</strong> staff &#8377;<?= number_format($__staff, 0) ?> · associates &#8377;<?= number_format($__assocInr, 0) ?><?php foreach ($__assocFc as $cur => $amt): ?> (<?= htmlspecialchars($cur) ?> <?= number_format($amt, 2) ?>)<?php endforeach; ?></p>
  <div class="btn-row">
    <?php if ($__dueIds !== []): ?>
      <form method="post" action="/incentives/payouts/paid-all" data-confirm="Mark every due payout of this month as paid, at the amounts shown?">
        <?= Csrf::field() ?>
        <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
        <?php foreach ($__dueIds as $id): ?><input type="hidden" name="payout_ids[]" value="<?= $id ?>"><?php endforeach; ?>
        <label class="small">Paid on <input type="date" name="paid_on" required min="<?= \App\Services\IncentiveService::earliestPaidOn($month) ?>" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
        <label class="small">Paid via <select name="paid_via" required aria-label="Paid via" title="Paid via"><option value="">Paid via…</option><?php foreach (IncentiveRepository::PAID_VIA as $__k => $__v): ?><option value="<?= $__k ?>"><?= htmlspecialchars(ucfirst($__v)) ?></option><?php endforeach; ?></select></label>
        <label class="small">Payment reference <input type="text" name="paid_reference" required minlength="4" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9 .\/_#:\(\)\-]*" placeholder="Payroll Nov-2026 / UTR / cheque no." title="Payment reference: payroll month, UTR or cheque no. (rule D-212)" style="width:13em"></label>
        <button type="submit" class="btn-sm">Mark all paid</button>
      </form>
    <?php endif; ?>
    <a class="btn-sm" href="/incentives/payouts?month=<?= rawurlencode($month) ?>&amp;format=csv">Download list (CSV)</a>
  </div>
</div>
