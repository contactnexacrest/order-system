<?php
use App\Helpers\Csrf;
use App\Repositories\IncentiveRepository;
use App\Services\IncentiveService;

$__table = $settings['table'];
while (count($__table) < 5) {
    $__table[] = ['from' => '', 'reward' => ''];
}
$__cutoffLabel = $settings['cutoff'] === 'last'
    ? 'the 1st to the last day of this month'
    : 'the ' . ((int) $settings['cutoff'] + 1) . date('S', mktime(0, 0, 0, 1, (int) $settings['cutoff'] + 1)) . ' of last month to the ' . (int) $settings['cutoff'] . date('S', mktime(0, 0, 0, 1, (int) $settings['cutoff'])) . ' of this month';
?>
<div class="card">
  <h1>Team Incentives</h1>
  <p class="muted">Only people with "Manage team incentives" can see this page. Staff never see the profit, the minimum or the reward table. Every change is recorded in the audit log; orders the buyer has already fully paid keep the figures they were fixed with.</p>

  <form method="post" action="/incentives/settings">
    <?= Csrf::field() ?>
    <fieldset><legend>Program</legend>
      <label class="setting-row"><span class="setting-label">Incentive program on<small class="muted">Off: no order gets a team reward. Associates are still paid under their agreements.<?= $settings['start_date'] !== '' ? ' Started ' . htmlspecialchars(\App\Helpers\Dates::short($settings['start_date'])) . ': orders fully paid before then get no team reward.' : '' ?></small></span>
        <input type="checkbox" name="program_enabled" value="1"<?= $settings['enabled'] ? ' checked' : '' ?>></label>
      <label class="setting-row"><span class="setting-label">Minimum order profit to qualify (&#8377;)<small class="muted">Checked on the order's profit after any associate commission.</small></span>
        <input type="number" name="min_profit_inr" min="0" step="1" required value="<?= (int) round($settings['min_profit']) ?>"></label>
    </fieldset>

    <fieldset><legend>Incentive cycle</legend>
      <label class="setting-row"><span class="setting-label">Incentive cut-off day<small class="muted">The last day of each incentive cycle: 1 to 28, or the last day of the month. A change applies from the next cycle.</small></span>
        <select name="cutoff_day" required>
          <option value="last"<?= $settings['cutoff'] === 'last' ? ' selected' : '' ?>>Last day of the month (1st to 31st)</option>
          <?php for ($d = 1; $d <= 28; $d++): ?><option value="<?= $d ?>"<?= $settings['cutoff'] === (string) $d ? ' selected' : '' ?>><?= $d ?></option><?php endfor; ?>
        </select></label>
      <p class="alert alert-info">Incentive cycle (Incentive pay): <strong><?= htmlspecialchars($__cutoffLabel) ?></strong>. Orders the buyer fully pays in this cycle are this month's incentive, paid together with this month's salary (fixed pay, 1st to 31st) on the last day of the month.</p>
    </fieldset>

    <fieldset><legend>Reward table</legend>
      <p class="muted small">The team reward for an order whose profit reaches each amount. Leave a row empty to drop it; one row is enough for one fixed amount. An amount set on an order replaces this.</p>
      <table class="list"><tr><th>Order profit from (&#8377;)</th><th>Team reward (&#8377;)</th></tr>
        <?php foreach ($__table as $i => $t): ?>
          <tr>
            <td><input type="number" data-unique-among="table_from[" data-unique-among-message="Another row already starts at this profit." name="table_from[<?= $i ?>]" min="1" step="1" value="<?= $t['from'] === '' ? '' : (int) round((float) $t['from']) ?>" aria-label="Order profit from, row <?= $i + 1 ?>"></td>
            <td><input type="number" name="table_reward[<?= $i ?>]" min="1" step="1" value="<?= $t['reward'] === '' ? '' : (int) round((float) $t['reward']) ?>" aria-label="Team reward, row <?= $i + 1 ?>"></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <label class="setting-row"><span class="setting-label">New client bonus (&#8377;)<small class="muted">Added on a client's first order (when it earns a reward); goes to Sales and Marketing. 0 = off.</small></span>
        <input type="number" name="new_client_bonus_inr" min="0" step="1" required value="<?= (int) round($settings['bonus']) ?>"></label>
    </fieldset>

    <fieldset><legend>How the reward is split</legend>
      <table class="list"><tr><th>Role</th><th>Share (%)</th></tr>
        <?php foreach (IncentiveRepository::ROLES as $role => $label): ?>
          <tr><td><?= htmlspecialchars($label) ?></td>
            <td><input type="number" name="split[<?= $role ?>]" min="0" max="100" step="0.01" required value="<?= rtrim(rtrim(number_format($settings['split'][$role], 2, '.', ''), '0'), '.') ?>" data-sum-group="split" data-sum-total="100" aria-label="<?= htmlspecialchars($label) ?> share"></td></tr>
        <?php endforeach; ?>
      </table>
      <p class="muted small">Must add up to 100. Two people in the same role on an order share that role's amount equally.</p>
    </fieldset>

    <fieldset><legend>Each person's incentive role</legend>
      <p class="muted small">Who usually does which role. On each order, the people assigned to it with that role get it (or everyone with the role, if none is assigned); it can be changed on the order.</p>
      <table class="list">
        <tr><th>Person</th><?php foreach (IncentiveRepository::ROLES as $label): ?><th><?= htmlspecialchars($label) ?></th><?php endforeach; ?></tr>
        <?php foreach ($users as $u): ?>
          <tr><td><?= htmlspecialchars((string) $u['name']) ?> <small class="muted"><?= htmlspecialchars((string) ($u['role_name'] ?? '')) ?></small></td>
            <?php foreach (IncentiveRepository::ROLES as $role => $label): ?>
              <td><input type="checkbox" name="user_roles[u<?= (int) $u['id'] ?>][]" value="<?= $role ?>"<?= in_array($role, $userRoles[(int) $u['id']] ?? [], true) ? ' checked' : '' ?> aria-label="<?= htmlspecialchars($u['name'] . ' — ' . $label) ?>"></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </table>
    </fieldset>

    <div class="btn-row"><button type="submit">Save</button> <a href="/associates">Associates</a> · <a href="/incentives/payouts">Incentive payouts</a></div>
  </form>
</div>
