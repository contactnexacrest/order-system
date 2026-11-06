<?php
/**
 * Step 12d · Team Incentive. Owner decision 2026-10-30; reworked by D-245 P4
 * (4 Nov 2026, mockup q2): the order's team incentive (global rule, % of
 * profit or a fixed amount set by the MD with a reason), who gets it, the
 * state Open → Ready to lock → Locked → Paid (the MD locks; nothing locks by
 * itself) and the history of every change. Shown to manage_incentives and
 * (read-only) view_price_buildup; only manage_pricing ($incentiveCanEdit)
 * changes or locks it. Expects $order, $incentiveRow, $incentiveCalc,
 * $incentivePayouts, $incentiveUsers, $incentiveHistory, $incentiveCanEdit.
 */
use App\Helpers\Csrf;
use App\Helpers\Dates;
use App\Repositories\IncentiveRepository;
use App\Services\IncentiveService;

$__inc = $incentiveRow ?? null;
$__c = $incentiveCalc;
$__teamOn = $incentiveProgramOn ?? true;
$__state = $__c['state'];
$__locked = in_array($__state, ['locked', 'paid'], true);
$__edit = !empty($incentiveCanEdit) && !$__locked;
$__oid = (int) $order['id'];
$__stateText = ['open' => 'Open — can be changed', 'ready' => 'Ready to lock', 'locked' => 'Locked', 'paid' => 'Paid — can never change'];
$__stateClass = ['open' => 'badge-info', 'ready' => 'badge-warning', 'locked' => 'badge-success', 'paid' => 'badge-success'];
$__money = static fn (float $v): string => '&#8377;' . number_format($v, 2);
$__est = $__c['estimated'] && !$__locked ? ' <small class="muted">(estimated)</small>' : '';
$__rule = $__c['rule'];
?>
<details class="step-card" id="financials-12d">
  <summary><h2>Step 12d &middot; Team Incentive</h2>
    <span class="fin-sub-summary"><?= $__locked ? $__money((float) $__inc['team_total_inr']) : $__money($__c['team_total']) ?> · <?= htmlspecialchars($__stateText[$__state]) ?></span></summary>
  <p class="muted small">Only the MD, the ED and people with "Manage team incentives" see this box<?= empty($incentiveCanEdit) ? ' (read-only for you — only the MD changes or locks it)' : '' ?>. The team sees only their own amounts, once the order is locked.<?= !$__teamOn ? ' The team incentive program is off: no global incentive — the MD may still set one by hand for this order.' : '' ?></p>
  <p><span class="badge <?= $__stateClass[$__state] ?>"><?= htmlspecialchars($__stateText[$__state]) ?></span>
    <?php if ($__state === 'open'): ?><small class="muted"><?= htmlspecialchars((string) IncentiveService::readyReason($__oid)) ?></small><?php endif; ?></p>

  <?php if ($__locked): ?>
    <div class="kv-grid">
      <div><span class="k">Locked</span><span class="v"><?= htmlspecialchars(Dates::short(substr((string) $__inc['locked_at'], 0, 10))) ?></span></div>
      <div><span class="k">Buyer fully paid on</span><span class="v"><?= htmlspecialchars(Dates::short((string) $__inc['fully_paid_on'])) ?></span></div>
      <div><span class="k">Staff payout run</span><span class="v"><?= htmlspecialchars(IncentiveService::monthLabel((string) $__inc['incentive_month'])) ?></span></div>
      <div><span class="k">Profit used for the incentive</span><span class="v"><?= $__money((float) $__inc['profit_basis_inr']) ?></span></div>
      <div><span class="k">Team incentive</span><span class="v"><?= $__money((float) $__inc['team_total_inr']) ?></span></div>
      <?php if (!empty($__inc['associate_id'])): ?>
        <div><span class="k">Associate commission</span><span class="v"><?= htmlspecialchars((string) $__inc['associate_currency']) ?> <?= number_format((float) $__inc['associate_amount_fc'], 2) ?> (<?= $__money((float) $__inc['associate_amount_inr']) ?>)</span></div>
      <?php endif; ?>
    </div>
    <table class="list" style="margin-top:10px">
      <tr><th>Person</th><th>Role</th><th>Amount (&#8377;)</th><th>Status</th></tr>
      <?php foreach ($incentivePayouts as $p): ?>
        <tr>
          <td><?= htmlspecialchars((string) ($p['user_name'] ?? $p['associate_name'] ?? '—')) ?></td>
          <td><?= htmlspecialchars(IncentiveRepository::roleLabel((string) $p['role'])) ?></td>
          <td><?= $p['amount_fc'] !== null ? htmlspecialchars((string) $p['currency_code']) . ' ' . number_format((float) $p['amount_fc'], 2) . ' · ' : '' ?><?= number_format((float) $p['amount_inr'], 2) ?></td>
          <td><?= $p['status'] === 'paid' ? 'Paid ' . htmlspecialchars(Dates::short((string) $p['paid_on'])) : 'Due' . (!empty($p['payout_run_on']) ? ' — ' . htmlspecialchars(Dates::short((string) $p['payout_run_on'])) . ' run' : '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($incentivePayouts === []): ?><tr><td colspan="4" class="muted">No incentive on this order.</td></tr><?php endif; ?>
    </table>
    <?php $__kept = IncentiveRepository::keptForOrder($__oid); // D-259 ?>
    <?php if ($__kept !== []): ?>
      <table class="list" style="margin-top:6px">
        <tr><th>Person</th><th>Role</th><th>By share (&#8377;)</th><th>Gets (&#8377;)</th><th>Kept by company</th></tr>
        <?php foreach ($__kept as $__k): ?>
          <tr><td><?= htmlspecialchars((string) $__k['user_name']) ?></td><td><?= htmlspecialchars(IncentiveRepository::roleLabel((string) $__k['role'])) ?></td>
            <td><?= number_format((float) $__k['by_share_inr'], 2) ?></td><td><?= number_format((float) $__k['paid_inr'], 2) ?></td>
            <td><?= number_format((float) $__k['kept_inr'], 2) ?> &mdash; <?= htmlspecialchars((string) $__k['kept_note']) ?></td></tr>
        <?php endforeach; ?>
        <tr><td colspan="4"><strong>Kept by company</strong></td><td><strong><?= number_format((float) $__inc['kept_not_eligible_inr'] + (float) $__inc['kept_capped_inr'], 2) ?></strong> (not eligible <?= number_format((float) $__inc['kept_not_eligible_inr'], 2) ?> &middot; capped <?= number_format((float) $__inc['kept_capped_inr'], 2) ?>)</td></tr>
      </table>
    <?php endif; ?>
    <?php if (!empty($__c['associate'])): $c = $__c['associate']; ?>
      <details style="margin-top:8px"><summary class="small">Associate commission statement</summary><?php require __DIR__ . '/../associate_portal/_statement.php'; ?></details>
    <?php endif; ?>
    <?php if (!empty($incentiveCanEdit) && $__state === 'locked'): ?>
      <form method="post" action="/orders/<?= $__oid ?>/incentive/recalculate" style="margin-top:8px">
        <?= Csrf::field() ?>
        <label>Reason for unlocking * <input type="text" name="unlock_reason" required minlength="10" maxlength="500" placeholder="e.g. A buyer claim came in after locking; the amount must change"></label>
        <button type="submit" class="btn-sm btn-secondary">Unlock</button>
        <span class="muted small">Possible until anything of this order is paid.</span>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <div class="kv-grid">
      <div><span class="k">Order profit before incentives<?= $__est ?></span><span class="v"><?= $__money($__c['profit_before']) ?></span></div>
      <?php if ($__c['associate'] !== null): $__a = $__c['associate']; ?>
        <div><span class="k">&minus; Associate commission (<?= htmlspecialchars($__a['name']) ?>, <?= htmlspecialchars($__a['level_label']) ?><?= $__a['state'] === 'unpaid' ? ' — none: not paid in full' : '' ?>)</span><span class="v"><?= $__money((float) $__a['amount_inr']) ?></span></div>
      <?php endif; ?>
      <div><span class="k">Profit used for the incentive<?= $__est ?></span><span class="v"><?= $__money($__c['basis']) ?><?= $__c['basis_raw'] < 0 ? ' <small class="muted">(never below 0; ' . $__money($__c['basis_raw']) . ' so far)</small>' : '' ?></span></div>
      <div><span class="k">Global rule</span><span class="v"><?= $__teamOn ? htmlspecialchars($__c['global_text']) . ' → ' . $__money($__c['global_amount']) : 'program off — none' ?></span></div>
      <div><span class="k"><strong>Team incentive for this order</strong></span><span class="v"><strong><?= $__money($__c['amount']) ?></strong> <small class="muted"><?= htmlspecialchars(IncentiveService::ruleText($__c)) ?></small></span></div>
      <div><span class="k">Profit left for NexaCrest</span><span class="v"><?= $__money($__c['left_for_company']) ?><?= $__c['margin_after_pct'] !== null ? ' <small class="muted">(margin ' . number_format($__c['margin_after_pct'], 2) . '%)</small>' : '' ?></span></div>
    </div>
    <?php if ($__c['over_profit']): ?>
      <p class="review-banner bad">The team incentive is more than the order's profit after the associate's commission — lower it; the order cannot be locked like this.</p>
    <?php elseif ($__c['margin_warning']): ?>
      <p class="review-banner warn">&#9888; Margin after the incentive is <?= number_format((float) $__c['margin_after_pct'], 2) ?>%, below your minimum margin of <?= number_format($__c['min_margin_pct'], 2) ?>% — allowed, but check it.</p>
    <?php endif; ?>
    <?php if ($__c['incentive_cut_usd'] > 0): ?>
      <p class="muted small">Lines the MD approved below the floor lowered this order's profit (USD <?= number_format($__c['incentive_cut_usd'], 2) ?> of incentive reserve on the price check). The incentive is a % of the actual profit, so that is already counted — it is not taken off a second time.</p>
    <?php endif; ?>
    <?php if ($__c['associate'] !== null): $c = $__c['associate']; ?>
      <details style="margin-top:8px"><summary class="small">Associate commission statement</summary><?php require __DIR__ . '/../associate_portal/_statement.php'; ?></details>
    <?php endif; ?>

    <?php if ($__edit): ?>
    <form method="post" action="/orders/<?= $__oid ?>/incentive" style="margin-top:10px">
      <?= Csrf::field() ?>
      <h3 style="font-size:.95em">Change for this order only</h3>
      <div class="row">
        <label>How it is set
          <select name="incentive_mode">
            <option value="global"<?= $__rule['mode'] === 'global' ? ' selected' : '' ?>>Global rule<?= $__teamOn ? '' : ' (program off: none)' ?></option>
            <option value="pct"<?= $__rule['mode'] === 'pct' ? ' selected' : '' ?>>% of this order's profit</option>
            <option value="fixed"<?= $__rule['mode'] === 'fixed' ? ' selected' : '' ?>>Fixed amount (&#8377;)</option>
          </select></label>
        <label>% or amount <input type="number" name="incentive_value" min="0" step="0.01" value="<?= $__rule['value'] !== null ? htmlspecialchars(rtrim(rtrim(number_format((float) $__rule['value'], 4, '.', ''), '0'), '.')) : '' ?>" placeholder="e.g. 25 or 100000" data-required-when="incentive_mode=fixed" data-required-when-message="Enter the amount for this order."></label>
      </div>
      <label>Reason <small class="muted">(needed when the amount changes; at least 10 characters)</small>
        <input type="text" name="incentive_reason" minlength="10" maxlength="500" value="<?= htmlspecialchars((string) ($__rule['reason'] ?? '')) ?>" placeholder="e.g. Large first order, the team worked weekends on samples"></label>
      <?php if (!empty($incentiveHasAssociate) || !empty($__inc['associate_id'])): ?>
      <label>Associate commission on this order
        <select name="associate_mode">
          <?php foreach (['auto' => 'Automatic (10 days from the client\'s first PO)', 'yes' => 'Yes — MD only, with a reason', 'no' => 'No'] as $__v => $__l): ?>
            <option value="<?= $__v ?>"<?= ($__inc['associate_mode'] ?? 'auto') === $__v ? ' selected' : '' ?>><?= $__l ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php // Owner decision D-245 P3: forcing commission on (a repeat order) is the MD's decision, with a written reason. ?>
      <label>Reason for forcing "Yes" <small class="muted">(MD only; at least 10 characters)</small>
        <input type="text" name="associate_mode_reason" minlength="10" maxlength="300" value="<?= htmlspecialchars((string) ($__inc['associate_mode_reason'] ?? '')) ?>" placeholder="e.g. Buyer introduced before the registration was recorded" data-required-when="associate_mode=yes" data-required-when-message="Say why commission is forced on for this order (at least 10 characters)."></label>
      <?php else: // owner point 10 (2 Nov): no associate select, but Save keeps the saved mode ?>
      <input type="hidden" name="associate_mode" value="<?= htmlspecialchars((string) ($__inc['associate_mode'] ?? 'auto')) ?>">
      <?php endif; ?>
      <button type="submit" class="btn-sm">Save</button>
    </form>
    <?php endif; ?>

    <h3 style="font-size:.95em">Who gets it</h3>
    <form method="post" action="/orders/<?= $__oid ?>/incentive/people">
      <?= Csrf::field() ?>
      <table class="list">
        <tr><th>Gets it?</th><th>Role</th><th>Usual share</th><th>Person(s)</th><th>Share on this order</th><th>Amount (&#8377;)</th></tr>
        <?php foreach ($__c['roles'] as $__key => $__r): ?>
          <tr>
            <td><input type="checkbox" name="gets_it[<?= htmlspecialchars($__key) ?>]" value="1"<?= $__r['ticked'] ? ' checked' : '' ?><?= $__edit ? '' : ' disabled' ?> aria-label="<?= htmlspecialchars($__r['label']) ?> gets it"></td>
            <td><?= htmlspecialchars($__r['label']) ?></td>
            <td><?= rtrim(rtrim(number_format($__r['usual_pct'], 2), '0'), '.') ?>%</td>
            <td><?= $__r['people'] === [] ? '<span class="muted">nobody — its share goes to the others</span>' : htmlspecialchars(implode(', ', array_column($__r['people'], 'name'))) ?></td>
            <td><?php if ($__edit): ?><input type="number" name="share_pct[<?= htmlspecialchars($__key) ?>]" min="0" max="100" step="0.01" style="width:6em" value="<?= htmlspecialchars(rtrim(rtrim(number_format($__r['custom_pct'] ?? $__r['pct'], 2, '.', ''), '0'), '.')) ?>" aria-label="<?= htmlspecialchars($__r['label']) ?> share on this order" placeholder="e.g. 30"><?php else: ?><?= $__r['gets_it'] ? rtrim(rtrim(number_format($__r['pct'], 2), '0'), '.') . '%' : '—' ?><?php endif; ?></td>
            <td><?= $__r['gets_it'] ? number_format($__r['amount'], 2) . (count($__r['people']) > 1 ? ' (' . number_format($__r['each'], 2) . ' each)' : '') : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <tr><td colspan="5"><strong>Total paid to the team</strong></td><td><strong><?= number_format($__c['team_total'], 2) ?></strong></td></tr>
      </table>
      <?php if ($__c['per_person'] !== []): ?>
        <p class="small"><strong>Per person:</strong> <?= implode(' · ', array_map(static fn (array $p): string => htmlspecialchars($p['name']) . ' ' . $__money($p['amount']), $__c['per_person'])) ?></p>
      <?php endif; ?>
      <?php // D-259 (owner approval 6 Nov 2026): each person's share, what they get and what the company keeps.
      $__lines = array_values(array_filter($__c['lines'] ?? [], static fn (array $l): bool => $l['by_share'] > 0)); ?>
      <?php if ($__lines !== []): ?>
        <table class="list" style="margin-top:6px" id="incentive-kept-<?= $__oid ?>">
          <tr><th>Person</th><th>Role</th><th>By share (&#8377;)</th><th>Gets (&#8377;)</th><th>Kept by company</th></tr>
          <?php foreach ($__lines as $__l): ?>
            <tr><td><?= htmlspecialchars($__l['name']) ?></td><td><?= htmlspecialchars($__l['role_label']) ?></td>
              <td><?= number_format($__l['by_share'], 2) ?></td><td><?= number_format($__l['gets'], 2) ?></td>
              <td><?= $__l['kept'] > 0 ? number_format($__l['kept'], 2) . ' &mdash; ' . htmlspecialchars((string) $__l['note']) : '&mdash;' ?></td></tr>
          <?php endforeach; ?>
          <tr><td colspan="2"><strong>Total</strong></td><td><strong><?= number_format((float) ($__c['team_by_share'] ?? 0), 2) ?></strong></td><td><strong><?= number_format($__c['team_total'], 2) ?></strong></td>
            <td><strong><?= number_format((float) ($__c['kept_total'] ?? 0), 2) ?> kept by company</strong><?= ($__c['kept_total'] ?? 0) > 0 ? ' (not eligible ' . number_format((float) $__c['kept_not_eligible'], 2) . ' &middot; capped ' . number_format((float) $__c['kept_capped'], 2) . ')' : '' ?></td></tr>
        </table>
        <?php if (($__c['kept_total'] ?? 0) > 0): ?><p class="muted small">The company's incentive cost on this order is <?= $__money($__c['team_total']) ?>, not <?= $__money((float) $__c['team_by_share']) ?>. Other people's amounts are not raised.</p><?php endif; ?>
      <?php endif; ?>
      <?php if ($__c['unallocated'] > 0): ?><p class="small">&#8377;<?= number_format($__c['unallocated'], 2) ?> has nobody to go to and is not paid. Tick a role with people, or give staff their incentive roles in Team Incentives.</p><?php endif; ?>
      <?php if ($__edit): ?>
        <label><input type="checkbox" name="shares_customised" value="1"<?= $__c['shares_customised'] ? ' checked' : '' ?>> Use the shares typed above (they must add up to 100% over the ticked roles); otherwise unticked shares spread over the ticked roles in proportion.</label>
        <details style="margin-top:6px">
          <summary class="small">Change who did each role on this order</summary>
          <table class="list">
            <tr><th>Person</th><?php foreach ($__c['roles'] as $__r): ?><th><?= htmlspecialchars($__r['label']) ?></th><?php endforeach; ?></tr>
            <?php foreach ($incentiveUsers as $__u): ?>
              <tr><td><?= htmlspecialchars((string) $__u['name']) ?></td>
                <?php foreach ($__c['roles'] as $__key => $__r): ?>
                  <td><input type="checkbox" name="people[<?= htmlspecialchars($__key) ?>][]" value="<?= (int) $__u['id'] ?>"<?= in_array((int) $__u['id'], array_column($__r['people'], 'id'), true) ? ' checked' : '' ?> aria-label="<?= htmlspecialchars($__u['name'] . ' — ' . $__r['label']) ?>"></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </table>
        </details>
        <label>Note <small class="muted">(optional)</small> <input type="text" name="people_note" maxlength="500" placeholder="e.g. Priya handled sales and documents alone"></label>
        <label><input type="checkbox" name="reset" value="1"> Go back to the usual roles, shares and people</label>
        <button type="submit" class="btn-sm">Save who gets it</button>
      <?php endif; ?>
    </form>

    <?php if (!empty($incentiveCanEdit) && $__state === 'ready'): ?>
      <form method="post" action="/orders/<?= $__oid ?>/incentive/lock" data-confirm="Lock this order's incentive? The team is paid in the next payout run." style="margin-top:8px">
        <?= Csrf::field() ?><button type="submit" class="btn-sm">Lock incentive</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($incentiveHistory)): ?>
    <h3 style="font-size:.95em">History</h3>
    <table class="list">
      <tr><th>When</th><th>Who</th><th>What</th><th>Before → after</th><th>Reason</th></tr>
      <?php foreach ($incentiveHistory as $__h): ?>
        <tr><td><?= htmlspecialchars(Dates::short(substr((string) $__h['changed_at'], 0, 10))) ?> <?= htmlspecialchars(substr((string) $__h['changed_at'], 11, 5)) ?></td>
          <td><?= htmlspecialchars((string) ($__h['changed_by_name'] ?? '')) ?></td>
          <td><?= htmlspecialchars(['amount' => 'Amount', 'people' => 'Who gets it', 'lock' => 'Locked', 'unlock' => 'Unlocked', 'associate_mode' => 'Associate commission'][$__h['action']] ?? (string) $__h['action']) ?></td>
          <td><?= htmlspecialchars(trim(((string) $__h['before_value']) . ' → ' . ((string) $__h['after_value']), ' →')) ?></td>
          <td><?= htmlspecialchars((string) ($__h['reason'] ?? '')) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <p class="muted small">Open: changes any time. Ready to lock: the buyer has fully paid and no claim is open. Locked by the MD: fixed and added to the order's costs; unlock with a reason until anything is paid. Paid: never changes.</p>
</details>
