<?php
use App\Helpers\Csrf;
use App\Services\AssociateCommission;

$__roleTotal = 0.0;
foreach ($roles as $__r) {
    $__roleTotal += $__r['is_active'] ? $__r['share_pct'] : 0.0;
}
?>
<div class="card">
  <h1>Team Incentives</h1>
  <p class="muted">Only people with "Manage team incentives" can see this page. Staff never see an order's profit or another person's amount. Every change is recorded in the audit log.</p>
  <p><a href="/incentives/ready">Ready to lock (<?= (int) $readyCount ?>)</a> · <a href="/incentives/payouts">Incentive payouts</a> · <a href="/associates">Associates</a> · <a href="/pricing/settings">Pricing settings (incentive % or amount)</a></p>

  <form method="post" action="/incentives/settings">
    <?= Csrf::field() ?>
    <fieldset><legend>Program</legend>
      <label class="setting-row"><span class="setting-label">Incentive program on<small class="muted">On: every order gets the team incentive of the global rule (Pricing settings: a % of profit or a fixed amount; a product may have its own). Off: no order gets one, unless the MD sets one by hand on an order. Associates are still paid under their agreements.</small></span>
        <input type="checkbox" name="program_enabled" value="1"<?= $settings['enabled'] ? ' checked' : '' ?>></label>
      <label class="setting-row"><span class="setting-label">Remind the MD before a payout run (days)<small class="muted">Orders ready to lock but not locked are listed to the MD this many days before each payout run (<?= htmlspecialchars(AssociateCommission::runLabel()) ?>). 0 = no reminder.</small></span>
        <input type="number" name="lock_reminder_days" min="0" max="28" step="1" required value="<?= (int) $settings['reminder_days'] ?>" placeholder="e.g. 3"></label>
      <p class="alert alert-info">Nothing locks by itself: an order is <strong>ready to lock</strong> once the buyer has fully paid and no claim is open; the MD locks it (one by one or all ready). The team is paid in the payout run after the lock date; an associate on their due date.</p>
    </fieldset>

    <fieldset><legend>Incentive roles and their usual shares</legend>
      <p class="muted small">An editable list. The usual shares of the active roles must add up to 100% (now <?= rtrim(rtrim(number_format($__roleTotal, 2), '0'), '.') ?>%). On each order the MD can untick a role (its share spreads over the others) or set the shares. A role used on orders or payouts can only be deactivated.<?= $canManagePricing ? '' : ' Only the MD can change the roles.' ?></p>
      <table class="list"><tr><th>Role</th><th>Usual share (%)</th><th>Active</th><th></th></tr>
        <?php foreach ($roles as $key => $r): ?>
          <tr>
            <td><input type="text" name="roles[<?= htmlspecialchars($key) ?>][label]" required maxlength="80" value="<?= htmlspecialchars($r['label']) ?>" aria-label="Role name" placeholder="e.g. Sales"<?= $canManagePricing ? '' : ' disabled' ?>></td>
            <td><input type="number" name="roles[<?= htmlspecialchars($key) ?>][share_pct]" min="0" max="100" step="0.01" required value="<?= rtrim(rtrim(number_format($r['share_pct'], 2, '.', ''), '0'), '.') ?>" aria-label="<?= htmlspecialchars($r['label']) ?> share" placeholder="e.g. 30"<?= $canManagePricing ? '' : ' disabled' ?>></td>
            <td><input type="checkbox" name="roles[<?= htmlspecialchars($key) ?>][is_active]" value="1"<?= $r['is_active'] ? ' checked' : '' ?> aria-label="<?= htmlspecialchars($r['label']) ?> active"<?= $canManagePricing ? '' : ' disabled' ?>></td>
            <td><?php if ($canManagePricing): ?><button type="submit" class="btn-sm btn-secondary" formaction="/incentives/roles/<?= rawurlencode($key) ?>/delete" formnovalidate data-confirm="Delete the role <?= htmlspecialchars($r['label']) ?>? Only possible if it was never used.">Delete</button><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($canManagePricing): ?>
          <tr>
            <td><input type="text" name="new_role_label" maxlength="80" aria-label="New role name" placeholder="New role, e.g. Quality"></td>
            <td><input type="number" name="new_role_share" min="0" max="100" step="0.01" aria-label="New role share" placeholder="e.g. 5"></td>
            <td colspan="2" class="muted small">Add a role (lower another share so they still add up to 100%).</td>
          </tr>
        <?php endif; ?>
      </table>
    </fieldset>

    <fieldset><legend>Each person's incentive role</legend>
      <p class="muted small">Who usually does which role. On each order, the people assigned to it with that role get it (or everyone with the role, if none is assigned); the MD can change it on the order.</p>
      <input type="hidden" name="user_roles_present" value="1">
      <table class="list">
        <tr><th>Person</th><?php foreach ($activeRoles as $label): ?><th><?= htmlspecialchars($label) ?></th><?php endforeach; ?></tr>
        <?php foreach ($users as $u): ?>
          <tr><td><?= htmlspecialchars((string) $u['name']) ?> <small class="muted"><?= htmlspecialchars((string) ($u['role_name'] ?? '')) ?></small></td>
            <?php foreach ($activeRoles as $role => $label): ?>
              <td><input type="checkbox" name="user_roles[u<?= (int) $u['id'] ?>][]" value="<?= htmlspecialchars($role) ?>"<?= in_array($role, $userRoles[(int) $u['id']] ?? [], true) ? ' checked' : '' ?> aria-label="<?= htmlspecialchars($u['name'] . ' — ' . $label) ?>"></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </table>
    </fieldset>

    <div class="btn-row"><button type="submit">Save</button></div>
  </form>
</div>
