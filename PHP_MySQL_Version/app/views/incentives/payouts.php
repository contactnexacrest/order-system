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
$__payDay = date('j M', strtotime(date('Y-m-t', strtotime($month . '-01'))));
?>
<div class="card">
  <h1>Incentive Payouts</h1>
  <form method="get" action="/incentives/payouts">
    <label>Incentive month
      <select name="month" onchange="this.form.submit()">
        <?php foreach ($months as $m): ?>
          <option value="<?= htmlspecialchars($m) ?>"<?= $m === $month ? ' selected' : '' ?>><?= htmlspecialchars(IncentiveService::monthLabel($m)) ?> incentive — orders fully paid <?= htmlspecialchars(IncentiveService::cycleLabel($m, $cutoff)) ?> · paid with the <?= htmlspecialchars(date('F', strtotime($m . '-01'))) ?> salary</option>
        <?php endforeach; ?>
      </select></label>
    <noscript><button type="submit" class="btn-sm">Show</button></noscript>
  </form>
  <p class="muted">Pay these together with the <?= htmlspecialchars(date('F', strtotime($month . '-01'))) ?> salary on <?= htmlspecialchars($__payDay) ?> (associates: against their invoice), then mark them paid. Each order's incentive then goes to Zoho Books as Commission Expense. Staff incentives go through payroll (salary TDS); the CA advises the TDS for associates.</p>

  <table class="list">
    <tr><th>Person</th><th>Order</th><th>Role</th><th>Amount</th><th>Status</th><th>Paid on · amount paid · TDS</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars((string) ($r['user_name'] ?? $r['associate_name'])) ?><?= $r['payee_kind'] === 'associate' ? ' <small class="muted">(associate, ' . htmlspecialchars($r['based_in'] === 'india' ? 'India' : ((string) ($r['country'] ?: 'abroad'))) . ')</small>' : '' ?></td>
        <td><?= htmlspecialchars((string) ($r['buyer_inquiry_ref'] ?: $r['order_reference'])) ?></td>
        <td><?= htmlspecialchars($r['payee_kind'] === 'associate' ? 'Associate (agreement)' : (IncentiveRepository::ROLES[$r['role']] ?? (string) $r['role'])) ?></td>
        <td>&#8377;<?= number_format((float) $r['amount_inr'], 2) ?><?= $r['payee_kind'] === 'associate' && $r['currency_code'] !== null && $r['currency_code'] !== 'INR' ? '<br><small class="muted">' . htmlspecialchars((string) $r['currency_code']) . ' ' . number_format((float) $r['amount_fc'], 2) . ' (order currency)</small>' : '' ?></td>
        <td><?= $r['status'] === 'paid' ? '<span class="badge badge-success">Paid ' . htmlspecialchars(\App\Helpers\Dates::short((string) $r['paid_on'])) . '</span><br><small class="muted">&#8377;' . number_format((float) $r['paid_inr'], 2) . ($r['tds_inr'] !== null ? ' · TDS &#8377;' . number_format((float) $r['tds_inr'], 2) : '') . '</small>' : '<span class="badge badge-warning">Due</span>' ?></td>
        <td>
          <?php if ($r['status'] === 'due'): ?>
            <form method="post" action="/incentives/payouts/<?= (int) $r['id'] ?>/paid">
              <?= Csrf::field() ?>
              <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
              <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:flex-start">
                <input type="date" name="paid_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" aria-label="Paid on" title="Paid on" style="width:10.5em">
                <input type="number" name="paid_inr" required min="0.01" step="0.01" value="<?= number_format((float) $r['amount_inr'], 2, '.', '') ?>" aria-label="Amount paid (₹)" title="Amount paid (₹)" style="width:8.5em">
                <input type="number" name="tds_inr" min="0" step="0.01" placeholder="TDS ₹" aria-label="TDS (₹)" title="TDS (₹), if any" style="width:7em" data-not-more-than="[name=paid_inr]" data-not-more-than-message="TDS cannot be more than the amount paid.">
                <button type="submit" class="btn-sm">Mark paid</button>
              </div>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="6" class="muted">No incentives for this month.</td></tr><?php endif; ?>
  </table>

  <p><strong>Total <?= htmlspecialchars(IncentiveService::monthLabel($month)) ?> incentive:</strong> staff &#8377;<?= number_format($__staff, 0) ?> · associates &#8377;<?= number_format($__assocInr, 0) ?><?php foreach ($__assocFc as $cur => $amt): ?> (<?= htmlspecialchars($cur) ?> <?= number_format($amt, 2) ?>)<?php endforeach; ?></p>
  <div class="btn-row">
    <?php if ($__dueIds !== []): ?>
      <form method="post" action="/incentives/payouts/paid-all" data-confirm="Mark every due payout of this month as paid, at the amounts shown?">
        <?= Csrf::field() ?>
        <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
        <?php foreach ($__dueIds as $id): ?><input type="hidden" name="payout_ids[]" value="<?= $id ?>"><?php endforeach; ?>
        <label class="small">Paid on <input type="date" name="paid_on" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></label>
        <button type="submit" class="btn-sm">Mark all paid</button>
      </form>
    <?php endif; ?>
    <a class="btn-sm" href="/incentives/payouts?month=<?= rawurlencode($month) ?>&amp;format=csv">Download list (CSV)</a>
  </div>
</div>
