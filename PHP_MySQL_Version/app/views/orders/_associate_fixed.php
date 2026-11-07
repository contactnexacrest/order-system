<?php
/**
 * Owner decision D-264 (7 Nov 2026): the associate's commission, fixed by itself when the buyer
 * paid in full — the fixed amount and rate, where its payout stands, and (MD only, before it is
 * paid) "Change commission" with a reason. Expects $__inc (order_incentives row), $__oid, $incentiveCanEdit.
 */
use App\Helpers\Csrf;
use App\Helpers\Dates;
use App\Repositories\AssociatePortalRepository;
use App\Repositories\IncentiveRepository;
use App\Services\AssociateCommission;

$__ap = null;
foreach (IncentiveRepository::payoutsForOrder($__oid) as $__x) {
    if ($__x['payee_kind'] === 'associate' && $__x['clawback_of_payout_id'] === null) {
        $__ap = $__x;
        break;
    }
}
$__cur = (string) $__inc['associate_currency'];
$__claim = $__ap !== null && !empty($__ap['due_on']) && AssociateCommission::settings()['claim_hold'] && AssociateCommission::claimOpen($__oid, (string) $__ap['due_on']);
?>
<div class="info-box" data-associate-fixed style="margin:8px 0">
  <b>Associate commission fixed</b> — <?= htmlspecialchars($__cur) ?> <?= number_format((float) $__inc['associate_amount_fc'], 2) ?> = &#8377;<?= number_format((float) $__inc['associate_amount_inr'], 2) ?>
  at &#8377;<?= number_format((float) $__inc['associate_fixed_rate'], 2) ?> per <?= htmlspecialchars($__cur) ?> (the rate of all the buyer's payments), fixed on <?= htmlspecialchars(Dates::short(substr((string) $__inc['associate_fixed_at'], 0, 10))) ?>.
  This is the cost in the order's profit and it does not change.
  <?php if ($__ap !== null): ?><br><small><?= htmlspecialchars(AssociatePortalRepository::payoutLabel($__ap, $__claim, (string) $__ap['incentive_month'])) ?></small><?php endif; ?>
  <?php if ($__inc['associate_override_fc'] !== null): ?><br><small class="muted">Changed by the MD: <?= htmlspecialchars((string) $__inc['associate_override_reason']) ?></small><?php endif; ?>
  <?php if (!empty($incentiveCanEdit) && $__ap !== null && $__ap['status'] !== 'paid'): ?>
    <details style="margin-top:6px"><summary class="small">Change commission (MD, before it is paid)</summary>
      <form method="post" action="/orders/<?= $__oid ?>/incentive/associate-commission" class="field-grid" style="margin-top:6px">
        <?= Csrf::field() ?>
        <label>New commission (<?= htmlspecialchars($__cur) ?>) *<input type="number" name="associate_amount_fc" required min="0" step="0.01" value="<?= number_format((float) $__inc['associate_amount_fc'], 2, '.', '') ?>" placeholder="e.g. 1250.00"><span class="help-line">Worked out in rupees at the same fixed rate.</span></label>
        <label>Reason * <input type="text" name="associate_change_reason" required minlength="10" maxlength="500" placeholder="e.g. Agreed with the associate after the buyer's late claim"></label>
        <div class="btn-row"><button type="submit" class="btn-sm">Change commission</button></div>
      </form>
    </details>
  <?php endif; ?>
</div>
