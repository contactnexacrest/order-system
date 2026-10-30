<?php
/**
 * Step 12d · Team Incentive (owner decision 2026-10-30, mockups approved).
 * Shown only to holders of manage_incentives: the profit basis, the reward,
 * the associate's commission and who gets what. Expects $order and
 * $incentiveRow / $incentiveCalc / $incentivePayouts / $incentiveUsers.
 */
use App\Helpers\Csrf;
use App\Repositories\IncentiveRepository;
use App\Services\IncentiveService;

$__inc = $incentiveRow ?? null;
$__locked = $__inc !== null && !empty($__inc['locked_at']);
$__oid = (int) $order['id'];
$__sourceText = [
    'override' => 'the amount set on this order',
    'table' => 'your reward table',
    'excluded' => 'excluded — no reward on this order',
    'off' => 'the incentive program is switched off',
    'before_start' => 'fully paid before the program started',
    'below_min' => 'below the minimum profit — no reward',
];
?>
<details class="step-card" id="financials-12d">
  <summary><h2>Step 12d &middot; Team Incentive</h2>
    <span class="fin-sub-summary"><?= $__locked
        ? '&#8377;' . number_format((float) $__inc['team_total_inr'], 0) . ' · ' . htmlspecialchars(IncentiveService::monthLabel((string) $__inc['incentive_month'])) . ' incentive'
        : '&#8377;' . number_format($incentiveCalc['team_total'], 0) . ' · pending' ?></span></summary>
  <p class="muted small">Only you and people with "Manage team incentives" see this box. The team sees only their own amounts, once the buyer has fully paid.</p>

  <?php if ($__locked): ?>
    <div class="kv-grid">
      <div><span class="k">Buyer fully paid on</span><span class="v"><?= htmlspecialchars(\App\Helpers\Dates::short((string) $__inc['fully_paid_on'])) ?></span></div>
      <div><span class="k">Incentive month</span><span class="v"><?= htmlspecialchars(IncentiveService::monthLabel((string) $__inc['incentive_month'])) ?></span></div>
      <div><span class="k">Profit used for the incentive</span><span class="v"><?= number_format((float) $__inc['profit_basis_inr'], 2) ?></span></div>
      <div><span class="k">Team incentive</span><span class="v"><?= number_format((float) $__inc['team_total_inr'], 2) ?></span></div>
      <?php if (!empty($__inc['associate_id'])): ?>
        <div><span class="k">Associate commission</span><span class="v"><?= htmlspecialchars((string) $__inc['associate_currency']) ?> <?= number_format((float) $__inc['associate_amount_fc'], 2) ?> (&#8377;<?= number_format((float) $__inc['associate_amount_inr'], 2) ?>)</span></div>
      <?php endif; ?>
    </div>
    <table class="list" style="margin-top:10px">
      <tr><th>Person</th><th>Role</th><th>Amount (&#8377;)</th><th>Status</th></tr>
      <?php foreach ($incentivePayouts as $p): ?>
        <tr>
          <td><?= htmlspecialchars((string) ($p['user_name'] ?? $p['associate_name'] ?? '—')) ?></td>
          <td><?= htmlspecialchars($p['payee_kind'] === 'associate' ? 'Associate (agreement)' : (IncentiveRepository::ROLES[$p['role']] ?? $p['role'])) ?></td>
          <td><?= $p['amount_fc'] !== null ? htmlspecialchars((string) $p['currency_code']) . ' ' . number_format((float) $p['amount_fc'], 2) . ' · ' : '' ?><?= number_format((float) $p['amount_inr'], 2) ?></td>
          <td><?= $p['status'] === 'paid' ? 'Paid ' . htmlspecialchars(\App\Helpers\Dates::short((string) $p['paid_on'])) : 'Due' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($incentivePayouts === []): ?><tr><td colspan="4" class="muted">No incentive on this order.</td></tr><?php endif; ?>
    </table>
    <form method="post" action="/orders/<?= $__oid ?>/incentive/recalculate" data-confirm="Work this order's incentive out again with the current settings and people? Only possible while nothing is paid yet." style="margin-top:8px">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-sm btn-secondary">Work it out again</button>
      <span class="muted small">Fixed when the buyer fully paid. Use this to correct a mistake before anything is paid.</span>
    </form>
  <?php else: ?>
    <div class="kv-grid">
      <div><span class="k">Order profit before incentives</span><span class="v"><?= number_format($incentiveCalc['profit_before'], 2) ?></span></div>
      <?php if ($incentiveCalc['associate'] !== null): $__a = $incentiveCalc['associate']; ?>
        <div><span class="k">&minus; Associate commission (<?= htmlspecialchars($__a['name']) ?>, <?= number_format($__a['rate'], 2) ?>%, client no. <?= (int) $__a['order_number'] ?>)</span><span class="v"><?= number_format($__a['amount_inr'], 2) ?></span></div>
      <?php endif; ?>
      <div><span class="k">Profit used for the incentive</span><span class="v"><?= number_format($incentiveCalc['basis'], 2) ?></span></div>
      <div><span class="k">Team reward</span><span class="v">&#8377;<?= number_format($incentiveCalc['reward'], 0) ?><?= $incentiveCalc['new_client_bonus'] > 0 ? ' + &#8377;' . number_format($incentiveCalc['new_client_bonus'], 0) . ' new client bonus' : '' ?></span></div>
    </div>
    <p class="small">Reward from <?= htmlspecialchars($__sourceText[$incentiveCalc['source']] ?? $incentiveCalc['source']) ?>. Minimum profit: &#8377;<?= number_format($incentiveCalc['min_profit'], 0) ?>.
      <?php if ($incentiveCalc['pct_of_profit'] !== null && $incentiveCalc['team_total'] > 0): ?><span class="muted">Private check: &#8377;<?= number_format($incentiveCalc['team_total'], 0) ?> is <strong><?= number_format($incentiveCalc['pct_of_profit'], 1) ?>%</strong> of this order's profit.</span><?php endif; ?></p>

    <form method="post" action="/orders/<?= $__oid ?>/incentive">
      <?= Csrf::field() ?>
      <label>Reward for this order (&#8377;) <input type="number" name="reward_override_inr" min="0" step="1" value="<?= (int) round((float) ($__inc['reward_override_inr'] ?? 0)) ?>" required style="width:10em"> <small class="muted">0 = use the settings</small></label>
      <label><input type="checkbox" name="is_excluded" value="1"<?= !empty($__inc['is_excluded']) ? ' checked' : '' ?>> Exclude this order (no team reward)</label>
      <label>Associate commission on this order
        <select name="associate_mode">
          <?php foreach (['auto' => 'Automatic (client\'s first 10 days)', 'yes' => 'Yes', 'no' => 'No'] as $__v => $__l): ?>
            <option value="<?= $__v ?>"<?= ($__inc['associate_mode'] ?? 'auto') === $__v ? ' selected' : '' ?>><?= $__l ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn-sm">Save</button>
    </form>

    <h3 style="font-size:.95em">Who gets what</h3>
    <table class="list">
      <tr><th>Role</th><th>People</th><th>Share</th><th>Amount (&#8377;)</th></tr>
      <?php foreach ($incentiveCalc['roles'] as $__r): ?>
        <tr>
          <td><?= htmlspecialchars($__r['label']) ?></td>
          <td><?= $__r['people'] === [] ? '<span class="muted">nobody — this share is not paid</span>' : htmlspecialchars(implode(', ', array_column($__r['people'], 'name'))) ?></td>
          <td><?= rtrim(rtrim(number_format($__r['pct'], 2), '0'), '.') ?>%</td>
          <td><?= number_format($__r['amount'], 2) ?><?= count($__r['people']) > 1 ? ' (' . number_format($__r['each'], 2) . ' each)' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      <tr><td colspan="3"><strong>Total paid to the team</strong></td><td><strong><?= number_format($incentiveCalc['team_total'], 2) ?></strong></td></tr>
    </table>
    <?php if ($incentiveCalc['unallocated'] > 0): ?><p class="small">&#8377;<?= number_format($incentiveCalc['unallocated'], 2) ?> has nobody to go to and is not paid. Add people below or give staff their incentive roles in Team Incentives.</p><?php endif; ?>
    <details style="margin-top:8px">
      <summary class="small">Change who did each role on this order</summary>
      <form method="post" action="/orders/<?= $__oid ?>/incentive/people">
        <?= Csrf::field() ?>
        <table class="list">
          <tr><th>Person</th><?php foreach (IncentiveRepository::ROLES as $__label): ?><th><?= htmlspecialchars($__label) ?></th><?php endforeach; ?></tr>
          <?php foreach ($incentiveUsers as $__u): ?>
            <tr><td><?= htmlspecialchars((string) $__u['name']) ?></td>
              <?php foreach (IncentiveRepository::ROLES as $__role => $__label): ?>
                <td><input type="checkbox" name="people[<?= $__role ?>][]" value="<?= (int) $__u['id'] ?>"<?= in_array((int) $__u['id'], array_column($incentiveCalc['roles'][$__role]['people'], 'id'), true) ? ' checked' : '' ?> aria-label="<?= htmlspecialchars($__u['name'] . ' — ' . $__label) ?>"></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </table>
        <label><input type="checkbox" name="reset" value="1"> Go back to the usual people (each person's incentive role)</label>
        <button type="submit" class="btn-sm">Save people</button>
      </form>
    </details>
    <p class="muted small">Pending: fixed and added to the order's costs when the buyer has fully paid, then paid in that incentive month.</p>
  <?php endif; ?>
</details>
